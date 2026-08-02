<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Sync;

use Closure;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use ReyemTech\Hubspot\Facades\Hubspot;
use ReyemTech\Hubspot\HubspotManager;
use ReyemTech\Hubspot\Sync\ArchiveHubspotObjectJob;
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
 * `Hubspot::withoutSyncing()` is IN-PROCESS and stops a dispatch. `HUBSPOT_DISABLED` is
 * ENVIRONMENT-level and survives any process boundary, including a queue worker that started before
 * the switch was flipped. Neither substitutes for the other:
 *
 * | hatch | stops | cannot stop |
 * |---|---|---|
 * | `withoutSyncing()` | the dispatch, in this process | a job already on the queue |
 * | `HUBSPOT_DISABLED` | the dispatch AND the worker | a daemon that has not restarted |
 *
 * Without the first, `migrate:fresh --seed` fires thousands of API calls. Without the second,
 * nothing protects a worker that is already running.
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
     * A restore is gated separately from every other event -- it answers for an archive that already
     * happened, so it deliberately ignores `auto_sync.on` -- but it does ride both escape hatches.
     * A bulk restore inside `withoutSyncing()` must not flag a link per row.
     *
     * The stale flag is what makes the difference observable: an unsuppressed restore of an archived
     * link sets it, and issues no request either way.
     */
    public function test_without_syncing_suppresses_the_response_a_restore_would_have_made(): void
    {
        Hubspot::fake();
        $lead = SoftDeletingLead::create(['email' => 'suppressrestore@example.com', 'first_name' => 'Ada']);
        $lead->delete();

        self::assertNotNull(
            HubspotObjectLink::query()->sole()->archived_at,
            'The delete must actually have archived, or the restore has nothing to respond to.'
        );

        Hubspot::fake();

        Hubspot::withoutSyncing(function () use ($lead): void {
            $lead->restore();
        });

        Hubspot::assertRequestCount(0);
        self::assertFalse(
            HubspotObjectLink::query()->sole()->is_stale,
            'A suppressed restore must leave the link exactly as it found it.'
        );
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

        app()->call([$job, 'handle']);

        Hubspot::assertRequestCount(0);
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
