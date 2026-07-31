<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Registry;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use ReyemTech\Hubspot\Registry\Stores\DatabaseAssociationTypeStore;
use ReyemTech\Hubspot\ServiceProvider;
use ReyemTech\Hubspot\Tests\TestCase;

/**
 * # `composer require` is the whole install.
 *
 * Zero-migration install is a named goal of this package (core spec §2 goal 5) and it is the kind of
 * promise that is easy to state and easy to break: one unconditional `loadMigrationsFrom()` and every
 * consumer inherits a migration they never asked for. This file is the gate on that.
 *
 * ## Both directions, and both asserted against the schema
 *
 * An absent-only test would also pass against a provider that never loads migrations at all — which
 * would leave the database store silently broken while this file reported success. So the switched
 * direction is asserted too.
 *
 * And **neither direction asserts that a path was registered** (Codex P1 on PR #22). A
 * registered-path assertion passes against a directory containing only a `.php.stub`, which is
 * precisely the broken state: Laravel's migrator globs `*_*.php` and never discovers a stub, so
 * `migrate` would produce nothing while the test reported the path was there. Every assertion below
 * runs the migrator and then asks the schema.
 */
final class ZeroMigrationInstallTest extends TestCase
{
    /**
     * Points the application's database directory at one this process owns, BEFORE any provider
     * boots -- the same reason and the same timing as `ServiceProviderTest::defineEnvironment()`.
     *
     * `ServiceProvider::boot()` computes each migration's publish target once, as
     * `$this->app->databasePath('migrations/'.basename($file))`, and `publishes()` stores it
     * statically, so the target has to be redirected before boot or not at all.
     *
     * Without this, `test_vendor_publish_writes_the_migration_into_the_application` writes into
     * `vendor/orchestra/testbench-core/laravel/database/migrations` -- one directory shared by every
     * parallel worker -- and deletes it again. `Migrator::run()` is
     * `$this->requireFiles($files = $this->getMigrationFiles($paths))`: glob, then require. Any
     * worker calling `migrate` inside that window either requires a file this test has since
     * removed, or runs a migration it was never meant to see. Same shape as the config race that
     * took the `mutation` job on `main` down at `8d9a247` and `8b3f64c`, and this is the other half
     * of it -- found while fixing that one, not observed failing on its own.
     *
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app->useDatabasePath(self::isolatedDatabaseDirectory());
    }

    /**
     * A database directory this process alone owns; keyed by PID because `pest --parallel` isolates
     * workers as processes.
     */
    private static function isolatedDatabaseDirectory(): string
    {
        // The trailing `/database` segment is load-bearing: the publish-map assertion below checks
        // the target sits under `database/migrations`, which states where a published migration
        // belongs. Isolating the directory must not quietly weaken that into a different claim.
        $directory = sys_get_temp_dir().'/laravel-hubspot-'.getmypid().'/database';

        File::ensureDirectoryExists($directory.'/migrations');

        return $directory;
    }

    /**
     * The default install touches no database at all: the default store is `cache`, so the migration
     * group is not loaded and `php artisan migrate` creates nothing belonging to this package.
     */
    public function test_the_default_install_migrates_nothing_belonging_to_this_package(): void
    {
        self::assertSame('cache', config('hubspot.store'));

        Artisan::call('migrate', ['--force' => true]);

        self::assertFalse(Schema::hasTable(DatabaseAssociationTypeStore::TABLE));
        self::assertFalse(Schema::hasTable(DatabaseAssociationTypeStore::STATE_TABLE));
    }

    /**
     * Switching the store is the whole of the opt-in: the same `boot()` that skipped the group above
     * loads it, and the migrator then really creates the tables.
     *
     * `boot()` is re-run against the reconfigured application rather than the assertion being moved
     * to a differently-configured TestCase, because running BOTH directions through the same
     * production method in one test is what proves the conditional is the thing doing the gating.
     */
    public function test_switching_the_store_to_database_makes_migrate_create_the_tables(): void
    {
        Artisan::call('migrate', ['--force' => true]);
        self::assertFalse(Schema::hasTable(DatabaseAssociationTypeStore::TABLE));

        config(['hubspot.store' => 'database']);
        (new ServiceProvider(app()))->boot();

        Artisan::call('migrate', ['--force' => true]);

        self::assertTrue(Schema::hasTable(DatabaseAssociationTypeStore::TABLE));
        self::assertTrue(Schema::hasTable(DatabaseAssociationTypeStore::STATE_TABLE));
    }

    /**
     * **The migration is executable PHP where it sits, not a `.php.stub`** (Codex P1 on PR #22).
     *
     * The stub-only convention belongs to packages that publish and never load. This package does
     * both, so a stub in the loaded path would mean `HUBSPOT_STORE=database` plus `php artisan
     * migrate` leaves the table absent — REG-03's promise that the store works without publishing,
     * broken in a way no path assertion can see.
     */
    public function test_the_loaded_path_holds_a_real_migration_and_no_stub(): void
    {
        $path = dirname(__DIR__, 3).'/database/migrations';

        self::assertNotEmpty(
            File::glob($path.'/*_*.php'),
            'The loaded migration path holds no file the migrator would discover.',
        );
        self::assertSame(
            [],
            File::glob($path.'/*.stub'),
            'Laravel\'s migrator globs *_*.php and will never discover a .stub.',
        );
    }

    /**
     * Publishing works under the DEFAULT configuration, not only when the database store is
     * selected — a team that wants to own the file must not have to flip a setting to get at it.
     */
    public function test_the_migration_is_publishable_under_the_default_configuration(): void
    {
        self::assertSame('cache', config('hubspot.store'));

        $published = self::publishableMigrations();

        self::assertNotEmpty($published, 'The hubspot-migrations tag publishes nothing.');

        foreach ($published as $source => $target) {
            self::assertFileExists($source);
            self::assertStringEndsWith('.php', $target);
            self::assertStringContainsString('database/migrations', str_replace('\\', '/', $target));
            self::assertSame(
                basename($source),
                basename($target),
                'A published copy keeps the package filename, so the migrator treats it and the '
                .'package copy as one migration rather than running the same table creation twice.',
            );
        }
    }

    /**
     * The publish actually writes the file, rather than merely declaring an intention to.
     */
    public function test_vendor_publish_writes_the_migration_into_the_application(): void
    {
        $targets = array_values(self::publishableMigrations());

        File::delete($targets);

        Artisan::call('vendor:publish', ['--tag' => 'hubspot-migrations', '--force' => true]);

        try {
            self::assertNotEmpty($targets);

            foreach ($targets as $target) {
                self::assertFileExists($target);
            }
        } finally {
            // The target is inside the Testbench skeleton application, so it is removed however this
            // test ends rather than being left for the next run to trip over.
            File::delete($targets);
        }
    }

    /**
     * The publish map, with both halves proven to be paths.
     *
     * `pathsToPublish()` is declared as a plain `array`, so every value arrives as `mixed`. Asserting
     * each one is a non-empty string rather than casting keeps a map that had stopped holding paths
     * from passing the tests above by being quietly skipped.
     *
     * @return array<string, string>
     */
    private static function publishableMigrations(): array
    {
        $paths = [];

        foreach (ServiceProvider::pathsToPublish(ServiceProvider::class, 'hubspot-migrations') as $source => $target) {
            $source = (string) $source;

            self::assertTrue(
                is_string($target) && $target !== '',
                "The hubspot-migrations tag maps {$source} to something that is not a path.",
            );

            $paths[$source] = $target;
        }

        return $paths;
    }
}
