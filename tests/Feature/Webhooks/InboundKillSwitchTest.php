<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Webhooks;

use DateTimeImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use ReyemTech\Hubspot\Tests\TestCase;
use ReyemTech\Hubspot\Webhooks\Contracts\WebhookEventStore;
use ReyemTech\Hubspot\Webhooks\Contracts\WebhookHandler;
use ReyemTech\Hubspot\Webhooks\Events\HubspotWebhookReceived;
use ReyemTech\Hubspot\Webhooks\NormalizedWebhookEvent;
use ReyemTech\Hubspot\Webhooks\ProcessWebhookEventJob;
use ReyemTech\Hubspot\Webhooks\Stores\DatabaseWebhookEventStore;
use ReyemTech\Hubspot\Webhooks\WebhookController;

mutates(WebhookController::class);

/**
 * `HUBSPOT_DISABLED` is a hard kill switch, and `config/hubspot.php` writes its contract as covering
 * BOTH directions and as being *"checked at DISPATCH and again on the WORKER"*.
 *
 * `Sync\SyncGate` honours both points for the outbound half. These pin the inbound half to the same
 * promise:
 *
 * - **At dispatch** — a signed delivery arriving while the switch is on is refused with a 500 and
 *   nothing is queued. Added 2026-08-09 from a Codex P2: the worker check alone let a disabled
 *   deployment decode and enqueue every delivery throughout an incident, discarding it only once a
 *   worker drained it. 500 rather than 204 for the reason `DisabledReceiptTest` states for the
 *   adjacent `webhooks.enabled` flag — HubSpot treats any 2xx as delivered and never re-sends, so
 *   acknowledging destroys the event, while a 5xx is retried and an operator who ends the incident
 *   receives the backlog. Two adjacent switches, one rule: refuse what cannot be processed.
 * - **On the worker** — items already queued when the switch was thrown stop as workers drain them.
 *   The dispatch check cannot reach those, which is exactly why both exist.
 *
 * The worker check returns BEFORE the durable claim rather than claiming and discarding: a disabled
 * package must leave no trace, so a redelivery after the switch is turned back off is still
 * processed normally rather than being silently deduped against a claim nothing ever handled.
 */
final class InboundKillSwitchTest extends TestCase
{
    private const string SECRET = 'a-client-secret';

    private const string PATH = 'hubspot/webhook';

    protected function defineEnvironment($app): void
    {
        $app['config']->set('hubspot.webhooks.enabled', true);
        $app['config']->set('hubspot.webhooks.secret', self::SECRET);
    }

