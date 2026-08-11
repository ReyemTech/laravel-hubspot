<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Webhooks;

use DateTimeImmutable;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use ReyemTech\Hubspot\Tests\TestCase;
use ReyemTech\Hubspot\Webhooks\Contracts\WebhookEventStore;
use ReyemTech\Hubspot\Webhooks\NormalizedWebhookEvent;
use ReyemTech\Hubspot\Webhooks\Stores\DatabaseWebhookEventStore;
use ReyemTech\Hubspot\Webhooks\WebhookEventClaim;

mutates(DatabaseWebhookEventStore::class, NormalizedWebhookEvent::class);

/**
 * **`eventId` alone is not a delivery identity, because HubSpot says so.**
 *
 * From HubSpot's own field definitions (Webhooks v3 API guide, checked 2026-08-11 at
 * https://developers.hubspot.com/docs/api-reference/legacy/webhooks/guide):
 *
 * > **eventId** — "The ID of the event that triggered this notification." **"This value is not
 * > guaranteed to be unique."**
 * >
 * > "HubSpot also does not guarantee that you'll only get a single notification for an event.
 * > Though this should be rare, it is possible that HubSpot will send you the same notification
 * > multiple times."
 *
 * The second sentence is why a delivery identity is needed at all (D-01). The FIRST is why it
 * cannot be `eventId`: two legitimately distinct events that happen to share one collided on the
 * `unique('event_id')` index, so the second was answered as a redelivery and — once the first
 * completed — silently skipped its generic event, typed event and every handler, after the route
 * had already returned 204. That is the data loss the store exists to prevent, reached through the
 * front door.
 *
 * D-01 is revised accordingly: the claim is keyed on a delivery identity of
 * `(portalId, subscriptionId, eventId, objectId, occurredAt)`, hashed into one indexed column in
 * the shape `hubspot_object_links.lookup_hash` already established. A genuine redelivery repeats
 * all five and differs only in `attemptNumber`, so deduplication still holds; two distinct events
 * differ in at least one, so they no longer collide.
 */
