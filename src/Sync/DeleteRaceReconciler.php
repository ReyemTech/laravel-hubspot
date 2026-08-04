<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Sync;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;

/**
 * Replays a delete event which raced an unlinked HubSpot upsert.
 *
 * The replay, rather than a direct archive, keeps the configured delete policy and its evidence in
 * one place. A delete after the fresh read is handled normally by its own observer event.
 *
 * @internal
 */
final class DeleteRaceReconciler
{
    /**
     * Which event is replayed depends on what happened: a gone soft-deleting row was force-deleted,
     * a gone plain row was deleted, and a present trashed row was soft-deleted. The events do not
     * share a policy: `trashed()` archives unconditionally while hard deletes honour `hard_delete`.
     */
    public function reconcile(Model $model): void
    {
        $usesSoftDeletes = in_array(SoftDeletes::class, class_uses_recursive($model), true);
        $fresh = $model->newQueryWithoutScopes()->find($model->getKey());

        /** @var HubspotObserver $observer */
        $observer = App::make(HubspotObserver::class);

        if (! $fresh instanceof Model) {
            $this->logRacedDelete($model);

            $usesSoftDeletes
                ? $observer->forceDeleted($model)
                : $observer->deleted($model);

            return;
        }

        if ($usesSoftDeletes && $fresh->trashed() === true) { // @phpstan-ignore-line method.notFound
            $this->logRacedDelete($model);

            $observer->trashed($fresh);
        }
    }

    private function logRacedDelete(Model $model): void
    {
        Log::info(
            'A model was deleted while its HubSpot sync was in flight, so the delete policy is '
            .'being applied now that the link it needed exists.',
            ['model' => get_class($model), 'model_id' => $model->getKey()],
        );
    }
}
