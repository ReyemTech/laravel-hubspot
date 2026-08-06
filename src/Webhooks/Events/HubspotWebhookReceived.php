<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Webhooks\Events;

use ReyemTech\Hubspot\Webhooks\NormalizedWebhookEvent;
use ReyemTech\Hubspot\Webhooks\ProcessWebhookEventJob;

/**
 * Emitted by {@see ProcessWebhookEventJob} for every accepted webhook
 * item, before any recognized-subscription-type event or configured handler runs (D-06). A plain
 * Laravel event: any application listener may subscribe to it with no package-specific interface.
 *
 * `final readonly`; carries one immutable value with no documented extension point (STANDARDS §8).
 */
final readonly class HubspotWebhookReceived
{
    public function __construct(public NormalizedWebhookEvent $event) {}
}
