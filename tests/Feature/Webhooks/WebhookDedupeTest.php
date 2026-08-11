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
use ReyemTech\Hubspot\Tests\TestCase;
use ReyemTech\Hubspot\Webhooks\Events\HubspotWebhookReceived;
use ReyemTech\Hubspot\Webhooks\NormalizedWebhookEvent;
use ReyemTech\Hubspot\Webhooks\ProcessWebhookEventJob;
use ReyemTech\Hubspot\Webhooks\Stores\DatabaseWebhookEventStore;
use RuntimeException;

mutates(ProcessWebhookEventJob::class);

/**
 * HOOK-01's durable half, proven end to end (05-02-PLAN.md Task 3, D-01/D-03).
 *
 * `Bus::fake()` is deliberately never used in this file: the default `sync` queue connection runs
 * `ProcessWebhookEventJob::handle()` INLINE, so a request that dispatches a job actually executes it
 * -- the redelivery contract is only meaningful end to end, not against a job in isolation.
 */
final class WebhookDedupeTest extends TestCase
{
    private const string SECRET = 'test-client-secret-05-02';

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

    public function test_a_first_delivery_claims_dispatches_and_completes(): void
    {
        Event::fake([HubspotWebhookReceived::class]);

        $item = self::rawEventItem('evt-first');
        $body = self::batchPayload([$item]);
        $headers = self::signedHeaders('POST', 'http://localhost'.self::URI, $body);

        $response = $this->call('POST', self::URI, [], [], [], $headers, $body);

        $response->assertNoContent();
        Event::assertDispatched(HubspotWebhookReceived::class, 1);

        $row = DB::table(DatabaseWebhookEventStore::TABLE)->where('event_id', 'evt-first')->first();
        self::assertNotNull($row);
        self::assertNotNull($row->handled_at);
    }

    public function test_a_redelivery_still_queues_a_job_but_it_emits_nothing_and_marks_nothing(): void
    {
        Event::fake([HubspotWebhookReceived::class]);

        $item = self::rawEventItem('evt-redelivered');
        $body = self::batchPayload([$item]);

        $this->call(
            'POST',
            self::URI,
            [],
            [],
            [],
            self::signedHeaders('POST', 'http://localhost'.self::URI, $body),
            $body,
        )->assertNoContent();

        Event::assertDispatched(HubspotWebhookReceived::class, 1);

        $firstHandledAt = DB::table(DatabaseWebhookEventStore::TABLE)
            ->where('event_id', 'evt-redelivered')
            ->value('handled_at');

        // A redelivery: HubSpot retries the identical eventId with a freshly signed request.
        $this->call(
            'POST',
            self::URI,
            [],
            [],
            [],
            self::signedHeaders('POST', 'http://localhost'.self::URI, $body),
            $body,
        )->assertNoContent();

        Event::assertDispatched(HubspotWebhookReceived::class, 1);

        self::assertSame(
            1,
            DB::table(DatabaseWebhookEventStore::TABLE)->where('event_id', 'evt-redelivered')->count(),
        );
        self::assertSame(
            $firstHandledAt,
            DB::table(DatabaseWebhookEventStore::TABLE)->where('event_id', 'evt-redelivered')->value('handled_at'),
        );
    }

    public function test_an_empty_batch_returns_no_content_dispatches_nothing_and_writes_no_row(): void
    {
        Event::fake([HubspotWebhookReceived::class]);

        $body = self::batchPayload([]);
        $headers = self::signedHeaders('POST', 'http://localhost'.self::URI, $body);

        $this->call('POST', self::URI, [], [], [], $headers, $body)->assertNoContent();

        Event::assertNotDispatched(HubspotWebhookReceived::class);
        self::assertSame(0, DB::table(DatabaseWebhookEventStore::TABLE)->count());
    }

