<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Webhooks;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Config;
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
 * -- so `handle()` returns having done nothing. A `Held` claim means another worker's claim on this
 * event is still inside its lease window, doing the work right now; `handle()` returns without
 * emitting anything and, critically, WITHOUT failing the job -- failing here would retry a race the
 * other worker is already winning. Only an `Acquired` claim reaches the dispatch this plan's
 * predecessor (05-01) established, and `complete()` is called only AFTER that dispatch returns. This
 * is what makes a redelivery of a still-in-flight event safe (05-RESEARCH.md Pitfall 3) rather than a
 * second, concurrent run of the same handlers.
 *
 * A failure between the claim and `complete()` releases the claim through
 * {@see WebhookEventStore::abandon()} and rethrows untouched, so Laravel fails and retries the job
 * exactly as it always did. That release is not housekeeping: without it the retry arrives inside the
 * lease, is answered `Held` — indistinguishable from a concurrent worker — and returns successfully,
 * so Laravel deletes the job and the row stays claimed-but-unhandled forever while HubSpot, having
 * had its 204, never re-sends. `catch`, never `finally`: a completed claim must stay completed. The
 * lease still covers the worker that dies without unwinding, which is the one case nothing
 * in-process can clean up after.
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
