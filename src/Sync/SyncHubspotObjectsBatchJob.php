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
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Gateway\BatchError;
use ReyemTech\Hubspot\Gateway\Contracts\ObjectGatewayContract;
use ReyemTech\Hubspot\Gateway\HubspotObject;

/**
 * Queues one HubSpot API batch for models sharing a binding (SYNC-03c).
 *
 * `Batchable` only interoperates with an application that wraps its own jobs in `Bus::batch()`.
 * This package never calls it, so it never requires a `job_batches` migration: batching here is
 * HubSpot's `upsertMany()` endpoint, one request for this job's collection.
 */
final class SyncHubspotObjectsBatchJob implements ShouldQueue
{
    use Batchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public bool $deleteWhenMissingModels;

    /**
     * @param  list<Model>  $models
     */
    public function __construct(public array $models)
    {
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

        $binding = $bindings->for(get_class($this->models[0]));
        [$updates, $linksByHubspotId, $upserts, $modelsByIdentifier] = $this->recordsFor($binding, $mapper);

        if ($updates === [] && $upserts === []) {
            return;
        }

        if ($updates !== []) {
            $result = $gateway->updateMany($binding->objectType, $updates);
            $this->markUpdatedRecords($result->recordsDespitePartialFailure(), $linksByHubspotId);
            $this->logErrors($result->errors(), $binding->objectType);
        }

        if ($upserts !== []) {
            $result = $gateway->upsertMany($binding->objectType, $binding->idProperty, $upserts);
            $this->storeConfirmedRecords($result->recordsDespitePartialFailure(), $binding, $modelsByIdentifier);
            $this->logErrors($result->errors(), $binding->objectType);
        }
    }

    /**
     * @return array{
     *     list<array{id: string, properties: array<string, string>}>,
     *     array<string, HubspotObjectLink>,
     *     list<array{id: string, properties: array<string, string>}>,
     *     array<string, Model>
     * }
     */
    private function recordsFor(ModelBinding $binding, PropertyMapper $mapper): array
    {
        $updates = [];
        $linksByHubspotId = [];
        $upserts = [];
        $modelsByIdentifier = [];

        foreach ($this->models as $model) {
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
                $updates[] = ['id' => $link->hubspot_id, 'properties' => $mapper->mapForUpdate($model, $map, $updateMap)];
                $linksByHubspotId[$link->hubspot_id] = $link;

                continue;
            }

            $properties = $mapper->map($model, $map);
            $idValue = $this->idValueFor($properties, $binding);

            if (array_key_exists($idValue, $modelsByIdentifier)) {
                throw ConfigurationException::duplicateBatchIdentifier($binding->modelClass, $binding->idProperty, $idValue);
            }

            $upserts[] = ['id' => $idValue, 'properties' => $properties];
            $modelsByIdentifier[$idValue] = $model;
        }

        return [$updates, $linksByHubspotId, $upserts, $modelsByIdentifier];
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
            $model = is_string($id) ? ($modelsById[$id] ?? null) : null;

            if (! $model instanceof Model) {
                continue;
            }

            HubspotObjectLink::query()->updateOrCreate(
                [
                    'lookup_hash' => HubspotObjectLink::lookupHashFor($model->getMorphClass()),
                    'model_id' => (string) $model->getKey(), // @phpstan-ignore-line cast.string
                    'object_type' => $binding->objectType,
                ],
                [
                    'model_type' => $model->getMorphClass(),
                    'hubspot_id' => $object->id,
                    'synced_at' => Carbon::now(),
                    'is_stale' => false,
                    'stale_at' => null,
                ],
            );

            $this->archiveIfTheModelWasDeletedMeanwhile($model);
        }
    }

    private function archiveIfTheModelWasDeletedMeanwhile(Model $model): void
    {
        $usesSoftDeletes = in_array(SoftDeletes::class, class_uses_recursive($model), true);
        $fresh = $model->newQueryWithoutScopes()->find($model->getKey());

        /** @var HubspotObserver $observer */
        $observer = App::make(HubspotObserver::class);

        if (! $fresh instanceof Model) {
            $usesSoftDeletes ? $observer->forceDeleted($model) : $observer->deleted($model);

            return;
        }

        if ($usesSoftDeletes && $fresh->trashed() === true) { // @phpstan-ignore-line method.notFound
            $observer->trashed($fresh);
        }
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

    /** @param list<BatchError> $errors */
    private function logErrors(array $errors, string $objectType): void
    {
        foreach ($errors as $error) {
            Log::error($error->message, [
                'object_type' => $objectType,
                'category' => $error->category,
                'status' => $error->status,
                'context' => $error->context,
            ]);
        }
    }

    private function modelIsTrashed(Model $model): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model), true)
            && $model->trashed() === true; // @phpstan-ignore-line method.notFound
    }
}
