<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Unit\Sync;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Bus;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Sync\HubspotObserver;
use ReyemTech\Hubspot\Sync\ModelBindings;
use ReyemTech\Hubspot\Sync\SyncHubspotObjectJob;
use ReyemTech\Hubspot\Sync\SyncsToHubspot;
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

    /**
     * A bound model that does NOT apply the trait still resolves its events from
     * `hubspot.auto_sync.on`, rather than fataling on a missing method.
     *
     * `ModelBindings::validate()` checks `id_property`, not trait usage, so `hubspot.models` can
     * name a model with no `getHubspotAutoSync()` on it. That is a misconfiguration, and the JOB is
     * where it surfaces (`PropertyMapper` needs `$hubspotMap`) -- but the observer's own job is to
     * gate an event, and it must not turn a config mistake into a fatal error inside an Eloquent
     * event handler, which is a far worse place to discover it.
     *
     * An anonymous model rather than a fixture file: the class exists only to be trait-less, and
     * constructing the observer directly means nothing has to be registered, migrated or booted.
     */
    public function test_a_bound_model_without_the_trait_falls_back_to_the_configured_events(): void
    {
        Bus::fake();

        $model = new class extends Model
        {
            protected $table = 'untraited';
        };

        config(['hubspot.models' => [
            $model::class => ['object' => 'contacts', 'id_property' => 'email'],
        ]]);

        $observer = new HubspotObserver(
            new ModelBindings(app('config')),
            app(Dispatcher::class),
        );

        $observer->created($model);

        Bus::assertDispatched(SyncHubspotObjectJob::class);
    }

    /**
     * A method NAMED `getDeletedAtColumn()` is not proof the model soft-deletes.
     *
     * `method_exists()` is a name check, and D-17's guard used to treat it as the whole contract.
     * Two ways that misfires on a model that never used `SoftDeletes` (Codex, PR #48):
     *
     * 1. A model defining the method for unrelated reasons has its ordinary updates SUPPRESSED
     *    whenever the named attribute has a non-null original value -- silently, since a suppressed
     *    sync looks exactly like a model that was not meant to sync.
     * 2. A non-public method of that name is reached through `Model::__call()` -- Eloquent defines
     *    it, so PHP routes there rather than raising "Call to protected method" -- and Eloquent
     *    forwards to the query builder, which raises `BadMethodCallException` from inside an
     *    Eloquent event handler.
     *
     * The guard now asks whether the model carries `SoftDeletingScope`, which `SoftDeletes::boot`
     * registers and nothing else does.
     */
    public function test_a_lookalike_delete_column_method_does_not_suppress_an_update(): void
    {
        Bus::fake();

        $model = new class extends Model
        {
            use SyncsToHubspot;

            protected $table = 'lookalikes';

            /**
             * @var array<string, string>
             */
            protected array $hubspotMap = ['email' => 'email'];

            public function getDeletedAtColumn(): string
            {
                // Names a real attribute with a non-null original, which is what makes the old
                // guard suppress this model's updates rather than merely misread them.
                return 'email';
            }
        };

        $model->setRawAttributes(['email' => 'ada@example.com'], true);

        config(['hubspot.models' => [
            $model::class => ['object' => 'contacts', 'id_property' => 'email'],
        ]]);

        $observer = new HubspotObserver(
            new ModelBindings(app('config')),
            app(Dispatcher::class),
        );

        $observer->updated($model);

        Bus::assertDispatched(SyncHubspotObjectJob::class);
    }

    /**
     * The same misfire in its throwing form: a NON-PUBLIC method of that name. `method_exists()`
     * returns true for it, and calling it from here reaches `Model::__call()` rather than a
     * visibility error, which Eloquent forwards to the query builder -- so an update on this model
     * died with `BadMethodCallException` inside an event handler.
     */
    public function test_a_non_public_delete_column_method_does_not_break_an_update(): void
    {
        Bus::fake();

        $model = new class extends Model
        {
            use SyncsToHubspot;

            protected $table = 'hidden_lookalikes';

            /**
             * @var array<string, string>
             */
            protected array $hubspotMap = ['email' => 'email'];

            protected function getDeletedAtColumn(): string
            {
                return 'email';
            }
        };

        $model->setRawAttributes(['email' => 'ada@example.com'], true);

        config(['hubspot.models' => [
            $model::class => ['object' => 'contacts', 'id_property' => 'email'],
        ]]);

        $observer = new HubspotObserver(
            new ModelBindings(app('config')),
            app(Dispatcher::class),
        );

        $observer->updated($model);

        Bus::assertDispatched(SyncHubspotObjectJob::class);
    }

    /**
     * An ATTRIBUTE named `hubspotAutoSync` is not a declaration.
     *
     * `getHubspotAutoSync()` asks `property_exists()`, which sees only real declared properties.
     * Reading `$this->hubspotAutoSync` directly would instead go through `Model::__get()` and hit
     * the attribute bag, so a column or a filled attribute of that name would silently pose as an
     * opt-out and stop the model syncing -- with nothing anywhere saying why. The two are
     * indistinguishable on a model that has neither, which is why this test gives it one.
     */
    public function test_an_attribute_named_hubspot_auto_sync_is_not_read_as_a_declaration(): void
    {
        Bus::fake();

        $lead = new SyncedLead(['email' => 'ada@example.com']);
        $lead->setAttribute('hubspotAutoSync', false);

        self::assertNull(
            $lead->getHubspotAutoSync(),
            'The model declares no $hubspotAutoSync property, so it has declared nothing -- '
            .'whatever its attribute bag happens to contain.'
        );

        config(['hubspot.models' => [
            SyncedLead::class => ['object' => 'contacts', 'id_property' => 'email'],
        ]]);

        $observer = new HubspotObserver(
            new ModelBindings(app('config')),
            app(Dispatcher::class),
        );

        $observer->created($lead);

        Bus::assertDispatched(SyncHubspotObjectJob::class);
    }

    /**
     * `$hubspotAutoSync = true` is not one of the two documented forms (a narrowing array, or
     * `false`). It collapses to "declared nothing", because "sync on everything in `auto_sync.on`"
     * is precisely what declaring nothing already means -- treating it as a third shape would put
     * an undocumented case in front of every caller for no behavioural gain.
     */
    public function test_a_per_model_true_defers_to_the_configured_events(): void
    {
        Bus::fake();

        $model = new class extends Model
        {
            use SyncsToHubspot;

            protected $table = 'always_syncs';

            /**
             * Declared because the trait's contract expects it of every consuming model, the same
             * way SoftDeletes expects a deleted_at column -- not because this test reads it.
             *
             * @var array<string, string>
             */
            protected array $hubspotMap = ['email' => 'email'];

            /**
             * @var array<int, string>|bool
             */
            protected array|bool $hubspotAutoSync = true;
        };

        self::assertNull($model->getHubspotAutoSync());

        config(['hubspot.models' => [
            $model::class => ['object' => 'contacts', 'id_property' => 'email'],
        ]]);

        $observer = new HubspotObserver(
            new ModelBindings(app('config')),
            app(Dispatcher::class),
        );

        $observer->created($model);

        Bus::assertDispatched(SyncHubspotObjectJob::class);
    }
}
