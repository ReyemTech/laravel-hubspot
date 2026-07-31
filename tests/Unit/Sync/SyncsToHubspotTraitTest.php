<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Unit\Sync;

use Illuminate\Support\Facades\DB;
use ReyemTech\Hubspot\Facades\Hubspot;
use ReyemTech\Hubspot\Sync\SyncsToHubspot;
use ReyemTech\Hubspot\Tests\Support\Sync\MultiBindingTestCase;
use ReyemTech\Hubspot\Tests\Support\Sync\SyncedContact;
use ReyemTech\Hubspot\Tests\Support\Sync\SyncedLead;

/**
 * `SyncsToHubspot`'s full read surface (D-06): the relation, and the three query scopes 04-02
 * deliberately left for this plan -- `whereHubspotId()`, `syncedToHubspot()`,
 * `pendingHubspotSync()`.
 *
 * Runs against {@see MultiBindingTestCase}'s three bindings rather than a single-model fixture:
 * the property that matters most here is that a scope constrains to the QUERYING model's own
 * class, not merely that it returns rows -- a `Lead::whereHubspotId($x)` that also matched a
 * `Contact` linked to the same id would be exactly the cross-class leak T-04-17 names.
 */
mutates(SyncsToHubspot::class);

final class SyncsToHubspotTraitTest extends MultiBindingTestCase
{
    /**
     * Inserted directly through the query builder rather than `SyncedLead::create()`, so no
     * `created` event ever fires and no link row is ever written -- the same reason
     * `TracerSyncTest::test_hubspot_id_is_null_before_any_sync_has_happened` avoids `create()`.
     */
    public function test_hubspot_link_and_hubspot_id_are_null_before_any_sync_has_happened(): void
    {
        DB::table('synced_leads')->insert([
            'email' => 'never-synced@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $lead = SyncedLead::query()->firstOrFail();

        self::assertNull($lead->hubspotLink);
        self::assertNull($lead->hubspotId());
    }

    public function test_where_hubspot_id_returns_only_the_model_linked_to_that_id(): void
    {
        Hubspot::fake();

        $lead = SyncedLead::create(['email' => 'lead@example.com', 'first_name' => 'Ada']);
        SyncedContact::create(['email' => 'contact@example.com', 'last_name' => 'Lovelace']);

        $match = SyncedLead::query()->whereHubspotId('lead@example.com')->sole();

        self::assertSame($lead->id, $match->id);
    }

    public function test_where_hubspot_id_never_matches_a_different_model_class_linked_to_the_same_id(): void
    {
        Hubspot::fake();

        SyncedLead::create(['email' => 'shared@example.com', 'first_name' => 'Ada']);

        // SyncedContact converges on a DIFFERENT id_property (company_email), so its own
        // hubspot_id can never literally equal SyncedLead's -- the assertion that matters is that
        // querying SyncedContact by SyncedLead's id returns nothing, not merely that the ids
        // happen to differ.
        self::assertSame(0, SyncedContact::query()->whereHubspotId('shared@example.com')->count());
    }

    public function test_synced_to_hubspot_returns_only_models_that_have_a_link_row(): void
    {
        Hubspot::fake();

        $synced = SyncedLead::create(['email' => 'synced@example.com', 'first_name' => 'Ada']);

        // Inserted directly through the query builder so no `created` event fires and no link
        // row is ever written -- the never-synced case has to be a real absence, not merely an
        // untouched fixture.
        DB::table('synced_leads')->insert([
            'email' => 'unsynced@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $match = SyncedLead::syncedToHubspot()->sole();

        self::assertSame($synced->id, $match->id);
    }

    public function test_pending_hubspot_sync_includes_a_model_that_has_never_synced(): void
    {
        DB::table('synced_leads')->insert([
            'email' => 'never-synced@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $match = SyncedLead::pendingHubspotSync()->sole();

        self::assertSame('never-synced@example.com', $match->email);
    }

    /**
     * The stale leg is not optional (T-04-18): SYNC-04's restore path (04-06) flags a link stale
     * rather than nulling it, and a scope that only handled the never-synced case would silently
     * under-report every model a restore just re-queued.
     */
    public function test_pending_hubspot_sync_includes_a_model_whose_link_row_is_flagged_stale(): void
    {
        Hubspot::fake();

        $stale = SyncedLead::create(['email' => 'stale@example.com', 'first_name' => 'Ada']);
        $stale->hubspotLink?->update(['is_stale' => true]);

        SyncedLead::create(['email' => 'fresh@example.com', 'first_name' => 'Bea']);

        $match = SyncedLead::pendingHubspotSync()->get()->sole();

        self::assertSame($stale->id, $match->id);
    }
}
