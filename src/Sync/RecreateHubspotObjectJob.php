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
use Illuminate\Support\Facades\Log;
use ReyemTech\Hubspot\Gateway\Contracts\NonRetryingObjectGatewayContract;

/**
 * The queued job behind `hubspot.auto_sync.on_restore => 'recreate'` (SYNC-04).
 *
 * A third job rather than a mode flag, for the reason {@see ArchiveHubspotObjectJob} is a second
 * one: a mode parameter would make the HTTP verb depend on a constructor argument, which is the
 * shape `04-CONTEXT.md`'s Phase 2 note already rejected. Here the verb IS the feature.
 *
 * ## Why this cannot be `SyncHubspotObjectJob`
 *
 * That job's no-link branch UPSERTS on the model's `id_property`, and deliberately so (D-11): a
 * create whose response was lost has to converge onto the record it already made rather than make
 * a second one. `recreate` asks for the opposite. Its entire purpose is to stop pointing at the
 * archived record and start a new one, and an upsert on an unchanged unique value -- the same
 * email, typically -- is an instruction to converge onto whatever HubSpot matches. Codex raised
 * this on PR #49; the upsert path reached HubSpot's batch-upsert endpoint, so the record it
 * converged onto was not this package's to predict.
 *
 * **This does not guarantee a new object, and must not claim to.** HubSpot retains a unique
 * property value on an archived record, so creating with the same value can be REJECTED for
 * conflicting with the very object this restore is forking away from. That arrives as this
 * package's own `ApiException` carrying HubSpot's own reason, which an operator can read and act
 * on. What it can no longer do is silently match the archived record and go on writing to it.
 *
 * Everything else is the sync job's shape and the sync job's reasons: queue primitives per D-07,
 * collaborators resolved per call so `Hubspot::fake()`'s transport swap is picked up, and the link
 * row keyed on `lookupHashFor()` so this write and every read agree on one encoding.
 */
final class RecreateHubspotObjectJob implements ShouldQueue
{
    use Batchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * D-10: `CallQueuedHandler::handleModelNotFound()` deletes the queue message before `handle()`
     * runs when the payload's model can no longer be found. A restore that is itself undone by a
     * hard delete before the worker gets here has nothing left to recreate.
     */
    public bool $deleteWhenMissingModels;

    /**
     * **This job is never retried, and that is the point** (Codex, PR #49).
     *
     * A create is not idempotent and this one cannot be made so. If the create succeeds and the
     * worker then dies before `updateOrCreate()` lands -- or the acknowledgement is simply lost and
     * the broker redelivers -- a second attempt issues a second create, and the result is two
     * ACTIVE CRM objects for one model with only the last one linked locally. Nothing durable
     * distinguishes "the response was lost" from "the request failed", which is exactly the
     * distinction a retry has to make.
     *
     * `SyncHubspotObjectJob` does not have this problem because it upserts, and D-11 chose that verb
     * for precisely this reason: a create whose response was lost converges instead of duplicating.
     * `recreate` is the one path where converging is the wrong answer, so it gives up the retry
     * instead. When you cannot converge, do not repeat.
     *
     * The cost is real and stated rather than hidden: a recreate that fails for a transient reason
     * -- a 429, a blip -- is not retried either. It lands in `failed_jobs` with HubSpot's own reason
     * attached, which is the correct destination for an operation that forks CRM history and cannot
     * be undone. An operator re-dispatching it knowingly is safe; a queue worker doing so silently
     * is not.
     *
     * Assigned in the constructor body rather than as the property's own default, for the reason
     * `$deleteWhenMissingModels` is: `pest --mutate` reports a mutation on a bare property default
     * as UNCOVERED, because a property declaration is not an executed line coverage can attribute a
     * test to. The worker reads it off the live object either way.
     */
    public int $tries;

    public function __construct(public Model $model)
    {
        $this->deleteWhenMissingModels = true;
        $this->tries = 1;
    }

