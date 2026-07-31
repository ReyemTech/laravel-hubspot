<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Sync;

use Illuminate\Foundation\Application;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Facades\Hubspot;
use ReyemTech\Hubspot\Tests\Support\Sync\SyncedLead;
use ReyemTech\Hubspot\Tests\Support\Sync\SyncTestCase;

/**
 * # The binding declares an `id_property` its own model's `$hubspotMap` does not produce.
 *
 * Distinct from D-12 (`MissingIdPropertyThrowsAtBootTest`): that case is a config shape the
 * `ServiceProvider` rejects before anything runs. This one is only detectable once a sync is
 * actually attempted, because the mismatch is between the BINDING (in config) and the MODEL'S OWN
 * `$hubspotMap` (in code) -- `ModelBindings::validate()` has no way to see the second at boot.
 *
 * The id_property is overridden to `phone`, which {@see SyncedLead}'s map never produces (it maps
 * only `email` and `firstname`), so the job's own resolved-property-bag lookup is what has to
 * catch it.
 *
 * Not listed in 04-02-PLAN.md's `files_modified` -- added under deviation Rule 2, for the same
 * reason `MissingIdPropertyThrowsAtBootTest` was: 04-02's own Task 3 action text requires
 * `ConfigurationException::idPropertyNotMapped()` to exist and follow this codebase's
 * "every factory's message is asserted verbatim, and is therefore mutation-covered" precedent, and
 * the tracer's own happy-path binding never exercises the mismatch this factory reports.
 */
final class UnmappedIdPropertyThrowsTest extends SyncTestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app->make('config')->set('hubspot.models.'.SyncedLead::class.'.id_property', 'phone');
    }

    public function test_a_map_that_does_not_produce_the_bound_id_property_throws_naming_the_model_and_the_property(): void
    {
        Hubspot::fake();

        try {
            SyncedLead::create(['email' => 'ada@example.com', 'first_name' => 'Ada']);

            self::fail('Expected a map that does not produce the bound id_property to throw.');
        } catch (ConfigurationException $exception) {
            // The literal message, not `idPropertyNotMapped()->getMessage()` compared against
            // itself -- a mutated internal sprintf would still equal whatever the SAME mutated
            // code produced a second time (04-04, Codex-equivalent finding).
            self::assertSame(
                SyncedLead::class.' is bound to HubSpot with id_property "phone", but its '
                .'$hubspotMap does not produce that key. Add an entry to $hubspotMap that maps '
                .'"phone" to one of the model\'s own attributes, so the upsert has a value to '
                .'converge on.',
                $exception->getMessage(),
            );
        }

        Hubspot::assertNothingSynced();
    }
}
