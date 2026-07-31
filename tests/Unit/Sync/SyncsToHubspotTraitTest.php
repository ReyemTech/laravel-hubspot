<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Unit\Sync;

use Illuminate\Database\Events\QueryExecuted;
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

    /**
     * A second `SyncedLead` with a DIFFERENT hubspot id is created alongside the target one --
     * without it, `sole()` would still resolve the single row this test cares about even if the
     * scope's `hubspot_id` predicate were removed entirely (`whereHas('hubspotLink')` alone would
     * already narrow to "has synced at all", which happens to be true for only one row here).
     * With two synced leads present, dropping the id predicate would make this query match BOTH,
     * and `sole()` would throw.
     */
    public function test_where_hubspot_id_returns_only_the_model_linked_to_that_id(): void
    {
        Hubspot::fake();

        $lead = SyncedLead::create(['email' => 'lead@example.com', 'first_name' => 'Ada']);
        SyncedLead::create(['email' => 'other@example.com', 'first_name' => 'Bea']);
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

    /**
     * The shared-connection half of
     * `ScopesAcrossConnectionsTest::test_a_scope_keeps_its_position_when_a_caller_chains_a_top_level_or_where`.
     *
     * Laravel places a scope's clauses where the scope was called, so a chained top-level
     * `orWhere()` widens the scope rather than filtering it. That is the REFERENCE meaning; this
     * test states it explicitly so the cross-connection branch has something to be equal to, rather
     * than each side asserting its own behaviour and both drifting together unnoticed.
     */
    public function test_a_scope_composes_with_a_later_or_where_as_an_alternative_not_a_filter(): void
    {
        Hubspot::fake();

        SyncedLead::create(['email' => 'linked@example.com', 'first_name' => 'Ada']);

        DB::table('synced_leads')->insert([
            'email' => 'unlinked@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $emails = SyncedLead::syncedToHubspot()
            ->orWhere('email', 'unlinked@example.com')
            ->pluck('email')
            ->sort()
            ->values()
            ->all();

        self::assertSame(['linked@example.com', 'unlinked@example.com'], $emails);
    }

    /**
     * Every scope has a second, cross-connection branch (see
     * `SyncsToHubspot::hubspotLinkSharesConnectionWith()`), which resolves the link rows in PHP
     * and constrains the parent by key. That branch is CORRECT here too -- it would return exactly
     * the rows the tests above assert -- so no assertion about results can tell the two apart, and
     * losing the shared-connection branch would be invisible to all of them while quietly turning
     * every scope into two round trips and materialising every link row of the class into memory.
     *
     * Statements are what distinguishes them, so statements are what this counts. One statement
     * means the link lookup travelled inside the parent query as a subquery the database resolved
     * itself, which is the entire reason the branch exists.
     *
     * The listener is registered AFTER the fixtures are created, so the sync path's own writes are
     * not counted, and `$statements` is reset per scope rather than re-registering a listener --
     * `DB::listen()` appends, and a second registration would count every query twice.
     */
    public function test_a_shared_connection_resolves_each_scope_in_a_single_statement(): void
    {
        Hubspot::fake();

        SyncedLead::create(['email' => 'counted@example.com', 'first_name' => 'Ada']);

        /** @var list<string> $statements */
        $statements = [];

        DB::listen(function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $scopes = [
            'whereHubspotId' => static fn () => SyncedLead::query()
                ->whereHubspotId('counted@example.com')->get(),
            'syncedToHubspot' => static fn () => SyncedLead::syncedToHubspot()->get(),
            'pendingHubspotSync' => static fn () => SyncedLead::pendingHubspotSync()->get(),
        ];

        foreach ($scopes as $name => $run) {
            $statements = [];

            $run();

            self::assertCount(
                1,
                $statements,
                $name.'() must resolve the link table inside the parent statement when both share '
                .'a connection. More than one statement means it took the cross-connection branch, '
                .'which reads every link row of this class into memory to do the same job.'
            );
        }
    }
}