    protected function defineRoutes($router): void
    {
        // @phpstan-ignore staticMethod.notFound
        Route::hubspotWebhook(self::PATH);
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    private function signed(): array
    {
        $body = json_encode([[
            'eventId' => 1,
            'subscriptionType' => 'contact.propertyChange',
            'portalId' => 1,
            'objectId' => 1,
            'occurredAt' => 1_700_000_000_000,
            'attemptNumber' => 0,
        ]], JSON_THROW_ON_ERROR);

        $timestamp = (int) round(microtime(true) * 1000);
        $uri = 'http://localhost/'.self::PATH;

        $signature = base64_encode(hash_hmac(
            'sha256', 'POST'.$uri.$body.$timestamp, self::SECRET, true,
        ));

        return [$body, [
            'HTTP_X_HUBSPOT_SIGNATURE_V3' => $signature,
            'HTTP_X_HUBSPOT_SIGNATURE_VERSION' => 'v3',
            'HTTP_X_HUBSPOT_REQUEST_TIMESTAMP' => (string) $timestamp,
            'CONTENT_TYPE' => 'application/json',
        ]];
    }

    public function test_a_disabled_package_refuses_a_signed_delivery_and_queues_nothing(): void
    {
        config(['hubspot.disabled' => true]);
        Bus::fake();

        [$body, $headers] = $this->signed();

        $response = $this->call('POST', '/'.self::PATH, [], [], [], $headers, $body);

        // NOT 204: a 2xx tells HubSpot the event is handled and it will never be sent again.
        self::assertSame(500, $response->getStatusCode());
        Bus::assertNotDispatched(ProcessWebhookEventJob::class);
    }

    /**
     * The guard is the switch, not the route. Without this the 500 above would pass just as well
     * against a controller that refused everything.
     */
    public function test_the_same_delivery_is_accepted_when_the_switch_is_off(): void
    {
        config(['hubspot.disabled' => false]);
        Bus::fake();

        [$body, $headers] = $this->signed();

        $response = $this->call('POST', '/'.self::PATH, [], [], [], $headers, $body);

        self::assertSame(204, $response->getStatusCode());
        Bus::assertDispatched(ProcessWebhookEventJob::class);
    }

    /** Switch state must not be observable to an unauthenticated caller, as with every other guard. */
    public function test_an_unsigned_request_still_gets_401_rather_than_the_kill_switch_refusal(): void
    {
        config(['hubspot.disabled' => true]);
        Bus::fake();

        [$body] = $this->signed();

        $response = $this->call('POST', '/'.self::PATH, [], [], [], [
            'HTTP_X_HUBSPOT_SIGNATURE_V3' => base64_encode('wrong'),
            'HTTP_X_HUBSPOT_SIGNATURE_VERSION' => 'v3',
            'HTTP_X_HUBSPOT_REQUEST_TIMESTAMP' => (string) ((int) round(microtime(true) * 1000)),
            'CONTENT_TYPE' => 'application/json',
        ], $body);

        self::assertSame(401, $response->getStatusCode());
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations/webhooks');
    }

    private function event(string $id = 'kill-switch-1'): NormalizedWebhookEvent
    {
        return new NormalizedWebhookEvent(
            eventId: $id,
            subscriptionType: 'contact.propertyChange',
            portalId: 1,
            appId: null,
            objectId: '1',
            occurredAt: new DateTimeImmutable('2026-08-07T00:00:00+00:00'),
            attemptNumber: 0,
        );
    }

    public function test_a_disabled_package_dispatches_no_event_from_the_worker(): void
    {
        config(['hubspot.disabled' => true]);
        Event::fake([HubspotWebhookReceived::class]);

        app()->call([new ProcessWebhookEventJob($this->event()), 'handle']);

        Event::assertNotDispatched(HubspotWebhookReceived::class);
    }

    public function test_a_disabled_package_runs_no_configured_handler(): void
    {
        config([
            'hubspot.disabled' => true,
            'hubspot.webhooks.handlers' => ['*' => [KillSwitchSpyHandler::class]],
        ]);

        KillSwitchSpyHandler::$calls = 0;

        app()->call([new ProcessWebhookEventJob($this->event('kill-switch-2')), 'handle']);

        self::assertSame(0, KillSwitchSpyHandler::$calls);
    }

    public function test_a_disabled_package_takes_no_claim_so_the_event_survives_re_enabling(): void
    {
        config(['hubspot.disabled' => true]);

        app()->call([new ProcessWebhookEventJob($this->event('kill-switch-3')), 'handle']);

        $store = app(WebhookEventStore::class);
        self::assertInstanceOf(DatabaseWebhookEventStore::class, $store);

        // No row: the switch returned before the claim, so nothing was consumed.
        self::assertSame(0, app('db')->connection()->table(DatabaseWebhookEventStore::TABLE)->count());

        // Turned back off, the same delivery is processed normally rather than deduped away.
        config(['hubspot.disabled' => false]);
        Event::fake([HubspotWebhookReceived::class]);

        app()->call([new ProcessWebhookEventJob($this->event('kill-switch-3')), 'handle']);

        Event::assertDispatched(HubspotWebhookReceived::class);
    }

    public function test_the_switch_stays_off_by_default(): void
    {
        self::assertFalse(config('hubspot.disabled'));

        Event::fake([HubspotWebhookReceived::class]);

        app()->call([new ProcessWebhookEventJob($this->event('kill-switch-4')), 'handle']);

        Event::assertDispatched(HubspotWebhookReceived::class);
    }
}

final class KillSwitchSpyHandler implements WebhookHandler
{
    public static int $calls = 0;

    public function handle(NormalizedWebhookEvent $event): void
    {
        self::$calls++;
    }
}
