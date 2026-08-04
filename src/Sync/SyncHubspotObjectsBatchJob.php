<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Sync;

use Closure;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use ReyemTech\Hubspot\Exceptions\ApiException;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Gateway\BatchError;
use ReyemTech\Hubspot\Gateway\BatchResult;
use ReyemTech\Hubspot\Gateway\Contracts\ObjectGatewayContract;
use ReyemTech\Hubspot\Gateway\HubspotObject;

/**
 * Queues HubSpot API batches for models sharing a binding (SYNC-03c).
 *
 * `Batchable` only interoperates with an application that wraps its own jobs in `Bus::batch()`.
 * This package never calls it, so it never requires a `job_batches` migration: batching here is
 * HubSpot's batch endpoint. Homogeneous collections of at most 100 records use one endpoint request;
 * mixed or oversized collections use bounded update and upsert chunks.
 */
final class SyncHubspotObjectsBatchJob implements ShouldQueue
{
    use Batchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public bool $deleteWhenMissingModels;

    /**
     * @var list<array{class: class-string<Model>, key: mixed, connection: string|null}>
     */
    public array $models;

    /** @param list<Model> $models */
    public function __construct(array $models)
    {
        $this->models = array_map(
            static fn (Model $model): array => [
                'class' => $model::class,
                'key' => $model->getKey(),
                'connection' => $model->getConnectionName(),
            ],
            $models,
        );
        $this->deleteWhenMissingModels = true;
    }

    public function handle(ModelBindings $bindings, PropertyMapper $mapper, ObjectGatewayContract $gateway): void
    {
        if (! App::make(SyncGate::class)->permits()) {
            return;
        }

        if ($this->models === []) {
            return;
        }

        $models = $this->reloadedModels();

        if ($models === []) {
            return;
        }

        foreach ($models as $model) {
            if (get_class($model) !== get_class($models[0])) {
                throw new \InvalidArgumentException(get_class($models[0]).' cannot batch-sync '.get_debug_type($model)
                    .'; every model must be an instance of '.get_class($models[0]).'.');
            }
        }

        $binding = $bindings->for(get_class($models[0]));
        [$updates, $linksByHubspotId, $modelsByHubspotId, $upserts, $modelsByIdentifier] = $this->recordsFor($models, $binding, $mapper);

        if ($updates === [] && $upserts === []) {
            return;
        }

        foreach (array_chunk($updates, 100) as $chunk) {
            $result = $gateway->updateMany($binding->objectType, $chunk);
            $this->markUpdatedRecords($result->recordsDespitePartialFailure(), $linksByHubspotId);
            $this->logErrors($result->errors(), $binding->objectType, $this->modelsForChunk($chunk, $modelsByHubspotId));
            $this->throwForUnitemizedPartialFailure($result);
        }

        foreach (array_chunk($upserts, 100) as $chunk) {
            $result = $gateway->upsertMany($binding->objectType, $binding->idProperty, $chunk);
            $this->storeConfirmedRecords($result->recordsDespitePartialFailure(), $binding, $modelsByIdentifier);
            $this->logErrors($result->errors(), $binding->objectType, $this->modelsForChunk($chunk, $modelsByIdentifier, $binding));
            $this->throwForUnitemizedPartialFailure($result);
        }
    }

    /** @return list<Model> */
    private function reloadedModels(): array
    {
        $models = [];

        foreach ($this->models as ['class' => $class, 'key' => $key, 'connection' => $connection]) {
            /** @var class-string<Model> $class */
            $model = new $class;

            if ($connection !== null) {
                $model->setConnection($connection);
            }

            $model = $model->newQueryWithoutScopes()->find($key);

            if ($model instanceof Model) {
                $models[] = $model;
            }
        }

        return $models;
    }

    /**
     * @param  list<Model>  $models
     * @return array{
     *     list<array{id: string, properties: array<string, string>}>,
     *     array<string, HubspotObjectLink>,
     *     array<string, Model>,
     *     list<array{id: string, properties: array<string, string>}>,
     *     array<string, Model>
     * }
     */
    private function recordsFor(array $models, ModelBinding $binding, PropertyMapper $mapper): array
    {
        $updates = [];
        $linksByHubspotId = [];
        $modelsByHubspotId = [];
        $upserts = [];
        $modelsByIdentifier = [];

        foreach ($models as $model) {
            /** @var HubspotObjectLink|null $link */
            $link = $model->hubspotLink()->first(); // @phpstan-ignore-line method.notFound

            if ($link?->archived_at !== null) {
                Log::info(
                    'A HubSpot property push was skipped because the delete path owns that record.',
                    ['model' => get_class($model), 'model_id' => $model->getKey()],
                );

                continue;
            }

            if ($link === null && $this->modelIsTrashed($model)) {
                Log::info(
                    'A HubSpot property push was skipped because its model arrived soft-deleted and '
                    .'has never synced.',
                    ['model' => get_class($model), 'model_id' => $model->getKey()],
                );

                continue;
            }

            /** @var array<string, string|Closure> $map */
            $map = $model->getHubspotMap(); // @phpstan-ignore-line method.notFound

            if ($link !== null) {
                /** @var array<string, string|Closure> $updateMap */
                $updateMap = $model->getHubspotUpdateMap(); // @phpstan-ignore-line method.notFound

                if (array_key_exists($link->hubspot_id, $linksByHubspotId)) {
                    throw ConfigurationException::duplicateBatchLinkedHubspotId($binding->modelClass, $binding->objectType);
                }

                $updates[] = ['id' => $link->hubspot_id, 'properties' => $mapper->mapForUpdate($model, $map, $updateMap)];
                $linksByHubspotId[$link->hubspot_id] = $link;
                $modelsByHubspotId[$link->hubspot_id] = $model;

                continue;
            }

            $properties = $mapper->map($model, $map);
            $idValue = $this->idValueFor($properties, $binding);
            $identifierKey = $this->normalizedIdentifierKey($idValue, $binding);

            if (array_key_exists($identifierKey, $modelsByIdentifier)) {
                throw ConfigurationException::duplicateBatchIdentifier($binding->modelClass, $binding->idProperty, $idValue);
            }

            $upserts[] = ['id' => $idValue, 'properties' => $properties];
            $modelsByIdentifier[$identifierKey] = $model;
        }

        return [$updates, $linksByHubspotId, $modelsByHubspotId, $upserts, $modelsByIdentifier];
    }

