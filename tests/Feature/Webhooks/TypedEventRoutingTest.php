<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Webhooks;

use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use ReyemTech\Hubspot\Tests\TestCase;
use ReyemTech\Hubspot\Webhooks\Events\ContactPropertyChanged;
use ReyemTech\Hubspot\Webhooks\Events\HubspotWebhookReceived;
use ReyemTech\Hubspot\Webhooks\Events\ObjectAssociationChanged;
use ReyemTech\Hubspot\Webhooks\Events\ObjectLifecycleChanged;
use ReyemTech\Hubspot\Webhooks\Events\ObjectPropertyChanged;
use ReyemTech\Hubspot\Webhooks\Events\WebhookLifecycleTransition;
use ReyemTech\Hubspot\Webhooks\ProcessWebhookEventJob;
use ReyemTech\Hubspot\Webhooks\TypedEventMap;

mutates(
    TypedEventMap::class,
    ObjectPropertyChanged::class,
    ContactPropertyChanged::class,
    ObjectLifecycleChanged::class,
    ObjectAssociationChanged::class,
    ProcessWebhookEventJob::class,
);

/**
 * D-06, D-09: every recognized item reaches its typed event AFTER the generic one, an unrecognized
 * item reaches only the generic one, and the recognition table cannot be steered by the payload
 * (05-03-PLAN.md Task 1).
 */
final class TypedEventRoutingTest extends TestCase
{
    private const string SECRET = 'test-client-secret-05-03-typed';

    private const string URI = '/hubspot/webhook';

    /**
     * @var list<class-string>
     */
    private const array ALL_TYPED_EVENTS = [
        HubspotWebhookReceived::class,
        ContactPropertyChanged::class,
        ObjectPropertyChanged::class,
        ObjectLifecycleChanged::class,
        ObjectAssociationChanged::class,
    ];

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

    public function test_a_contact_property_change_dispatches_the_generic_event_then_the_contact_specific_typed_event(): void
    {
        Event::fake(self::ALL_TYPED_EVENTS);

        $item = self::rawEventItem('evt-contact-prop', 'contact.propertyChange', [
            'propertyName' => 'email',
            'propertyValue' => 'new@example.test',
        ]);
        $this->deliver([$item]);

        Event::assertDispatchedTimes(HubspotWebhookReceived::class, 1);
        Event::assertDispatchedTimes(ContactPropertyChanged::class, 1);
        Event::assertNotDispatched(ObjectPropertyChanged::class);
        Event::assertNotDispatched(ObjectLifecycleChanged::class);
        Event::assertNotDispatched(ObjectAssociationChanged::class);

        Event::assertDispatched(ContactPropertyChanged::class, function (ContactPropertyChanged $typed): bool {
            self::assertSame('email', $typed->propertyName);
            self::assertSame('new@example.test', $typed->propertyValue);

            return true;
        });
    }

    public function test_a_deal_property_change_dispatches_the_generic_event_then_the_family_typed_event(): void
    {
        Event::fake(self::ALL_TYPED_EVENTS);

        $item = self::rawEventItem('evt-deal-prop', 'deal.propertyChange', [
            'propertyName' => 'amount',
            'propertyValue' => '1000',
        ]);
        $this->deliver([$item]);

        Event::assertDispatchedTimes(HubspotWebhookReceived::class, 1);
        Event::assertDispatchedTimes(ObjectPropertyChanged::class, 1);
        Event::assertNotDispatched(ContactPropertyChanged::class);

        Event::assertDispatched(ObjectPropertyChanged::class, function (ObjectPropertyChanged $typed): bool {
            self::assertSame('amount', $typed->propertyName);
            self::assertSame('1000', $typed->propertyValue);

            return true;
        });
    }

    public function test_a_company_creation_dispatches_object_lifecycle_changed_carrying_the_creation_transition(): void
    {
        Event::fake(self::ALL_TYPED_EVENTS);

        $item = self::rawEventItem('evt-company-create', 'company.creation');
        $this->deliver([$item]);

        Event::assertDispatchedTimes(HubspotWebhookReceived::class, 1);
        Event::assertDispatchedTimes(ObjectLifecycleChanged::class, 1);

        Event::assertDispatched(ObjectLifecycleChanged::class, function (ObjectLifecycleChanged $typed): bool {
            self::assertSame(WebhookLifecycleTransition::Creation, $typed->transition);

            return true;
        });
    }

