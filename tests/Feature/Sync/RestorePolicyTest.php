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
     * A restore repairs an initial sync that never happened (Codex, PR #49).
     *
     * The sequence is ordinary: a model created, soft-deleted before its queued initial sync ran,
     * and that job returning without creating anything because `SyncHubspotObjectJob` refuses to
     * bring a CRM record into existence for a trashed model. Nothing else would ever pick it up --
     * D-17 suppresses the restore's own `updated` event, and there is no link for anything to flag,
     * so the model stayed unsynced forever with auto-sync fully enabled.
     */
    public function test_a_restore_dispatches_the_initial_sync_its_deletion_skipped(): void
    {
        Hubspot::fake();
        $lead = SoftDeletingLead::create(['email' => 'skipped@example.com', 'first_name' => 'Ada']);

        // The initial sync never landed: exactly the state the trashed guard leaves behind.
        HubspotObjectLink::query()->delete();
        $lead->delete();

        Hubspot::fake();
        $log = Log::spy();
        $lead->restore();

        Hubspot::assertRequestCount(1);
        self::assertNotNull(
            HubspotObjectLink::query()->value('id'),
            'The restore must leave the model synced, not merely un-deleted.'
        );
        $log->shouldHaveReceived('info', [
            'A restored model has no HubSpot link, so the first sync this application configures is '
            .'being dispatched now. That sync was skipped because the model was already deleted by '
            .'the time its job ran.',
            [
                'model' => SoftDeletingLead::class,
                'model_id' => $lead->getKey(),
                'event' => 'restored',
                // Named, because `created` and `updated` are separately sufficient here and the
                // context is the only place the difference between them is observable.
                'action' => 'created',
            ],
        ]);
    }

    /**
     * `updated` initiates a first link exactly as `created` does, and gating the repair on `created`
     * alone lost it (Codex, PR #49).
     *
     * An application syncing on `['updated', 'deleted']` dispatches the same upserting job on an
     * ordinary edit, and that job CREATES the CRM record when no link exists yet. Delete the model
     * before the job runs and it skips for being trashed; the restore then refused to replay it
     * solely because `created` was absent from a list that had never been the question. The
     * configured update was permanently lost, with auto-sync fully enabled.
     */
    public function test_a_restore_dispatches_the_first_sync_an_update_would_have_made(): void
    {
        config()->set('hubspot.auto_sync.on', ['updated', 'deleted']);

        Hubspot::fake();
        $lead = SoftDeletingLead::create(['email' => 'nevercreated@example.com', 'first_name' => 'Ada']);
        $lead->delete();

        self::assertNull(HubspotObjectLink::query()->value('id'), 'The create must not have linked.');

        Hubspot::fake();
        $log = Log::spy();
        $lead->restore();

        Hubspot::assertRequestCount(1);
        self::assertNotNull(
            HubspotObjectLink::query()->value('id'),
            'The restore must leave the model synced, not merely un-deleted.'
        );
        $log->shouldHaveReceived('info', [
            'A restored model has no HubSpot link, so the first sync this application configures is '
            .'being dispatched now. That sync was skipped because the model was already deleted by '
            .'the time its job ran.',
            [
                'model' => SoftDeletingLead::class,
                'model_id' => $lead->getKey(),
                'event' => 'restored',
                // The event that WOULD have linked it, which is what makes the repair legitimate.
                // Asserted because it is the only thing separating this case from the `created` one.
                'action' => 'updated',
            ],
        ]);
    }

    /**
     * ...unless no configured event would ever have linked this model, in which case an absent link
     * is absent for an innocent reason and manufacturing one would create a CRM record nobody asked
     * for.
     *
     * `['deleted']` alone is the only shape that means that now: neither of the two events able to
     * bring a CRM record into existence is enabled.
     */
    public function test_a_restore_creates_nothing_when_no_configured_event_would_have_linked_it(): void
    {
        config()->set('hubspot.auto_sync.on', ['deleted']);

        Hubspot::fake();
        $lead = SoftDeletingLead::create(['email' => 'neverlinked@example.com', 'first_name' => 'Ada']);
        $lead->delete();

        self::assertNull(HubspotObjectLink::query()->value('id'));

        Hubspot::fake();
        $lead->restore();

        Hubspot::assertRequestCount(0);
        self::assertNull(HubspotObjectLink::query()->value('id'));
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
        self::assertFalse(
            HubspotObjectLink::query()->sole()->is_stale,
            'The link must be left exactly as the opt-out found it -- not even flagged.'
        );
    }
}
