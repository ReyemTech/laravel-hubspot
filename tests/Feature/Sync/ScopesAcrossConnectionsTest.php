<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Sync;

use Illuminate\Database\Eloquent\Builder;
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
     * The cross-connection constraint must sit where the scope was CALLED, so a chained top-level
     * `orWhere()` widens it rather than filtering it: `(link) OR email = ?`, never
     * `email = ? AND id IN (...)`, which demotes the OR leg to a filter and drops the unlinked lead
     * it was written to find (Codex, PR #44).
     *
     * The shared-connection branch gets this from Laravel, which places a scope's clauses where the
     * scope was called. This asserts the cross-connection branch agrees, and
     * `SyncsToHubspotTraitTest` asserts the same chain on a shared connection -- pinned from both
     * sides, which is the only way a divergence like this stays fixed rather than drifting together.
     */
    public function test_a_scope_keeps_its_position_when_a_caller_chains_a_top_level_or_where(): void
    {
        Hubspot::fake();

        TenantLead::create(['email' => 'linked@example.com', 'first_name' => 'Ada']);

        DB::connection('tenant')->table('tenant_leads')->insert([
            'email' => 'unlinked@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $emails = TenantLead::syncedToHubspot()
            ->orWhere('email', 'unlinked@example.com')
            ->pluck('email')
            ->sort()
            ->values()
            ->all();

        self::assertSame(
            ['linked@example.com', 'unlinked@example.com'],
            $emails,
            'The scope must remain an alternative the chained orWhere() can widen, not a filter '
            .'applied on top of it. Losing the unlinked lead means the constraint moved.'
        );
    }

    /**
     * The same property with two clauses after the scope rather than one.
     *
     * `(link) AND first_name = ? OR email = ?` groups as `(link AND Ada) OR unlinked`. Bea is
     * linked but not Ada, so she is the row that separates a correctly-placed constraint from one
     * that has swallowed the `first_name` clause -- a failure an arrangement with a single trailing
     * clause cannot see at all.
     */
    public function test_a_scope_composes_with_two_clauses_chained_after_it(): void
    {
        Hubspot::fake();

        TenantLead::create(['email' => 'linked@example.com', 'first_name' => 'Ada']);
        TenantLead::create(['email' => 'other@example.com', 'first_name' => 'Bea']);

        DB::connection('tenant')->table('tenant_leads')->insert([
            'email' => 'unlinked@example.com',
            'first_name' => 'Ada',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $emails = TenantLead::syncedToHubspot()
            ->where('first_name', 'Ada')
            ->orWhere('email', 'unlinked@example.com')
            ->pluck('email')
            ->sort()
            ->values()
            ->all();

        self::assertSame(['linked@example.com', 'unlinked@example.com'], $emails);
    }

    /**
     * A scope used inside a NESTED predicate must still constrain (Codex, PR #44).
     *
     * This is the case that killed the deferred implementation outright: registering the constraint
     * on the nested builder left that builder with no clauses of its own, so
     * `addNestedWhereQuery()` discarded the empty group and the constraint vanished entirely --
     * `select * from tenant_leads`, every unlinked model returned, no error anywhere. Resolving
     * through the ordinary builder API cannot fail this way, because the nested builder receives a
     * real clause.
     */
    public function test_a_scope_still_constrains_inside_a_nested_predicate(): void
    {
        Hubspot::fake();

        TenantLead::create(['email' => 'linked@example.com', 'first_name' => 'Ada']);

        DB::connection('tenant')->table('tenant_leads')->insert([
            'email' => 'unlinked@example.com',
            'first_name' => 'Ada',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $emails = TenantLead::query()
            ->where(static fn (Builder $nested): Builder => $nested->syncedToHubspot())
            ->pluck('email')
            ->all();

        self::assertSame(
            ['linked@example.com'],
            $emails,
            'A nested predicate that loses the constraint returns every row, which is the most '
            .'dangerous shape this defect can take: no error, just an unfiltered result.'
        );
    }

    /**
     * A global scope must apply to the WHOLE query, link leg included (Codex, PR #44).
     *
     * `applyScopes()` regroups the existing clauses when it adds a global scope, so a constraint
     * whose position was recorded as a numeric offset beforehand ends up outside the regrouped
     * predicate: `link OR (email AND global)` instead of `(link OR email) AND global`. For the
     * global scope every consumer actually has -- `SoftDeletes` -- that means the link leg returns
     * soft-deleted rows while the shared-connection branch excludes them.
     *
     * A plain global scope stands in for `SoftDeletes` here: it exercises the identical
     * `applyScopes()` regrouping without making this fixture carry a `deleted_at` column and a
     * second trait whose own behaviour is not what is under test.
     */
    public function test_a_global_scope_applies_to_the_link_leg_too(): void
    {
        Hubspot::fake();

        TenantLead::create(['email' => 'linked@example.com', 'first_name' => 'Ada']);
        TenantLead::create(['email' => 'hidden@example.com', 'first_name' => 'Excluded']);

        TenantLead::addGlobalScope('not-excluded', static function (Builder $query): void {
            $query->where('first_name', '!=', 'Excluded');
        });

        try {
            $emails = TenantLead::syncedToHubspot()
                ->orWhere('email', 'nobody@example.com')
                ->pluck('email')
                ->all();
        } finally {
            // Global scopes are static per model class; leaking one would reshape every later test.
            TenantLead::addGlobalScope('not-excluded', static function (Builder $query): void {});
        }

        self::assertSame(
            ['linked@example.com'],
            $emails,
            'The excluded lead is linked, so it comes back only if the global scope failed to '
            .'apply to the link leg -- which is how a SoftDeletes consumer would see deleted rows.'
        );
    }

    /**
     * The cross-connection branch resolves its links WHEN THE SCOPE IS CALLED, and that is stated
     * here rather than left implicit, because it is the one place this branch is visibly unlike an
     * ordinary Eloquent scope.
     *
     * Deferring it to execution time was implemented and reverted -- see `hubspotLinkedKeys()` for
     * the three silent wrong-results defects deferral produced, each found only after the previous
     * was fixed. This test is what makes a future re-attempt announce itself rather than land
     * quietly.
     */
    public function test_the_cross_connection_branch_resolves_its_links_when_the_scope_is_called(): void
    {
        Hubspot::fake();

        TenantLead::create(['email' => 'eager@example.com', 'first_name' => 'Ada']);

        /** @var list<string> $statements */
        $statements = [];

        DB::listen(function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $builder = TenantLead::syncedToHubspot();

        self::assertCount(
            1,
            $statements,
            'The link table is read while the scope is being applied. If this ever becomes 0, the '
            .'resolution has been deferred again -- read hubspotLinkedKeys() before keeping it.'
        );

        $builder->get();

        self::assertCount(2, $statements, 'Executing the builder adds the parent statement.');
    }
}
