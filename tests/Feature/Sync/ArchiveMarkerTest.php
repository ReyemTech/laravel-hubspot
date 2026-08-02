<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Sync;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use ReyemTech\Hubspot\Facades\Hubspot;
use ReyemTech\Hubspot\Sync\HubspotObjectLink;
use ReyemTech\Hubspot\Sync\HubspotObserver;
use ReyemTech\Hubspot\Tests\Support\Sync\SoftDeletingLead;
use ReyemTech\Hubspot\Tests\Support\Sync\SyncTestCase;
use Throwable;

/**
 * # The marker, the archive and their cleanup are ONE deferred unit
 *
 * Split from {@see DeletePolicyTest} when the two together outgrew the 500-line file ceiling, on the
 * seam the findings themselves drew. That file decides WHETHER to archive; this one is about what
 * `archived_at` promises once the decision is made.
 *
 * `archived_at` is what every read path downstream of a delete trusts -- `SyncHubspotObjectJob`
 * skips a link carrying it, a restore flags one carrying it, and a later delete declines to archive
 * twice on its strength. A marker describing an archive that never happened therefore does not
 * merely mislead: it removes a live model from every sync path there is, silently. Findings 18 and
 * 23-27 on PR #49 are all one seam of that, and every test here is one of them.
 *
 * | property | what it buys |
 * |---|---|
 * | after commit | a rolled-back delete archives nothing; the archive is irreversible |
 * | together | the marker cannot outlive the archive, nor the archive the marker |
 * | marker first *within* | a restore racing the request still sees an archive was issued |
 * | catch inside | publication happens in the callback, not where it was registered |
 * | recheck first | a delete taken back before its own commit archives nothing at all |
 *
 * `queue => false` is honoured throughout rather than overridden: it asks for the call to happen in
 * the request, not for it to happen before the delete is real.
 */
mutates(HubspotObserver::class);

