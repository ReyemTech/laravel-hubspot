<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Signals;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\ServiceProvider;
use ReyemTech\Hubspot\Signals\Stores\LocalSignalStore;
use ReyemTech\Hubspot\Tests\TestCase;

mutates(LocalSignalStore::class);

/**
 * `Signals\Contracts\SignalStore`'s `local` driver (SIG-07): D-06's trail idempotence half, a
 * deliberately-uncached `isReady()`, and a missing-table `QueryException` translated into a
 * directed `ConfigurationException` rather than left as a raw `SQLSTATE[42S02]`.
 *
 * **`hubspot.signals.enabled` is controlled per test by method-name prefix, mirroring
 * `MigrationGateTest`'s own documented reason.** PHPUnit's file-based test discovery registers only
 * ONE class per file, so the natural split -- one TestCase subclass per environment state -- does
 * not fit here either. `test_disabled_*` prefixed tests run with the flag OFF; every other test
 * runs with it ON, matching this file's own majority concern (the store's write behaviour, not the
 * flag boundary MigrationGateTest already owns).
 */
final class LocalSignalStoreTest extends TestCase
{
    private bool $signalsEnabled = true;

    protected function setUp(): void
    {
        $this->signalsEnabled = ! str_starts_with($this->name(), 'test_disabled_');

        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-12 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var ConfigRepository $config */
        $config = $app->make('config');

        $config->set('hubspot.signals.enabled', $this->signalsEnabled);
    }

    private function migrate(): void
    {
        Artisan::call('migrate', ['--force' => true]);
    }

    private function store(bool $trailPayload = false, bool $featureEnabled = true): LocalSignalStore
    {
        return new LocalSignalStore(
            app(DatabaseManager::class)->connection(),
            $trailPayload,
            $featureEnabled,
        );
    }

    public function test_append_writes_one_trail_row_carrying_subject_signal_name_and_occurred_at(): void
    {
        $this->migrate();

        $occurredAt = Carbon::parse('2026-08-10 09:00:00', 'UTC');

        $this->store()->append(
            1,
            'App\\Models\\Contact',
            '42',
            'pricing_page_viewed',
            ['source' => 'ad'],
            $occurredAt,
        );

        self::assertSame(1, DB::table('hubspot_signal_trail')->count());

        $row = DB::table('hubspot_signal_trail')->first();

        self::assertNotNull($row);
        self::assertSame(1, (int) $row->hubspot_signal_id); // @phpstan-ignore-line cast.int
        self::assertSame('App\\Models\\Contact', $row->subject_type);
        self::assertSame('42', $row->subject_id);
        self::assertSame('pricing_page_viewed', $row->signal_name);
        self::assertSame('2026-08-10 09:00:00', $row->occurred_at);
    }

    /**
     * D-06's trail half: a retried append of the same source row is a no-op, and throws nothing.
     */
    public function test_append_called_twice_for_the_same_signal_id_leaves_exactly_one_row_and_throws_nothing(): void
    {
        $this->migrate();

        $store = $this->store();
        $occurredAt = Carbon::now();

        $store->append(7, 'App\\Models\\Contact', '42', 'pricing_page_viewed', [], $occurredAt);
        $store->append(7, 'App\\Models\\Contact', '42', 'pricing_page_viewed', [], $occurredAt);

        self::assertSame(1, DB::table('hubspot_signal_trail')->where('hubspot_signal_id', 7)->count());
    }

    public function test_append_for_two_different_source_rows_of_the_same_subject_writes_two_rows(): void
    {
        $this->migrate();

        $store = $this->store();

        $store->append(1, 'App\\Models\\Contact', '42', 'pricing_page_viewed', [], Carbon::now());
        $store->append(2, 'App\\Models\\Contact', '42', 'pricing_page_viewed', [], Carbon::now());

        self::assertSame(2, DB::table('hubspot_signal_trail')->where('subject_id', '42')->count());
    }

    public function test_is_ready_returns_false_when_the_trail_table_is_absent_and_true_when_present(): void
    {
        self::assertFalse($this->store()->isReady());

        $this->migrate();

        self::assertTrue($this->store()->isReady());
    }

    /**
     * Proves no cached readiness latch: the SAME instance observes the table appearing between the
     * first and second call.
     */
    public function test_is_ready_re_asks_the_schema_builder_on_every_call(): void
    {
        $store = $this->store();

        self::assertFalse($store->isReady());

        $this->migrate();

        self::assertTrue(
            $store->isReady(),
            'Expected the same instance to observe the table created after the first call.',
        );
    }

