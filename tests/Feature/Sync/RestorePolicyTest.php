<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Sync;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use ReyemTech\Hubspot\Facades\Hubspot;
use ReyemTech\Hubspot\Sync\HubspotObjectLink;
use ReyemTech\Hubspot\Sync\HubspotObserver;
use ReyemTech\Hubspot\Sync\RecreateHubspotObjectJob;
use ReyemTech\Hubspot\Tests\Support\Sync\DisabledSoftDeletingLead;
use ReyemTech\Hubspot\Tests\Support\Sync\SoftDeletingLead;
use ReyemTech\Hubspot\Tests\Support\Sync\SyncedLead;
use ReyemTech\Hubspot\Tests\Support\Sync\SyncTestCase;

/**
 * # A restore answers for an archive that already happened
 *
 * SYNC-04's other half, split from {@see DeletePolicyTest} when the two together outgrew the
 * 500-line file ceiling. The delete side decides whether to archive; this side decides what is owed
 * afterwards, and the two are deliberately gated differently.
 *
 * A restore cannot be mirrored -- HubSpot exposes no unarchive endpoint -- so `on_restore` chooses
 * between the two honest responses: `flag` keeps the stored id and marks the link stale, and
 * `recreate` drops the link and creates a NEW object, forking CRM history.
 *
 * ## Everything here keys on evidence, never on current config
 *
 * `archived_at` records that THIS PACKAGE archived a link. `hubspot.auto_sync.on` describes what
 * happens to deletes from now on, and a restore has to answer for one that already happened -- so
 * removing `deleted` from that list between the delete and the restore must not strand the record,
 * while a link this package never archived must not be flagged or forked at all. Three separate
 * defects came from reading the gate instead of the evidence (Codex, PR #49).
 *
 * The kill switch and the per-model `$hubspotAutoSync = false` DO still refuse: each is a statement
 * about whether this package acts for this model at all, rather than about which events mirror.
 */
mutates(HubspotObserver::class);

