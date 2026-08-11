<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\PendingCommand;
use ReyemTech\Hubspot\ServiceProvider;
use ReyemTech\Hubspot\Tests\TestCase;

/**
 * PHPUnit-style test class rather than a Pest closure test (CLAUDE.md: "Pest runs on
 * PHPUnit, so PHPUnit-style test classes are valid here if you prefer them"). This is
 * deliberate here: `getPackageProviders()` must register `ServiceProvider::class` for
 * this file only, without touching the shared `Tests\TestCase::getPackageProviders()`
 * every other Feature/Unit test relies on -- keeping this test self-contained instead
 * of widening the shared fixture's registered-providers surface.
 *
 * Boots a real Laravel application under orchestra/testbench (per the design decision:
 * "test it through orchestra/testbench so the provider is actually booted") and asserts
 * the three things this pulled-forward ServiceProvider must do: merge config defaults
 * without requiring a publish step, make the config publishable, and never load
 * migrations under the default (cache) store -- zero-migration install must hold.
 */
mutates(ServiceProvider::class);

final class ServiceProviderTest extends TestCase
{
    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [ServiceProvider::class];
    }

    /**
     * Points the application's config directory at one this process owns, BEFORE any provider
     * boots.
     *
     * The timing is the whole trick. `ServiceProvider::boot()` computes its publish target once,
     * as `$this->app->configPath('hubspot.php')`, and hands it to `publishes()`, which stores it in
     * a STATIC array -- so redirecting the path afterwards moves nothing, and `vendor:publish` would
     * still write into the skeleton. `defineEnvironment()` runs while the application is being
     * created, which is the last moment the target is still open to change.
     *
     * Losing the skeleton's own config files costs nothing here: Testbench merges the framework
     * defaults from `getFrameworkDefaultConfigurations()`, which is independent of this path, and
     * `hubspot.*` reaches config through the provider's own `mergeConfigFrom()` rather than through
     * any published file -- which is exactly what
     * `test_it_merges_config_hubspot_php_default_values_without_publishing` asserts, and it still
     * passes.
     *
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app->useConfigPath(self::emptied(self::isolatedConfigDirectory()));
    }

    public function test_it_merges_config_hubspot_php_default_values_without_publishing(): void
    {
        self::assertNull(config('hubspot.token'));
        self::assertSame('cache', config('hubspot.store'));
        self::assertFalse(config('hubspot.disabled'));
        self::assertTrue(config('hubspot.webhooks.enforce'));
        self::assertNull(config('hubspot.webhooks.secret'));
    }

    /**
     * **The published config exposes no signature-tolerance knob, deliberately.**
     *
     * `hubspot.webhooks.tolerance` shipped through v0.6.0 documented as "the signature timestamp
     * window, in seconds", and nothing ever read it: verification delegates to
     * `HubSpot\Utils\Signature::isValid()`, which hardcodes
     * `Signature::MAX_ALLOWED_TIMESTAMP = 300000` and accepts no tolerance argument at all — only
     * a `checkTimestamp` on/off flag. Tightening or widening the value changed nothing, which is
     * the worst shape a security setting can take: it reads as applied. Its default happened to
     * equal the SDK's own 300 seconds, which is why no test ever caught the difference.
     *
     * Asserted as absent rather than merely deleted, so re-adding the key requires deciding to
     * make it real first. `assertNull()` would not do: it passes for a key that is present and
     * set to null.
     */
    public function test_the_published_config_declares_no_signature_tolerance_key(): void
    {
        /** @var array<string, mixed> $webhooks */
        $webhooks = config('hubspot.webhooks');

        self::assertArrayNotHasKey('tolerance', $webhooks);
    }

    /**
     * The publish target must live outside the Testbench skeleton, and this asserts it rather than
     * assuming it -- the invariant is the whole point of `useConfigPath()` below.
     *
     * `config_path()` under Testbench resolves into
     * `vendor/orchestra/testbench-core/laravel/config`, which is ONE directory on disk shared by
     * every parallel worker. Publishing there and deleting it in a `finally` opens a window in
     * which another worker's `LoadConfiguration` globs the directory, sees `hubspot.php`, and then
     * `require`s a path this test has since removed:
     *
     *     Failed opening required '.../testbench-core/laravel/config/hubspot.php'
     *     at vendor/orchestra/testbench-core/src/Bootstrap/LoadConfiguration.php:89
     *
     * That is a real CI failure, not a hypothetical: it took down the `mutation` job on `main` at
     * both `8d9a247` and `8b3f64c`, and the victim is whichever unrelated test happened to be
     * booting at the time, never this one. It reproduces only under `--parallel`, which is why the
     * gate that runs parallel found it and the ordinary suite never did.
     */
    public function test_the_config_publish_target_is_not_inside_the_shared_testbench_skeleton(): void
    {
        self::assertStringNotContainsString(
            'testbench-core',
            str_replace('\\', '/', self::isolatedConfigPath()),
            'Publishing into the Testbench skeleton mutates state every parallel worker shares.',
        );
    }

    public function test_it_publishes_config_hubspot_php_under_the_hubspot_config_tag(): void
    {
        $published = self::isolatedConfigPath();

        self::removePublished($published);

        try {
            $result = $this->artisan('vendor:publish', [
                '--tag' => 'hubspot-config',
                '--force' => true,
            ]);

            if ($result instanceof PendingCommand) {
                $result->assertExitCode(0);

                // PendingCommand defers actually running the artisan command until it is
                // destructed (Illuminate\Testing\PendingCommand::__destruct() -> run()),
                // which only happens naturally once no variable still references it.
                // Force that now, before asserting the published file exists below.
                unset($result);
            } else {
                self::assertSame(0, $result);
            }

            // assertFileExists() alone would also pass for a directory (file_exists()
            // is true for both) -- assertFileIsReadable() plus an explicit is_file()
            // check is what actually proves a single config *file* was published, not
            // a directory tree copied wholesale because the publish source resolved to
            // something other than config/hubspot.php.
            self::assertTrue(is_file($published), "Expected {$published} to be a file, not a directory.");
            self::assertFileIsReadable($published);
        } finally {
            self::removePublished($published);
        }
    }

    /**
     * A config file left behind by an interrupted run must never reach the next application boot.
     *
     * This is the failure Codex named on PR #45, made deterministic: the sentinel below stands in
     * for a `hubspot.php` that a killed run published and never cleaned up, under a PID the OS has
     * since handed to this process. Without the clearing step, Testbench loads it during
     * bootstrapping and every `hubspot.*` assertion in this file starts reading a published file
     * instead of `mergeConfigFrom()` -- passing for the wrong reason.
     */
    public function test_a_stale_published_config_never_reaches_the_next_boot(): void
    {
        file_put_contents(
            self::isolatedConfigPath(),
            '<?php return '.var_export(['store' => 'stale-sentinel'], true).';',
        );

        $this->refreshApplication();

        self::assertFalse(
            is_file(self::isolatedConfigPath()),
            'A config file surviving into the next boot is adopted by Testbench before the '
            .'provider runs.',
        );
        self::assertSame(
            'cache',
            config('hubspot.store'),
            'The default must come from mergeConfigFrom(), never from a leftover published file.',
        );
    }

    /**
     * A config directory this process owns alone.
     *
     * Keyed by PID because `pest --parallel` isolates workers as PROCESSES, not threads: two
     * workers running this file concurrently would otherwise share a directory and race each other
     * exactly the way the skeleton race worked. `sys_get_temp_dir()` rather than anywhere in the
     * repository, so nothing a failed run leaves behind can be picked up by a later one as source.
     */
    private static function isolatedConfigDirectory(): string
    {
        return sys_get_temp_dir().'/laravel-hubspot-publish-'.getmypid();
    }

    /**
     * Creates the directory if absent, and empties it if not.
     *
     * Emptying is the part that matters (Codex, PR #45). A PID is unique among LIVE processes, not
     * over time: a run killed between publishing and its `finally` leaves `hubspot.php` behind, and
     * the next process to be handed that PID adopts it. Because `useConfigPath()` is installed
     * before configuration bootstrapping, Testbench would then LOAD that stale file -- and
     * `test_it_merges_config_hubspot_php_default_values_without_publishing()` would pass while
     * exercising nothing, since its values would be coming from a published file rather than from
     * `mergeConfigFrom()`. A vacuous pass is worse than a failure.
     *
     * Plain filesystem calls rather than the `File` facade: this runs while the application is
     * still being created, which is exactly when depending on a resolved facade root is unwise.
     */
    private static function emptied(string $directory): string
    {
        if (! is_dir($directory)) {
            mkdir($directory, 0o777, true);

            return $directory;
        }

        foreach ((array) glob($directory.'/*') as $entry) {
            if (is_string($entry) && is_file($entry)) {
                unlink($entry);
            }
        }

        return $directory;
    }

    private static function isolatedConfigPath(): string
    {
        return self::isolatedConfigDirectory().'/hubspot.php';
    }

    /**
     * Removes whatever vendor:publish left at the given path, whether a file (the
     * correct outcome) or a directory (what a broken source path would copy).
     */
    private static function removePublished(string $path): void
    {
        if (File::isDirectory($path)) {
            File::deleteDirectory($path);

            return;
        }

        if (File::exists($path)) {
            File::delete($path);
        }
    }

    public function test_it_never_loads_migrations_when_the_default_cache_store_is_active(): void
    {
        $migrationsPath = realpath(dirname(__DIR__, 2).'/database/migrations');

        $migrator = app(Migrator::class);

        $registeredPaths = array_map(
            static fn (string $path): string|false => realpath($path),
            $migrator->paths()
        );

        self::assertNotContains($migrationsPath, $registeredPaths);
    }
}
