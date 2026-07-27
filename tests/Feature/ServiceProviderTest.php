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

    public function test_it_merges_config_hubspot_php_default_values_without_publishing(): void
    {
        self::assertNull(config('hubspot.token'));
        self::assertSame('cache', config('hubspot.store'));
        self::assertFalse(config('hubspot.disabled'));
        self::assertTrue(config('hubspot.webhooks.enforce'));
        self::assertNull(config('hubspot.webhooks.secret'));
        self::assertSame(300, config('hubspot.webhooks.tolerance'));
    }

    public function test_it_publishes_config_hubspot_php_under_the_hubspot_config_tag(): void
    {
        $published = config_path('hubspot.php');

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
