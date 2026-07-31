<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Sync;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Carbon;
use ReyemTech\Hubspot\Facades\Hubspot;
use ReyemTech\Hubspot\Sync\HubspotObjectLink;
use ReyemTech\Hubspot\Sync\PropertyMapper;
use ReyemTech\Hubspot\Sync\SyncHubspotObjectJob;
use ReyemTech\Hubspot\Tests\Support\Sync\SyncedLead;
use ReyemTech\Hubspot\Tests\Support\Sync\SyncTestCase;

/**
 * The job's update leg (SYNC-02, D-11): a second sync of an already-linked model addresses its
 * STORED HubSpot id and never re-derives it from a mapped property -- re-deriving would let a
 * changed `id_property` value repoint the write at a different record the moment it changed.
 *
 * `SyncedLead` has no `$hubspotUpdateMap` of its own (04-02's tracer fixture, untouched by this
 * plan), so this file proves the branch itself -- addressed by stored id, exactly one request,
 * `synced_at` moves, `hubspot_id` does not -- with `PropertyMapper::mapForUpdate()` falling back
 * to the full `$hubspotMap` unnarrowed. `PropertyMapperTest` already proves the narrowing
 * SELECTION rule in isolation, against explicit map/update-map arguments, since no model in this
 * plan's fixtures can yet declare an update map without touching `SyncsToHubspot.php` (04-04's).
 *
 * There is no `updated` observer hook yet (04-05's) -- `updated`/`deleted`/`trashed`/
 * `forceDeleted`/`restored` are none of them wired until later plans -- so the second sync below
 * is a direct `Dispatcher::dispatch()` of a fresh job for the already-linked model, exactly the
 * shape a real `updated` hook will call once it exists.
 */
mutates(SyncHubspotObjectJob::class, PropertyMapper::class);

final class UpdateSyncTest extends SyncTestCase
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

    public function test_a_model_with_a_link_row_is_updated_by_its_stored_hubspot_id_in_one_request(): void
    {
        Hubspot::fake(['contacts' => Hubspot::response(self::cannedUpsertBody(), 200)]);

        $lead = SyncedLead::create(['email' => 'ada@example.com', 'first_name' => 'Ada']);

        $originalLink = $lead->hubspotLink;
        self::assertInstanceOf(HubspotObjectLink::class, $originalLink);
        $originalHubspotId = $originalLink->hubspot_id;
        $originalSyncedAt = $originalLink->synced_at;
        self::assertNotNull($originalSyncedAt);

        // A distinguishable instant so a MOVED synced_at is provably different from an unmoved
        // one, rather than merely "not asserted to be equal".
        Carbon::setTestNow($originalSyncedAt->copy()->addMinute());

        $fake = Hubspot::fake();

        app(Dispatcher::class)->dispatch(new SyncHubspotObjectJob($lead->fresh()));

        Hubspot::assertRequestCount(1);

        $request = $fake->recordedRequests()[0]['request'];

        self::assertSame('PATCH', $request->getMethod());
        self::assertSame(
            '/crm/v3/objects/contacts/'.$originalHubspotId,
            $request->getUri()->getPath(),
        );

        $link = $originalLink->fresh();
        self::assertNotNull($link);
        self::assertSame($originalHubspotId, $link->hubspot_id);
        self::assertTrue($link->synced_at?->greaterThan($originalSyncedAt));

        Carbon::setTestNow();
    }

    public function test_a_model_with_no_link_row_is_still_upserted_never_addressed_by_id(): void
    {
        $fake = Hubspot::fake(['contacts' => Hubspot::response(self::cannedUpsertBody(), 200)]);

        SyncedLead::create(['email' => 'ada@example.com', 'first_name' => 'Ada']);

        Hubspot::assertRequestCount(1);

        $request = $fake->recordedRequests()[0]['request'];

        self::assertSame('POST', $request->getMethod());
        self::assertSame('/crm/v3/objects/contacts/batch/upsert', $request->getUri()->getPath());

        self::assertSame(1, HubspotObjectLink::query()->count());
    }
}
