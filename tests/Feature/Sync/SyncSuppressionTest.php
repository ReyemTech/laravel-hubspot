<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Sync;

use Closure;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use ReyemTech\Hubspot\Facades\Hubspot;
use ReyemTech\Hubspot\HubspotManager;
use ReyemTech\Hubspot\Sync\ArchiveHubspotObjectJob;
use ReyemTech\Hubspot\Sync\ArchiveMarker;
use ReyemTech\Hubspot\Sync\HubspotObjectLink;
use ReyemTech\Hubspot\Sync\SyncGate;
use ReyemTech\Hubspot\Sync\SyncHubspotObjectJob;
use ReyemTech\Hubspot\Tests\Support\Sync\SoftDeletingLead;
use ReyemTech\Hubspot\Tests\Support\Sync\SyncedLead;
use ReyemTech\Hubspot\Tests\Support\Sync\SyncTestCase;
use RuntimeException;

/**
 * # Two escape hatches, and the reason there are two
 *
 * SYNC-05, and ROADMAP SC5's last clause.
 *
 * `Hubspot::withoutSyncing()` is IN-PROCESS and stops a dispatch. `HUBSPOT_DISABLED` is read from
 * CONFIG on both sides of the queue boundary, so it also stops the job when a worker picks it up.
 * Neither substitutes for the other:
 *
 * | hatch | stops | cannot stop |
 * |---|---|---|
 * | `withoutSyncing()` | the dispatch, in this process | a job already on the queue |
 * | `HUBSPOT_DISABLED` | the dispatch AND the worker | a daemon that has not restarted |
 *
 * Without the first, `migrate:fresh --seed` fires thousands of API calls. Without the second,
 * nothing stops the jobs already sitting on the queue.
 *
 * The second is smaller than it first looks, and the tests say so rather than overselling it:
 * `Config::get()` re-reads the running PROCESS's repository, so a `queue:work` daemon keeps what it
 * booted with and `queue:restart` is part of flipping the switch.
 *
 * ## Suppression has to stop the DISPATCH, not the request
 *
 * Asserting only `assertRequestCount(0)` would pass against an implementation that queued every job
 * and merely refused at the far end -- which leaves a backlog that fires the moment the queue
 * drains. That is precisely what a seeder is protected from, so the seeding test asserts BOTH that
 * nothing was pushed and that nothing was sent.
 */
mutates(SyncGate::class);
mutates(HubspotManager::class);

