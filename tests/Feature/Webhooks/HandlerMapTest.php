<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Webhooks;

use Illuminate\Foundation\Application;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Tests\Support\Webhooks\DependentWebhookHandler;
use ReyemTech\Hubspot\Tests\Support\Webhooks\NotAWebhookHandler;
use ReyemTech\Hubspot\Tests\Support\Webhooks\RecordingWebhookHandlerA;
use ReyemTech\Hubspot\Tests\Support\Webhooks\RecordingWebhookHandlerB;
use ReyemTech\Hubspot\Tests\Support\Webhooks\ThrowingWebhookHandler;
use ReyemTech\Hubspot\Tests\Support\Webhooks\WebhookHandlerCallLog;
use ReyemTech\Hubspot\Tests\TestCase;
use ReyemTech\Hubspot\Webhooks\Events\HubspotWebhookReceived;
use ReyemTech\Hubspot\Webhooks\HandlerMap;
use ReyemTech\Hubspot\Webhooks\Stores\DatabaseWebhookEventStore;

mutates(HandlerMap::class, ConfigurationException::class);

/**
 * D-07, D-08: `webhooks.handlers` (including `'*'`) is validated whole, once, before any claim is
 * taken, and a matching item's key handlers run before its `'*'` handlers, each class exactly once
 * (05-03-PLAN.md Task 2).
 */
final class HandlerMapTest extends TestCase
{
    private const string SECRET = 'test-client-secret-05-03-handlers';

    private const string URI = '/hubspot/webhook';

    private const string EVENT_KEY = 'contact.propertyChange';

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

        WebhookHandlerCallLog::reset();
    }

    public function test_a_handler_registered_under_both_the_event_key_and_the_wildcard_runs_exactly_once(): void
    {
        config()->set('hubspot.webhooks.handlers', [
            self::EVENT_KEY => [RecordingWebhookHandlerA::class],
            '*' => [RecordingWebhookHandlerA::class],
        ]);

        $this->deliver([self::rawEventItem('evt-dedupe')]);

        self::assertCount(1, WebhookHandlerCallLog::$calls);
        self::assertSame(RecordingWebhookHandlerA::class, WebhookHandlerCallLog::$calls[0]['handler']);
    }

    public function test_key_handlers_run_before_wildcard_handlers(): void
    {
        config()->set('hubspot.webhooks.handlers', [
            '*' => [RecordingWebhookHandlerB::class],
            self::EVENT_KEY => [RecordingWebhookHandlerA::class],
        ]);

        $this->deliver([self::rawEventItem('evt-order')]);

        self::assertSame(
            [RecordingWebhookHandlerA::class, RecordingWebhookHandlerB::class],
            array_column(WebhookHandlerCallLog::$calls, 'handler'),
        );
    }

    public function test_a_bare_string_and_a_list_are_both_accepted_for_one_key(): void
    {
        config()->set('hubspot.webhooks.handlers', [
            self::EVENT_KEY => RecordingWebhookHandlerA::class,
        ]);

        $this->deliver([self::rawEventItem('evt-bare-string')]);

        self::assertSame([RecordingWebhookHandlerA::class], array_column(WebhookHandlerCallLog::$calls, 'handler'));
    }

    public function test_wildcard_handlers_run_for_an_item_with_no_key_specific_handlers_and_no_recognized_type(): void
    {
        config()->set('hubspot.webhooks.handlers', [
            '*' => [RecordingWebhookHandlerA::class],
        ]);

        $this->deliver([self::rawEventItem('evt-wildcard-only', 'company.merge')]);

        self::assertSame([RecordingWebhookHandlerA::class], array_column(WebhookHandlerCallLog::$calls, 'handler'));
    }

    public function test_a_handler_naming_a_class_that_does_not_exist_fails_before_any_event_or_claim(): void
    {
        Event::fake([HubspotWebhookReceived::class]);

        config()->set('hubspot.webhooks.handlers', [
            self::EVENT_KEY => ['ReyemTech\\Hubspot\\Tests\\Support\\Webhooks\\ThisClassDoesNotExist'],
        ]);

        $response = $this->deliverRaw([self::rawEventItem('evt-missing-class')]);

        $response->assertStatus(500);
        Event::assertNotDispatched(HubspotWebhookReceived::class);
        self::assertSame(0, DB::table(DatabaseWebhookEventStore::TABLE)->count());
    }

    public function test_a_handler_not_implementing_the_interface_fails_before_any_event_or_claim(): void
    {
        Event::fake([HubspotWebhookReceived::class]);

        config()->set('hubspot.webhooks.handlers', [
            self::EVENT_KEY => [NotAWebhookHandler::class],
        ]);

        $response = $this->deliverRaw([self::rawEventItem('evt-wrong-interface')]);

        $response->assertStatus(500);
        Event::assertNotDispatched(HubspotWebhookReceived::class);
        self::assertSame(0, DB::table(DatabaseWebhookEventStore::TABLE)->count());
    }

    public function test_a_handler_with_a_container_resolvable_dependency_is_invoked_successfully(): void
    {
        config()->set('hubspot.webhooks.handlers', [
            self::EVENT_KEY => [DependentWebhookHandler::class],
        ]);

        $this->deliver([self::rawEventItem('evt-dependency')]);

        self::assertSame([DependentWebhookHandler::class], array_column(WebhookHandlerCallLog::$calls, 'handler'));
    }

    public function test_a_throwing_handler_leaves_the_event_records_completion_timestamp_null(): void
    {
        $jobFailed = false;
        Event::listen(JobFailed::class, function () use (&$jobFailed): void {
            $jobFailed = true;
        });

        config()->set('hubspot.webhooks.handlers', [
            self::EVENT_KEY => [ThrowingWebhookHandler::class],
        ]);

        $response = $this->deliverRaw([self::rawEventItem('evt-handler-throws')]);

        $response->assertStatus(500);
        self::assertTrue($jobFailed, 'The queued job was never reported as failed.');

        $row = DB::table(DatabaseWebhookEventStore::TABLE)->where('event_id', 'evt-handler-throws')->first();
        self::assertNotNull($row);
        self::assertNull($row->handled_at);
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
     */
    private function deliverRaw(array $items): TestResponse
    {
        $body = self::batchPayload($items);
        $headers = self::signedHeaders('POST', 'http://localhost'.self::URI, $body);

        return $this->call('POST', self::URI, [], [], [], $headers, $body);
    }

    /**
     * @return array<string, mixed>
     */
    private static function rawEventItem(string $eventId, string $subscriptionType = self::EVENT_KEY): array
    {
        return [
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
        ];
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
            'HTTP_X_HUBSPOT_SIGNATURE' => $signature,
            'HTTP_X_HUBSPOT_SIGNATURE_VERSION' => 'v3',
            'HTTP_X_HUBSPOT_REQUEST_TIMESTAMP' => (string) $timestamp,
            'CONTENT_TYPE' => 'application/json',
        ];
    }
}