    public function test_two_items_sharing_one_event_id_in_one_delivery_dispatch_the_receipt_event_exactly_once(): void
    {
        Event::fake([HubspotWebhookReceived::class]);

        $item = self::rawEventItem('evt-duplicate-in-batch');
        $body = self::batchPayload([$item, $item]);
        $headers = self::signedHeaders('POST', 'http://localhost'.self::URI, $body);

        $this->call('POST', self::URI, [], [], [], $headers, $body)->assertNoContent();

        Event::assertDispatched(HubspotWebhookReceived::class, 1);
        self::assertSame(
            1,
            DB::table(DatabaseWebhookEventStore::TABLE)->where('event_id', 'evt-duplicate-in-batch')->count(),
        );
    }

    /**
     * D-03: a dispatch that throws must escape `handle()` so Laravel fails and retries the job, and
     * the row it claimed stays claimed rather than completed.
     */
    public function test_a_dispatch_failure_leaves_the_record_claimed_and_fails_the_job(): void
    {
        $jobFailed = false;

        Event::listen(HubspotWebhookReceived::class, function (): void {
            throw new RuntimeException('a configured handler exploded');
        });
        Event::listen(JobFailed::class, function () use (&$jobFailed): void {
            $jobFailed = true;
        });

        $item = self::rawEventItem('evt-throws');
        $body = self::batchPayload([$item]);
        $headers = self::signedHeaders('POST', 'http://localhost'.self::URI, $body);

        $response = $this->call('POST', self::URI, [], [], [], $headers, $body);

        $response->assertStatus(500);
        self::assertTrue($jobFailed, 'The queued job was never reported as failed.');

        $row = DB::table(DatabaseWebhookEventStore::TABLE)->where('event_id', 'evt-throws')->first();
        self::assertNotNull($row);
        self::assertNull($row->handled_at);
    }

    /**
     * A worker that died holding a claim leaves it re-claimable once the lease elapses (D-01, D-03)
     * -- proven by writing `claimed_at` directly through the connection rather than sleeping.
     */
    public function test_a_stale_claim_is_reclaimed_and_reprocessed_on_retry(): void
    {
        Event::fake([HubspotWebhookReceived::class]);

        // Derived from the very item this test goes on to deliver, rather than hand-written: the
        // claim is keyed on NormalizedWebhookEvent::deliveryIdentity(), so a fixture whose fields
        // drifted from the delivery would be a different delivery and the reclaim under test would
        // never happen.
        $dead = NormalizedWebhookEvent::fromArray(self::rawEventItem('evt-stale'));

        DB::table(DatabaseWebhookEventStore::TABLE)->insert([
            'delivery_hash' => $dead->deliveryIdentity(),
            'event_id' => $dead->eventId,
            'subscription_id' => $dead->subscriptionId,
            'subscription_type' => $dead->subscriptionType,
            'portal_id' => $dead->portalId,
            'object_id' => $dead->objectId,
            'occurred_at' => $dead->occurredAt,
            'attempts' => 1,
            'claimed_at' => now()->subSeconds(901),
            'handled_at' => null,
            'payload' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $item = self::rawEventItem('evt-stale');
        $body = self::batchPayload([$item]);
        $headers = self::signedHeaders('POST', 'http://localhost'.self::URI, $body);

        $this->call('POST', self::URI, [], [], [], $headers, $body)->assertNoContent();

        Event::assertDispatched(HubspotWebhookReceived::class, 1);

        $row = DB::table(DatabaseWebhookEventStore::TABLE)->where('event_id', 'evt-stale')->first();
        self::assertNotNull($row);
        self::assertNotNull($row->handled_at);
        self::assertSame(2, $row->attempts);
    }

    /**
     * @return array{eventId: string, subscriptionId: int, portalId: int, appId: int, occurredAt: int, subscriptionType: string, attemptNumber: int, objectId: int, changeSource: string, changeFlag: string}
     */
    private static function rawEventItem(string $eventId): array
    {
        return [
            'eventId' => $eventId,
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