    public function handle(ModelBindings $bindings, PropertyMapper $mapper, NonRetryingObjectGatewayContract $gateway): void
    {
        // The same race {@see SyncHubspotObjectJob} guards against, and worse here, because this
        // job CREATES (Codex, PR #49). Queued is the default, so a model restored and then
        // soft-deleted again before the worker runs arrives trashed -- `SerializesModels` restores
        // a trashed row rather than discarding the job. Creating then would leave an ACTIVE HubSpot
        // object behind a deleted local model, and nothing would clean it up: the observer dropped
        // the old link before dispatching, so the intervening `trashed` found no link to archive.
        //
        // Returning is the correct end state, not merely the safe one. The record this restore was
        // forking away from is still archived, the model is deleted again, and nothing points
        // anywhere -- which is what a deleted model should look like.
        if ($this->isTrashed($this->model)) {
            Log::info(
                'A HubSpot recreate was skipped because its model was deleted again before the '
                .'job ran. The archived record it was forking away from stays archived.',
                ['model' => get_class($this->model), 'model_id' => $this->model->getKey()],
            );

            return;
        }

        // A link exists again, so there is nothing left to recreate (Codex, PR #49). The observer
        // drops the old link before dispatching, so any link found HERE was written after that --
        // and the race is ordinary under the default queued `created` sync: a model created,
        // soft-deleted and restored before its initial SyncHubspotObjectJob runs leaves the restore
        // seeing no link, while that older job then upserts and writes a live one. Creating on top
        // of it would leave a second ACTIVE CRM object and overwrite the link with its id, so the
        // first object is orphaned and nothing local names it.
        //
        // Any link at all is enough to stop: whatever wrote it did so after the observer decided,
        // so the state this job was dispatched for no longer holds and re-deciding belongs to the
        // observer rather than here.
        /** @var HubspotObjectLink|null $existing */
        $existing = $this->model->hubspotLink()->first(); // @phpstan-ignore-line method.notFound

        if ($existing !== null) {
            Log::info(
                'A HubSpot recreate was skipped because a link already exists again: another sync '
                .'linked this model after the restore decided there was nothing to point at.',
                ['model' => get_class($this->model), 'model_id' => $this->model->getKey()],
            );

            return;
        }

        $binding = $bindings->for(get_class($this->model));

        /** @var array<string, string|Closure> $map */
        $map = $this->model->getHubspotMap(); // @phpstan-ignore-line method.notFound

        $object = $gateway->create($binding->objectType, $mapper->map($this->model, $map));

        // Model::getKey() is declared `@return mixed` in the framework itself; D-18 is the decision
        // that this column stores it as a string whichever key strategy the model uses.
        $modelId = (string) $this->model->getKey(); // @phpstan-ignore-line cast.string

        // getMorphClass(), never get_class(), and lookup_hash rather than the raw model_type --
        // both for the reasons SyncHubspotObjectJob's own write states at length (Codex, PR #39).
        $morphClass = $this->model->getMorphClass();

        HubspotObjectLink::query()->updateOrCreate(
            [
                'lookup_hash' => HubspotObjectLink::lookupHashFor($morphClass),
                'model_id' => $modelId,
                'object_type' => $binding->objectType,
            ],
            [
                'model_type' => $morphClass,
                'hubspot_id' => $object->id,
                'synced_at' => Carbon::now(),
                // The fork is complete, so the link is current rather than stale and this package
                // has not archived the new record. `updateOrCreate` rather than `create` because
                // the local write should not fail on a row the guard above raced past, even though
                // that guard means it is a create in every path a test can reach.
                'is_stale' => false,
                'stale_at' => null,
                'archived_at' => null,
            ],
        );

        $this->archiveIfTheModelWasDeletedMeanwhile($binding->objectType, $object->id, $gateway);
    }

    /**
     * Converges on the delete that raced this create, rather than pretending to exclude it (Codex,
     * PR #49).
     *
     * The guard at the top of `handle()` is a check-before-act on an in-memory model, and no
     * arrangement of checks makes it anything else: a soft delete landing between the last check
     * and the `create()` above leaves an ACTIVE HubSpot object behind a deleted local model, and no
     * `trashed` handler will clean it up because the observer had already dropped the link that
     * handler looks for.
     *
     * **A lock was considered and rejected.** Mutual exclusion here would have to be taken by the
     * DELETE path as well as by this job -- an exclusion one side observes is not exclusion -- so
     * every delete of every bound model would acquire a lock, on every consumer, to protect one
     * opt-in restore policy. That is a cost imposed on everybody for a race almost nobody runs.
     *
     * So the state is made to converge instead. The model is re-read from the database, without
     * scopes so a trashed row is actually found, and if it came back deleted the object this job
     * just created is archived immediately and the link stamped accordingly -- exactly what the
     * `trashed` handler would have done had it been able to see it. The window is not closed, it is
     * made self-correcting, and one more delete landing after THIS read simply produces the same
     * outcome on its own next pass.
     */
    private function archiveIfTheModelWasDeletedMeanwhile(
        string $objectType,
        string $hubspotId,
        NonRetryingObjectGatewayContract $gateway,
    ): void {
        $fresh = $this->model->newQueryWithoutScopes()->find($this->model->getKey());

        if (! $fresh instanceof Model || ! $this->isTrashed($fresh)) {
            return;
        }

        Log::warning(
            'A model was deleted while its HubSpot recreate was in flight, so the record this job '
            .'created has been archived immediately. The local row is gone and nothing points at '
            .'an active CRM object.',
            ['model' => get_class($this->model), 'model_id' => $this->model->getKey()],
        );

        $gateway->archive($objectType, $hubspotId);

        HubspotObjectLink::query()
            ->where('lookup_hash', HubspotObjectLink::lookupHashFor($this->model->getMorphClass()))
            ->where('model_id', (string) $this->model->getKey()) // @phpstan-ignore-line cast.string
            ->where('object_type', $objectType)
            ->update(['archived_at' => Carbon::now()]);
    }

    /**
     * Whether a model applying `SoftDeletes` is currently deleted. Answers false for a model that
     * does not apply the trait at all, which is the correct reading: it cannot be soft-deleted, and
     * a hard delete would have removed the row this method was handed.
     */
    private function isTrashed(Model $model): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model), true)
            && $model->trashed() === true; // @phpstan-ignore-line method.notFound
    }
}
