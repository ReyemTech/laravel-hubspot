<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Webhooks;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use ReyemTech\Hubspot\Webhooks\Events\HubspotWebhookReceived;

/**
 * The one queued unit of work per validated batch item (HOOK-01). `Webhooks\WebhookController`
 * dispatches exactly one of these per item in a signed batch, never one job per batch — so a
 * queue-worker failure on one item never blocks the others, and D-14's "dispatch failure means 500"
 * rule is asserted at the handoff loop in the controller, not inside this job.
 *
 * This plan (05-01) ships the generic receipt path only: `handle()` fires
 * {@see HubspotWebhookReceived} and stops there. Recognized-subscription-type events, configured
 * handlers, and the durable claim/complete event store are a later plan's concern (05-CONTEXT.md
 * D-01, D-06, D-08).
 *
 * Collaborators are resolved as `handle()` parameters, never constructor-captured properties — the
 * same reason `Sync\SyncHubspotObjectJob` does (see its own docblock): a dispatcher captured at
 * construction would still answer after `Hubspot::fake()` or a container rebind changed what a
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

    public function handle(Dispatcher $events): void
    {
        $events->dispatch(new HubspotWebhookReceived($this->event));
    }
}
