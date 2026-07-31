<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Sync;

use Illuminate\Foundation\Application;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\ServiceProvider;
use ReyemTech\Hubspot\Tests\Support\Sync\SyncedLead;
use ReyemTech\Hubspot\Tests\Support\Sync\SyncTestCase;
use ReyemTech\Hubspot\Tests\TestCase;
use Throwable;

/**
 * # D-12: a binding without `id_property` throws while the application boots, not on first use.
 *
 * That has to be proved from a genuinely fresh application: {@see SyncTestCase}'s
 * shared app is deliberately valid for every other Sync test, so this file builds its own,
 * isolated the same way `ServiceProviderDatabaseStoreTest` is isolated from `ServiceProviderTest`
 * -- a class-level `defineEnvironment()` fixes one config for every test in the class, and the
 * point here is that booting AT ALL is what fails.
 *
 * `setUp()` is overridden to catch the exception `parent::setUp()` otherwise lets escape while
 * building the application, so the test can assert ON that exception rather than merely erroring.
 * Testbench's own teardown only tears down what it finished setting up (`$this->app` stays null
 * when `createApplication()` throws before assigning it), so a failed boot here leaves nothing to
 * clean up.
 *
 * Not listed in 04-02-PLAN.md's `files_modified` -- added under deviation Rule 2 (auto-add missing
 * critical test coverage): the plan's own Task 2 acceptance criteria requires this exact scenario
 * ("its own test that builds an application"), which cannot be expressed inside
 * `TracerSyncTest.php`'s shared, already-valid `SyncTestCase` fixture.
 */
final class MissingIdPropertyThrowsAtBootTest extends TestCase
{
    private ?Throwable $bootException = null;

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
    protected function defineEnvironment($app): void
    {
        $app->make('config')->set('hubspot.models', [
            SyncedLead::class => [
                'object' => 'contacts',
                // id_property deliberately absent -- the one shape D-12 forbids.
            ],
        ]);
    }

    protected function setUp(): void
    {
        try {
            parent::setUp();
        } catch (Throwable $exception) {
            $this->bootException = $exception;
        }
    }

    public function test_a_binding_without_id_property_throws_while_the_application_boots(): void
    {
        self::assertInstanceOf(
            ConfigurationException::class,
            $this->bootException,
            'Expected building the application to throw a ConfigurationException naming the missing id_property.',
        );

        // The literal message, not `missingIdProperty()->getMessage()` compared against itself:
        // a mutated internal sprintf (reordered/dropped concatenation segments) would still equal
        // whatever the SAME mutated code produced a second time, which is why pest --mutate
        // reports several concatenation mutants on this factory as UNTESTED without a hardcoded
        // expectation somewhere in the suite (Codex-equivalent finding, 04-04).
        self::assertSame(
            SyncedLead::class.' is bound in hubspot.models but has no "id_property" set. Add '
            .'the HubSpot property this model upserts on, for example \'id_property\' => '
            .'\'email\' for a model bound to the "contacts" object. Without it, an upsert has '
            .'no property to converge on and this package refuses to guess one.',
            $this->bootException->getMessage(),
        );
    }
}
