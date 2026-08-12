<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Signals;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReyemTech\Hubspot\Exceptions\ApiException;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Facades\Hubspot;
use ReyemTech\Hubspot\ServiceProvider;
use ReyemTech\Hubspot\Signals\FlushClaims;
use ReyemTech\Hubspot\Signals\FlushSignalsJob;
use ReyemTech\Hubspot\Signals\SubjectFlushClaim;
use ReyemTech\Hubspot\Tests\Support\Signals\SignalsTestCase;
use ReyemTech\Hubspot\Tests\Support\Signals\SignalSubject;

mutates(FlushClaims::class, SubjectFlushClaim::class);

/**
 * The subject-level atomic claim D-06 (revised 2026-08-12) requires around calculate-and-write
 * (06-06-PLAN.md Task 2). Test 3 below is the direct regression test for the exact lost-update
 * trace `06-CONTEXT.md` D-06 works through.
 *
 * **`hubspot.signals.enabled` is controlled per test by method-name prefix**, mirroring
 * `MigrationGateTest`'s own documented reason: `defineEnvironment()` runs once per test method and
 * `SignalsTestCase::defineEnvironment()` hardcodes the flag true, so a `test_disabled_*`-prefixed
 * test flips it back off after calling the parent implementation for everything else.
 */
final class FlushClaimTest extends SignalsTestCase
{
    private bool $signalsEnabled = true;

    protected function setUp(): void
    {
        $this->signalsEnabled = ! str_starts_with($this->name(), 'test_disabled_');

        parent::setUp();
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        if ($this->signalsEnabled) {
            return;
        }

        /** @var ConfigRepository $config */
        $config = $app->make('config');
        $config->set('hubspot.signals.enabled', false);
    }

    private function migrate(): void
    {
        Artisan::call('migrate', ['--force' => true]);
    }

    private function claims(int $lease = 900, bool $featureEnabled = true): FlushClaims
    {
        return new FlushClaims(app(DatabaseManager::class)->connection(), $lease, $featureEnabled);
    }

