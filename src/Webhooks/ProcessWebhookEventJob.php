<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Webhooks;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Config;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Webhooks\Contracts\WebhookEventStore;
use ReyemTech\Hubspot\Webhooks\Contracts\WebhookHandler;
use ReyemTech\Hubspot\Webhooks\Contracts\WebhookReceiptRecorder;
use ReyemTech\Hubspot\Webhooks\Events\HubspotWebhookReceived;
use Throwable;

/**
 * The one queued unit of work per validated batch item (HOOK-01). `Webhooks\WebhookController`
 * dispatches exactly one of these per item in a signed batch, never one job per batch — so a
 * queue-worker failure on one item never blocks the others, and D-14's "dispatch failure means 500"
 * rule is asserted at the handoff loop in the controller, not inside this job.
 *
 * ## Claim, dispatch, complete -- in that order, with nothing between dispatch and either end (D-03)
 *
 * `handle()` opens by claiming the event's id ({@see WebhookEventStore::claim()}). A `Handled` claim
 * means this exact eventId already completed successfully -- HOOK-01's durable redelivery guarantee
 * -- so `handle()` returns having done nothing. A `Held` claim means another claim on this event is
 * still inside its lease window, and what `handle()` does with it DEPENDS ON THE QUEUE DRIVER --
 * see the branch itself for the measurements that forced that. On a driver that can defer it
 * queues a FRESH copy of itself past the lease; on `sync`, which cannot defer, it raises so the
 * controller answers 500 and HubSpot redelivers. Neither returns silently, because Laravel reads
 * that as success and deletes a job that is the event's last chance once the holder has died.
 * Only an `Acquired` claim reaches the dispatch this plan's
 * predecessor (05-01) established, and `complete()` is called only AFTER that dispatch returns. This
 * is what makes a redelivery of a still-in-flight event safe (05-RESEARCH.md Pitfall 3) rather than a
 * second, concurrent run of the same handlers.
 *
 * **That exclusion holds for the length of the lease, and no longer — stated plainly because an
 * earlier version of this paragraph implied otherwise.** A lease is a timeout, not a lock: a
 * handler still running when `hubspot.webhooks.claim_lease` elapses no longer holds anything, and
 * a waiting duplicate will reclaim the event and run every handler for it alongside the first.
 * Nothing here detects that, because a worker that is merely SLOW is indistinguishable from one
 * that has died, which is the same ambiguity the lease exists to resolve in the other direction.
 *
 * This is why `config/hubspot.php` requires handlers to be idempotent, and it is a real
 * requirement rather than defensive advice: an ordinary retry already re-runs handlers that
 * succeeded, and a handler that outlives the lease can be running twice at once. Set `claim_lease`
 * above the slowest handler's worst case. Fencing each claim generation so a stale worker cannot
 * complete is tracked separately; it is a change to the store's contract, not to this job.
 *
 * A failure between the claim and `complete()` releases the claim through
 * {@see WebhookEventStore::abandon()} and rethrows untouched, so Laravel fails and retries the job
 * exactly as it always did. That release is not housekeeping: without it the retry arrives inside the
 * lease, is answered `Held` — indistinguishable from a concurrent worker — and returns successfully,
 * so Laravel deletes the job and the row stays claimed-but-unhandled forever while HubSpot, having
 * had its 204, never re-sends. `catch`, never `finally`: a completed claim must stay completed.
 *
 * The worker that DIES runs no `catch`, so `abandon()` cannot cover it — and the lease alone does
 * not either. A lease makes the row reclaimable; it does not make anything come back to reclaim it,
 * and the queue's own retry lands after `retry_after` (90 seconds by default), inside the lease.
 * That is what the `Held` branch above is for. The two together are what make D-03's "leaves it
 * retryable" true for both a handler that throws and a worker that is killed.
 *
 * ## Typed events (05-03, D-06, D-08, D-09)
 *
 * Immediately after the generic dispatch, `handle()` asks {@see TypedEventMap} to resolve at most
 * one typed event class for the item's `subscriptionType` and, when one resolves, dispatches it
 * too -- never before the generic event, since D-06 makes the generic one the only event every item
 * is guaranteed to reach. An unrecognized subscription type resolves nothing, and no second event is
 * dispatched for it.
 *
 * ## Configured handlers (05-03, D-07, D-08)
 *
 * `HandlerMap::validate()` runs FIRST, before `WebhookEventStore::claim()` -- a configuration typo
 * (a missing class, or one that does not implement `Contracts\WebhookHandler`) must not burn a claim,
 * and must not emit half an item's events before failing. After the typed-event dispatch, every
 * handler class `HandlerMap::resolve()` returns for this item's subscription type is resolved from
 * the container AT EXECUTION TIME -- the same reason
 * `Registry\Console\SyncAssociationsCommand` resolves its gateway inside `handle()`. A handler's own
 * throw still reaches Laravel unchanged so the job fails and D-03's retry holds, and `complete()`
 * below is never reached for that item -- the only thing the surrounding `catch` does before
 * rethrowing is hand the claim back so that retry can actually take it.
 *
 * ## The inbound receipt (05-03, T-05-16)
 *
 * `WebhookReceiptRecorder::recordWebhookHandled()` is called LAST, after `complete()` returns and
 * never before: a receipt is a record that the work finished, and recording it earlier would let
 * `Hubspot::assertWebhookHandled()` pass for an item whose handler threw.
 *
 * Collaborators are resolved as `handle()` parameters, never constructor-captured properties — the
 * same reason `Sync\SyncHubspotObjectJob` does (see its own docblock): a dispatcher or store captured
 * at construction would still answer after `Hubspot::fake()` or a container rebind changed what a
 * fresh resolution would return.
 *
 * `$event` is a public, plain (non-readonly) property rather than constructor-promoted `readonly`,
 * for the identical reason `SyncHubspotObjectJob::$model` is: `SerializesModels`-adjacent queue
 * restoration sets properties via reflection on an instance the constructor never ran for, and a
 * `readonly` property cannot be written to twice. This job carries no Eloquent model, so
 * `SerializesModels` itself is not used — `NormalizedWebhookEvent` is a plain value object PHP's
 * native (de)serialization already round-trips correctly.
 */
