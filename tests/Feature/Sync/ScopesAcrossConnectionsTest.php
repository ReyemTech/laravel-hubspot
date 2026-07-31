<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Sync;

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
}
