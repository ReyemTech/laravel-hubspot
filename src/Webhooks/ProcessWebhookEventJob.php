<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Webhooks;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use ReyemTech\Hubspot\Webhooks\Contracts\WebhookEventStore;
use ReyemTech\Hubspot\Webhooks\Contracts\WebhookHandler;
use ReyemTech\Hubspot\Webhooks\Events\HubspotWebhookReceived;

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
 * predecessor (05-01) established, and `complete()` is called only AFTER that dispatch returns, with
 * no `try`/`catch` around it: a handler exception must escape `handle()` so Laravel retries the
 * queued job, and the row this claim wrote is left claimed rather than marked handled. This is what
 * makes a redelivery of a still-in-flight event safe (05-RESEARCH.md Pitfall 3) rather than a second,
 * concurrent run of the same handlers.
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
 * `Registry\Console\SyncAssociationsCommand` resolves its gateway inside `handle()` -- and invoked
 * with no `try`/`catch` around it: a handler's own throw must reach Laravel so the job fails and
 * D-03's retry holds, and `complete()` below is never reached for that item.
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
    ): void {
        $handlers->validate();

        $claim = $store->claim($this->event);

        if ($claim !== WebhookEventClaim::Acquired) {
            return;
        }

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

        $store->complete($this->event->eventId);
    }
}
