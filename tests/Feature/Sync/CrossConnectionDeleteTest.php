<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Sync;

use Illuminate\Support\Facades\DB;
use ReyemTech\Hubspot\Facades\Hubspot;
use ReyemTech\Hubspot\Sync\HubspotObjectLink;
use ReyemTech\Hubspot\Sync\HubspotObserver;
use ReyemTech\Hubspot\Tests\Support\Sync\CrossConnectionTestCase;
use ReyemTech\Hubspot\Tests\Support\Sync\TenantLead;

/**
 * # The archive marker and the archive share one transaction's fate
 *
 * The link table lives on the DEFAULT connection whatever connection a bound model is on -- that
 * split is the whole point of {@see CrossConnectionTestCase}, and it is what makes a delete inside
 * the MODEL's transaction able to commit a marker that the transaction cannot take back.
 *
 * `archived_at` is what every read path downstream of a delete trusts:
 * `SyncHubspotObjectJob` skips a link that carries it, a restore flags one that carries it, and a
 * later delete declines to archive twice on the strength of it. A marker describing an archive that
 * never happened therefore does not merely mislead -- it silently and permanently removes the model
 * from every sync path there is (Codex, PR #49).
 */
mutates(HubspotObserver::class);

final class CrossConnectionDeleteTest extends CrossConnectionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('hubspot.auto_sync.on', ['created', 'updated', 'deleted']);
        config()->set('hubspot.auto_sync.hard_delete', 'allow');
    }

    /**
     * The queued archive is deferred with `afterCommit()` and is correctly discarded when the
     * model's transaction rolls back. The marker has to go with it: registered through
     * `DB::afterCommit()`, it now defers through the same `DatabaseTransactionsManager` the
     * dispatch does, so a rollback takes both or neither.
     */
    public function test_a_rolled_back_delete_leaves_no_archive_marker_behind(): void
    {
        Hubspot::fake();
        $lead = TenantLead::create(['email' => 'tenant@example.com', 'first_name' => 'Ada']);

        self::assertNotNull(HubspotObjectLink::query()->value('id'), 'The create must have linked.');

        Hubspot::fake();

        DB::connection('tenant')->beginTransaction();
        $lead->delete();
        DB::connection('tenant')->rollBack();

        Hubspot::assertRequestCount(0);
        self::assertNull(
            HubspotObjectLink::query()->sole()->archived_at,
            'A marker that outlived its rolled-back delete removes a live model from every sync '
            .'path there is: pushes skip it, and later deletes decline to archive on its strength.'
        );
    }

    /**
     * The committed case, so the test above cannot pass merely because nothing ever archives across
     * connections.
     */
    public function test_a_committed_delete_archives_and_records_it(): void
    {
        Hubspot::fake();
        $lead = TenantLead::create(['email' => 'committed@example.com', 'first_name' => 'Ada']);

        Hubspot::fake();

        DB::connection('tenant')->beginTransaction();
        $lead->delete();
        DB::connection('tenant')->commit();

        Hubspot::assertRequestCount(1);
        self::assertNotNull(HubspotObjectLink::query()->sole()->archived_at);
    }
}
