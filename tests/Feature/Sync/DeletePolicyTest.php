<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Sync;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use ReyemTech\Hubspot\Facades\Hubspot;
use ReyemTech\Hubspot\Sync\HubspotObjectLink;
use ReyemTech\Hubspot\Sync\HubspotObserver;
use ReyemTech\Hubspot\Sync\SyncHubspotObjectJob;
use ReyemTech\Hubspot\Tests\Support\Sync\SoftDeletingLead;
use ReyemTech\Hubspot\Tests\Support\Sync\SyncedLead;
use ReyemTech\Hubspot\Tests\Support\Sync\SyncTestCase;

/**
 * # Deletes cannot surprise anyone
 *
 * SYNC-04. Three DISTINCT Eloquent events drive this table, because the obvious one does not
 * distinguish the rows: `deleted` fires identically for a `SoftDeletes` model's ordinary `delete()`
 * and for its `forceDelete()`, and branching inside a `deleted` handler on `$model->trashed()`
 * misclassifies a hard delete -- the in-memory `deleted_at` is already set by the time it runs
 * (04-RESEARCH.md, Common Pitfall 2, verified against the framework source).
 *
 * So: `trashed` fires if and only if a genuine soft delete happened, `forceDeleted` only after a
 * hard delete, and plain `deleted` is gated on the model not using `SoftDeletes` at all.
 *
 * ## How these tests are written so a wrong implementation cannot pass
 *
 * The force-delete test force-deletes a model that was NEVER soft-deleted first, and asserts the
 * archive happens EXACTLY once. An implementation hooking `deleted` without gating on the absence
 * of `SoftDeletes` archives twice there, because `forceDelete()` calls `delete()` internally -- and
 * a test that only asserted "at least one archive" would pass against it.
 *
 * Request counts are taken by re-faking immediately before the action under test: `Hubspot::fake()`
 * installs a NEW fake, so the log holds only what the delete path itself sent, rather than that
 * plus whatever creating the fixture sent.
 */
mutates(HubspotObserver::class);

