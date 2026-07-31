<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Sync;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Gateway\Contracts\ObjectGatewayContract;

/**
 * The single queued job that syncs one model to HubSpot (SYNC-03).
 *
 * `Illuminate\Bus\Queueable`, `Illuminate\Queue\InteractsWithQueue` and
 * `Illuminate\Queue\SerializesModels` are the idiomatic queue-job primitives (D-07). Dispatch goes
 * through the injected `Illuminate\Contracts\Bus\Dispatcher` rather than the `Dispatchable` trait
 * (D-08): `Illuminate\Foundation` has no split package, so `Dispatchable` can never be a declared
 * dependency of this package regardless of what else `illuminate/*` allows.
 *
 * `Batchable` is present for interoperability with a consumer that wraps its own dispatches in
 * `Bus::batch()` -- it is provably migration-free unless that happens, because
 * `Illuminate\Bus\Batchable::batch()` only touches `BatchRepository` when a batch id is actually
 * set. **This package itself never calls `Bus::batch()`.** SYNC-03's "one batch request rather
 * than N" is answered by the HubSpot API's own batch endpoint, `ObjectGateway::upsertMany()`
 * (04-08), not by Laravel's job-batching machinery, which would force a `job_batches` migration
 * onto every consumer regardless of whether they ever bind a model.
 *
 * `$model` is a public, plain (non-readonly) property rather than a constructor-promoted
 * `readonly` one: `SerializesModels::__unserialize()` restores it via `ReflectionProperty::setValue()`
 * on a freshly-deserialized instance the constructor never ran for, and a plain public property is
 * the shape every job scaffold in the framework itself uses for exactly this reason.
 */
final class SyncHubspotObjectJob implements ShouldQueue
{
    use Batchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * D-10: verified against `CallQueuedHandler::handleModelNotFound()`, which deletes the queue
     * message before `handle()` ever runs when the payload's model can no longer be found --
     * `handle()` therefore cannot observe, log, or otherwise react to a hard-deleted model; it
     * simply never runs for one.
     */
    public bool $deleteWhenMissingModels;

    /**
     * Assigned in the constructor body rather than as the property's own default value:
     * `Illuminate\Queue\Queue::createObjectPayload()` reads `$job->deleteWhenMissingModels` off
     * the live object at dispatch time regardless of which one set it, and `pest --mutate`
     * reports a mutation on a bare property default as UNCOVERED for the identical reason
     * `ServiceProvider::supportedStores()`/`consoleCommands()` are methods rather than class
     * constants -- neither is an executed line coverage can attribute a test to.
     */
    public function __construct(public Model $model)
    {
        $this->deleteWhenMissingModels = true;
    }

    /**
     * Collaborators are method parameters, resolved by the container PER CALL, never
     * constructor-captured properties -- the identical reason `ServiceProvider` binds
     * `ObjectGatewayContract` non-shared: `Hubspot::fake()` replaces the client factory the
     * gateway is built against, and a job whose gateway was resolved once, early, would keep
     * talking to whatever transport was live at construction time instead of picking up the fake.
     *
     * `$model` is resolved fresh by `SerializesModels` before this runs (D-09), so `$hubspotMap`
     * is read from that freshly-fetched state -- nothing map-related is ever serialized onto the
     * queue.
     */
    public function handle(ModelBindings $bindings, PropertyMapper $mapper, ObjectGatewayContract $gateway): void
    {
        $binding = $bindings->for(get_class($this->model));

        // getHubspotMap() is declared by the SyncsToHubspot trait every bound model uses -- this
        // job accepts a plain Model because binding is generic across model classes, so the
        // method is real on every model that reaches here but not statically visible on the base
        // Eloquent type.
        /** @var array<string, mixed> $map */
        $map = $this->model->getHubspotMap(); // @phpstan-ignore-line method.notFound

        $properties = $mapper->map($this->model, $map);

        /** @var mixed $idValue */
        $idValue = $properties[$binding->idProperty] ?? null;

        if (! is_string($idValue) || $idValue === '') {
            throw ConfigurationException::idPropertyNotMapped($binding->modelClass, $binding->idProperty);
        }

        // D-11: with no local link yet, this upserts on the declared id_property rather than
        // creating, so a create whose response was lost converges instead of duplicating. The
        // "a link already exists, update by HubSpot id instead" branch is deliberately NOT here --
        // it belongs with $hubspotUpdateMap in 04-03.
        $object = $gateway->upsert($binding->objectType, $binding->idProperty, $idValue, $properties);

        // Model::getKey() is declared `@return mixed` in the framework itself -- every primary
        // key strategy this package supports (autoincrement, UUID, ULID) is a scalar, and D-18 is
        // exactly the decision that this column stores that value as a string regardless of
        // which one a bound model uses.
        $modelId = (string) $this->model->getKey(); // @phpstan-ignore-line cast.string

        // getMorphClass(), never get_class(): SyncsToHubspot::hubspotLink()'s morphOne() queries
        // model_type with getMorphClass(), so under Relation::morphMap() the two differ and a row
        // written under the FQCN is one no read path can ever find. The sync would succeed and
        // hubspotId() would return null forever. Codex, PR #39.
        $morphClass = $this->model->getMorphClass();

        HubspotObjectLink::query()->updateOrCreate(
            [
                // lookup_hash, never the raw model_type, is what identifies the row: getMorphClass()
                // returns a USER-DEFINED morph-map alias, no longer a value this package controls
                // the shape of, and MySQL's usual default collation folds case -- so two aliases
                // differing only by case would collide on a raw model_type predicate. Codex, PR #39.
                // See the migration's own docblock for the full argument.
                'lookup_hash' => HubspotObjectLink::lookupHashFor($morphClass),
                'model_id' => $modelId,
                'object_type' => $binding->objectType,
            ],
            [
                // model_type is still written, and kept current on every re-sync -- it is the
                // operator-readable column beside the indexed digest, never a predicate itself.
                'model_type' => $morphClass,
                'hubspot_id' => $object->id,
                'synced_at' => Carbon::now(),
            ],
        );
    }
}
