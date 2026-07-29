<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Support;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Application;
use ReyemTech\Hubspot\Tests\TestCase;

/**
 * A Testbench application booted with `HUBSPOT_STORE=database`.
 *
 * The store selector is read in `ServiceProvider::register()` and again in `boot()`, both of which
 * run while the application is being created — so a test that sets `hubspot.store` in its own body
 * is setting it after every decision that depends on it has already been made. `defineEnvironment()`
 * is the hook that runs early enough, which is why this is a TestCase rather than a `beforeEach`.
 *
 * Migrations are deliberately NOT run here. Two of this plan's guarantees are about the state where
 * the store is selected and the table does not exist yet, and a base class that migrated for
 * everybody would make those unreachable. Each test class runs the migrator itself.
 */
class DatabaseStoreTestCase extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var ConfigRepository $config */
        $config = $app->make('config');

        $config->set('hubspot.store', 'database');
    }
}
