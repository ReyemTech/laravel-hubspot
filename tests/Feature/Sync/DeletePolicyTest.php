<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Sync;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Mockery;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
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
     * A PURGE -- soft-delete now, `forceDelete()` later -- archives ONCE in total, not twice.
     *
     * `trashed` already dispatched the archive on the way down, and HubSpot has no unarchive
     * endpoint for that first archive to have been undone by, so the record `forceDeleted` would
     * address is one HubSpot has already archived. The second request is at best wasted and at
     * worst a job that retries and fails (Codex, PR #49).
     *
     * Under `allow` on purpose: `guard` would issue nothing anyway, so it could not tell a fixed
     * implementation from a broken one.
     */
    public function test_purging_an_already_trashed_model_does_not_archive_a_second_time(): void
    {
        config()->set('hubspot.auto_sync.hard_delete', 'allow');

        Hubspot::fake();
        $lead = SoftDeletingLead::create(['email' => 'purged@example.com', 'first_name' => 'Ada']);

        Hubspot::fake();
        $lead->delete();
        Hubspot::assertRequestCount(1);

        Hubspot::fake();
        $log = Log::spy();
        $lead->forceDelete();

        Hubspot::assertRequestCount(0);
        $log->shouldHaveReceived('info', [
            Mockery::type('string'),
            [
                'model' => SoftDeletingLead::class,
                'model_id' => $lead->getKey(),
                'event' => 'forceDeleted',
                'action' => 'already-archived',
            ],
        ]);
    }

    /**
     * The same purge with `deleted` not opted in issues nothing and says nothing. A skip is only
     * worth logging once the consumer has asked for deletes to mirror at all.
     */
    public function test_purging_while_deleted_is_not_opted_in_stays_silent(): void
    {
        Hubspot::fake();
        $lead = SoftDeletingLead::create(['email' => 'quietpurge@example.com', 'first_name' => 'Ada']);
        $lead->delete();

        config()->set('hubspot.auto_sync.on', ['created', 'updated']);

        Hubspot::fake();
        $log = Log::spy();
        $lead->forceDelete();

        Hubspot::assertRequestCount(0);
        $log->shouldNotHaveReceived('info');
        $log->shouldNotHaveReceived('warning');
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
        $log = Log::spy();
        $lead->restore();

        Hubspot::assertRequestCount(0);
        $log->shouldHaveReceived('info');

        $link = HubspotObjectLink::query()->sole();

        self::assertTrue($link->is_stale, 'A restore must flag the link stale.');
        self::assertNotNull(
            $link->stale_at,
            'The flag carries a timestamp. `is_stale` alone says a link went stale but never when, '
            .'which is the first question anybody reading the row afterwards asks.'
        );
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
     * `on_restore => 'recreate'`, the opt-in that forks CRM history.
     *
     * The link row's own primary key is what proves the fork: an implementation that merely
     * re-synced onto the EXISTING link would keep writing to the archived HubSpot record and leave
     * the row's id unchanged. Recreating means the old link is dropped and a new one written, so
     * the id must move.
     */
    public function test_a_restore_under_recreate_drops_the_link_and_syncs_afresh(): void
    {
        config()->set('hubspot.auto_sync.on_restore', 'recreate');

        Hubspot::fake();
        $lead = SoftDeletingLead::create(['email' => 'forked@example.com', 'first_name' => 'Ada']);
        $lead->delete();

        $linkRowIdBefore = HubspotObjectLink::query()->value('id');

        Hubspot::fake();
        $log = Log::spy();
        $lead->restore();

        Hubspot::assertRequestCount(1);
        $log->shouldHaveReceived('warning');

        $link = HubspotObjectLink::query()->sole();

        self::assertNotSame(
            $linkRowIdBefore,
            $link->id,
            'A recreate must DROP the old link row rather than re-sync onto it -- re-syncing onto '
            .'the old row would write to the record that is already archived.'
        );
        self::assertFalse($link->is_stale, 'A freshly recreated link is not stale.');
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
        $log->shouldHaveReceived('info');
    }

    /**
     * The stale flag is set by a restore and cleared by the next successful write, and nothing
     * else clears it -- which `SyncsToHubspot::scopePendingHubspotSync()`'s own docblock already
     * assumed when 04-04 wrote the stale leg into that scope. Without the clear, a link goes stale
     * once, on the first restore, and every later successful sync still re-reports the model as
     * having work outstanding, forever (Codex, PR #49).
     */
    public function test_a_successful_resync_clears_the_stale_flag_a_restore_set(): void
    {
        Hubspot::fake();
        $lead = SoftDeletingLead::create(['email' => 'relinked@example.com', 'first_name' => 'Ada']);
        $lead->delete();
        $lead->restore();

        self::assertTrue(
            HubspotObjectLink::query()->sole()->is_stale,
            'The restore must have flagged the link stale, or this test proves nothing.'
        );

        Hubspot::fake();
        $lead->update(['first_name' => 'Bea']);

        $link = HubspotObjectLink::query()->sole();

        self::assertFalse($link->is_stale, 'A successful write to the stored id is the record being current again.');
        self::assertNull($link->stale_at, 'The timestamp goes with the flag it belongs to.');
        self::assertNotNull($link->synced_at);
    }

    /**
     * `recreate` means "sync this model afresh", and a restored model that never linked -- deleted
     * before its initial create sync ran -- needs exactly that. Sending it down the missing-link
     * guard left it permanently unsynced under the one setting whose entire purpose is to resync
     * it, and silently: D-17 suppresses the restore's own `updated` event, so nothing else would
     * ever dispatch for it (Codex, PR #49).
     *
     * `'created'` is left out of `auto_sync.on` here precisely so no link row is ever written.
     */
    public function test_a_restore_under_recreate_syncs_a_model_that_never_linked(): void
    {
        config()->set('hubspot.auto_sync.on', ['deleted']);
        config()->set('hubspot.auto_sync.on_restore', 'recreate');

        Hubspot::fake();
        $lead = SoftDeletingLead::create(['email' => 'neverlinked@example.com', 'first_name' => 'Ada']);
        $lead->delete();

        self::assertNull(HubspotObjectLink::query()->value('id'), 'Nothing may have linked yet.');

        Hubspot::fake();
        $lead->restore();

        Hubspot::assertRequestCount(1);
        self::assertNotNull(
            HubspotObjectLink::query()->value('id'),
            'The fresh sync must have written the link row the restore had none of.'
        );
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
