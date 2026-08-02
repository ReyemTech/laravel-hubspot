<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Sync;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use ReyemTech\Hubspot\Exceptions\ApiException;
use ReyemTech\Hubspot\Gateway\Contracts\ObjectGatewayContract;

/**
 * The queued job that archives one HubSpot record after a local delete (SYNC-04).
 *
 * A separate job rather than a mode flag on {@see SyncHubspotObjectJob}: a mode parameter would
 * make the HTTP verb depend on a constructor argument, which is the shape `04-CONTEXT.md`'s Phase 2
 * note already rejected for the labelled association write. The queue primitives are its sibling's,
 * for its sibling's reasons (D-07, D-08) -- dispatch goes through the injected
 * `Illuminate\Contracts\Bus\Dispatcher`, and `Batchable` is present for a consumer who wraps their
 * own dispatches in `Bus::batch()`. **This package itself never calls `Bus::batch()`.**
 *
 * ## It carries two strings, and never the model
 *
 * This is the one place the two jobs must differ, and the reason is not stylistic. By the time a
 * hard delete has fired, the local row is GONE. `SerializesModels` re-fetches by key on the worker,
 * finds nothing, and `CallQueuedHandler::handleModelNotFound()` DELETES the queue message before
 * `handle()` ever runs when the job declares `deleteWhenMissingModels` -- so a model-carrying
 * archive job would be silently discarded on exactly the deletes it exists to mirror, with no
 * retry, no `failed_jobs` row and no log line. `deleteWhenMissingModels` is absent here for the
 * same reason: with no model in the payload there is no model to be missing.
 *
 * So {@see HubspotObserver} reads the link row while the event is still running -- the only moment
 * at which it is guaranteed to be readable -- and hands this job the two scalars the call needs.
 * That also makes the payload independent of the local schema: nothing about this job's work
 * depends on the local row still existing, because archiving a CRM record never did.
 *
 * `SerializesModels` is still applied, and does nothing here beyond what it does for any payload of
 * plain strings. It is kept so the two jobs stay one shape, and so a future property that IS a
 * model does not arrive without it.
 */
final class ArchiveHubspotObjectJob implements ShouldQueue
{
    use Batchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Public, plain (non-readonly) properties for the reason `SyncHubspotObjectJob::$model` is one:
     * `SerializesModels::__unserialize()` restores them via `ReflectionProperty::setValue()` on a
     * freshly-deserialized instance the constructor never ran for.
     */
    public function __construct(public string $objectType, public string $hubspotId) {}

    /**
     * The gateway is a method parameter resolved by the container PER CALL, never a
     * constructor-captured property -- `Hubspot::fake()` replaces the client factory the gateway is
     * built against, and a gateway resolved once, early, would keep talking to whatever transport
     * was live at construction time.
     *
     * HubSpot's delete IS an archive and it is one-way; see `ObjectGatewayContract::archive()` for
     * what that means for anything downstream of this line.
     *
     * ## A record that is not there is a record that is archived
     *
     * A 404 is treated as a COMPLETED archive rather than a failure, which is `04-06-PLAN.md`'s own
     * rule for a missing link row -- "an archive with nothing to archive is a completed archive" --
     * applied to the record instead of the row. Two paths reach it, and neither is a defect:
     *
     * 1. A PURGE. A soft delete archives from `trashed`, and the later `forceDelete()` archives
     *    again under `hard_delete => 'allow'`. The observer deliberately does NOT deduplicate those
     *    (Codex, PR #49): `trashed()` proves a soft delete happened, never that its archive passed
     *    the gate in force at the time, so skipping the second one would silently orphan a live
     *    HubSpot record whenever deletes were enabled BETWEEN the two events. The redundant request
     *    is the price of never doing that, and this is what stops it costing a failed job as well.
     * 2. Somebody archived the record in the HubSpot UI first.
     *
     * Every other status still throws. A 401, a 429 or a 500 says nothing about whether the record
     * is archived, and swallowing those would turn a retryable failure into a silent no-op.
     */
    public function handle(ObjectGatewayContract $gateway): void
    {
        try {
            $gateway->archive($this->objectType, $this->hubspotId);
        } catch (ApiException $exception) {
            if ($exception->status() !== 404) {
                throw $exception;
            }

            Log::info(
                'A HubSpot record was already gone when this archive ran, so the archive is '
                .'complete. Either a purge archived it on the way down, or somebody archived it '
                .'in HubSpot first.',
                ['object_type' => $this->objectType, 'hubspot_id' => $this->hubspotId],
            );
        }
    }
}