final class DeliveryIdentityTest extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('hubspot.webhooks.enabled', true);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations/webhooks');
    }

    private function store(): WebhookEventStore
    {
        /** @var WebhookEventStore $store */
        $store = $this->app?->make(WebhookEventStore::class);

        return $store;
    }

    /**
     * Everything is held constant except the one field named, so each test below isolates a single
     * axis of the delivery identity.
     */
    private static function event(
        string $eventId = 'shared-event-id',
        ?int $subscriptionId = 111,
        int $portalId = 62515,
        string $objectId = 'obj-1',
        string $occurredAt = '2026-08-11T00:00:00+00:00',
    ): NormalizedWebhookEvent {
        return new NormalizedWebhookEvent(
            eventId: $eventId,
            subscriptionType: 'contact.propertyChange',
            portalId: $portalId,
            appId: 54321,
            objectId: $objectId,
            occurredAt: new DateTimeImmutable($occurredAt),
            attemptNumber: 0,
            subscriptionId: $subscriptionId,
        );
    }

    /**
     * The defect itself. Two events sharing an `eventId` but arriving through DIFFERENT
     * subscriptions are different deliveries and must both be processed.
     */
    public function test_two_events_sharing_an_event_id_on_different_subscriptions_both_claim(): void
    {
        $store = $this->store();

        self::assertSame(
            WebhookEventClaim::Acquired,
            $store->claim(self::event(subscriptionId: 111)),
        );

        self::assertSame(
            WebhookEventClaim::Acquired,
            $store->claim(self::event(subscriptionId: 222)),
            'A different subscription is a different delivery, not a redelivery of the first.',
        );
    }

    /** Different portals, same eventId: two accounts, two deliveries. */
    public function test_two_events_sharing_an_event_id_in_different_portals_both_claim(): void
    {
        $store = $this->store();

        self::assertSame(WebhookEventClaim::Acquired, $store->claim(self::event(portalId: 1)));
        self::assertSame(WebhookEventClaim::Acquired, $store->claim(self::event(portalId: 2)));
    }

    /** Different objects, same eventId. */
    public function test_two_events_sharing_an_event_id_for_different_objects_both_claim(): void
    {
        $store = $this->store();

        self::assertSame(WebhookEventClaim::Acquired, $store->claim(self::event(objectId: 'obj-1')));
        self::assertSame(WebhookEventClaim::Acquired, $store->claim(self::event(objectId: 'obj-2')));
    }

    /** Different moments, same eventId. */
    public function test_two_events_sharing_an_event_id_at_different_times_both_claim(): void
    {
        $store = $this->store();

        self::assertSame(
            WebhookEventClaim::Acquired,
            $store->claim(self::event(occurredAt: '2026-08-11T00:00:00+00:00')),
        );

        self::assertSame(
            WebhookEventClaim::Acquired,
            $store->claim(self::event(occurredAt: '2026-08-11T00:00:01+00:00')),
        );
    }

    /**
     * **The half that must NOT regress.** A genuine redelivery repeats every identity field and
     * differs only in `attemptNumber`, which is deliberately not part of the identity -- if it
     * were, every retry would read as a new delivery and HOOK-01 would provide nothing at all.
     */
    public function test_a_genuine_redelivery_is_still_deduplicated(): void
    {
        $store = $this->store();

        $first = self::event();
        self::assertSame(WebhookEventClaim::Acquired, $store->claim($first));

        $store->complete($first);

        $redelivery = new NormalizedWebhookEvent(
            eventId: 'shared-event-id',
            subscriptionType: 'contact.propertyChange',
            portalId: 62515,
            appId: 54321,
            objectId: 'obj-1',
            occurredAt: new DateTimeImmutable('2026-08-11T00:00:00+00:00'),
            attemptNumber: 3,
            subscriptionId: 111,
        );

        self::assertSame(
            WebhookEventClaim::Handled,
            $store->claim($redelivery),
            'A retry of the same delivery must still be a no-op -- attemptNumber is not identity.',
        );
    }

    /**
     * An item carrying no `subscriptionId` still has a stable identity from the remaining four
     * fields, and a missing id is distinguishable from a present one rather than colliding with it.
     */
    public function test_an_absent_subscription_id_still_yields_a_stable_distinct_identity(): void
    {
        $store = $this->store();

        self::assertSame(WebhookEventClaim::Acquired, $store->claim(self::event(subscriptionId: null)));

        // Same delivery again: still deduplicated.
        self::assertSame(WebhookEventClaim::Handled, self::completeThen($store, self::event(subscriptionId: null)));

        // A present id is a DIFFERENT delivery from an absent one.
        self::assertSame(WebhookEventClaim::Acquired, $store->claim(self::event(subscriptionId: 111)));
    }

    private static function completeThen(WebhookEventStore $store, NormalizedWebhookEvent $event): WebhookEventClaim
    {
        $store->complete($event);

        return $store->claim($event);
    }

    /** The rendered identity is stable across processes -- it is a hash of values, never of object state. */
    public function test_the_delivery_identity_is_deterministic(): void
    {
        self::assertSame(
            self::event()->deliveryIdentity(),
            self::event()->deliveryIdentity(),
        );

        self::assertNotSame(
            self::event(subscriptionId: 111)->deliveryIdentity(),
            self::event(subscriptionId: 222)->deliveryIdentity(),
        );
    }

    public function test_the_table_no_longer_makes_event_id_unique_on_its_own(): void
    {
        Artisan::call('migrate', ['--force' => true]);

        $store = $this->store();

        $store->claim(self::event(subscriptionId: 111));
        $store->claim(self::event(subscriptionId: 222));

        $rows = $this->app?->make('db')->connection()
            ->table(DatabaseWebhookEventStore::TABLE)
            ->where('event_id', 'shared-event-id')
            ->count();

        self::assertSame(2, $rows, 'Two distinct deliveries sharing an eventId must both persist.');
    }
}
