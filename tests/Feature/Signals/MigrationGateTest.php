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
use ReyemTech\Hubspot\Signals\IdentityResolver;
use ReyemTech\Hubspot\Signals\SignalRecorder;
use ReyemTech\Hubspot\Tests\Support\Signals\SignalSubject;
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
        /** @var ConfigRepository $config */
        $config = $app->make('config');

        // 06-02 gates Hubspot::signal() on SignalMap::knows() before anything else runs -- every
        // test in this file calls record('pricing_page_viewed', ...), so it needs to be mapped
        // regardless of $this->signalsEnabled, or every call here would report an unknown signal
        // name instead of exercising the migration-gate behaviour this file exists to test.
        $config->set('hubspot.signals.map', [
            'pricing_page_viewed' => [
                'object' => 'contacts',
                'properties' => [
                    'pricing_page_views' => 'increment',
                ],
            ],
        ]);

        if (! $this->signalsEnabled) {
            return;
        }

        $config->set('hubspot.signals.enabled', true);

        // Boot-time SignalMap::validate() (D-07) runs only when enabled -- and D-03 requires the
        // map's declared object type be claimed by some hubspot.models binding, so one is added
        // here purely to keep boot green. Nothing in this file constructs a SignalSubject.
        $config->set('hubspot.models', [
            SignalSubject::class => ['object' => 'contacts', 'id_property' => 'email'],
        ]);
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

    /**
     * Same reasoning as {@see self::recorder()}: resolved from the container, not called through
     * `Hubspot::identify()`, to avoid the identical PHPStan dead-catch false positive against the
     * facade's `@method` signature (06-03-PLAN.md Task 2).
     */
    private function identityResolver(): IdentityResolver
    {
        return app(IdentityResolver::class);
    }

    /**
     * `IdentityResolver::guarded()`'s own missing-table translation (06-03-PLAN.md Task 2),
     * mirroring `test_enabled_signal_with_the_flag_on_and_no_table_names_the_table_and_migrate()`
     * above exactly. The subject IS persisted here -- `refuseUnsavedSubject()` (PR #82 review)
     * now refuses an unsaved model before `guarded()` is ever reached, so this test creates
     * `signal_subjects` itself and saves the subject to it, isolating what it exists to prove: the
     * `hubspot_signals` missing-table translation, not the unsaved-subject refusal.
     */
    public function test_enabled_identify_with_the_flag_on_and_no_table_names_the_table_and_migrate(): void
    {
        self::assertTrue(config('hubspot.signals.enabled'));
        self::assertFalse(Schema::hasTable('hubspot_signals'), 'This test is only meaningful before migrating.');

        Schema::create('signal_subjects', function (Blueprint $table): void {
            $table->id();
            $table->string('email');
            $table->timestamps();
        });

        $subject = SignalSubject::query()->create(['email' => 'ada@example.com']);

        try {
            $this->identityResolver()->identify('visitor-1', $subject);

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