final class RestorePolicyTest extends SyncTestCase
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

        // `deleted` is opt-in and absent from the shipped default; the restore path does not read
        // this list at all, and one of the tests below is exactly about that.
        config()->set('hubspot.auto_sync.on', ['created', 'updated', 'deleted']);
    }

    /**
     * A restore after a delete that was never mirrored has nothing to respond to. The link points
     * at a LIVE HubSpot record, so flagging it stale would report a perfectly current record as
     * having sync work outstanding until some unrelated later write cleared it (Codex, PR #49).
     */
    public function test_a_restore_does_not_flag_a_link_this_package_never_archived(): void
    {
        config()->set('hubspot.auto_sync.on', ['created', 'updated']);

        Hubspot::fake();
        $lead = SoftDeletingLead::create(['email' => 'neverarchived@example.com', 'first_name' => 'Ada']);
        $lead->delete();

        config()->set('hubspot.auto_sync.on', ['created', 'updated', 'deleted']);

        Hubspot::fake();
        $log = Log::spy();
        $lead->restore();

        Hubspot::assertRequestCount(0);
        self::assertFalse(HubspotObjectLink::query()->sole()->is_stale);
        $log->shouldHaveReceived('info', [
            'A restored model was not flagged stale, because this package never archived its '
            .'HubSpot record. The link, if any, still points at a live record.',
            [
                'model' => SoftDeletingLead::class,
                'model_id' => $lead->getKey(),
                'event' => 'restored',
                'action' => 'flag-stale',
            ],
        ]);
    }

    /**
     * The same history under `recreate`, where getting it wrong is far more expensive: dropping a
     * link that still points at a live record and creating a SECOND object for the same model.
     */
    public function test_a_restore_does_not_recreate_over_a_link_this_package_never_archived(): void
    {
        config()->set('hubspot.auto_sync.on', ['created', 'updated']);
        config()->set('hubspot.auto_sync.on_restore', 'recreate');

        Hubspot::fake();
        $lead = SoftDeletingLead::create(['email' => 'stillvalid@example.com', 'first_name' => 'Ada']);
        $lead->delete();

        $linkRowIdBefore = HubspotObjectLink::query()->value('id');

        config()->set('hubspot.auto_sync.on', ['created', 'updated', 'deleted']);

        Hubspot::fake();
        $log = Log::spy();
        $lead->restore();

        Hubspot::assertRequestCount(0);
        $log->shouldHaveReceived('info', [
            'A restored model was not recreated, because this package never archived its HubSpot '
            .'record. Its existing link still points at a live record, and forking it would create '
            .'a duplicate.',
            [
                'model' => SoftDeletingLead::class,
                'model_id' => $lead->getKey(),
                'event' => 'restored',
                'action' => 'recreate',
            ],
        ]);
        self::assertSame(
            $linkRowIdBefore,
            HubspotObjectLink::query()->sole()->id,
            'The existing link is valid and must survive -- forking it would create a duplicate.'
        );
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
        $log->shouldHaveReceived('info', [
            'A restored model still points at an archived HubSpot record. HubSpot has no unarchive '
            .'endpoint, so its link row is now flagged stale and the stored id is kept.',
            [
                'model' => SoftDeletingLead::class,
                'model_id' => $lead->getKey(),
                'event' => 'restored',
                'action' => 'flag-stale',
            ],
        ]);

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
        $log->shouldHaveReceived('warning', [
            'A restored model is being recreated in HubSpot under hubspot.auto_sync.on_restore = '
            .'"recreate". The previously archived record is left archived and its id is dropped, '
            ."which forks this record's CRM history.",
            [
                'model' => SoftDeletingLead::class,
                'model_id' => $lead->getKey(),
                'event' => 'restored',
                'action' => 'recreate',
            ],
        ]);

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
     * The stale flag is set by a restore and cleared by the next successful write, and nothing else
     * clears it -- which `SyncsToHubspot::scopePendingHubspotSync()`'s own docblock already assumed
     * when 04-04 wrote the stale leg into that scope. Without the clear, a link goes stale once, on
     * the first restore, and every later successful sync still re-reports the model as having work
     * outstanding, forever (Codex, PR #49).
     *
     * The relink is what makes a successful write possible at all, and it is the scenario the
     * finding described: while `archived_at` stands, this package refuses to push to a record it
     * archived, so an operator pointing the link at a live record -- a new `hubspot_id`, and
     * `archived_at` cleared because that record was never archived by us -- is precisely the
     * "operator relinks the record and it syncs successfully" case. The flag has to come off then,
     * and the guard is what makes sure it cannot come off any other way.
     */
    public function test_a_successful_resync_clears_the_stale_flag_a_restore_set(): void
    {
        Hubspot::fake();
        $lead = SoftDeletingLead::create(['email' => 'relinked@example.com', 'first_name' => 'Ada']);
        $lead->delete();
        $lead->restore();

        $link = HubspotObjectLink::query()->sole();

        self::assertTrue($link->is_stale, 'The restore must have flagged the link stale.');
        self::assertNotNull($link->archived_at, 'The delete must have recorded its own archive.');

        // The operator relinks: a live HubSpot record, so this package's archive no longer applies.
        $link->update(['hubspot_id' => '424242', 'archived_at' => null]);

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
     * The recreate job's own race, and it is worse than the sync job's because this one CREATES
     * (Codex, PR #49). Queued is the default, so a model restored and then deleted again before the
     * worker runs arrives trashed -- and nothing would clean up what it created, since the observer
     * dropped the old link before dispatching and the intervening `trashed` found nothing to
     * archive.
     *
     * The job is invoked directly, after the deletion, to place the model in exactly the state the
     * worker would have found it in.
     */
    public function test_a_recreate_job_whose_model_was_deleted_again_creates_nothing(): void
    {
        config()->set('hubspot.auto_sync.on_restore', 'recreate');

        Hubspot::fake();
        $lead = SoftDeletingLead::create(['email' => 'reraced@example.com', 'first_name' => 'Ada']);
        $lead->delete();
        $lead->restore();
        $lead->delete();

        Hubspot::fake();
        $log = Log::spy();

        app()->call([new RecreateHubspotObjectJob($lead), 'handle']);

        Hubspot::assertRequestCount(0);
        $log->shouldHaveReceived('info');
    }

    /**
     * The restore response is owed for an archive that ALREADY happened, so removing `deleted` from
     * `auto_sync.on` between the delete and the restore must not strand the record (Codex, PR #49).
     *
     * Stranding is the exact outcome the gate produced: the link stays archived and unflagged, so
     * property pushes skip it because `archived_at` is set, while `pendingHubspotSync()` cannot
     * report it because the stale flag was never set. Nothing local would mention it again.
     */
    public function test_a_restore_still_responds_when_deletes_stopped_mirroring_after_the_archive(): void
    {
        Hubspot::fake();
        $lead = SoftDeletingLead::create(['email' => 'stranded@example.com', 'first_name' => 'Ada']);
        $lead->delete();

        self::assertNotNull(HubspotObjectLink::query()->sole()->archived_at);

        // The archive already happened; the application stops mirroring deletes only afterwards.
        config()->set('hubspot.auto_sync.on', ['created', 'updated']);

        Hubspot::fake();
        $lead->restore();

        self::assertTrue(
            HubspotObjectLink::query()->sole()->is_stale,
            'A link this package archived must still be flagged on restore, whatever the event '
            .'list says now -- otherwise nothing local ever reports it again.'
        );
    }

    /**
     * The kill switch is different from the event list and still applies: it is a statement about
     * the package as a whole, and `recreate` reaches the API.
     */
    public function test_a_restore_does_nothing_while_auto_sync_is_disabled(): void
    {
        Hubspot::fake();
        $lead = SoftDeletingLead::create(['email' => 'killswitch@example.com', 'first_name' => 'Ada']);
        $lead->delete();

        config()->set('hubspot.auto_sync.enabled', false);

        Hubspot::fake();
        $lead->restore();

        Hubspot::assertRequestCount(0);
        self::assertFalse(HubspotObjectLink::query()->sole()->is_stale);
    }

    /**
     * The race that makes a recreate create a DUPLICATE, and it needs no failure to happen: under
     * the default queued `created` sync a model can be created, soft-deleted and restored before
     * its initial `SyncHubspotObjectJob` runs. The restore sees no link and queues a recreate; the
     * older sync then upserts and writes a live link; the recreate creates a second ACTIVE object
     * and overwrites the link with its id, orphaning the first (Codex, PR #49).
     *
     * The link is written directly here because that is exactly what the older sync job would have
     * done between the observer's decision and this job running.
     */
    public function test_a_recreate_job_creates_nothing_once_a_link_exists_again(): void
    {
        config()->set('hubspot.auto_sync.on_restore', 'recreate');

        Hubspot::fake();
        $lead = SoftDeletingLead::create(['email' => 'raced-again@example.com', 'first_name' => 'Ada']);

        // Whatever the restore decided, another sync has linked this model since.
        $linkIdBefore = HubspotObjectLink::query()->sole()->id;

        Hubspot::fake();
        $log = Log::spy();

        app()->call([new RecreateHubspotObjectJob($lead), 'handle']);

        Hubspot::assertRequestCount(0);
        $log->shouldHaveReceived('info');
        self::assertSame(
            $linkIdBefore,
            HubspotObjectLink::query()->sole()->id,
            'The existing link must survive untouched -- overwriting it orphans the record it names.'
        );
    }

    /**
     * A recreate is never retried, because a create is not idempotent and this one cannot be made
     * so: a lost response is indistinguishable from a failed request, and repeating it produces two
     * ACTIVE CRM objects for one model (Codex, PR #49). `SyncHubspotObjectJob` upserts and so does
     * not have this problem; this is the one path where converging is the wrong answer, so it gives
     * up the retry instead.
     */
    public function test_the_recreate_job_is_never_retried(): void
    {
        self::assertSame(
            1,
            (new RecreateHubspotObjectJob(new SoftDeletingLead(['email' => 'once@example.com'])))->tries,
            'A retried create is a duplicate CRM object, and nothing durable tells a lost response '
            .'from a failed request.'
        );
    }

    /**
     * `$hubspotAutoSync = false` means never auto-sync this model, and that outranks the evidence
     * an archived link carries (Codex, PR #49). Regating the restore on the kill switch alone --
     * which was the right fix for the event list -- dropped this per-model statement with it, so an
     * operator who opted a model out AFTER it was archived could still have a new CRM object
     * created for it by a restore.
     */
    public function test_a_restore_refuses_when_the_model_itself_opted_out(): void
    {
        config()->set('hubspot.auto_sync.on_restore', 'recreate');

        // Archived while the model still synced: the link is written by hand because the model's
        // own override means no observer path would ever create one.
        $lead = DisabledSoftDeletingLead::create(['email' => 'optedout@example.com', 'first_name' => 'Ada']);
        HubspotObjectLink::query()->create([
            'lookup_hash' => HubspotObjectLink::lookupHashFor($lead->getMorphClass()),
            'model_id' => (string) $lead->id,
            'object_type' => 'contacts',
            'model_type' => $lead->getMorphClass(),
            'hubspot_id' => '9001',
            'archived_at' => now(),
        ]);
        $lead->delete();

        Hubspot::fake();
        $lead->restore();

        Hubspot::assertRequestCount(0);
        self::assertNotNull(
            HubspotObjectLink::query()->sole()->archived_at,
            'The link must be left exactly as the opt-out found it.'
        );
    }

    /**
     * The race the entry guard cannot close: a soft delete landing between that check and the
     * `create()` leaves an ACTIVE HubSpot object behind a deleted local model, and no `trashed`
     * handler cleans it up because the observer already dropped the link that handler looks for
     * (Codex, PR #49).
     *
     * A lock would have to be taken by the DELETE path too -- an exclusion one side observes is not
     * exclusion -- so every delete of every bound model would pay for one opt-in restore policy.
     * The state is made to CONVERGE instead: the model is re-read without scopes after the create,
     * and a row that came back deleted has the object archived immediately.
     *
     * The race is reproduced deterministically by deleting the row underneath an in-memory model
     * that still believes it is live, which is exactly what the worker would be holding.
     */
    public function test_a_create_that_raced_a_delete_archives_what_it_just_created(): void
    {
        Hubspot::fake();
        $lead = SoftDeletingLead::create(['email' => 'raced-delete@example.com', 'first_name' => 'Ada']);
        HubspotObjectLink::query()->delete();

        // The delete lands in another request while this job holds a live in-memory model.
        SoftDeletingLead::query()->whereKey($lead->getKey())->update(['deleted_at' => now()]);

        Hubspot::fake();
        $log = Log::spy();

        app()->call([new RecreateHubspotObjectJob($lead), 'handle']);

        // The create, then the archive that converges on the delete it raced.
        Hubspot::assertRequestCount(2);
        $log->shouldHaveReceived('warning');
        self::assertNotNull(
            HubspotObjectLink::query()->sole()->archived_at,
            'The link must record the archive, or the next delete would archive an archived record.'
        );
    }
}
