<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Sync;

use Illuminate\Support\Facades\Schema;
use ReyemTech\Hubspot\Facades\Hubspot;
use ReyemTech\Hubspot\Sync\HubspotObjectLink;
use ReyemTech\Hubspot\Sync\HubspotObserver;
use ReyemTech\Hubspot\Sync\ModelBindings;
use ReyemTech\Hubspot\Sync\PropertyMapper;
use ReyemTech\Hubspot\Sync\SyncHubspotObjectJob;
use ReyemTech\Hubspot\Sync\SyncsToHubspot;
use ReyemTech\Hubspot\Tests\Support\Sync\SyncedLead;
use ReyemTech\Hubspot\Tests\Support\Sync\SyncTestCase;

/**
 * # The tracer: one bound model, one `created` event, one HubSpot request, one link row.
 *
 * This is the thin end-to-end slice every later Model Sync plan expands -- production quality,
 * never a throwaway. `SyncedLead` binds to `contacts` with `id_property: email`, and every
 * assertion below is about the wiring surviving the trip through every layer this phase touches:
 * the trait's relation, `ModelBindings`' config read, `PropertyMapper`'s literal resolution,
 * `HubspotObserver`'s per-call binding lookup, `SyncHubspotObjectJob`'s dispatch and upsert, and
 * the `hubspot_object_links` row it leaves behind.
 *
 * A canned response is used deliberately rather than `DefaultResponses`' own default: the default
 * upsert echoes the submitted id back, which would make the "the row carries the id HubSpot
 * returned" assertion vacuous. `queue.default` is forced to `sync` in {@see SyncTestCase} so the
 * whole path runs in one process -- the queued-by-default contract itself is 04-05's assertion
 * under `Bus::fake()`, not this one.
 */
mutates(
    SyncsToHubspot::class,
    HubspotObjectLink::class,
    ModelBindings::class,
    PropertyMapper::class,
    HubspotObserver::class,
    SyncHubspotObjectJob::class,
);

final class TracerSyncTest extends SyncTestCase
{
    /**
     * @return array{status: string, results: list<array{id: string, properties: array<string, string>}>}
     */
    private static function cannedUpsertBody(): array
    {
        return [
            'status' => 'COMPLETE',
            'results' => [
                ['id' => '99001', 'properties' => ['email' => 'ada@example.com', 'firstname' => 'Ada']],
            ],
        ];
    }

    public function test_creating_a_bound_model_issues_exactly_one_upsert_request_carrying_the_mapped_properties(): void
    {
        $fake = Hubspot::fake(['contacts' => Hubspot::response(self::cannedUpsertBody(), 200)]);

        SyncedLead::create(['email' => 'ada@example.com', 'first_name' => 'Ada']);

        Hubspot::assertRequestCount(1);

        $request = $fake->recordedRequests()[0]['request'];

        self::assertSame('POST', $request->getMethod());
        self::assertSame('/crm/v3/objects/contacts/batch/upsert', $request->getUri()->getPath());

        /** @var array{inputs: list<array{id: string, idProperty: string, properties: array<string, string>}>} $body */
        $body = json_decode((string) $request->getBody(), true);

        self::assertCount(1, $body['inputs']);
        self::assertSame('ada@example.com', $body['inputs'][0]['id']);
        self::assertSame('email', $body['inputs'][0]['idProperty']);
        self::assertSame(
            ['email' => 'ada@example.com', 'firstname' => 'Ada'],
            $body['inputs'][0]['properties'],
        );
    }

    public function test_exactly_one_link_row_exists_afterwards_carrying_the_model_class_key_object_type_and_hubspot_id(): void
    {
        Hubspot::fake(['contacts' => Hubspot::response(self::cannedUpsertBody(), 200)]);

        $lead = SyncedLead::create(['email' => 'ada@example.com', 'first_name' => 'Ada']);

        self::assertSame(1, HubspotObjectLink::query()->count());

        $link = HubspotObjectLink::query()->sole();

        self::assertSame(SyncedLead::class, $link->model_type);
        self::assertSame((string) $lead->getKey(), $link->model_id);
        self::assertSame('contacts', $link->object_type);
        self::assertSame('99001', $link->hubspot_id);
    }

    public function test_hubspot_link_and_hubspot_id_resolve_the_stored_id(): void
    {
        Hubspot::fake(['contacts' => Hubspot::response(self::cannedUpsertBody(), 200)]);

        $lead = SyncedLead::create(['email' => 'ada@example.com', 'first_name' => 'Ada']);

        self::assertInstanceOf(HubspotObjectLink::class, $lead->hubspotLink);
        self::assertSame('99001', $lead->hubspotLink?->hubspot_id);
        self::assertSame('99001', $lead->hubspotId());
    }

    /**
     * The assertion reads the link table, never a model attribute -- the whole point of D-13/D-06
     * is that binding a model alters no consumer schema.
     */
    public function test_the_consumers_own_table_carries_no_hubspot_id_column(): void
    {
        Hubspot::fake(['contacts' => Hubspot::response(self::cannedUpsertBody(), 200)]);

        SyncedLead::create(['email' => 'ada@example.com', 'first_name' => 'Ada']);

        self::assertFalse(
            Schema::hasColumn('synced_leads', 'hubspot_id'),
            'The HubSpot id must live in hubspot_object_links, never on the consumer table.',
        );
        self::assertTrue(Schema::hasTable('hubspot_object_links'));
    }
}
