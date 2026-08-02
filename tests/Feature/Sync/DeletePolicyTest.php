<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Sync;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Mockery;
use ReyemTech\Hubspot\Exceptions\ApiException;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Facades\Hubspot;
use ReyemTech\Hubspot\Sync\HubspotObjectLink;
use ReyemTech\Hubspot\Sync\HubspotObserver;
use ReyemTech\Hubspot\Sync\SyncHubspotObjectJob;
use ReyemTech\Hubspot\Tests\Support\Sync\DisabledSoftDeletingLead;
use ReyemTech\Hubspot\Tests\Support\Sync\SoftDeletingLead;
use ReyemTech\Hubspot\Tests\Support\Sync\SyncedLead;
use ReyemTech\Hubspot\Tests\Support\Sync\SyncTestCase;

/**
 * # Deletes cannot surprise anyone
 *
 * SYNC-04. Three DISTINCT Eloquent events drive this table, because the obvious one does not
 * distinguish the rows: `deleted` fires identically for a `SoftDeletes` model's ordinary `delete()`
 * and for its `forceDelete()`, since `forceDelete()` calls `delete()` internally.
 *
 * Branching inside a `deleted` handler on `$model->trashed()` does not rescue it, though NOT for
 * the reason 04-RESEARCH.md Pitfall 2 gives -- the in-memory delete column is NOT set during a
 * direct force delete, because `performDeleteOnModel()` skips `runSoftDelete()` while
 * `forceDeleting` is true. Measured, not recalled: a direct force delete reads `trashed()` FALSE,
 * while a PURGE (soft delete, then `forceDelete()`) fires `deleted` TWICE and reads true both
 * times. So such an implementation misclassifies the purge, archiving it twice even under
 * `hard_delete => 'guard'`.
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
            DisabledSoftDeletingLead::class => ['object' => 'contacts', 'id_property' => 'email'],
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

        Schema::create('disabled_soft_deleting_leads', function (Blueprint $table): void {
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
     * A PURGE -- soft-delete now, `forceDelete()` later -- archives ONCE in total.
     *
     * The deduplication is evidence-based, and only evidence will do. An earlier revision keyed it
     * on `$model->trashed()`, which proves a soft delete happened and never that ITS archive passed
     * the gate; the test below is the case that broke. `archived_at` on the link row is the proof,
     * stamped when this package dispatches the archive (Codex, PR #49).
     */
    public function test_a_purge_archives_once_in_total(): void
    {
        config()->set('hubspot.auto_sync.hard_delete', 'allow');

        Hubspot::fake();
        $lead = SoftDeletingLead::create(['email' => 'purged@example.com', 'first_name' => 'Ada']);

        Hubspot::fake();
        $lead->delete();
        Hubspot::assertRequestCount(1);
        self::assertInstanceOf(
            Carbon::class,
            HubspotObjectLink::query()->sole()->archived_at,
            'archived_at is cast to a date, so every reader compares timestamps rather than strings.'
        );

        Hubspot::fake();
        $log = Log::spy();
        $lead->forceDelete();

        Hubspot::assertRequestCount(0);

        // The message is asserted VERBATIM, the discipline every ConfigurationException factory in
        // this suite is held to. A log line is only useful if it says what happened, and a test
        // that asserted only the level would pass against one that said anything at all.
        $log->shouldHaveReceived('info', [
            'A deleted model was NOT archived a second time: this package already archived that '
            .'HubSpot record, and there is no unarchive endpoint for it to have come back through.',
            [
                'model' => SoftDeletingLead::class,
                'model_id' => $lead->getKey(),
                'event' => 'forceDeleted',
                'action' => 'already-archived',
            ],
        ]);
    }

    /**
     * The case that makes the deduplication evidence-based rather than trashed-based, and the one
     * a `trashed()` check gets wrong: the soft delete was gated off, so nothing archived, and the
     * purge under `allow` is the only thing standing between HubSpot and an orphaned live record.
     */
    public function test_a_purge_still_archives_when_the_earlier_soft_delete_was_gated_off(): void
    {
        config()->set('hubspot.auto_sync.on', ['created', 'updated']);

        Hubspot::fake();
        $lead = SoftDeletingLead::create(['email' => 'gatedoff@example.com', 'first_name' => 'Ada']);

        Hubspot::fake();
        $lead->delete();
        Hubspot::assertRequestCount(0);
        self::assertNull(HubspotObjectLink::query()->sole()->archived_at);

        config()->set('hubspot.auto_sync.on', ['created', 'updated', 'deleted']);
        config()->set('hubspot.auto_sync.hard_delete', 'allow');

        Hubspot::fake();
        $lead->forceDelete();

        Hubspot::assertRequestCount(1);
    }

    /**
     * Under the SHIPPED DEFAULT `deleted` is not mirrored, so a soft delete archives nothing and
     * the HubSpot record stays live and editable. Editing a soft-deleted model must therefore still
     * push: discarding it lost the edit outright, since D-17 suppresses the restore's `updated`
     * event and the `restored` handler is gated off by that same absent option (Codex, PR #49).
     */
    public function test_an_edit_to_a_soft_deleted_model_still_pushes_when_the_delete_was_not_mirrored(): void
    {
        config()->set('hubspot.auto_sync.on', ['created', 'updated']);

        Hubspot::fake();
        $lead = SoftDeletingLead::create(['email' => 'edited@example.com', 'first_name' => 'Ada']);
        $lead->delete();

        Hubspot::fake();
        $lead->update(['first_name' => 'Bea']);

        Hubspot::assertRequestCount(1);
    }

    /**
     * A soft-deleted model that has NEVER synced is the one case `archived_at` cannot speak to,
     * and the answer is still no: an unmirrored delete asks for the HubSpot record to be left
     * alone, not for one to be brought into existence for a locally deleted row.
     */
    public function test_an_edit_to_a_trashed_model_that_never_linked_creates_nothing(): void
    {
        config()->set('hubspot.auto_sync.on', ['updated']);

        Hubspot::fake();
        $lead = SoftDeletingLead::create(['email' => 'neversynced@example.com', 'first_name' => 'Ada']);
        $lead->delete();

        self::assertNull(HubspotObjectLink::query()->value('id'), 'Nothing may have linked yet.');

        Hubspot::fake();
        $log = Log::spy();
        $lead->update(['first_name' => 'Bea']);

        Hubspot::assertRequestCount(0);
        $log->shouldHaveReceived('info');
    }

    /**
     * The mirror image, and the reason the guard is keyed on the LINK rather than dropped: once
     * this package has archived the record, a push writes to archived CRM state.
     */
    public function test_an_edit_to_a_model_whose_record_we_archived_does_not_push(): void
    {
        Hubspot::fake();
        $lead = SoftDeletingLead::create(['email' => 'archivededit@example.com', 'first_name' => 'Ada']);
        $lead->delete();

        Hubspot::fake();
        $log = Log::spy();
        $lead->update(['first_name' => 'Bea']);

        Hubspot::assertRequestCount(0);
        $log->shouldHaveReceived('info');
    }

    /**
     * A soft delete landing between the sync job's trashed guard and its link write finds NO link
     * -- the job has not written one yet -- so `trashed` has nothing to archive and schedules
     * nothing. The job then records a link for a model that is already deleted, and no later event
     * revisits it (Codex, PR #49).
     *
     * The response is to replay the event that could not act, once the link exists. The race is
     * reproduced deterministically by deleting the row underneath an in-memory model that still
     * believes it is live, which is exactly what the worker would be holding.
     */
    public function test_a_sync_that_raced_a_delete_applies_the_delete_policy_afterwards(): void
    {
        config()->set('hubspot.auto_sync.hard_delete', 'allow');

        Hubspot::fake();
        $lead = SoftDeletingLead::create(['email' => 'racedsync@example.com', 'first_name' => 'Ada']);
        HubspotObjectLink::query()->delete();

        // The delete lands in another request while this job holds a live in-memory model.
        SoftDeletingLead::query()->whereKey($lead->id)->update(['deleted_at' => now()]);

        Hubspot::fake();

        app()->call([new SyncHubspotObjectJob($lead), 'handle']);

        // The upsert this job came to make, then the archive the delete policy owed once the link
        // it needed existed.
        Hubspot::assertRequestCount(2);
        self::assertNotNull(
            HubspotObjectLink::query()->sole()->archived_at,
            'The replayed delete policy must leave its own evidence, or the next delete archives '
            .'an already-archived record.'
        );
    }

    /**
     * The same race under the SHIPPED DEFAULT, where `deleted` is not mirrored: the delete policy
     * is replayed and correctly decides to do nothing. Reproducing the archive by hand here rather
     * than replaying the observer would have got this backwards.
     */
    public function test_a_sync_that_raced_a_delete_mirrors_nothing_when_deletes_are_not_mirrored(): void
    {
        config()->set('hubspot.auto_sync.on', ['created', 'updated']);

        Hubspot::fake();
        $lead = SoftDeletingLead::create(['email' => 'racedquiet@example.com', 'first_name' => 'Ada']);
        HubspotObjectLink::query()->delete();

        SoftDeletingLead::query()->whereKey($lead->id)->update(['deleted_at' => now()]);

        Hubspot::fake();

        app()->call([new SyncHubspotObjectJob($lead), 'handle']);

        Hubspot::assertRequestCount(1);
        self::assertNull(HubspotObjectLink::query()->sole()->archived_at);
    }

    /**
     * The race, but with a FORCE delete -- and under the default `guard`, which is the case that
     * makes replaying the right event load-bearing rather than tidy (Codex, PR #49).
     *
     * `trashed` resolves straight to `archive` without consulting `hard_delete`, because a soft
     * delete is locally recoverable. Replaying it for a row that is GONE would therefore archive
     * irreversibly under the very setting that exists to forbid it. `forceDeleted` is the event
     * that answers for a hard delete, and it follows `hard_delete`.
     */
    public function test_a_sync_that_raced_a_force_delete_still_obeys_the_guard(): void
    {
        Hubspot::fake();
        $lead = SoftDeletingLead::create(['email' => 'racedforce@example.com', 'first_name' => 'Ada']);
        HubspotObjectLink::query()->delete();

        // The row is GONE, not trashed: a hard delete in another request.
        SoftDeletingLead::query()->whereKey($lead->id)->forceDelete();

        Hubspot::fake();

        app()->call([new SyncHubspotObjectJob($lead), 'handle']);

        Hubspot::assertRequestCount(1);
        self::assertNull(
            HubspotObjectLink::query()->sole()->archived_at,
            'hard_delete defaults to guard, and a raced force delete must not archive around it.'
        );
    }

    /**
     * The same force-delete race under `allow`, so the test above cannot pass merely because
     * nothing was ever dispatched.
     */
    public function test_a_sync_that_raced_a_force_delete_archives_under_allow(): void
    {
        config()->set('hubspot.auto_sync.hard_delete', 'allow');

        Hubspot::fake();
        $lead = SoftDeletingLead::create(['email' => 'racedforceallow@example.com', 'first_name' => 'Ada']);
        HubspotObjectLink::query()->delete();

        SoftDeletingLead::query()->whereKey($lead->id)->forceDelete();

        Hubspot::fake();

        app()->call([new SyncHubspotObjectJob($lead), 'handle']);

        Hubspot::assertRequestCount(2);
        self::assertNotNull(HubspotObjectLink::query()->sole()->archived_at);
    }

    /**
     * A model with no `SoftDeletes` at all races the same way, and `deleted` is the event that
     * answers for it -- also following `hard_delete`.
     */
    public function test_a_sync_that_raced_a_plain_delete_applies_the_delete_policy(): void
    {
        config()->set('hubspot.auto_sync.hard_delete', 'allow');

        Hubspot::fake();
        $lead = SyncedLead::create(['email' => 'racedplain@example.com', 'first_name' => 'Ada']);
        HubspotObjectLink::query()->delete();

        SyncedLead::query()->whereKey($lead->id)->delete();

        Hubspot::fake();

        app()->call([new SyncHubspotObjectJob($lead), 'handle']);

        Hubspot::assertRequestCount(2);
        self::assertNotNull(HubspotObjectLink::query()->sole()->archived_at);
    }

    /**
     * A record HubSpot no longer has is a record that is archived. The redundant archive a purge
     * issues must not become a failed job for saying so, which is `04-06-PLAN.md`'s own rule for a
     * missing link row applied to the record instead of the row.
     */
    public function test_an_archive_of_an_already_gone_record_is_a_completed_archive(): void
    {
        config()->set('hubspot.auto_sync.hard_delete', 'allow');

        Hubspot::fake();
        $lead = SoftDeletingLead::create(['email' => 'gone@example.com', 'first_name' => 'Ada']);

        Hubspot::fake(['contacts' => Hubspot::response(['message' => 'not found'], 404)]);
        $log = Log::spy();

        $lead->forceDelete();

        Hubspot::assertRequestCount(1);
        $log->shouldHaveReceived('info');
    }

    /**
     * Every other status still throws. A 429 or a 500 says nothing about whether the record is
     * archived, and swallowing it would turn a retryable failure into a silent no-op.
     */
    public function test_an_archive_rejected_for_any_other_reason_still_throws(): void
    {
        config()->set('hubspot.auto_sync.hard_delete', 'allow');

        Hubspot::fake();
        $lead = SoftDeletingLead::create(['email' => 'ratelimited@example.com', 'first_name' => 'Ada']);

        Hubspot::fake(['contacts' => Hubspot::response(['message' => 'slow down'], 429)]);

        $this->expectException(ApiException::class);

        $lead->forceDelete();
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
     * Resolves the item `04-CONTEXT.md` left for this planner: a property-push job queued BEFORE a
     * soft delete arrives with the model already trashed.
     *
     * `SerializesModels` restores soft-deleted models -- `newQueryForRestoration()` uses
     * `newQueryWithoutScopes()` -- so the job does find its model rather than being discarded. It
     * must not push: the delete path has already archived that record, so the push is at best
     * wasted and at worst a write to an archived record. The delete path owns the archived state.
     *
     * `handle()` is reached through the container rather than called bare, because its collaborators
     * are METHOD parameters resolved per call -- the shape that lets `Hubspot::fake()` swap the
     * transport underneath a job that was constructed before the fake existed. Calling it with no
     * arguments raises `ArgumentCountError` before any assertion in this test can run; going through
     * `app()->call()` is how the queue itself invokes it, and changes nothing this test asserts.
     */
    public function test_a_property_push_job_arriving_with_the_model_trashed_issues_no_request(): void
    {
        Hubspot::fake();
        $lead = SoftDeletingLead::create(['email' => 'raced@example.com', 'first_name' => 'Ada']);
        $lead->delete();

        Hubspot::fake();

        $log = Log::spy();

        app()->call([new SyncHubspotObjectJob($lead), 'handle']);

        Hubspot::assertRequestCount(0);
        $log->shouldHaveReceived('info', [
            Mockery::type('string'),
            ['model' => SoftDeletingLead::class, 'model_id' => $lead->getKey()],
        ]);
    }

    /**
     * D-21 is a claim about LOG LEVEL, and the request-count tests above cannot see it: `guard` and
     * `warn` take the same action, so a suite that only counted requests would pass against an
     * implementation where the two were literally the same branch. These two tests are what make
     * the decision real rather than documented.
     */
    public function test_the_default_guard_logs_the_skipped_archive_at_info(): void
    {
        Hubspot::fake();
        $lead = SoftDeletingLead::create(['email' => 'quiet@example.com', 'first_name' => 'Ada']);

        $log = Log::spy();

        $lead->forceDelete();

        // The CONTEXT is asserted whole, not merely the level: a log line that says an archive was
        // skipped without saying which record it was skipped for is not actionable, and every key
        // here is what makes the local row and its CRM counterpart findable afterwards.
        $log->shouldHaveReceived('info', [
            Mockery::type('string'),
            [
                'model' => SoftDeletingLead::class,
                'model_id' => $lead->getKey(),
                'event' => 'forceDeleted',
                'action' => 'skip-quietly',
            ],
        ]);
        $log->shouldNotHaveReceived('warning');
    }

    public function test_warn_logs_the_same_skipped_archive_at_warning(): void
    {
        config()->set('hubspot.auto_sync.hard_delete', 'warn');

        Hubspot::fake();
        $lead = SoftDeletingLead::create(['email' => 'loud@example.com', 'first_name' => 'Ada']);

        $log = Log::spy();

        $lead->forceDelete();

        $log->shouldHaveReceived('warning');
        $log->shouldNotHaveReceived('info');
    }

    /**
     * A model deleted before its first sync ever landed is ordinary, not a failure: there is no
     * HubSpot record to archive, so the delete path logs and stops. `'created'` is left out of
     * `auto_sync.on` here precisely so no link row is ever written.
     */
    public function test_a_delete_with_no_link_row_issues_no_request_and_does_not_throw(): void
    {
        config()->set('hubspot.auto_sync.on', ['deleted']);
        config()->set('hubspot.auto_sync.hard_delete', 'allow');

        Hubspot::fake();
        $lead = SyncedLead::create(['email' => 'unsynced@example.com', 'first_name' => 'Ada']);

        Hubspot::assertRequestCount(0);
        self::assertNull(HubspotObjectLink::query()->value('id'));

        $log = Log::spy();
        $lead->delete();

        Hubspot::assertRequestCount(0);
        $log->shouldHaveReceived('info', [
            'A deleted model has no HubSpot link row, so there is nothing to archive. It was '
            .'deleted before its first sync landed.',
            [
                'model' => SyncedLead::class,
                'model_id' => $lead->getKey(),
                'event' => 'deleted',
                'action' => 'archive',
            ],
        ]);
    }

    /**
     * A policy value that is not even a string still fails as this package's own exception naming
     * the key and the supported values, not as a `TypeError` raised from inside an Eloquent event
     * handler that names neither.
     */
    public function test_a_non_string_hard_delete_value_fails_as_a_configuration_exception(): void
    {
        config()->set('hubspot.auto_sync.hard_delete', 42);

        Hubspot::fake();
        $lead = SyncedLead::create(['email' => 'mistyped@example.com', 'first_name' => 'Ada']);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'hubspot.auto_sync.hard_delete is set to "int", which is not a supported delete policy.'
        );

        $lead->delete();
    }
}