final class DeletePolicyTest extends SyncTestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        /** @var ConfigRepository $config */
        $config = $app->make('config');

        $config->set('hubspot.models', [
            SyncedLead::class => ['object' => 'contacts', 'id_property' => 'email'],
            SoftDeletingLead::class => ['object' => 'contacts', 'id_property' => 'email'],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('soft_deleting_leads', function (Blueprint $table): void {
            $table->id();
            $table->string('email');
            $table->string('first_name')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        // `deleted` is opt-in and absent from the shipped default, so every test that expects a
        // delete to reach HubSpot has to turn it on -- which is itself the SC5 contract, asserted
        // on its own below.
        config()->set('hubspot.auto_sync.on', ['created', 'updated', 'deleted']);
    }

    public function test_a_soft_delete_archives_exactly_once(): void
    {
        Hubspot::fake();
        $lead = SoftDeletingLead::create(['email' => 'soft@example.com', 'first_name' => 'Ada']);

        Hubspot::fake();
        $lead->delete();

        Hubspot::assertRequestCount(1);
    }

    /**
     * The test the whole plan exists for. This model is force-deleted WITHOUT being soft-deleted
     * first, so `forceDelete()`'s internal `delete()` fires `deleted` while `deleted_at` is being
     * set -- exactly the sequence that makes a `deleted`-plus-`trashed()` implementation archive
     * twice, or classify a hard delete as a soft one.
     */
    public function test_a_force_delete_follows_hard_delete_and_archives_exactly_once_under_allow(): void
    {
        config()->set('hubspot.auto_sync.hard_delete', 'allow');

        Hubspot::fake();
        $lead = SoftDeletingLead::create(['email' => 'forced@example.com', 'first_name' => 'Ada']);

        Hubspot::fake();
        $lead->forceDelete();

        Hubspot::assertRequestCount(1);
    }

    public function test_a_force_delete_under_the_default_guard_issues_no_request(): void
    {
        Hubspot::fake();
        $lead = SoftDeletingLead::create(['email' => 'guarded@example.com', 'first_name' => 'Ada']);

        Hubspot::fake();
        $lead->forceDelete();

        Hubspot::assertRequestCount(0);
    }

    /**
     * D-21: `warn` takes the same ACTION as `guard` and differs only in log level. A test that
     * asserted `warn` archives would be asserting the trap that decision exists to avoid.
     */
    public function test_warn_skips_exactly_as_guard_does(): void
    {
        config()->set('hubspot.auto_sync.hard_delete', 'warn');

        Hubspot::fake();
        $lead = SoftDeletingLead::create(['email' => 'warned@example.com', 'first_name' => 'Ada']);

        Hubspot::fake();
        $lead->forceDelete();

        Hubspot::assertRequestCount(0);
    }

    public function test_a_model_without_soft_deletes_follows_hard_delete_exactly_once(): void
    {
        config()->set('hubspot.auto_sync.hard_delete', 'allow');

        Hubspot::fake();
        $lead = SyncedLead::create(['email' => 'plain@example.com', 'first_name' => 'Ada']);

        Hubspot::fake();
        $lead->delete();

        Hubspot::assertRequestCount(1);
    }

    /**
     * ROADMAP SC5's first clause, and the shipped default: with `deleted` absent from
     * `auto_sync.on`, none of the three events reaches HubSpot -- whatever `hard_delete` says.
     * Asserted across all three, because a gate applied to one event and forgotten on another is
     * exactly the shape this plan's three-event split makes possible.
     */
    public function test_no_delete_event_reaches_hubspot_while_deleted_is_not_opted_in(): void
    {
        config()->set('hubspot.auto_sync.on', ['created', 'updated']);
        config()->set('hubspot.auto_sync.hard_delete', 'allow');

        Hubspot::fake();
        $soft = SoftDeletingLead::create(['email' => 'a@example.com', 'first_name' => 'Ada']);
        $forced = SoftDeletingLead::create(['email' => 'b@example.com', 'first_name' => 'Bea']);
        $plain = SyncedLead::create(['email' => 'c@example.com', 'first_name' => 'Cleo']);

        Hubspot::fake();
        $soft->delete();
        $forced->forceDelete();
        $plain->delete();

        Hubspot::assertRequestCount(0);
    }

    /**
     * A restore cannot be mirrored -- there is no unarchive endpoint -- so the package flags the
     * link row and NEVER nulls the stored id, keeping re-linking possible.
     *
     * Three separate assertions on purpose. Asserting only the flag would pass against an
     * implementation that nulled the id and then flagged it, which is precisely the outcome the
     * requirement forbids in bold.
     */
    public function test_a_restore_flags_the_link_stale_without_touching_the_stored_id(): void
    {
        Hubspot::fake();
        $lead = SoftDeletingLead::create(['email' => 'restored@example.com', 'first_name' => 'Ada']);
        $lead->delete();

        $idBefore = HubspotObjectLink::query()->value('hubspot_id');

        Hubspot::fake();
        $lead->restore();

        Hubspot::assertRequestCount(0);

        $link = HubspotObjectLink::query()->sole();

        self::assertTrue($link->is_stale, 'A restore must flag the link stale.');
        self::assertSame(
            $idBefore,
            $link->hubspot_id,
            'The stored HubSpot id must survive a restore byte-identical -- nulling it makes '
            .'re-linking impossible, and there is no unarchive endpoint to recover from.'
        );
    }

    /**
     * Resolves the item `04-CONTEXT.md` left for this planner: a property-push job queued BEFORE a
     * soft delete arrives with the model already trashed.
     *
     * `SerializesModels` restores soft-deleted models -- `newQueryForRestoration()` uses
     * `newQueryWithoutScopes()` -- so the job does find its model rather than being discarded. It
     * must not push: the delete path has already archived that record, so the push is at best
     * wasted and at worst a write to an archived record. The delete path owns the archived state.
     */
    public function test_a_property_push_job_arriving_with_the_model_trashed_issues_no_request(): void
    {
        Hubspot::fake();
        $lead = SoftDeletingLead::create(['email' => 'raced@example.com', 'first_name' => 'Ada']);
        $lead->delete();

        Hubspot::fake();

        (new SyncHubspotObjectJob($lead))->handle();

        Hubspot::assertRequestCount(0);
    }
}
