<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Signals;

use DateTimeInterface;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDOException;
use ReflectionMethod;
use ReyemTech\Hubspot\Facades\Hubspot;
use ReyemTech\Hubspot\ServiceProvider;
use ReyemTech\Hubspot\Signals\Console\FlushSignalsCommand;
use ReyemTech\Hubspot\Signals\FlushClaims;
use ReyemTech\Hubspot\Signals\FlushSignalsJob;
use ReyemTech\Hubspot\Tests\Support\CommandOutput;
use ReyemTech\Hubspot\Tests\Support\Signals\SignalsTestCase;
use ReyemTech\Hubspot\Tests\Support\Signals\SignalSubject;
use Symfony\Component\Console\Command\Command;

mutates(FlushSignalsCommand::class);

/**
 * `php artisan hubspot:signals:flush` (06-07-PLAN.md Task 2, D-04): selects every identified
 * subject carrying at least one unflushed row, batches at 100 per dispatch, and dispatches one
 * `FlushSignalsJob` per batch. The package registers no schedule of its own -- `PruneWebhookEventsCommand`
 * is the shape precedent, matched exactly (resolve inside `handle()`, never the constructor).
 */
final class FlushSignalsCommandTest extends SignalsTestCase
{
    private function insertBoundSignal(string $visitorId, SignalSubject $subject, ?DateTimeInterface $flushedAt = null): void
    {
        DB::table('hubspot_signals')->insert([
            'visitor_id' => $visitorId,
            'subject_type' => $subject::class,
            'subject_id' => (string) $subject->getKey(), // @phpstan-ignore-line cast.string
            'signal_name' => 'pricing_page_viewed',
            'properties' => '[]',
            'occurred_at' => now(),
            'flushed_at' => $flushedAt,
            'reconciled_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // -- Test 1: pending subjects dispatched and counted -----------------------------------------

    public function test_pending_identified_subjects_are_dispatched_and_the_count_is_reported(): void
    {
        Bus::fake();

        $subject = SignalSubject::query()->create(['email' => 'command-pending@example.com']);
        $this->insertBoundSignal('visitor-command-pending', $subject);

        $exitCode = Artisan::call('hubspot:signals:flush');
        $lines = CommandOutput::linesOf(Artisan::output());

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertContains('Dispatched 1 pending subject for flush.', $lines);

        Bus::assertDispatched(
            FlushSignalsJob::class,
            static fn (FlushSignalsJob $job): bool => $job->subjects === [
                ['subjectType' => SignalSubject::class, 'subjectId' => (string) $subject->getKey()], // @phpstan-ignore-line cast.string
            ],
        );
    }

    // -- Test 2: nothing pending -----------------------------------------------------------------

    public function test_with_nothing_pending_it_reports_nothing_to_do_and_exits_zero(): void
    {
        Bus::fake();

        $exitCode = Artisan::call('hubspot:signals:flush');

        self::assertSame(Command::SUCCESS, $exitCode);
        // The exact, WHOLE output -- not merely "contains" -- so a fallthrough that also prints a
        // second "Dispatched 0 ..." line (the empty-array chunk loop is a harmless no-op, so a
        // missing early return would not otherwise be observable) is caught.
        self::assertSame(['No pending identified subjects to flush.'], CommandOutput::linesOf(Artisan::output()));
        Bus::assertNotDispatched(FlushSignalsJob::class);
    }

    // -- Test 3: batched at 100 ------------------------------------------------------------------

    public function test_two_hundred_fifty_pending_subjects_produce_three_dispatches_of_100_100_and_50(): void
    {
        Bus::fake();

        for ($i = 1; $i <= 250; $i++) {
            $subject = SignalSubject::query()->create(['email' => "batch-{$i}@example.com"]);
            $this->insertBoundSignal("visitor-batch-{$i}", $subject);
        }

        Artisan::call('hubspot:signals:flush');

        Bus::assertDispatchedTimes(FlushSignalsJob::class, 3);

        $sizes = [];
        Bus::assertDispatched(FlushSignalsJob::class, function (FlushSignalsJob $job) use (&$sizes): bool {
            $sizes[] = count($job->subjects);

            return true;
        });

        sort($sizes);
        self::assertSame([50, 100, 100], $sizes);
    }

    // -- Test 4: unidentified rows never selected ------------------------------------------------

    public function test_unidentified_rows_produce_zero_dispatches(): void
    {
        Bus::fake();

        DB::table('hubspot_signals')->insert([
            'visitor_id' => 'visitor-anon',
            'subject_type' => null,
            'subject_id' => null,
            'signal_name' => 'pricing_page_viewed',
            'properties' => '[]',
            'occurred_at' => now(),
            'flushed_at' => null,
            'reconciled_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exitCode = Artisan::call('hubspot:signals:flush');

        self::assertSame(Command::SUCCESS, $exitCode);
        Bus::assertNotDispatched(FlushSignalsJob::class);
    }

    // -- Test 5: only-already-flushed rows produce no dispatch ----------------------------------

    public function test_a_subject_with_only_already_flushed_rows_produces_no_dispatch(): void
    {
        Bus::fake();

        $subject = SignalSubject::query()->create(['email' => 'already-flushed@example.com']);
        $this->insertBoundSignal('visitor-already-flushed', $subject, now());

        Artisan::call('hubspot:signals:flush');

        Bus::assertNotDispatched(FlushSignalsJob::class);
    }

    // -- Test 6: HubspotException reported through error() and FAILURE --------------------------

    public function test_with_the_table_absent_it_exits_non_zero_naming_the_missing_table(): void
    {
        Schema::drop('hubspot_signals');

        $exitCode = Artisan::call('hubspot:signals:flush');

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertContains(
            'HUBSPOT_SIGNALS is true but the "hubspot_signals" table does not exist. Run '
            .'`php artisan migrate` to create it. Nothing needs publishing first: this package '
            .'loads its own migrations whenever HUBSPOT_SIGNALS=true.',
            CommandOutput::linesOf(Artisan::output()),
        );
    }

    /**
     * A `QueryException` that is NOT the missing-table case propagates unchanged -- the guard only
     * translates "the table this package owns has never been created", never every other database
     * failure. Simulated deterministically via `Connection::beforeExecuting()` (the same technique
     * `FlushClaimTest::test_a_lost_reclaim_race_answers_held_rather_than_recursing()` uses),
     * scoped to the query's own `FROM "hubspot_signals"` clause so it does not also intercept the
     * guard's own `hasTable()` schema check -- which legitimately queries `sqlite_master` and must
     * be allowed to answer `true` for this test to prove anything.
     */
    public function test_an_unrelated_query_failure_propagates_unchanged(): void
    {
        $connection = app(DatabaseManager::class)->connection();
        $armed = true;

        $connection->beforeExecuting(function (string $query, array $bindings) use ($connection, &$armed): void {
            if ($armed && str_contains($query, 'from "hubspot_signals"')) {
                $armed = false;

                throw new QueryException((string) $connection->getName(), $query, $bindings, new PDOException('simulated'));
            }
        });

        $this->expectException(QueryException::class);

        Artisan::call('hubspot:signals:flush');
    }

    // -- Test 7: dependencies resolved inside handle(), never the constructor -------------------

    public function test_the_command_is_registered_without_error_even_with_signals_unmigrated(): void
    {
        Schema::drop('hubspot_signals');

        // Merely resolving the command list must not throw -- proves handle() (not the
        // constructor) does the database work. If FlushSignalsCommand's constructor touched the
        // database, this call -- which every artisan invocation performs while the console kernel
        // boots -- would throw here, not only when hubspot:signals:flush itself runs.
        self::assertArrayHasKey('hubspot:signals:flush', Artisan::all());
    }

    // -- Test 8: the package registers no schedule of its own -----------------------------------

    public function test_the_package_registers_no_schedule_of_its_own(): void
    {
        $schedule = app(Schedule::class);

        self::assertSame([], $schedule->events());
    }

    // -- Test 9: registered in ServiceProvider::consoleCommands() -------------------------------

    public function test_the_command_is_registered_in_the_service_providers_command_list(): void
    {
        $method = new ReflectionMethod(ServiceProvider::class, 'consoleCommands');

        /** @var list<class-string> $commands */
        $commands = $method->invoke(null);

        self::assertContains(FlushSignalsCommand::class, $commands);
    }

    // -- Test 10: the race is real (end-to-end, D-06) --------------------------------------------

    public function test_a_scheduled_dispatch_and_an_identify_triggered_dispatch_race_and_exactly_one_wins(): void
    {
        Hubspot::fake();

        $subject = SignalSubject::query()->create(['email' => 'race@example.com']);
        $this->insertBoundSignal('visitor-race', $subject);

        // Worker A: the scheduled command's own flush, already in flight -- simulated by holding
        // the subject-level claim throughout.
        $claims = new FlushClaims(app(DatabaseManager::class)->connection(), 900, true);
        $claims->claim(SignalSubject::class, (string) $subject->getKey(), 'scheduled-worker'); // @phpstan-ignore-line cast.string

        // The identify()-triggered flush -- queue.default=sync means this runs synchronously,
        // exactly as it does in production once a worker picks it up -- races the held claim and
        // writes nothing for this subject.
        app()->call([new FlushSignalsJob([
            ['subjectType' => SignalSubject::class, 'subjectId' => (string) $subject->getKey()], // @phpstan-ignore-line cast.string
        ]), 'handle']);

        Hubspot::assertRequestCount(0);
        self::assertNull(DB::table('hubspot_signals')->where('visitor_id', 'visitor-race')->value('flushed_at'));

        // The scheduled worker's own flush completes and releases the claim.
        $claims->release(SignalSubject::class, (string) $subject->getKey(), 'scheduled-worker'); // @phpstan-ignore-line cast.string

        Artisan::call('hubspot:signals:flush');

        Hubspot::assertRequestCount(1);
        Hubspot::assertSynced('contacts', ['pricing_page_views' => '1']);
        self::assertNotNull(DB::table('hubspot_signals')->where('visitor_id', 'visitor-race')->value('flushed_at'));
    }
}
