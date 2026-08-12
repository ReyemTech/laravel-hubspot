<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Signals;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\ServiceProvider;
use ReyemTech\Hubspot\Signals\SignalRecorder;
use ReyemTech\Hubspot\Tests\TestCase;

mutates(ServiceProvider::class);

/**
 * SIG-01's migration gate: `HUBSPOT_SIGNALS` unset leaves the install migration-free, and
 * `HUBSPOT_SIGNALS=true` with no table throws a directed `ConfigurationException` naming the table
 * and `php artisan migrate` -- never a raw `SQLSTATE[42S02]`.
 *
 * **One class, `hubspot.signals.enabled` chosen per-test by method-name prefix.** The natural split
 * -- one TestCase subclass per environment, the shape `ServiceProviderWebhookStoreTest` /
 * `WebhookEventStoreTest` and `DatabaseStoreTestCase` / `DatabaseStoreMissingTableTest` already
 * establish -- does not fit in ONE file here: `defineEnvironment()` runs once per test method
 * (Testbench re-creates the application for every test), but PHPUnit's own file-based test
 * discovery registers only ONE class per file, keyed to the filename -- proven empirically: a
 * second class declared in this same file was silently never collected (`--filter` against it
 * reported "No tests found", not zero matching tests). `setUp()` reads `$this->name()` --
 * available before `parent::setUp()` triggers `defineEnvironment()`, since PHPUnit sets the test
 * name in the constructor -- and flips one instance flag from the `test_enabled_*` / `test_disabled_*`
 * prefix, which `defineEnvironment()` then reads. `test_enabled_*` prefixed tests deliberately do
 * NOT auto-migrate, for the same reason `DatabaseStoreTestCase`'s own docblock gives: several of
 * these behaviours are about the state where the flag is on and the table does not exist yet.
 */
final class MigrationGateTest extends TestCase
{
    private bool $signalsEnabled = false;

    protected function setUp(): void
    {
        $this->signalsEnabled = str_starts_with($this->name(), 'test_enabled_');

        parent::setUp();
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        if (! $this->signalsEnabled) {
            return;
        }

        /** @var ConfigRepository $config */
        $config = $app->make('config');

        $config->set('hubspot.signals.enabled', true);
    }

    /**
     * Resolved from the container, not called through the `Hubspot::` facade -- mirrors
     * `WebhookConfigurationGuardsTest`, which resolves `WebhookEventStore` from the container for
     * the identical reason: `HubspotManager::signal()` is exercised through the facade elsewhere
     * (`SignalTracerTest`), and PHPStan's dead-catch analysis can trace a facade's `@method`
     * signature (declared `void`, no `@throws`) as authoritative, flagging a
     * `catch (ConfigurationException)` around `Hubspot::signal()` as unreachable even though the
     * concrete implementation genuinely throws it.
     */
    private function recorder(): SignalRecorder
    {
        return app(SignalRecorder::class);
    }

    public function test_disabled_a_default_install_registers_no_migration_path_and_migrate_creates_no_table(): void
    {
        self::assertFalse(
            config('hubspot.signals.enabled'),
            'SIG-01 requires signals off by default -- this test is only meaningful against the shipped default.',
        );

        Artisan::call('migrate', ['--force' => true]);

        self::assertFalse(Schema::hasTable('hubspot_signals'));
    }

    /**
     * Publishing is never gated, only loading is (`src/ServiceProvider.php::boot()`'s own
     * docblock). `ServiceProvider::pathsToPublish()` reads the static array `publishes()` recorded
     * during `boot()`, which every application booted under Testbench runs regardless of this
     * test's own `hubspot.signals.enabled` value.
     */
    public function test_disabled_the_signals_migration_file_is_still_offered_for_publishing(): void
    {
        self::assertFalse(config('hubspot.signals.enabled'));

        $paths = ServiceProvider::pathsToPublish(ServiceProvider::class, 'hubspot-migrations');

        $offersSignalsMigration = false;

        foreach (array_keys($paths) as $from) {
            if (str_contains(str_replace('\\', '/', (string) $from), '/database/migrations/signals/')) {
                $offersSignalsMigration = true;
            }
        }

        self::assertTrue(
            $offersSignalsMigration,
            'Expected the signals migration file to be offered for publishing even while disabled.',
        );
    }

    public function test_disabled_signal_with_the_flag_off_and_no_table_names_the_flag_as_the_alternative_fix(): void
    {
        self::assertFalse(config('hubspot.signals.enabled'));
        self::assertFalse(Schema::hasTable('hubspot_signals'), 'This test is only meaningful before migrating.');

        try {
            $this->recorder()->record('pricing_page_viewed', 'visitor-1');

            self::fail('Expected a directed ConfigurationException for the absent table.');
        } catch (ConfigurationException $exception) {
            // A hardcoded literal, never `ConfigurationException::missingSignalsTable()->getMessage()`
            // -- comparing a factory's output against itself can never catch a mutated internal
            // string (04-03-SUMMARY.md's "message-factory assertions" decision, reused here).
            self::assertSame(
                'Recording a signal requires HUBSPOT_SIGNALS=true, and it is currently false. The '
                .'"hubspot_signals" table is where every buffered signal is written, so recording '
                .'cannot run without it. Set HUBSPOT_SIGNALS=true and run `php artisan migrate` '
                .'(+ `php artisan config:cache` if you cache config). Nothing needs publishing '
                .'first: this package loads its own migrations whenever HUBSPOT_SIGNALS=true.',
                $exception->getMessage(),
            );
        }
    }

    public function test_enabled_migrate_creates_the_signals_table(): void
    {
        self::assertTrue(config('hubspot.signals.enabled'));
        self::assertFalse(Schema::hasTable('hubspot_signals'), 'This test is only meaningful before migrating.');

        Artisan::call('migrate', ['--force' => true]);

        self::assertTrue(Schema::hasTable('hubspot_signals'));
    }

    public function test_enabled_signal_with_the_flag_on_and_no_table_names_the_table_and_migrate(): void
    {
        self::assertTrue(config('hubspot.signals.enabled'));
        self::assertFalse(Schema::hasTable('hubspot_signals'), 'This test is only meaningful before migrating.');

        try {
            $this->recorder()->record('pricing_page_viewed', 'visitor-1');

            self::fail('Expected a directed ConfigurationException for the absent table.');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                'HUBSPOT_SIGNALS is true but the "hubspot_signals" table does not exist. Run '
                .'`php artisan migrate` to create it. Nothing needs publishing first: this package '
                .'loads its own migrations whenever HUBSPOT_SIGNALS=true.',
                $exception->getMessage(),
            );
        }
    }

    /**
     * **A database failure that is not a missing table is not relabelled as one.**
     *
     * The table is replaced by one of the same name without the columns `SignalRecorder::record()`
     * writes, so the INSERT fails while `Schema::hasTable()` still answers true -- mirrors
     * `DatabaseStoreMissingTableTest::test_a_query_failure_with_the_table_present_is_not_reported_as_a_missing_table()`.
     */
    public function test_enabled_a_query_failure_with_the_table_present_is_not_reported_as_a_missing_table(): void
    {
        Artisan::call('migrate', ['--force' => true]);

        Schema::drop('hubspot_signals');
        Schema::create('hubspot_signals', static function (Blueprint $table): void {
            $table->id();
        });

        self::assertTrue(Schema::hasTable('hubspot_signals'));

        $this->expectException(QueryException::class);

        $this->recorder()->record('pricing_page_viewed', 'visitor-1');
    }
}
