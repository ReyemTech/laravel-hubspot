<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Webhooks;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use ReyemTech\Hubspot\Tests\TestCase;
use ReyemTech\Hubspot\Webhooks\ProcessWebhookEventJob;
use ReyemTech\Hubspot\Webhooks\WebhookController;
use RuntimeException;

mutates(WebhookController::class);

/**
 * The deterministic 401/400/500/204 status mapping (D-13, D-14) and D-15's local-development
 * bypass, over the shipped `WebhookController` (05-01-PLAN.md Task 3).
 */
final class InboundWebhookFailureTest extends TestCase
{
    private const string SECRET = 'test-client-secret-03';

    private const string URI = '/hubspot/webhook';

    /**
     * @param  Router  $router
     */
    protected function defineRoutes($router): void
    {
        // Larastan's macro-methods extension resolves a facade macro only by calling
        // Route::getFacadeRoot() at analysis time -- see the identical note on
        // InboundWebhookTracerTest::defineRoutes(), which this mirrors.
        // @phpstan-ignore staticMethod.notFound
        Route::hubspotWebhook(ltrim(self::URI, '/'));
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('hubspot.webhooks.secret', self::SECRET);
    }

    public function test_enforcement_is_true_by_default(): void
    {
        self::assertTrue(
            config('hubspot.webhooks.enforce'),
            'D-15\'s bypass must never be the shipped default -- an operator who never sets '
            .'HUBSPOT_WEBHOOK_ENFORCE gets the fail-closed behaviour D-20 requires.',
        );
    }