    /** @param array<string, string> $properties */
    private function idValueFor(array $properties, ModelBinding $binding): string
    {
        $idValue = $properties[$binding->idProperty] ?? null;

        if (! is_string($idValue) || $idValue === '') {
            throw ConfigurationException::idPropertyNotMapped($binding->modelClass, $binding->idProperty);
        }

        return $idValue;
    }

    /**
     * @param  list<HubspotObject>  $objects
     * @param  array<string, Model>  $modelsById
     */
    private function storeConfirmedRecords(array $objects, ModelBinding $binding, array $modelsById): void
    {
        // records() refuses a 207. This accessor deliberately retains confirmed survivors so a
        // retry converges on them rather than treating a partial success as a total loss.
        foreach ($objects as $object) {
            $id = $object->properties[$binding->idProperty] ?? null;
            $model = $this->modelForIdentifier($id, $binding, $modelsById);

            if (! $model instanceof Model) {
                throw ApiException::unmatchedBatchRecord();
            }

            $identity = [
                'lookup_hash' => HubspotObjectLink::lookupHashFor($model->getMorphClass()),
                'model_id' => (string) $model->getKey(), // @phpstan-ignore-line cast.string
                'object_type' => $binding->objectType,
            ];
            $attributes = [
                'model_type' => $model->getMorphClass(),
                'hubspot_id' => $object->id,
                'synced_at' => Carbon::now(),
                'is_stale' => false,
                'stale_at' => null,
            ];

            $link = HubspotObjectLink::query()->firstOrCreate($identity, $attributes);

            if ($link->wasRecentlyCreated) {
                App::make(DeleteRaceReconciler::class)->reconcile($model);
            }
        }
    }

    /**
     * @param  array<string, Model>  $modelsById
     */
    private function modelForIdentifier(mixed $id, ModelBinding $binding, array $modelsById): ?Model
    {
        if (! is_string($id)) {
            return null;
        }

        return $modelsById[$this->normalizedIdentifierKey($id, $binding)] ?? null;
    }

    private function normalizedIdentifierKey(string $id, ModelBinding $binding): string
    {
        return $binding->idProperty === 'email' ? mb_strtolower($id, 'UTF-8') : $id;
    }

    /**
     * @param  list<HubspotObject>  $objects
     * @param  array<string, HubspotObjectLink>  $linksByHubspotId
     */
    private function markUpdatedRecords(array $objects, array $linksByHubspotId): void
    {
        foreach ($objects as $object) {
            $link = $linksByHubspotId[$object->id] ?? null;

            if ($link === null) {
                continue;
            }

            $link->update([
                'synced_at' => Carbon::now(),
                'is_stale' => false,
                'stale_at' => null,
            ]);
        }
    }

    /**
     * @param  list<array{id: string, properties: array<string, string>}>  $inputs
     * @param  array<string, Model>  $modelsByIdentifier
     * @return array<string, Model>
     */
    private function modelsForChunk(array $inputs, array $modelsByIdentifier, ?ModelBinding $binding = null): array
    {
        $models = [];

        foreach ($inputs as $input) {
            $identifier = $binding === null ? $input['id'] : $this->normalizedIdentifierKey($input['id'], $binding);

            if (isset($modelsByIdentifier[$identifier])) {
                $models[$identifier] = $modelsByIdentifier[$identifier];
            }
        }

        return $models;
    }

    /**
     * @param  list<BatchError>  $errors
     * @param  array<string, Model>  $modelsByIdentifier
     */
    private function logErrors(array $errors, string $objectType, array $modelsByIdentifier): void
    {
        foreach ($errors as $error) {
            $models = [];

            foreach ($error->context ?? [] as $identifiers) {
                foreach ($identifiers as $identifier) {
                    if (isset($modelsByIdentifier[$identifier])) {
                        $models[$identifier] = $modelsByIdentifier[$identifier];
                    }
                }
            }

            if ($models === []) {
                Log::error('HubSpot rejected a batch record.', [
                    'object_type' => $objectType,
                    'category' => $error->category,
                    'status' => $error->status,
                ]);

                continue;
            }

            foreach ($models as $model) {
                Log::error('HubSpot rejected a batch record.', [
                    'object_type' => $objectType,
                    'category' => $error->category,
                    'status' => $error->status,
                    'model' => get_class($model),
                    'model_id' => $model->getKey(),
                ]);
            }
        }
    }

    private function throwForUnitemizedPartialFailure(BatchResult $result): void
    {
        if ($result->isPartialFailure() && $result->errors() === []) {
            throw ApiException::partialBatchFailure(0, null);
        }
    }

    private function modelIsTrashed(Model $model): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model), true)
            && $model->trashed() === true; // @phpstan-ignore-line method.notFound
    }
}