final class ProcessWebhookEventJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public function __construct(public NormalizedWebhookEvent $event) {}

    public function handle(
        Dispatcher $events,
        WebhookEventStore $store,
        TypedEventMap $typedEvents,
        HandlerMap $handlers,
        Container $container,
        WebhookReceiptRecorder $receipts,
        BusDispatcher $bus,
        QueueFactory $queue,
    ): void {
        // The inbound half of `hubspot.disabled`, which config/hubspot.php writes as governing BOTH
        // directions. Checked HERE, on the worker, and not only at dispatch: that is what stops
        // items already sitting on the queue when the switch was thrown, exactly as
        // `Sync\SyncGate` does for the outbound half. The same documented limit applies -- a
        // `queue:work` daemon keeps the config it booted with, so flipping this still needs
        // `php artisan queue:restart`.
        //
        // Returns BEFORE the claim, deliberately. A disabled package must leave no trace: taking a
        // claim and then discarding the item would dedupe a redelivery against work nothing ever
        // did, so re-enabling the switch would silently skip everything HubSpot retried meanwhile.
        if (Config::get('hubspot.disabled') === true) {
            return;
        }

        $handlers->validate();

        $claim = $store->claim($this->event);

        // `Held` and `Handled` are NOT the same answer, and treating them alike destroyed events.
        //
        // `Held` means somebody else's claim is still inside its lease. That somebody may be a
        // live worker -- or a worker that DIED holding the claim, which runs no `catch` and so
        // never reaches the `abandon()` below. The queue makes the dead worker's job visible again
        // after `retry_after` (90 seconds by default, far inside the 900-second lease), and if
        // this returned normally there, Laravel would take it for success and delete the last job
        // that would ever have touched the event. The row does become reclaimable once the lease
        // expires; nothing survives to reclaim it, and receipt already answered 204.
        //
        // So the job goes BACK on the queue, delayed past the lease. The live-worker case pays one
        // delayed no-op that then reads `Handled`; the dead-worker case reclaims and runs. Neither
        // fails the job, which is what the three-state claim was always for.
        if ($claim === WebhookEventClaim::Held) {
            /** @var int $claimLease */
            $claimLease = Config::get('hubspot.webhooks.claim_lease');

            // A driver that cannot defer has to be told apart from one that can, and it is told
            // apart by its RESOLVED CLASS, never by the connection's name: a connection named
            // anything at all may be configured `'driver' => 'sync'`, and measurement confirmed
            // one named `inline` resolves to SyncQueue just as `sync` does.
            //
            // `SyncQueue::later()` discards the delay and runs the job inline (measured: a
            // re-dispatch recursed 13 times before a test guard stopped it, and would otherwise
            // exhaust memory). There is no queue to come back to, because the "worker" IS the
            // request -- so the deferral is pushed up to HubSpot instead. Throwing reaches
            // WebhookController's dispatch-loop catch, which answers 500, and HubSpot redelivers
            // later; that is the same "refuse what cannot be processed now" rule the receipt
            // guards already follow, and on this driver it is the only honest one available.
            if ($queue->connection($this->job?->getConnectionName()) instanceof SyncQueue) {
                throw ConfigurationException::webhookHeldOnSynchronousQueue($claimLease);
            }

            // A FRESH job, not `$this->release()`. A held claim is not a failed attempt of this
            // job -- it is "not yet" -- and charging it as one is fatal on a default deployment:
            // `queue:work` ships with `--tries=1`, so a released job returns on attempt 2 and
            // `Worker::markJobAsFailedIfAlreadyExceedsMaxAttempts()` fails it BEFORE it runs. The
            // delayed reclaim this branch exists to arrange would never execute.
            //
            // Declaring `$tries` or `retryUntil()` here would buy the budget at a worse price:
            // both also govern HANDLER failures, so the package would be overriding the retry
            // policy the application chose -- and `retryUntil()` suppresses attempt-limiting
            // outright, letting a permanently-throwing handler run unbounded inside its window.
            // Re-dispatching keeps the two concerns apart: attempts stay the consumer's to budget,
            // and the replacement starts with a full one.
            //
            // The WHOLE lease, not the remainder: the store does not expose how much has elapsed,
            // and over-waiting costs a delay while under-waiting costs another round. Rounds
            // terminate -- a lease always expires, after which the next claim is `Acquired`, or
            // the holder finished and it reads `Handled`.
            $bus->dispatch((new self($this->event))->delay($claimLease));

            return;
        }

        if ($claim !== WebhookEventClaim::Acquired) {
            return;
        }

        // The claim this job now holds must be released if the work below throws, or the queue's
        // own retry cannot get it back: the retry arrives inside the 900-second lease, claim()
        // answers `Held` (indistinguishable from a concurrent worker), this method returns without
        // failing, and Laravel deletes the job as successful. The row then sits claimed and
        // unhandled forever -- prune() only deletes HANDLED rows -- and the delivery was
        // acknowledged 204 at receipt, so HubSpot never re-sends it. D-03 promises a handler
        // failure "leaves it retryable"; without this it left it destroyed.
        //
        // `catch`, deliberately not `finally`: the release must happen on the failure path only.
        // The exception is rethrown untouched, so Laravel still fails and retries the job exactly
        // as before -- this adds cleanup, it does not swallow anything.
        //
        // The lease is unchanged and still needed: it covers the worker that dies without ever
        // reaching this handler, which is the one case nothing in-process can clean up after.
        try {
            $events->dispatch(new HubspotWebhookReceived($this->event));

            $typedEventClass = $typedEvents->resolve($this->event->subscriptionType);

            if ($typedEventClass !== null) {
                $events->dispatch(new $typedEventClass($this->event));
            }

            foreach ($handlers->resolve($this->event->subscriptionType) as $handlerClass) {
                // HandlerMap::validate() already proved every configured entry implements
                // Contracts\WebhookHandler, before this line ever runs -- Larastan's container-make
                // return-type extension only narrows a LITERAL `Foo::class` argument, not a runtime
                // class-string variable, so this cast is the honest, already-proven type.
                /** @var WebhookHandler $handler */
                $handler = $container->make($handlerClass);

                $handler->handle($this->event);
            }
        } catch (Throwable $exception) {
            $store->abandon($this->event->eventId);

            throw $exception;
        }

        $store->complete($this->event->eventId);

        $receipts->recordWebhookHandled($this->event);
    }
}
