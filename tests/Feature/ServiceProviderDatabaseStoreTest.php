<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Foundation\Application;
use ReyemTech\Hubspot\ServiceProvider;
use ReyemTech\Hubspot\Tests\TestCase;

/**
 * Isolated from ServiceProviderTest deliberately: `getEnvironmentSetUp()` must force
 * `hubspot.store` to `database` BEFORE `ServiceProvider::boot()` runs (testbench calls
 * `getEnvironmentSetUp()` after provider `register()` but before `BootProviders`, see
 * `orchestra/testbench-core`'s `CreatesApplication::resolveApplicationBootstrappers()`),
 * which a single shared test class cannot do per-test since the application is built
 * once per test case.
 */
mutates(ServiceProvider::class);

final class ServiceProviderDatabaseStoreTest extends TestCase
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
     * @param  Application  $app
     */
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('hubspot.store', 'database');
    }

    public function test_it_loads_this_packages_migrations_when_the_database_store_is_active(): void
    {
        $migrationsPath = realpath(dirname(__DIR__, 2).'/database/migrations');

        $migrator = app(Migrator::class);

        $registeredPaths = array_map(
            static fn (string $path): string|false => realpath($path),
            $migrator->paths()
        );

        self::assertContains($migrationsPath, $registeredPaths);
    }
}
