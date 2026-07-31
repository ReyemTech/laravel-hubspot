<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Sync;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use ReyemTech\Hubspot\Facades\Hubspot;
use ReyemTech\Hubspot\Sync\HubspotObjectLink;
use ReyemTech\Hubspot\Sync\SyncsToHubspot;
use ReyemTech\Hubspot\Tests\Support\Sync\CrossConnectionTestCase;
use ReyemTech\Hubspot\Tests\Support\Sync\TenantLead;

/**
 * # The read surface has to survive the connection split the package itself creates
 *
 * `HubspotObjectLink` names its own connection on purpose (PR #39): a bound model on a tenant
 * database still reads its link row from the database the `sync` migration group ran against.
 * That fix made `hubspotLink` and `hubspotId()` work across the boundary and left the scopes
 * behind, because `whereHas()` does not run its existence subquery on the RELATED model's
 * connection -- it compiles it into the parent statement, which the parent's connection executes.
 * The result is a missing-table error for a table that exists, one connection over, while the
 * relation the scope is documented as delegating to answers correctly (Codex, PR #44).
 *
 * The scopes therefore resolve the link rows on the link table's own connection and constrain the
 * parent by key when the two differ. These tests pin the OUTCOME -- which models come back --
 * rather than the SQL, so the same assertions hold on both sides of that branch.
 */
mutates(SyncsToHubspot::class);

final class ScopesAcrossConnectionsTest extends CrossConnectionTestCase
{
    /**
     * The premise, asserted rather than assumed: the two tables really are in different databases,
     * and the relation really does read across. If this ever stops holding, the three scope tests
     * below would pass for a reason that has nothing to do with what they claim to prove.
     */
    public function test_the_link_table_and_the_bound_model_are_on_different_connections(): void
    {
        Hubspot::fake();

        $lead = TenantLead::create(['email' => 'premise@example.com', 'first_name' => 'Ada']);

        self::assertSame('tenant', $lead->getConnection()->getName());
        self::assertSame(
            DB::getDefaultConnection(),
            (new HubspotObjectLink)->getConnection()->getName(),
        );
        self::assertNotSame(
            $lead->getConnection()->getName(),
            (new HubspotObjectLink)->getConnection()->getName(),
        );

        self::assertSame('premise@example.com', $lead->hubspotId());
    }

    public function test_where_hubspot_id_resolves_across_the_connection_split(): void
    {
        Hubspot::fake();

        $lead = TenantLead::create(['email' => 'wanted@example.com', 'first_name' => 'Ada']);
        TenantLead::create(['email' => 'other@example.com', 'first_name' => 'Bea']);

        $match = TenantLead::query()->whereHubspotId('wanted@example.com')->sole();

        self::assertSame($lead->id, $match->id);
    }

    public function test_synced_to_hubspot_resolves_across_the_connection_split(): void
    {
        Hubspot::fake();

        $synced = TenantLead::create(['email' => 'synced@example.com', 'first_name' => 'Ada']);

        // Inserted through the query builder so no `created` event fires and no link row is ever
        // written -- the never-synced case has to be a real absence, not an untouched fixture.
        DB::connection('tenant')->table('tenant_leads')->insert([
            'email' => 'unsynced@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $match = TenantLead::syncedToHubspot()->sole();

        self::assertSame($synced->id, $match->id);
    }

    /**
     * Both legs at once, because the cross-connection form of this scope is where they are most
     * likely to diverge: "never linked" becomes an exclusion by key and "linked but stale" an
     * inclusion by key, and a fixture with only one of them present would let either leg go
     * missing unnoticed.
     */
    public function test_pending_hubspot_sync_resolves_both_legs_across_the_connection_split(): void
    {
        Hubspot::fake();

        $stale = TenantLead::create(['email' => 'stale@example.com', 'first_name' => 'Ada']);
        $stale->hubspotLink?->update(['is_stale' => true]);

        TenantLead::create(['email' => 'fresh@example.com', 'first_name' => 'Bea']);

        DB::connection('tenant')->table('tenant_leads')->insert([
            'email' => 'never-synced@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $emails = TenantLead::pendingHubspotSync()->pluck('email')->sort()->values()->all();

        self::assertSame(['never-synced@example.com', 'stale@example.com'], $emails);
    }

    /**
     * The cross-connection branch cannot put the link lookup inside the parent statement, so it
     * necessarily reads the link table separately. WHEN it reads is the part that is a choice, and
     * reading at scope-call time is the wrong one (Codex, PR #44): every other Eloquent scope is
     * lazy, so a builder that has already hit the database the moment it was constructed breaks the
     * only mental model a caller has for it, and it widens the staleness window from "between two
     * adjacent statements" -- unavoidable -- to "between construction and execution", which the
     * caller controls and can hold open indefinitely.
     *
     * Deferring cannot make the read atomic with the parent query, and does not claim to. It bounds
     * the window to the part that no two-statement strategy can remove.
     */
    public function test_the_scopes_issue_no_query_until_the_builder_is_executed(): void
    {
        Hubspot::fake();

        TenantLead::create(['email' => 'built@example.com', 'first_name' => 'Ada']);

        /** @var list<string> $statements */
        $statements = [];

        DB::listen(function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $builders = [
            'whereHubspotId' => static fn () => TenantLead::query()->whereHubspotId('built@example.com'),
            'syncedToHubspot' => static fn () => TenantLead::syncedToHubspot(),
            'pendingHubspotSync' => static fn () => TenantLead::pendingHubspotSync(),
        ];

        foreach ($builders as $name => $build) {
            $statements = [];

            $builder = $build();

            self::assertSame(
                [],
                $statements,
                $name.'() must not touch the database while merely building a query. Reading the '
                .'link table at scope-call time makes a builder that looks lazy execute eagerly.'
            );

            $builder->get();

            self::assertCount(
                2,
                $statements,
                $name.'() must read the link table and the parent table once each, when executed.'
            );
        }
    }

    /**
     * The behavioural consequence of the above, and the reason it is worth code rather than a
     * docblock: a link row written AFTER the builder was constructed but BEFORE it ran must be
     * seen. Under a scope-call-time read, this model stays in the result as pending work that has
     * already been done.
     *
     * The link row is written the way `SyncHubspotObjectJob` writes one rather than by running the
     * job, because what is under test is the read path's timing, not the write path.
     */
    public function test_pending_hubspot_sync_sees_a_link_written_after_the_builder_was_built(): void
    {
        DB::connection('tenant')->table('tenant_leads')->insert([
            'email' => 'raced@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $lead = TenantLead::query()->sole();

        $pending = TenantLead::pendingHubspotSync();

        HubspotObjectLink::create([
            'model_type' => $lead->getMorphClass(),
            'lookup_hash' => HubspotObjectLink::lookupHashFor($lead->getMorphClass()),
            // The `id` property, not getKey(): TenantLead declares `@property int $id`, while
            // getKey() is typed mixed and D-18 makes model_id a string column deliberately.
            'model_id' => (string) $lead->id,
            'object_type' => 'contacts',
            'hubspot_id' => 'raced@example.com',
            'synced_at' => now(),
            'is_stale' => false,
        ]);

        self::assertSame(
            0,
            $pending->count(),
            'A model linked after the builder was built, but before it ran, is no longer pending. '
            .'Reporting it as pending re-queues work that is already done.'
        );
    }
}
