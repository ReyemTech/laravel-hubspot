<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Sync;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use ReyemTech\Hubspot\Facades\Hubspot;
use ReyemTech\Hubspot\Sync\HubspotObjectLink;
use ReyemTech\Hubspot\Sync\SyncHubspotObjectJob;
use ReyemTech\Hubspot\Sync\SyncsToHubspot;
use ReyemTech\Hubspot\Tests\Support\Sync\SyncedLead;
use ReyemTech\Hubspot\Tests\Support\Sync\SyncTestCase;

/**
 * # Three ways the tracer could sync successfully and still leave the model unreachable
 *
 * All three were found by Codex on PR #39, and all three share one shape: the write path and the
 * read path disagreeing about where -- or whether -- the link row is. None of them raises an
 * error. The HubSpot request succeeds, the job reports done, and the model is either never synced
 * at all or carries a link nothing can resolve.
 *
 * That is exactly the failure class this package exists to prevent, which is why they are tested
 * as behaviour rather than patched quietly.
 */
mutates(
    SyncsToHubspot::class,
    HubspotObjectLink::class,
    SyncHubspotObjectJob::class,
);

final class TracerCorrectnessTest extends SyncTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A fake is bound for every test in this class, and that is a statement rather than
        // boilerplate: from 04-07 the testing environment refuses to sync unless one is, so that a
        // test which forgot cannot reach a real portal with no credentials. Binding it is how a
        // test says it intends to exercise the sync path. The alternative -- loosening the gate so
        // these pass -- would make the suite green by removing the protection it exists to provide.
        Hubspot::fake();
    }

    protected function tearDown(): void
    {
        // morphMap is global static state on the framework; leaking it would silently reshape
        // every later test's model_type values.
        Relation::morphMap([], false);

        parent::tearDown();
    }

    /**
     * `SerializesModels` re-fetches the model by key on the worker. A job made visible before its
     * creating transaction commits cannot find that row, and because the job declares
     * `deleteWhenMissingModels = true`, Laravel DISCARDS it permanently -- the transaction then
     * commits and the model is never synced. No retry, no `failed_jobs` row, no log line.
     *
     * `Queueable::afterCommit()` sets the flag `Illuminate\Queue\Queue::shouldDispatchAfterCommit()`
     * reads to hold the push until the outermost transaction commits.
     */
    public function test_it_defers_the_sync_job_until_the_creating_transaction_commits(): void
    {
        Bus::fake();

        SyncedLead::create(['email' => 'commit@example.com', 'first_name' => 'Ada']);

        Bus::assertDispatched(
            SyncHubspotObjectJob::class,
            fn (SyncHubspotObjectJob $job): bool => $job->afterCommit === true,
        );
    }

    /**
     * `morphOne` queries `model_type` with `getMorphClass()`. Writing `get_class()` instead means
     * that under `Relation::morphMap()` the row is written under the FQCN and read back under the
     * alias -- so it is never found. The sync "succeeds" and `hubspotId()` returns null forever.
     */
    public function test_it_stores_the_morph_discriminator_the_relation_reads_back(): void
    {
        Relation::morphMap(['synced_lead' => SyncedLead::class]);

        Hubspot::fake();

        $lead = SyncedLead::create(['email' => 'morph@example.com', 'first_name' => 'Grace']);

        $this->assertSame(
            'synced_lead',
            HubspotObjectLink::query()->value('model_type'),
            'The link row must carry the morph alias, since that is what the relation queries with.'
        );

        $fresh = $lead->fresh();

        $this->assertNotNull($fresh);
        $this->assertNotNull(
            $fresh->hubspotLink,
            'The link row must be findable through the relation. Storing get_class() while '
            .'morphOne() queries getMorphClass() writes a row no read path can ever see.'
        );
        $this->assertNotNull($fresh->hubspotId());
    }

    /**
     * `HasRelationships::newRelatedInstance()` assigns the PARENT model's connection to a related
     * model that names none of its own (framework source: it only sets the connection
     * `if (! $instance->getConnectionName())`). A bound model on a second connection therefore
     * made `hubspotLink` read a database the package table is not in, while the job's write went
     * to the default connection.
     */
    public function test_it_keeps_link_reads_on_the_package_table_connection(): void
    {
        $this->assertNotNull(
            (new HubspotObjectLink)->getConnectionName(),
            'HubspotObjectLink must name its own connection. While it names none, morphOne() '
            .'adopts the bound model connection and reads a database the package table is not in.'
        );

        $onAnotherConnection = new SyncedLead;
        $onAnotherConnection->setConnection('some-other-connection');

        $this->assertSame(
            DB::getDefaultConnection(),
            $onAnotherConnection->hubspotLink()->getRelated()->getConnectionName(),
            'The link relation must stay on the connection the package migration ran against, '
            .'regardless of which connection the bound model uses.'
        );
    }
}