final class SyncSuppressionTest extends SyncTestCase
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

        config()->set('hubspot.auto_sync.on', ['created', 'updated', 'deleted']);
    }

    /**
     * ROADMAP SC5, named: a seeding run inside the closure pushes nothing and sends nothing.
     *
     * Both assertions are load-bearing. See this file's docblock -- request count alone passes
     * against an implementation that leaves a queue backlog.
     */
    public function test_a_seeding_run_inside_without_syncing_pushes_nothing_and_sends_nothing(): void
    {
        Hubspot::fake();
        Bus::fake();

        Hubspot::withoutSyncing(function (): void {
            foreach (range(1, 5) as $i) {
                SyncedLead::create(['email' => "seed{$i}@example.com", 'first_name' => 'Ada']);
            }
        });

        Bus::assertNothingDispatched();
        Hubspot::assertRequestCount(0);
        self::assertSame(5, SyncedLead::query()->count(), 'The models themselves must still be created.');
    }

    /**
     * Suppression is scoped to the callback and nothing else.
     */
    public function test_syncing_resumes_after_the_callback_returns(): void
    {
        Hubspot::fake();

        Hubspot::withoutSyncing(function (): void {
            SyncedLead::create(['email' => 'inside@example.com', 'first_name' => 'Ada']);
        });

        Hubspot::assertRequestCount(0);

        SyncedLead::create(['email' => 'outside@example.com', 'first_name' => 'Ada']);

        Hubspot::assertRequestCount(1);
    }

    /**
     * A `try { $flag = true; ... } finally { $flag = false; }` is wrong in two ways, and this is the
     * first: an exception must restore the previous state on its way out, and must reach the caller
     * unchanged.
     */
    public function test_a_throwing_callback_restores_syncing_and_propagates_unchanged(): void
    {
        Hubspot::fake();

        // The MESSAGE is captured rather than the exception object, and asserted rather than merely
        // checked for null. PHPStan proves the closure always throws, so `assertNotNull` on the
        // caught exception is tautological and it says so; comparing the message covers both halves
        // of the claim -- that it reached the caller, and that it arrived unchanged.
        $message = null;

        try {
            Hubspot::withoutSyncing(function (): void {
                throw new RuntimeException('the callback exploded');
            });
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();
        }

        self::assertSame('the callback exploded', $message);

        SyncedLead::create(['email' => 'afterthrow@example.com', 'first_name' => 'Ada']);

        Hubspot::assertRequestCount(1);
    }

    /**
     * ...and this is the second: clearing the flag rather than restoring the SAVED value
     * un-suppresses at the inner call's exit while the outer call is still running.
     */
    public function test_nesting_stays_suppressed_until_the_outer_call_exits(): void
    {
        Hubspot::fake();

        Hubspot::withoutSyncing(function (): void {
            Hubspot::withoutSyncing(function (): void {
                SyncedLead::create(['email' => 'inner@example.com', 'first_name' => 'Ada']);
            });

            // The inner call has returned. A naive implementation is now un-suppressed.
            SyncedLead::create(['email' => 'betweeen@example.com', 'first_name' => 'Ada']);
        });

        Hubspot::assertRequestCount(0);
    }

    /**
     * The default, asserted directly rather than only through its consequences.
     *
     * `true` here would suppress every sync in the process from boot -- a package that silently
     * does nothing -- so the default is a behaviour and gets a test of its own.
     */
    public function test_syncing_is_not_suppressed_before_any_block_is_opened(): void
    {
        self::assertFalse(app(HubspotManager::class)->syncingSuppressed());
    }

    public function test_without_syncing_returns_the_callbacks_own_value(): void
    {
        Hubspot::fake();

        $returned = Hubspot::withoutSyncing(fn (): string => 'the callback said this');

        self::assertSame('the callback said this', $returned);
    }

    /**
     * The DELETE path is suppressed too, and this case did not exist when 04-07 was planned
     * (04-06 added four handlers after it). An archive cannot be undone through the HubSpot API, so
     * an archive that escapes a suppression block is the one failure in this file with no recovery.
     */
    public function test_without_syncing_suppresses_the_archive_a_delete_would_have_issued(): void
    {
        Hubspot::fake();
        $lead = SoftDeletingLead::create(['email' => 'suppressdelete@example.com', 'first_name' => 'Ada']);

        Hubspot::fake();
        Bus::fake();

        Hubspot::withoutSyncing(function () use ($lead): void {
            $lead->delete();
        });

        Bus::assertNotDispatched(ArchiveHubspotObjectJob::class);
        Hubspot::assertRequestCount(0);
        self::assertNull(
            HubspotObjectLink::query()->sole()->archived_at,
            'A suppressed delete must leave no archive marker: the marker is what every later read '
            .'path trusts, and it would report an archive that never happened.'
        );
    }

    /**
     * Suppression gates the DISPATCH, and local bookkeeping keeps running (Codex, PR #56).
     *
     * An earlier revision of this test asserted the opposite, and it was wrong. `flagStale()` writes
     * to this package's own table; it makes no outbound call, so neither escape hatch has any
     * business stopping it. Suppressing it left `archived_at` set with `is_stale` FALSE -- and that
     * pair is the silent stranding this package has fought all through 04-06: property pushes skip a
     * link carrying `archived_at`, while `pendingHubspotSync()` cannot report one that is not stale.
     * The restored model would have been invisible to every sync path even after syncing was
     * switched back on.
     */
    public function test_a_suppressed_restore_still_keeps_its_local_bookkeeping(): void
    {
        Hubspot::fake();
        $lead = SoftDeletingLead::create(['email' => 'suppressrestore@example.com', 'first_name' => 'Ada']);
        $lead->delete();

        self::assertNotNull(
            HubspotObjectLink::query()->sole()->archived_at,
            'The delete must actually have archived, or the restore has nothing to respond to.'
        );

        Hubspot::fake();
        Bus::fake();

        Hubspot::withoutSyncing(function () use ($lead): void {
            $lead->restore();
        });

        Hubspot::assertRequestCount(0);
        Bus::assertNothingDispatched();
        self::assertTrue(
            HubspotObjectLink::query()->sole()->is_stale,
            'archived_at set with is_stale false is invisible to every sync path there is -- pushes '
            .'skip it and pendingHubspotSync() cannot report it.'
        );
    }

    /**
     * The half that IS suppressed: a restore with no link at all would dispatch a first sync, and
     * that is an outbound call, so both hatches stop it.
     */
    public function test_a_suppressed_restore_dispatches_no_first_sync(): void
    {
        Hubspot::fake();
        $lead = SoftDeletingLead::create(['email' => 'suppressfirstsync@example.com', 'first_name' => 'Ada']);

        // The state the trashed guard leaves behind: deleted before the initial sync ever landed.
        HubspotObjectLink::query()->delete();
        $lead->delete();

        Hubspot::fake();
        Bus::fake();

        Hubspot::withoutSyncing(function () use ($lead): void {
            $lead->restore();
        });

        Bus::assertNothingDispatched();
        Hubspot::assertRequestCount(0);
        self::assertNull(HubspotObjectLink::query()->value('id'));
    }

    public function test_the_kill_switch_stops_a_dispatch(): void
    {
        Hubspot::fake();
        Bus::fake();

        config()->set('hubspot.disabled', true);

        SyncedLead::create(['email' => 'killed@example.com', 'first_name' => 'Ada']);

        Bus::assertNothingDispatched();
        Hubspot::assertRequestCount(0);
    }

    /**
     * The case `withoutSyncing()` cannot cover, and the entire reason there are two hatches: a job
     * already sitting on the queue must not fire when a worker reaches it. Suppression is in-process
     * state that does not survive that boundary; the kill switch is read from config on both sides.
     *
     * What this models is a worker whose CONFIG says disabled, which is the state after
     * `queue:restart`. It deliberately does not claim more: `Config::get()` re-reads the running
     * process's own repository, so editing `.env` alone never reaches a `queue:work` daemon that is
     * already up -- see {@see SyncGate}'s docblock, which states that limit rather than implying it
     * away.
     */
    public function test_the_kill_switch_stops_a_job_that_was_already_queued(): void
    {
        Hubspot::fake();
        $lead = SyncedLead::create(['email' => 'alreadyqueued@example.com', 'first_name' => 'Ada']);

        $job = new SyncHubspotObjectJob($lead);

        Hubspot::fake();
        config()->set('hubspot.disabled', true);

        $log = Log::spy();

        app()->call([$job, 'handle']);

        Hubspot::assertRequestCount(0);
        $log->shouldHaveReceived('info', [
            'A HubSpot sync was skipped on the worker because syncing is switched off. '
            .'hubspot.disabled is true, so this job was queued before the switch was flipped.',
            ['model' => SyncedLead::class, 'model_id' => $lead->getKey()],
        ]);
    }

    /**
     * The archive job re-checks for the same reason the sync job does. It is listed separately
     * because it carries no model and takes a different path into the gateway.
     */
    public function test_the_kill_switch_stops_an_archive_job_that_was_already_queued(): void
    {
        Hubspot::fake();

        $job = new ArchiveHubspotObjectJob('contacts', '9001');

        config()->set('hubspot.disabled', true);

        app()->call([$job, 'handle']);

        Hubspot::assertRequestCount(0);
    }

    /**
     * A suppressed archive takes its OWN marker back, and this is the failure mode that makes the
     * worker-side gate dangerous rather than merely cautious (Codex, PR #56).
     *
     * `HubspotObserver::archive()` stamps `archived_at` BEFORE dispatching, so a restore racing the
     * request can see an archive was issued. If the job then completes without archiving anything,
     * that stamp describes an archive that never happened -- and every read path downstream of a
     * delete trusts it. Property pushes skip the link, later deletes decline to archive twice on its
     * strength, and `pendingHubspotSync()` cannot report it. The kill switch an operator flipped to
     * be careful would permanently and silently strand a live HubSpot record.
     *
     * A refused archive and a failed one leave the same truth behind, so they leave the same row
     * behind.
     */
    public function test_a_suppressed_archive_takes_its_own_marker_back(): void
    {
        Hubspot::fake();
        $lead = SoftDeletingLead::create(['email' => 'strandedmarker@example.com', 'first_name' => 'Ada']);

        // Deleted through the query builder, so the observer does not run its own archive path: this
        // test is about the job the observer ALREADY dispatched, picked up by a worker later.
        SoftDeletingLead::query()->whereKey($lead->id)->update(['deleted_at' => now()]);

        // Exactly what the observer does between deciding to archive and the worker picking the job
        // up: stamp() writes the marker and hands back the thing that can take it back.
        $link = HubspotObjectLink::query()->sole();
        $job = new ArchiveHubspotObjectJob('contacts', $link->hubspot_id, ArchiveMarker::stamp($link));

        Hubspot::fake();
        $log = Log::spy();
        config()->set('hubspot.disabled', true);

        app()->call([$job, 'handle']);

        Hubspot::assertRequestCount(0);
        // Verbatim, which is this repository's answer to Concat mutators on a long message: the
        // wording is the operator's only notice that local and remote state have diverged.
        $log->shouldHaveReceived('warning', [
            'A HubSpot archive was skipped on the worker because syncing is switched off, and its '
            .'archive marker has been taken back. The local row is deleted and the HubSpot record '
            .'is still live; nothing will revisit it while hubspot.disabled is true.',
            ['object_type' => 'contacts', 'hubspot_id' => $link->hubspot_id, 'link_id' => $link->id],
        ]);
        self::assertNull(
            HubspotObjectLink::query()->sole()->archived_at,
            'A marker left behind by a suppressed archive removes a LIVE record from every sync '
            .'path there is, and nothing later revisits it.'
        );
        self::assertSame(
            0,
            SoftDeletingLead::query()->count(),
            'The local row stays deleted -- the divergence is real, which is why it is logged at '
            .'warning rather than info.'
        );
    }

    /**
     * The withdrawal restores the stale flag too, not just the marker (Codex, PR #56).
     *
     * A restore landing between this job's dispatch and a disabled worker picking it up sees the
     * marker, concludes an archive was issued and flags the link. Clearing only `archived_at` would
     * leave a live local model pointing at a live HubSpot record while `pendingHubspotSync()`
     * reported it stale for ever, with nothing to clear it. The synchronous failure path already
     * restores the snapshot; a refusal is the same truth as a failure, so it restores the same row.
     */
    public function test_a_suppressed_archive_restores_the_stale_flag_a_racing_restore_set(): void
    {
        Hubspot::fake();
        SoftDeletingLead::create(['email' => 'racedsuppression@example.com', 'first_name' => 'Ada']);

        $link = HubspotObjectLink::query()->sole();

        // The marker snapshots the row as it is NOW -- not stale -- and writes `archived_at`.
        $job = new ArchiveHubspotObjectJob('contacts', $link->hubspot_id, ArchiveMarker::stamp($link));

        // ...and then a restore raced it, on the strength of the marker the dispatch wrote.
        $link->update(['is_stale' => true, 'stale_at' => now()]);

        Hubspot::fake();
        config()->set('hubspot.disabled', true);

        app()->call([$job, 'handle']);

        $fresh = HubspotObjectLink::query()->sole();
        self::assertNull($fresh->archived_at);
        self::assertFalse(
            $fresh->is_stale,
            'A live record reported as stale for ever is the mirror image of the stranded marker, '
            .'and nothing later clears it.'
        );
        self::assertNull($fresh->stale_at);
    }

    /**
     * A job serialised by a release that predates {@see ArchiveMarker} carries none, and cannot
     * find its marker without guessing -- `hubspot_id` alone may match more than one link row, and
     * clearing the wrong one would destroy somebody else's legitimate archive. It says so loudly
     * instead of guessing.
     */
    public function test_an_archive_job_without_a_marker_says_so_rather_than_guessing(): void
    {
        Hubspot::fake();
        SoftDeletingLead::create(['email' => 'oldrelease@example.com', 'first_name' => 'Ada']);

        $link = HubspotObjectLink::query()->sole();
        $link->update(['archived_at' => now()]);

        $job = new ArchiveHubspotObjectJob('contacts', $link->hubspot_id);

        Hubspot::fake();
        $log = Log::spy();
        config()->set('hubspot.disabled', true);

        app()->call([$job, 'handle']);

        Hubspot::assertRequestCount(0);
        self::assertNotNull(
            HubspotObjectLink::query()->sole()->archived_at,
            'Without a marker the row is left alone -- clearing a guess would be worse.'
        );
        $log->shouldHaveReceived('warning', [
            'A HubSpot archive was skipped on the worker because syncing is switched off, and its '
            .'archive marker could NOT be taken back: this job was queued by an older release that '
            .'did not record which link row it came from. Clear archived_at on that link by hand, '
            .'or the record is treated as archived while it is still live.',
            ['object_type' => 'contacts', 'hubspot_id' => $link->hubspot_id, 'link_id' => null],
        ]);
    }

    /**
     * The testing environment is off unless a fake is bound, so a test that forgot to fake cannot
     * fire a real API call at a portal with no credentials.
     *
     * This is RUNTIME logic and never a value in `config/hubspot.php` -- see the config-cache test
     * below for why that distinction is load-bearing rather than stylistic.
     */
    public function test_the_testing_environment_dispatches_nothing_while_no_fake_is_bound(): void
    {
        Bus::fake();

        SyncedLead::create(['email' => 'nofake@example.com', 'first_name' => 'Ada']);

        Bus::assertNothingDispatched();
    }

    /**
     * ...and the same environment dispatches normally the moment one is, which is what keeps every
     * other Sync test in this phase working.
     */
    public function test_the_testing_environment_dispatches_normally_once_a_fake_is_bound(): void
    {
        Hubspot::fake();
        Bus::fake();

        SyncedLead::create(['email' => 'withfake@example.com', 'first_name' => 'Ada']);

        Bus::assertDispatched(SyncHubspotObjectJob::class);
    }

    /**
     * `config/hubspot.php` must survive `php artisan config:cache`, which serialises with
     * `var_export()` and refuses anything it cannot express -- a closure among them.
     *
     * Asserted by walking the loaded array for `Closure` instances rather than by calling
     * `var_export()` and hoping: `var_export()` on a closure emits
     * `\Closure::__set_state(array())` rather than throwing, so it would pass while the real
     * command failed. Laravel's own `ConfigCacheCommand` rethrows a `LogicException` at that point,
     * which is a production-breaking regression in any consumer that caches config -- and caching
     * config is an ordinary production step, not an exotic one.
     */
    public function test_the_config_file_contains_nothing_config_cache_cannot_serialise(): void
    {
        /** @var array<string, mixed> $config */
        $config = require __DIR__.'/../../../config/hubspot.php';

        self::assertSame([], self::closurePathsIn($config), 'These keys break php artisan config:cache.');
    }

    /**
     * @param  array<array-key, mixed>  $config
     * @return list<string>
     */
    private static function closurePathsIn(array $config, string $prefix = ''): array
    {
        $found = [];

        /** @var mixed $value */
        foreach ($config as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if ($value instanceof Closure) {
                $found[] = $path;

                continue;
            }

            if (is_array($value)) {
                $found = [...$found, ...self::closurePathsIn($value, $path)];
            }
        }

        return $found;
    }
}
