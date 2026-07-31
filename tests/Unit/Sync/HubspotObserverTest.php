<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Unit\Sync;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Bus;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Sync\HubspotObserver;
use ReyemTech\Hubspot\Sync\ModelBindings;
use ReyemTech\Hubspot\Sync\SyncHubspotObjectJob;
use ReyemTech\Hubspot\Tests\Support\Sync\SyncedLead;
use ReyemTech\Hubspot\Tests\TestCase;

/**
 * `HubspotObserver::created()` constructed and called directly, rather than through a real
 * `Model::observe()` event fire -- every other Sync test only reaches this class for a model
 * `ServiceProvider::boot()` actually bound, so the per-call binding lookup the class docblock
 * argues for (rather than constructor-captured state) has no other test proving it actually runs,
 * as opposed to being a comment beside a no-op line.
 *
 * Not listed in 04-02-PLAN.md's `files_modified` -- added under deviation Rule 2, for the same
 * reason `PropertyMapperTest`/`ModelBindingsTest` were.
 */
mutates(HubspotObserver::class);

final class HubspotObserverTest extends TestCase
{
    public function test_created_dispatches_the_sync_job_for_a_bound_model(): void
    {
        Bus::fake();

        config(['hubspot.models' => [
            SyncedLead::class => ['object' => 'contacts', 'id_property' => 'email'],
        ]]);

        $observer = new HubspotObserver(
            new ModelBindings(app('config')),
            app(Dispatcher::class),
        );

        $observer->created(new SyncedLead(['email' => 'ada@example.com']));

        Bus::assertDispatched(SyncHubspotObjectJob::class);
    }

    /**
     * The binding lookup this class's own docblock says never to skip: called for a model class
     * `hubspot.models` does not name, `created()` must throw rather than dispatch a job whose
     * binding lookup is guaranteed to fail on the worker anyway.
     *
     * `ConfigurationException`, not the internal-invariant `RuntimeException` this test asserted
     * before 04-04 (Codex would have found the same gap this file's own docblock names): a model
     * genuinely absent from `hubspot.models` is D-12's inverse and now throws the same directed
     * error `ModelBindings::for()` throws for any other caller.
     */
    public function test_created_throws_rather_than_dispatching_for_a_model_that_was_never_bound(): void
    {
        Bus::fake();

        config(['hubspot.models' => []]);

        $observer = new HubspotObserver(
            new ModelBindings(app('config')),
            app(Dispatcher::class),
        );

        $this->expectException(ConfigurationException::class);

        $observer->created(new SyncedLead(['email' => 'ada@example.com']));

        Bus::assertNothingDispatched();
    }
}
