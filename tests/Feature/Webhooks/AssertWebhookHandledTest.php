<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Webhooks;

use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use ReyemTech\Hubspot\Facades\Hubspot;
use ReyemTech\Hubspot\Tests\Support\FailedAssertion;
use ReyemTech\Hubspot\Tests\Support\Webhooks\ThrowingWebhookHandler;
use ReyemTech\Hubspot\Tests\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 2's deferred `assertWebhookHandled` (`.planning/phases/02-gateway-layer/deferred-items.md`
 * §02-06), delivered against a real INBOUND receipt log rather than the outbound Guzzle history
 * (05-03-PLAN.md Task 3).
 */
final class AssertWebhookHandledTest extends TestCase
{
    private const string SECRET = 'test-client-secret-05-03-receipts';

    private const string URI = '/hubspot/webhook';

    /**
     * @param  Router  $router
     */
    protected function defineRoutes($router): void
    {
        // @phpstan-ignore staticMethod.notFound
        Route::hubspotWebhook(ltrim(self::URI, '/'));
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('hubspot.webhooks.secret', self::SECRET);
        $app['config']->set('hubspot.webhooks.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_it_passes_after_a_signed_delivery_is_received_and_its_jobs_run(): void
    {
        Hubspot::fake();

        $this->deliver([self::rawEventItem('evt-handled', 'contact.propertyChange')]);

        Hubspot::assertWebhookHandled('contact.propertyChange');
    }

    public function test_it_passes_when_one_receipt_carries_every_expected_field(): void
    {
        Hubspot::fake();

        $this->deliver([self::rawEventItem('evt-subset', 'contact.propertyChange', [
            'propertyName' => 'email',
            'propertyValue' => 'someone@example.test',
        ])]);

        Hubspot::assertWebhookHandled('contact.propertyChange', [
            'eventId' => 'evt-subset',
            'propertyName' => 'email',
            'propertyValue' => 'someone@example.test',
        ]);
    }

    public function test_a_failing_assertion_names_the_event_keys_and_ids_that_were_actually_handled(): void
    {
        Hubspot::fake();

        $this->deliver([self::rawEventItem('evt-1', 'contact.propertyChange')]);

        $message = FailedAssertion::messageOf(static fn () => Hubspot::assertWebhookHandled('deal.propertyChange'));

        self::assertStringContainsString('contact.propertyChange', $message);
        self::assertStringContainsString('evt-1', $message);
    }

    public function test_an_expected_field_subset_spread_across_two_receipts_fails(): void
    {
        Hubspot::fake();

        $this->deliver([
            self::rawEventItem('evt-a', 'contact.propertyChange', ['propertyName' => 'email']),
            self::rawEventItem('evt-b', 'contact.propertyChange', ['propertyName' => 'firstname']),
        ]);

        // Neither receipt alone carries BOTH this eventId and that propertyName --
        // FailedAssertion::messageOf() itself fails this test if the assertion unexpectedly passed.
        $message = FailedAssertion::messageOf(static fn () => Hubspot::assertWebhookHandled('contact.propertyChange', [
            'eventId' => 'evt-a',
            'propertyName' => 'firstname',
        ]));

        self::assertStringContainsString('no single receipt did', $message);
    }

    public function test_a_delivery_whose_handler_throws_leaves_the_assertion_failing_for_that_key(): void
    {
        Hubspot::fake();

        config()->set('hubspot.webhooks.handlers', [
            'contact.propertyChange' => [ThrowingWebhookHandler::class],
        ]);

        $response = $this->deliverRaw([self::rawEventItem('evt-throws', 'contact.propertyChange')]);
        $response->assertStatus(500);

        $message = FailedAssertion::messageOf(static fn () => Hubspot::assertWebhookHandled('contact.propertyChange'));

        self::assertStringContainsString('No webhook was handled at all.', $message);
    }

    public function test_nothing_is_recorded_when_no_fake_is_installed(): void
    {
        $this->deliver([self::rawEventItem('evt-no-fake', 'contact.propertyChange')]);

        Hubspot::fake();

        $message = FailedAssertion::messageOf(static fn () => Hubspot::assertWebhookHandled('contact.propertyChange'));

        self::assertStringContainsString('No webhook was handled at all.', $message);
    }

    public function test_flush_state_clears_the_receipt_log_along_with_the_fake(): void
    {
        Hubspot::fake();

        $this->deliver([self::rawEventItem('evt-flush', 'contact.propertyChange')]);

        Hubspot::assertWebhookHandled('contact.propertyChange');

        Hubspot::flushState();
        Hubspot::fake();

        $message = FailedAssertion::messageOf(static fn () => Hubspot::assertWebhookHandled('contact.propertyChange'));

        self::assertStringContainsString('No webhook was handled at all.', $message);
    }

    public function test_an_outbound_write_never_satisfies_assert_webhook_handled_even_when_named_identically(): void
    {
        Hubspot::fake();

        Hubspot::objects()->create('contact.propertyChange', ['foo' => 'bar']);

        Hubspot::assertSynced('contact.propertyChange');

        $message = FailedAssertion::messageOf(static fn () => Hubspot::assertWebhookHandled('contact.propertyChange'));

        self::assertStringContainsString('No webhook was handled at all.', $message);
    }

    public function test_an_inbound_receipt_never_satisfies_assert_request_count(): void
    {
        Hubspot::fake();

        $this->deliver([self::rawEventItem('evt-count', 'contact.propertyChange')]);

        Hubspot::assertWebhookHandled('contact.propertyChange');
        Hubspot::assertRequestCount(0);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function deliver(array $items): void
    {
        $this->deliverRaw($items)->assertNoContent();
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return TestResponse<Response>
     */
    private function deliverRaw(array $items): TestResponse
    {
        $body = self::batchPayload($items);
        $headers = self::signedHeaders('POST', 'http://localhost'.self::URI, $body);

        return $this->call('POST', self::URI, [], [], [], $headers, $body);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private static function rawEventItem(string $eventId, string $subscriptionType, array $extra = []): array
    {
        return array_merge([
            'eventId' => $eventId,
            'subscriptionId' => 12345,
            'portalId' => 62515,
            'appId' => 54321,
            'occurredAt' => 1564113600000,
            'subscriptionType' => $subscriptionType,
            'attemptNumber' => 0,
            'objectId' => 123,
            'changeSource' => 'CRM',
            'changeFlag' => 'NEW',
            'propertyName' => 'email',
            'propertyValue' => 'someone@example.test',
        ], $extra);
    }

    /**
     * @param  list<array<string, mixed>>  $items
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
    private static function signedHeaders(string $method, string $absoluteUri, string $body): array
    {
        $timestamp = (int) round(microtime(true) * 1000);

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