    public function test_it_rejects_a_request_carrying_no_signature_at_all(): void
    {
        Bus::fake();

        $body = self::batchPayload([self::rawEventItem()]);

        $response = $this->call('POST', self::URI, [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);

        $response->assertStatus(401);
        Bus::assertNothingDispatched();
    }

    public function test_it_rejects_a_request_carrying_a_wrong_signature(): void
    {
        Bus::fake();

        $body = self::batchPayload([self::rawEventItem()]);
        $headers = self::signedHeaders('POST', 'http://localhost'.self::URI, $body);
        $headers['HTTP_X_HUBSPOT_SIGNATURE_V3'] = base64_encode('not-the-right-signature');

        $response = $this->call('POST', self::URI, [], [], [], $headers, $body);

        $response->assertStatus(401);
        Bus::assertNothingDispatched();
    }

    public function test_it_rejects_a_correctly_signed_request_whose_timestamp_is_stale(): void
    {
        Bus::fake();

        $body = self::batchPayload([self::rawEventItem()]);
        $staleTimestamp = ((int) round(microtime(true) * 1000)) - 400_000;
        $headers = self::signedHeaders('POST', 'http://localhost'.self::URI, $body, $staleTimestamp);

        $response = $this->call('POST', self::URI, [], [], [], $headers, $body);

        $response->assertStatus(401);
        Bus::assertNothingDispatched();
    }

    public function test_it_rejects_a_signed_body_that_is_not_valid_json(): void
    {
        Bus::fake();
        $log = Log::spy();

        $body = 'not json at all';
        $headers = self::signedHeaders('POST', 'http://localhost'.self::URI, $body);

        $response = $this->call('POST', self::URI, [], [], [], $headers, $body);

        $response->assertStatus(400);
        Bus::assertNothingDispatched();
        $log->shouldHaveReceived('error', [
            'A HubSpot webhook request failed shape validation.',
            ['error_code' => 'invalid_json', 'item_count' => null, 'route' => 'hubspot/webhook'],
        ]);
    }

    public function test_it_rejects_a_signed_body_that_is_not_a_json_array(): void
    {
        Bus::fake();
        $log = Log::spy();

        $body = self::rawEventItemAsJsonObject();
        $headers = self::signedHeaders('POST', 'http://localhost'.self::URI, $body);

        $response = $this->call('POST', self::URI, [], [], [], $headers, $body);

        $response->assertStatus(400);
        Bus::assertNothingDispatched();
        $log->shouldHaveReceived('error', [
            'A HubSpot webhook request failed shape validation.',
            ['error_code' => 'not_a_json_array', 'item_count' => null, 'route' => 'hubspot/webhook'],
        ]);
    }

    public function test_it_rejects_a_signed_batch_containing_one_invalid_item(): void
    {
        Bus::fake();
        $log = Log::spy();

        $invalidItem = self::rawEventItem();
        unset($invalidItem['eventId']);
        $body = self::batchPayload([self::rawEventItem(), $invalidItem]);
        $headers = self::signedHeaders('POST', 'http://localhost'.self::URI, $body);

        $response = $this->call('POST', self::URI, [], [], [], $headers, $body);

        $response->assertStatus(400);
        Bus::assertNothingDispatched();
        $log->shouldHaveReceived('error', [
            'A HubSpot webhook request failed shape validation.',
            ['error_code' => 'invalid_item', 'item_count' => 2, 'route' => 'hubspot/webhook'],
        ]);
    }

    /**
     * Distinct from the previous case: here the array ITEM ITSELF is not a JSON object at all
     * (a bare scalar), never reaching `NormalizedWebhookEvent::fromArray()`.
     */
    public function test_it_rejects_a_signed_batch_containing_a_non_object_item(): void
    {
        Bus::fake();
        $log = Log::spy();

        $body = self::batchPayload([self::rawEventItem(), 'not-an-object']);
        $headers = self::signedHeaders('POST', 'http://localhost'.self::URI, $body);

        $response = $this->call('POST', self::URI, [], [], [], $headers, $body);

        $response->assertStatus(400);
        Bus::assertNothingDispatched();
        $log->shouldHaveReceived('error', [
            'A HubSpot webhook request failed shape validation.',
            ['error_code' => 'invalid_item', 'item_count' => 2, 'route' => 'hubspot/webhook'],
        ]);
    }

    public function test_a_zero_item_batch_dispatches_nothing_and_returns_no_content(): void
    {
        Bus::fake();

        $body = self::batchPayload([]);
        $headers = self::signedHeaders('POST', 'http://localhost'.self::URI, $body);

        $response = $this->call('POST', self::URI, [], [], [], $headers, $body);

        $response->assertNoContent();
        Bus::assertNothingDispatched();
    }

    public function test_a_multi_item_batch_dispatches_one_job_per_item_and_returns_no_content(): void
    {
        Bus::fake();

        $items = [self::rawEventItem(), self::rawEventItem(), self::rawEventItem()];
        $body = self::batchPayload($items);
        $headers = self::signedHeaders('POST', 'http://localhost'.self::URI, $body);

        $response = $this->call('POST', self::URI, [], [], [], $headers, $body);

        $response->assertNoContent();
        Bus::assertDispatched(ProcessWebhookEventJob::class, 3);
    }

    /**
     * D-14: a batch this package cannot fully hand off to the queue is never acknowledged --
     * HubSpot must see a 500 so it redelivers the whole batch, not a 204 for a job that silently
     * never reached a worker.
     */
    public function test_it_returns_a_server_error_when_a_valid_item_cannot_be_queued(): void
    {
        app()->instance(Dispatcher::class, new class implements Dispatcher
        {
            public function dispatch($command): never
            {
                throw new RuntimeException('the queue connection was refused');
            }

            public function dispatchSync($command, $handler = null): never
            {
                throw new RuntimeException('unused in this fake');
            }

            public function dispatchNow($command, $handler = null): never
            {
                throw new RuntimeException('unused in this fake');
            }

            public function dispatchAfterResponse($command, $handler = null): void {}

            /**
             * @param  Collection<array-key, mixed>|array<array-key, mixed>|null  $jobs
             */
            public function chain($jobs = null): never
            {
                throw new RuntimeException('unused in this fake');
            }

            public function hasCommandHandler($command): bool
            {
                return false;
            }

            public function getCommandHandler($command): null
            {
                return null;
            }

            /**
             * @param  array<int, mixed>  $pipes
             */
            public function pipeThrough(array $pipes): static
            {
                return $this;
            }

            /**
             * @param  array<class-string, class-string>  $map
             */
            public function map(array $map): static
            {
                return $this;
            }
        });

        $body = self::batchPayload([self::rawEventItem()]);
        $headers = self::signedHeaders('POST', 'http://localhost'.self::URI, $body);

        $response = $this->call('POST', self::URI, [], [], [], $headers, $body);

        $response->assertStatus(500);
    }

    /**
     * D-15: the bypass accepts unsigned traffic and warns loudly -- but the warning is
     * payload-free, carrying only the route path, never the body this request sent.
     */
    public function test_the_enforcement_bypass_accepts_unsigned_traffic_and_warns_without_the_payload(): void
    {
        config()->set('hubspot.webhooks.enforce', false);
        Bus::fake();
        $log = Log::spy();

        $body = self::batchPayload([self::rawEventItem()]);

        $response = $this->call('POST', self::URI, [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);

        $response->assertNoContent();
        Bus::assertDispatched(ProcessWebhookEventJob::class, 1);
        $log->shouldHaveReceived('warning', [
            'UNSAFE: HUBSPOT_WEBHOOK_ENFORCE is false, so an unverified HubSpot webhook request '
            .'was accepted without checking its signature. This bypass exists for local '
            .'development only and must never be set in a deployed environment.',
            ['route' => 'hubspot/webhook'],
        ]);
    }

    /**
     * @return array{eventId: int, subscriptionId: int, portalId: int, appId: int, occurredAt: int, subscriptionType: string, attemptNumber: int, objectId: int, changeSource: string, changeFlag: string}
     */
    private static function rawEventItem(): array
    {
        return [
            'eventId' => 1,
            'subscriptionId' => 12345,
            'portalId' => 62515,
            'appId' => 54321,
            'occurredAt' => 1564113600000,
            'subscriptionType' => 'contact.creation',
            'attemptNumber' => 0,
            'objectId' => 123,
            'changeSource' => 'CRM',
            'changeFlag' => 'NEW',
        ];
    }

    private static function rawEventItemAsJsonObject(): string
    {
        return (string) json_encode(self::rawEventItem(), JSON_THROW_ON_ERROR);
    }

    /**
     * @param  list<array<string, mixed>|string>  $items
     */
    private static function batchPayload(array $items): string
    {
        return (string) json_encode($items, JSON_THROW_ON_ERROR);
    }

    /**
     * Mirrors `InboundWebhookTracerTest::signedHeaders()` -- test-only HubSpot v3 signing, never
     * hand-rolled in production code (D-21).
     *
     * @return array<string, string>
     */
    private static function signedHeaders(string $method, string $absoluteUri, string $body, ?int $timestamp = null): array
    {
        $timestamp ??= (int) round(microtime(true) * 1000);

        $signature = base64_encode(hash_hmac(
            'sha256',
            $method.$absoluteUri.$body.$timestamp,
            self::SECRET,
            true,
        ));

        return [
            'HTTP_X_HUBSPOT_SIGNATURE_V3' => $signature,
            'HTTP_X_HUBSPOT_SIGNATURE_VERSION' => 'v3',
            'HTTP_X_HUBSPOT_REQUEST_TIMESTAMP' => (string) $timestamp,
            'CONTENT_TYPE' => 'application/json',
        ];
    }
}
