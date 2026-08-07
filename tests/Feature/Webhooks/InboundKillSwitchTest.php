<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Webhooks;

use DateTimeImmutable;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Event;
use ReyemTech\Hubspot\Tests\TestCase;
use ReyemTech\Hubspot\Webhooks\Contracts\WebhookEventStore;
use ReyemTech\Hubspot\Webhooks\Events\HubspotWebhookReceived;
use ReyemTech\Hubspot\Webhooks\NormalizedWebhookEvent;
use ReyemTech\Hubspot\Webhooks\ProcessWebhookEventJob;
use ReyemTech\Hubspot\Webhooks\Stores\DatabaseWebhookEventStore;

/**
 * `HUBSPOT_DISABLED` is a hard kill switch, and `config/hubspot.php` writes its contract as covering
 * BOTH directions: *"The inbound half is not implemented yet. No webhook path exists before Phase 5;
 * this key is written to govern both from the start rather than being widened later, so treat the
 * sentence above as the contract and not as a description of what ships today."*
 *
 * Phase 5 is that later. `Sync\SyncGate` already honours the switch on the worker for the outbound
 * half; these pin the inbound half to the same promise — checked ON THE WORKER, so items already
 * queued when the switch is thrown stop as workers drain them.
 *
 * The switch returns BEFORE the durable claim rather than claiming and discarding: a disabled
 * package must leave no trace, so a redelivery after the switch is turned back off is still
 * processed normally rather than being silently deduped against a claim nothing ever handled.
 */
final class InboundKillSwitchTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('hubspot.webhooks.enabled', true);
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

final class KillSwitchSpyHandler implements \ReyemTech\Hubspot\Webhooks\Contracts\WebhookHandler
{
    public static int $calls = 0;

    public function handle(NormalizedWebhookEvent $event): void
    {
        self::$calls++;
    }
}