    public function test_a_company_deletion_dispatches_object_lifecycle_changed_carrying_the_deletion_transition(): void
    {
        Event::fake(self::ALL_TYPED_EVENTS);

        $item = self::rawEventItem('evt-company-delete', 'company.deletion');
        $this->deliver([$item]);

        Event::assertDispatchedTimes(HubspotWebhookReceived::class, 1);
        Event::assertDispatchedTimes(ObjectLifecycleChanged::class, 1);

        Event::assertDispatched(ObjectLifecycleChanged::class, function (ObjectLifecycleChanged $typed): bool {
            self::assertSame(WebhookLifecycleTransition::Deletion, $typed->transition);

            return true;
        });
    }

    public function test_a_contact_association_change_dispatches_object_association_changed(): void
    {
        Event::fake(self::ALL_TYPED_EVENTS);

        $item = self::rawEventItem('evt-assoc', 'contact.associationChange', [
            'associationType' => 'contact_to_company',
            'fromObjectId' => 123,
            'toObjectId' => 456,
        ]);
        $this->deliver([$item]);

        Event::assertDispatchedTimes(HubspotWebhookReceived::class, 1);
        Event::assertDispatchedTimes(ObjectAssociationChanged::class, 1);

        Event::assertDispatched(ObjectAssociationChanged::class, function (ObjectAssociationChanged $typed): bool {
            self::assertSame('contact_to_company', $typed->associationType);
            self::assertSame('456', $typed->associatedObjectId);

            return true;
        });
    }

    public function test_an_unrecognized_subscription_type_dispatches_only_the_generic_event(): void
    {
        Event::fake(self::ALL_TYPED_EVENTS);

        $item = self::rawEventItem('evt-unknown', 'company.merge');
        $this->deliver([$item]);

        Event::assertDispatchedTimes(HubspotWebhookReceived::class, 1);
        Event::assertNotDispatched(ContactPropertyChanged::class);
        Event::assertNotDispatched(ObjectPropertyChanged::class);
        Event::assertNotDispatched(ObjectLifecycleChanged::class);
        Event::assertNotDispatched(ObjectAssociationChanged::class);
    }

    public function test_the_typed_event_carries_the_identical_normalized_event_instance_as_the_generic_one(): void
    {
        Event::fake(self::ALL_TYPED_EVENTS);

        $item = self::rawEventItem('evt-identity', 'contact.propertyChange', [
            'propertyName' => 'email',
            'propertyValue' => 'identity@example.test',
        ]);
        $this->deliver([$item]);

        /** @var HubspotWebhookReceived $generic */
        $generic = Event::dispatched(HubspotWebhookReceived::class)->sole()[0];
        /** @var ContactPropertyChanged $typed */
        $typed = Event::dispatched(ContactPropertyChanged::class)->sole()[0];

        self::assertSame($generic->event, $typed->event);
    }

    public function test_a_three_item_batch_dispatches_jobs_carrying_the_payloads_own_event_id_order(): void
    {
        Bus::fake();

        $items = [
            self::rawEventItem('evt-order-1', 'company.creation'),
            self::rawEventItem('evt-order-2', 'contact.propertyChange', ['propertyName' => 'email', 'propertyValue' => 'x@example.test']),
            self::rawEventItem('evt-order-3', 'deal.propertyChange', ['propertyName' => 'amount', 'propertyValue' => '1']),
        ];
        $this->deliver($items);

        $dispatched = Bus::dispatched(ProcessWebhookEventJob::class);

        self::assertCount(3, $dispatched);
        self::assertSame(
            ['evt-order-1', 'evt-order-2', 'evt-order-3'],
            $dispatched->map(static fn (ProcessWebhookEventJob $job): string => $job->event->eventId)->all(),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function deliver(array $items): void
    {
        $body = self::batchPayload($items);
        $headers = self::signedHeaders('POST', 'http://localhost'.self::URI, $body);

        $this->call('POST', self::URI, [], [], [], $headers, $body)->assertNoContent();
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
            'HTTP_X_HUBSPOT_SIGNATURE' => $signature,
            'HTTP_X_HUBSPOT_SIGNATURE_VERSION' => 'v3',
            'HTTP_X_HUBSPOT_REQUEST_TIMESTAMP' => (string) $timestamp,
            'CONTENT_TYPE' => 'application/json',
        ];
    }
}