final class ArchiveMarkerTest extends SyncTestCase
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
        // delete to reach HubSpot has to turn it on.
        config()->set('hubspot.auto_sync.on', ['created', 'updated', 'deleted']);
    }

    /**
     * The archive evidence is written BEFORE the request goes out, and this test proves the order
     * rather than the outcome (Codex, PR #49).
     *
     * With `auto_sync.queue => false` the dispatch performs the HubSpot call inline, so stamping
     * afterwards leaves a window in which a concurrent restore reads a null marker, concludes
     * nothing was archived, and leaves the link current -- after which the archive completes and
     * stamps it, leaving a link that is archived but never flagged. Pushes skip it because
     * `archived_at` is set; `pendingHubspotSync()` cannot report it because it is not stale.
     *
     * The gateway is replaced only for the delete, so the record is read at exactly the moment the
     * archive is in flight -- which is the moment a racing restore would have read it.
     */
    public function test_the_archive_marker_is_written_before_the_request_goes_out(): void
    {
        config()->set('hubspot.auto_sync.queue', false);
        config()->set('hubspot.auto_sync.hard_delete', 'allow');

        Hubspot::fake();
        $lead = SoftDeletingLead::create(['email' => 'ordered@example.com', 'first_name' => 'Ada']);

        // A fresh fake, so the request log holds only what the delete itself sends.
        $fake = Hubspot::fake();

        // How many requests had gone out by the time the marker was written. Read from the query
        // log rather than from a mocked gateway: this asserts the ORDER of the two real operations,
        // which is the whole of the finding, and needs no test double to do it.
        $requestsSentWhenMarked = null;

        DB::listen(function (QueryExecuted $query) use ($fake, &$requestsSentWhenMarked): void {
            if ($requestsSentWhenMarked === null && str_contains($query->sql, 'archived_at')) {
                $requestsSentWhenMarked = count($fake->recordedRequests());
            }
        });

        $lead->delete();

        self::assertSame(
            0,
            $requestsSentWhenMarked,
            'A restore racing this archive must be able to see that an archive was issued before '
            .'the request goes out, or it leaves the link archived and unflagged -- invisible to '
            .'every read path there is.'
        );
    }

    /**
     * The marker is taken back when publication fails, and this is the half that makes writing it
     * first safe (Codex, PR #49). A marker left behind by a failed dispatch makes a repeated delete
     * SKIP -- it says this package already archived -- while ordinary pushes refuse the link for
     * the same reason, so a still-live HubSpot record could only be recovered by editing the link
     * row by hand.
     *
     * `queue => false` makes the failure synchronous, which is the configuration where a caller
     * sees it at all.
     */
    public function test_a_failed_archive_takes_its_marker_back(): void
    {
        config()->set('hubspot.auto_sync.queue', false);
        config()->set('hubspot.auto_sync.hard_delete', 'allow');

        Hubspot::fake();
        $lead = SoftDeletingLead::create(['email' => 'failedarchive@example.com', 'first_name' => 'Ada']);

        Hubspot::fake(['contacts' => Hubspot::response(['message' => 'boom'], 500)]);

        // Caught as Throwable rather than by class: the exception travels out of an Eloquent event
        // handler, and PHPStan reads `Model::delete()` as throwing nothing at all.
        $reachedTheCaller = false;

        try {
            $lead->delete();
        } catch (Throwable) {
            $reachedTheCaller = true;
        }

        self::assertTrue($reachedTheCaller, 'A synchronous archive failure must reach the caller.');
        self::assertNull(
            HubspotObjectLink::query()->sole()->archived_at,
            'A marker that outlived its failed archive would make every later delete skip and '
            .'every later push refuse, leaving a live record reachable only by hand.'
        );
    }

    /**
     * ...and it takes it back through an UNSCOPED query, so an application's own global scope
     * cannot strand the marker (Codex, PR #65).
     *
     * `HubspotObjectLink` is a public model, and an application may put a global scope on it --
     * `whereNull('archived_at')` being the obvious one, since a scope that hides archived links is
     * the natural thing to write. Stamping the marker is precisely what makes the row stop matching
     * that scope, so a SCOPED withdrawal matches zero rows and silently updates nothing. The
     * exception still reaches the caller, so nothing looks wrong, while `archived_at` stays set on a
     * record that was never archived -- removing a live model from every sync path there is.
     *
     * The synchronous path on `main` used `newQueryWithoutScopes()` for exactly this reason. Giving
     * the marker one owner is only an improvement if that owner keeps the STRONGER of the two
     * behaviours it replaced; this test is what holds it to that.
     */
    public function test_a_failed_archive_takes_its_marker_back_through_an_application_global_scope(): void
    {
        config()->set('hubspot.auto_sync.queue', false);
        config()->set('hubspot.auto_sync.hard_delete', 'allow');

        Hubspot::fake();
        $lead = SoftDeletingLead::create(['email' => 'scopedarchive@example.com', 'first_name' => 'Ada']);

        // Registered AFTER the link exists, so the scope is in force for the withdrawal alone --
        // exactly how an application's own boot-time scope would behave here.
        HubspotObjectLink::addGlobalScope('unarchived', function ($query): void {
            $query->whereNull('archived_at');
        });

        try {
            Hubspot::fake(['contacts' => Hubspot::response(['message' => 'boom'], 500)]);

            try {
                $lead->delete();
            } catch (Throwable) {
                // The failure reaching the caller is the subject of the test above.
            }

            $row = DB::table('hubspot_object_links')->sole();

            self::assertNull(
                $row->archived_at,
                'A scoped withdrawal matches zero rows once the marker itself has hidden the link, '
                .'so the marker outlives the failed archive with nothing to report it.'
            );
        } finally {
            // The scope lives on the MODEL CLASS, which outlives this test's container.
            HubspotObjectLink::clearBootedModels();
        }
    }

    /**
     * A soft delete UNDONE before its own transaction commits archives nothing (Codex, PR #49).
     *
     * Deferring the archive to commit is what makes this reachable. `restored()` runs inside the
     * transaction, sees a link carrying no marker -- there is none yet, by design -- and correctly
     * declines to flag it. The transaction then commits and the deferred callback archives the
     * HubSpot record of a model that is live again, and nothing later repairs it: no further event
     * fires, and `archived_at` then removes the model from every sync path there is.
     *
     * Only `trashed` can be undone this way. A hard delete's row is gone for good, which is why the
     * recheck is asked of that event alone.
     */
    public function test_a_restore_inside_the_deleting_transaction_cancels_the_deferred_archive(): void
    {
        Hubspot::fake();
        $lead = SoftDeletingLead::create(['email' => 'undeleted@example.com', 'first_name' => 'Ada']);

        Hubspot::fake();
        $log = Log::spy();

        DB::transaction(function () use ($lead): void {
            $lead->delete();
            $lead->restore();
        });

        Hubspot::assertRequestCount(0);
        $log->shouldHaveReceived('info', [
            'A soft-deleted model was restored before its delete committed, so the archive that '
            .'delete had scheduled was cancelled. Nothing was sent to HubSpot and the link is '
            .'untouched.',
            [
                'model' => SoftDeletingLead::class,
                'model_id' => $lead->getKey(),
                'event' => 'trashed',
                'action' => 'archive-cancelled',
            ],
        ]);

        $link = HubspotObjectLink::query()->sole();
        self::assertNull(
            $link->archived_at,
            'A marker for an archive that was cancelled makes every later push refuse the link and '
            .'every later delete skip it.'
        );
        self::assertFalse($link->is_stale, 'Nothing was archived, so nothing is stale.');
        self::assertFalse(
            $lead->fresh()?->trashed() ?? true,
            'The model must be live again -- the whole premise of the cancellation.'
        );
    }

    /**
     * The committed case, so the test above cannot pass merely because a restore in the same
     * transaction stops the delete from ever being seen.
     */
    public function test_a_delete_committed_without_a_restore_still_archives(): void
    {
        Hubspot::fake();
        $lead = SoftDeletingLead::create(['email' => 'committedsoft@example.com', 'first_name' => 'Ada']);

        Hubspot::fake();

        DB::transaction(function () use ($lead): void {
            $lead->delete();
        });

        Hubspot::assertRequestCount(1);
        self::assertNotNull(HubspotObjectLink::query()->sole()->archived_at);
    }

    /**
     * A failed archive takes back the stale flag its own marker caused, not merely the marker
     * (Codex, PR #49).
     *
     * With `queue => false` the request goes out inline, so a restore racing it reads the marker
     * this callback has just written, concludes an archive was issued and flags the link stale. The
     * request then fails and the archive never happened -- so a cleanup that clears only
     * `archived_at` leaves a LIVE HubSpot record reported as stale by `pendingHubspotSync()` for
     * ever, with nothing to clear it.
     *
     * The race is made deterministic by firing the restore from the marker's own query, which is
     * precisely the window the finding describes. The flag is restored to what it was before the
     * marker rather than blanked, so a stale flag that was already there for some other reason
     * survives a failure it had nothing to do with.
     */
    public function test_a_failed_archive_takes_back_the_stale_flag_its_own_marker_caused(): void
    {
        config()->set('hubspot.auto_sync.queue', false);

        Hubspot::fake();
        $lead = SoftDeletingLead::create(['email' => 'racedfail@example.com', 'first_name' => 'Ada']);

        Hubspot::fake(['contacts' => Hubspot::response(['message' => 'boom'], 500)]);

        $restoreRaced = false;

        DB::listen(function (QueryExecuted $query) use (&$restoreRaced, $lead): void {
            if ($restoreRaced || ! str_contains($query->sql, 'archived_at')) {
                return;
            }

            // Set before restoring, so the restore's own queries cannot re-enter this.
            $restoreRaced = true;
            $lead->restore();
        });

        try {
            $lead->delete();
        } catch (Throwable) {
            // The failure reaching the caller is what the test above already pins; this one is
            // about the row it leaves behind.
        }

        self::assertTrue($restoreRaced, 'The racing restore must actually have run.');

        $link = HubspotObjectLink::query()->sole();
        self::assertNull($link->archived_at);
        self::assertFalse(
            $link->is_stale,
            'The archive failed, so the record is still live -- reporting it stale is work no '
            .'later write would ever clear.'
        );
        self::assertNull($link->stale_at);
    }

    /**
     * An INLINE archive waits for the commit too (Codex, PR #49). `queue => false` asks for the API
     * call to happen in the request, not for it to happen before the delete is real -- and the
     * archive is irreversible, so issuing it inside a transaction that then rolls back leaves a
     * live local model whose HubSpot record is gone, with no marker to show for it.
     *
     * Deferring costs nothing a consumer asked for: the call still happens in the request, at
     * commit rather than before it.
     */
    public function test_an_inline_archive_waits_for_the_delete_to_commit(): void
    {
        config()->set('hubspot.auto_sync.queue', false);
        config()->set('hubspot.auto_sync.hard_delete', 'allow');

        Hubspot::fake();
        $lead = SoftDeletingLead::create(['email' => 'rolledback@example.com', 'first_name' => 'Ada']);

        Hubspot::fake();

        DB::beginTransaction();
        $lead->delete();
        DB::rollBack();

        Hubspot::assertRequestCount(0);
        self::assertNull(HubspotObjectLink::query()->sole()->archived_at);
    }

    /**
     * The committed case, so the test above cannot pass merely because an inline archive never
     * fires at all.
     */
    public function test_an_inline_archive_fires_once_the_delete_commits(): void
    {
        config()->set('hubspot.auto_sync.queue', false);
        config()->set('hubspot.auto_sync.hard_delete', 'allow');

        Hubspot::fake();
        $lead = SoftDeletingLead::create(['email' => 'committedinline@example.com', 'first_name' => 'Ada']);

        Hubspot::fake();

        DB::beginTransaction();
        $lead->delete();
        DB::commit();

        Hubspot::assertRequestCount(1);
        self::assertNotNull(HubspotObjectLink::query()->sole()->archived_at);
    }

    /**
     * Publication failing at COMMIT still takes the marker back. The `catch` lives inside the
     * deferred callback for exactly this reason: a `try` wrapped around the registration would have
     * finished long before the push ran (Codex, PR #49).
     */
    public function test_a_publication_that_fails_at_commit_takes_its_marker_back(): void
    {
        config()->set('hubspot.auto_sync.queue', false);
        config()->set('hubspot.auto_sync.hard_delete', 'allow');

        Hubspot::fake();
        $lead = SoftDeletingLead::create(['email' => 'failedatcommit@example.com', 'first_name' => 'Ada']);

        Hubspot::fake(['contacts' => Hubspot::response(['message' => 'boom'], 500)]);

        $reachedTheCaller = false;

        DB::beginTransaction();
        $lead->delete();

        try {
            DB::commit();
        } catch (Throwable) {
            $reachedTheCaller = true;
        }

        self::assertTrue($reachedTheCaller, 'A failure at commit must reach the caller.');
        self::assertNull(
            HubspotObjectLink::query()->sole()->archived_at,
            'A marker left by a publication that failed at commit would be as permanent as one '
            .'left by any other failed publication.'
        );
    }
}