    private function insertBoundSignal(string $visitorId, SignalSubject $subject, string $signalName): void
    {
        DB::table('hubspot_signals')->insert([
            'visitor_id' => $visitorId,
            'subject_type' => $subject::class,
            'subject_id' => (string) $subject->getKey(), // @phpstan-ignore-line cast.string
            'signal_name' => $signalName,
            'properties' => '[]',
            'occurred_at' => now(),
            'flushed_at' => null,
            'reconciled_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array{subjectType: class-string, subjectId: string} */
    private function subjectEntry(SignalSubject $subject): array
    {
        return ['subjectType' => $subject::class, 'subjectId' => (string) $subject->getKey()]; // @phpstan-ignore-line cast.string
    }

    public function test_claim_for_an_unclaimed_subject_returns_acquired(): void
    {
        $this->migrate();

        self::assertSame(
            SubjectFlushClaim::Acquired,
            $this->claims()->claim(SignalSubject::class, '1', 'token-a'),
        );
    }

    public function test_a_second_claim_under_a_different_token_while_the_first_is_live_returns_held(): void
    {
        $this->migrate();
        $claims = $this->claims();

        $claims->claim(SignalSubject::class, '1', 'token-a');

        self::assertSame(SubjectFlushClaim::Held, $claims->claim(SignalSubject::class, '1', 'token-b'));
    }

    /**
     * The direct regression test for the exact lost-update trace `06-CONTEXT.md` D-06 works
     * through: worker A holds the claim, worker B's full `FlushSignalsJob` run for the SAME
     * subject issues zero requests and leaves the subject's rows unflushed.
     */
    public function test_worker_a_holding_the_claim_makes_worker_bs_flush_issue_zero_requests(): void
    {
        $this->migrate();
        Hubspot::fake();

        $subject = SignalSubject::query()->create(['email' => 'lost-update@example.com']);
        $this->insertBoundSignal('visitor-lost-update', $subject, 'pricing_page_viewed');

        // Worker A: takes the claim and never releases it -- simulating a worker mid-flight.
        $this->claims()->claim(SignalSubject::class, (string) $subject->getKey(), 'worker-a'); // @phpstan-ignore-line cast.string

        // Worker B: a full flush run for the same subject.
        app()->call([new FlushSignalsJob([$this->subjectEntry($subject)]), 'handle']);

        Hubspot::assertRequestCount(0);
        self::assertNull(
            DB::table('hubspot_signals')->where('visitor_id', 'visitor-lost-update')->value('flushed_at'),
        );
    }

    /**
     * The claim is per subject, not per flush: a job covering S (already claimed by another
     * worker) and T still writes T in the same run.
     */
    public function test_a_job_covering_a_held_subject_and_a_free_one_still_writes_the_free_one(): void
    {
        $this->migrate();
        $fake = Hubspot::fake();

        $held = SignalSubject::query()->create(['email' => 'held@example.com']);
        $free = SignalSubject::query()->create(['email' => 'free@example.com']);
        $this->insertBoundSignal('visitor-held', $held, 'pricing_page_viewed');
        $this->insertBoundSignal('visitor-free', $free, 'pricing_page_viewed');

        $this->claims()->claim(SignalSubject::class, (string) $held->getKey(), 'other-worker'); // @phpstan-ignore-line cast.string

        app()->call([new FlushSignalsJob([
            $this->subjectEntry($held),
            $this->subjectEntry($free),
        ]), 'handle']);

        Hubspot::assertRequestCount(1);
        self::assertSame('free@example.com', self::decodedBody($fake)['inputs'][0]['id']);
    }

    public function test_after_the_winner_releases_the_same_subject_is_immediately_reclaimable(): void
    {
        $this->migrate();
        $claims = $this->claims();

        $claims->claim(SignalSubject::class, '1', 'token-a');
        $claims->release(SignalSubject::class, '1', 'token-a');

        self::assertSame(
            SubjectFlushClaim::Acquired,
            $claims->claim(SignalSubject::class, '1', 'token-b'),
        );
    }

    /**
     * Decided on the affected row count of a conditional UPDATE, asserted by mutating the stored
     * instant directly rather than sleeping.
     */
    public function test_a_claim_older_than_the_lease_is_reclaimed(): void
    {
        $this->migrate();
        $claims = $this->claims(lease: 900);

        $claims->claim(SignalSubject::class, '1', 'token-a');

        DB::table('hubspot_signal_flush_claims')
            ->where('subject_type', SignalSubject::class)
            ->where('subject_id', '1')
            ->update(['claimed_at' => now()->subSeconds(901)]);

        self::assertSame(
            SubjectFlushClaim::Acquired,
            $claims->claim(SignalSubject::class, '1', 'token-b'),
        );
    }

    public function test_a_claim_one_second_inside_the_lease_is_held(): void
    {
        $this->migrate();
        $claims = $this->claims(lease: 900);

        $claims->claim(SignalSubject::class, '1', 'token-a');

        DB::table('hubspot_signal_flush_claims')
            ->where('subject_type', SignalSubject::class)
            ->where('subject_id', '1')
            ->update(['claimed_at' => now()->subSeconds(899)]);

        self::assertSame(
            SubjectFlushClaim::Held,
            $claims->claim(SignalSubject::class, '1', 'token-b'),
        );
    }

    /**
     * A reclaim that loses its race to a concurrent worker resolves to `Held` rather than
     * recursing or throwing -- simulated deterministically via `DB::listen()`, which fires
     * synchronously right before this call's own reclaim UPDATE runs.
     */
    public function test_a_lost_reclaim_race_answers_held_rather_than_recursing(): void
    {
        $this->migrate();
        $claims = $this->claims(lease: 900);

        $claims->claim(SignalSubject::class, '1', 'token-a');

        DB::table('hubspot_signal_flush_claims')
            ->where('subject_type', SignalSubject::class)
            ->where('subject_id', '1')
            ->update(['claimed_at' => now()->subSeconds(901)]);

        $armed = true;

        DB::listen(function (QueryExecuted $query) use (&$armed): void {
            if (! $armed) {
                return;
            }

            if (
                str_starts_with(strtolower($query->sql), 'update')
                && str_contains($query->sql, 'hubspot_signal_flush_claims')
            ) {
                $armed = false;

                // The "concurrent worker": reclaims the row first, so this call's own reclaim
                // UPDATE -- already in flight -- loses the race when it runs next.
                DB::table('hubspot_signal_flush_claims')
                    ->where('subject_type', SignalSubject::class)
                    ->where('subject_id', '1')
                    ->update(['claim_token' => 'concurrent-winner', 'claimed_at' => now()]);
            }
        });

        self::assertSame(
            SubjectFlushClaim::Held,
            $claims->claim(SignalSubject::class, '1', 'token-b'),
        );
    }

    public function test_a_lease_of_zero_throws_naming_the_key_and_the_minimum(): void
    {
        $this->migrate();

        try {
            $this->claims(lease: 0);

            self::fail('Expected a ConfigurationException for a lease of zero.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('hubspot.signals.flush_lease', $exception->getMessage());
            self::assertStringContainsString('at least 1', $exception->getMessage());
        }
    }

    public function test_a_negative_lease_throws(): void
    {
        $this->migrate();

        $this->expectException(ConfigurationException::class);

        $this->claims(lease: -5);
    }

    public function test_claiming_against_a_missing_table_throws_naming_the_table_and_migrate(): void
    {
        self::assertFalse(Schema::hasTable('hubspot_signal_flush_claims'), 'This test is only meaningful before migrating.');

        try {
            $this->claims()->claim(SignalSubject::class, '1', 'token-a');

            self::fail('Expected a directed ConfigurationException for the absent table.');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                'HUBSPOT_SIGNALS is true but the "hubspot_signal_flush_claims" table does not exist. Run '
                .'`php artisan migrate` to create it. Nothing needs publishing first: this package loads '
                .'its own migrations whenever HUBSPOT_SIGNALS=true.',
                $exception->getMessage(),
            );
        }
    }

    public function test_an_unrelated_query_failure_propagates_unchanged(): void
    {
        $this->migrate();

        Schema::drop('hubspot_signal_flush_claims');
        Schema::create('hubspot_signal_flush_claims', static function (Blueprint $table): void {
            $table->id();
        });

        self::assertTrue(Schema::hasTable('hubspot_signal_flush_claims'));

        $this->expectException(QueryException::class);

        $this->claims()->claim(SignalSubject::class, '1', 'token-a');
    }

    public function test_disabled_claiming_with_the_flag_off_and_no_table_names_the_flag_as_the_alternative_fix(): void
    {
        self::assertFalse(config('hubspot.signals.enabled'));
        self::assertFalse(Schema::hasTable('hubspot_signal_flush_claims'), 'This test is only meaningful before migrating.');

        try {
            $this->claims(featureEnabled: false)->claim(SignalSubject::class, '1', 'token-a');

            self::fail('Expected a directed ConfigurationException for the absent table.');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                'Claiming a subject for flush requires HUBSPOT_SIGNALS=true, and it is currently false. '
                .'The "hubspot_signal_flush_claims" table is what stops two overlapping flushes from '
                .'writing the same subject twice, so claiming cannot run without it. Set '
                .'HUBSPOT_SIGNALS=true and run `php artisan migrate` (+ `php artisan config:cache` if '
                .'you cache config). Nothing needs publishing first: this package loads its own '
                .'migrations whenever HUBSPOT_SIGNALS=true.',
                $exception->getMessage(),
            );
        }
    }

    public function test_disabled_the_claim_migration_is_not_loaded_but_is_still_publishable(): void
    {
        self::assertFalse(config('hubspot.signals.enabled'));

        Artisan::call('migrate', ['--force' => true]);

        self::assertFalse(Schema::hasTable('hubspot_signal_flush_claims'));

        $paths = ServiceProvider::pathsToPublish(ServiceProvider::class, 'hubspot-migrations');

        $offersClaimMigration = false;

        foreach (array_keys($paths) as $from) {
            if (str_contains(
                str_replace('\\', '/', (string) $from),
                '/database/migrations/signals/0001_01_01_000002_create_hubspot_signal_flush_claims_table.php',
            )) {
                $offersClaimMigration = true;
            }
        }

        self::assertTrue(
            $offersClaimMigration,
            'Expected the claim migration file to be offered for publishing even while disabled.',
        );
    }

    public function test_enabled_migrate_creates_the_claims_table(): void
    {
        self::assertTrue(config('hubspot.signals.enabled'));
        self::assertFalse(Schema::hasTable('hubspot_signal_flush_claims'), 'This test is only meaningful before migrating.');

        Artisan::call('migrate', ['--force' => true]);

        self::assertTrue(Schema::hasTable('hubspot_signal_flush_claims'));
    }

    /**
     * Pinned by asserting the query log contains no SELECT against the claim storage, across
     * BOTH the fresh-insert path and the reclaim path.
     */
    public function test_the_claim_decision_never_reads_before_it_writes(): void
    {
        $this->migrate();
        $claims = $this->claims();

        $selects = [];

        DB::listen(function (QueryExecuted $query) use (&$selects): void {
            if (
                str_starts_with(strtolower($query->sql), 'select')
                && str_contains($query->sql, 'hubspot_signal_flush_claims')
            ) {
                $selects[] = $query->sql;
            }
        });

        $claims->claim(SignalSubject::class, '1', 'token-a');
        $claims->claim(SignalSubject::class, '1', 'token-b');
        $claims->claim(SignalSubject::class, '1', 'token-a');

        self::assertSame([], $selects);
    }

    /**
     * A job that dies mid-flush (after a subject was successfully claimed and carried into a
     * group, but before that group's write completes) leaves the claim recoverable through the
     * lease -- asserted explicitly, not left to discovery: immediately after the throw the claim
     * is still `Held`, and once the lease has elapsed it is `Acquired`.
     */
    public function test_a_job_that_throws_mid_flush_leaves_the_claim_recoverable_through_the_lease(): void
    {
        $this->migrate();
        Hubspot::fake(['contacts' => Hubspot::response(['status' => 'error', 'message' => 'boom'], 500)]);

        $subject = SignalSubject::query()->create(['email' => 'dead-worker@example.com']);
        $this->insertBoundSignal('visitor-dead-worker', $subject, 'pricing_page_viewed');

        try {
            app()->call([new FlushSignalsJob([$this->subjectEntry($subject)]), 'handle']);

            self::fail('Expected an ApiException.');
        } catch (ApiException) {
            // Expected -- the job died mid-flush.
        }

        $claims = $this->claims(lease: 900);

        self::assertSame(
            SubjectFlushClaim::Held,
            $claims->claim(SignalSubject::class, (string) $subject->getKey(), 'recovery-attempt'), // @phpstan-ignore-line cast.string
            'The stranded claim must still be held immediately after the throw.',
        );

        DB::table('hubspot_signal_flush_claims')
            ->where('subject_type', SignalSubject::class)
            ->where('subject_id', (string) $subject->getKey()) // @phpstan-ignore-line cast.string
            ->update(['claimed_at' => now()->subSeconds(901)]);

        self::assertSame(
            SubjectFlushClaim::Acquired,
            $claims->claim(SignalSubject::class, (string) $subject->getKey(), 'recovery-attempt-2'), // @phpstan-ignore-line cast.string
            'Once the lease has elapsed, the stranded claim must be reclaimable.',
        );
    }

    /**
     * A worker must not be blocked by its own claim: a retry of a job whose claim it still holds
     * (the same token, presented again) proceeds rather than reading as `Held`.
     */
    public function test_a_retry_holding_its_own_claim_is_not_blocked_by_it(): void
    {
        $this->migrate();
        $claims = $this->claims();

        self::assertSame(SubjectFlushClaim::Acquired, $claims->claim(SignalSubject::class, '1', 'same-token'));
        self::assertSame(
            SubjectFlushClaim::Acquired,
            $claims->claim(SignalSubject::class, '1', 'same-token'),
            'A worker retrying with the SAME token it already holds must not be blocked by its own claim.',
        );
    }

    /** @return array{inputs: list<array{id: string, properties: array<string, mixed>}>} */
    private static function decodedBody(mixed $fake): array
    {
        /** @var array{inputs: list<array{id: string, properties: array<string, mixed>}>} $body */
        $body = json_decode((string) $fake->recordedRequests()[0]['request']->getBody(), true);

        return $body;
    }
}