    public function test_append_against_a_missing_trail_table_throws_naming_the_table_and_migrate(): void
    {
        self::assertFalse(Schema::hasTable('hubspot_signal_trail'), 'This test is only meaningful before migrating.');

        try {
            $this->store()->append(1, 'App\\Models\\Contact', '42', 'pricing_page_viewed', [], Carbon::now());

            self::fail('Expected a directed ConfigurationException for the absent table.');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                'HUBSPOT_SIGNALS is true but the "hubspot_signal_trail" table does not exist. Run '
                .'`php artisan migrate` to create it. Nothing needs publishing first: this package '
                .'loads its own migrations whenever HUBSPOT_SIGNALS=true.',
                $exception->getMessage(),
            );
        }
    }

    /**
     * The `false` branch of `ConfigurationException::missingSignalTrailTable()` -- mirrors
     * `MigrationGateTest::test_disabled_signal_with_the_flag_off_and_no_table_names_the_flag_as_the_alternative_fix()`.
     * `featureEnabled` is passed directly to the store rather than resolved through the container,
     * but the `test_disabled_` prefix still keeps `hubspot.signals.enabled` off so the "before
     * migrating" assertion below is meaningful against the shipped default.
     */
    public function test_disabled_append_with_the_flag_off_and_no_table_names_the_flag_as_the_alternative_fix(): void
    {
        self::assertFalse(config('hubspot.signals.enabled'));
        self::assertFalse(Schema::hasTable('hubspot_signal_trail'), 'This test is only meaningful before migrating.');

        try {
            $this->store(featureEnabled: false)->append(
                1,
                'App\\Models\\Contact',
                '42',
                'pricing_page_viewed',
                [],
                Carbon::now(),
            );

            self::fail('Expected a directed ConfigurationException for the absent table.');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                'Appending to the signal trail requires HUBSPOT_SIGNALS=true, and it is currently '
                .'false. The "hubspot_signal_trail" table is where each buffered signal\'s flush '
                .'is recorded, so appending cannot run without it. Set HUBSPOT_SIGNALS=true and '
                .'run `php artisan migrate` (+ `php artisan config:cache` if you cache config). '
                .'Nothing needs publishing first: this package loads its own migrations whenever '
                .'HUBSPOT_SIGNALS=true.',
                $exception->getMessage(),
            );
        }
    }

    /**
     * A database failure that is not a missing table is not relabelled as one -- mirrors
     * `MigrationGateTest::test_enabled_a_query_failure_with_the_table_present_is_not_reported_as_a_missing_table()`.
     */
    public function test_a_query_failure_unrelated_to_a_missing_table_propagates_unchanged(): void
    {
        $this->migrate();

        Schema::drop('hubspot_signal_trail');
        Schema::create('hubspot_signal_trail', static function (Blueprint $table): void {
            $table->id();
        });

        self::assertTrue(Schema::hasTable('hubspot_signal_trail'));

        $this->expectException(QueryException::class);

        $this->store()->append(1, 'App\\Models\\Contact', '42', 'pricing_page_viewed', [], Carbon::now());
    }

    /**
     * Publishing is never gated, only loading is -- mirrors
     * `MigrationGateTest::test_disabled_the_signals_migration_file_is_still_offered_for_publishing()`.
     */
    public function test_disabled_the_trail_migration_is_not_loaded_but_is_still_publishable(): void
    {
        self::assertFalse(config('hubspot.signals.enabled'));

        Artisan::call('migrate', ['--force' => true]);

        self::assertFalse(Schema::hasTable('hubspot_signal_trail'));

        $paths = ServiceProvider::pathsToPublish(ServiceProvider::class, 'hubspot-migrations');

        $offersTrailMigration = false;

        foreach (array_keys($paths) as $from) {
            if (str_contains(
                str_replace('\\', '/', (string) $from),
                '/database/migrations/signals/0001_01_01_000001_create_hubspot_signal_trail_table.php',
            )) {
                $offersTrailMigration = true;
            }
        }

        self::assertTrue(
            $offersTrailMigration,
            'Expected the signal trail migration file to be offered for publishing even while disabled.',
        );
    }

    /**
     * The checkpoint decision: `properties` is nullable and populated only when
     * `hubspot.signals.trail_payload` is true (default false), mirroring
     * `hubspot.webhooks.audit_payload`'s own default.
     */
    public function test_append_persists_properties_only_when_trail_payload_is_enabled(): void
    {
        $this->migrate();

        $this->store(trailPayload: false)->append(
            1,
            'App\\Models\\Contact',
            '42',
            'pricing_page_viewed',
            ['source' => 'ad'],
            Carbon::now(),
        );

        self::assertNull(
            DB::table('hubspot_signal_trail')->where('hubspot_signal_id', 1)->value('properties'),
        );

        $this->store(trailPayload: true)->append(
            2,
            'App\\Models\\Contact',
            '42',
            'pricing_page_viewed',
            ['source' => 'ad'],
            Carbon::now(),
        );

        self::assertSame(
            '{"source":"ad"}',
            DB::table('hubspot_signal_trail')->where('hubspot_signal_id', 2)->value('properties'),
        );
    }

    /**
     * Driver-agreement cases (Task 3, PR #71's recorded lesson): a column constraint that behaves
     * three ways across SQLite, MySQL and PostgreSQL is a defect only one leg of CI reports. Every
     * check below is enforced by the store BEFORE the INSERT, so the same correctly-formed call
     * behaves identically regardless of which driver is running underneath it.
     */
    public function test_a_signal_id_at_the_top_of_the_supported_range_appends_and_reads_back_unchanged(): void
    {
        $this->migrate();

        // PHP_INT_MAX, not the true unsigned-bigint ceiling (18446744073709551615): a
        // hubspot_signals.id is an auto-increment bigint PK that can never practically reach that
        // value, and PHP has no native way to hold an integer past its own 64-bit signed range
        // without falling back to a string -- this is the top of what this store can ever actually
        // be asked to append.
        $topOfRange = PHP_INT_MAX;

        $this->store()->append($topOfRange, 'App\\Models\\Contact', '42', 'pricing_page_viewed', [], Carbon::now());

        self::assertSame(
            (string) $topOfRange,
            (string) DB::table('hubspot_signal_trail')->value('hubspot_signal_id'), // @phpstan-ignore-line cast.string
        );
    }

    public function test_a_negative_signal_id_is_refused_before_the_insert(): void
    {
        $this->migrate();

        try {
            $this->store()->append(-1, 'App\\Models\\Contact', '42', 'pricing_page_viewed', [], Carbon::now());

            self::fail('Expected an InvalidArgumentException for a negative hubspot_signal_id.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('-1', $exception->getMessage());
        }

        self::assertSame(0, DB::table('hubspot_signal_trail')->count());
    }

    public function test_a_subject_id_at_exactly_the_column_width_appends(): void
    {
        $this->migrate();

        $subjectId = str_repeat('9', 191);

        $this->store()->append(1, 'App\\Models\\Contact', $subjectId, 'pricing_page_viewed', [], Carbon::now());

        self::assertSame(1, DB::table('hubspot_signal_trail')->where('subject_id', $subjectId)->count());
    }

    public function test_a_subject_id_one_byte_over_the_column_width_is_refused_before_the_insert(): void
    {
        $this->migrate();

        $subjectId = str_repeat('9', 192);

        try {
            $this->store()->append(1, 'App\\Models\\Contact', $subjectId, 'pricing_page_viewed', [], Carbon::now());

            self::fail('Expected an InvalidArgumentException for an over-width subjectId.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('192', $exception->getMessage());
        }

        self::assertSame(0, DB::table('hubspot_signal_trail')->count());
    }

    public function test_a_nul_byte_in_subject_type_is_refused_before_the_insert(): void
    {
        $this->migrate();

        try {
            $this->store()->append(1, "App\\Models\\Contact\0evil", '42', 'pricing_page_viewed', [], Carbon::now());

            self::fail('Expected an InvalidArgumentException for a NUL byte in subjectType.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('subjectType', $exception->getMessage());
        }

        self::assertSame(0, DB::table('hubspot_signal_trail')->count());
    }

    public function test_a_nul_byte_in_subject_id_is_refused_before_the_insert(): void
    {
        $this->migrate();

        try {
            $this->store()->append(1, 'App\\Models\\Contact', "42\0evil", 'pricing_page_viewed', [], Carbon::now());

            self::fail('Expected an InvalidArgumentException for a NUL byte in subjectId.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('subjectId', $exception->getMessage());
        }

        self::assertSame(0, DB::table('hubspot_signal_trail')->count());
    }

    public function test_a_nul_byte_in_signal_name_is_refused_before_the_insert(): void
    {
        $this->migrate();

        try {
            $this->store()->append(1, 'App\\Models\\Contact', '42', "pricing_page_viewed\0evil", [], Carbon::now());

            self::fail('Expected an InvalidArgumentException for a NUL byte in signalName.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('signalName', $exception->getMessage());
        }

        self::assertSame(0, DB::table('hubspot_signal_trail')->count());
    }
}
