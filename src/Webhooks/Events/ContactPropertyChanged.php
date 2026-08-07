<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Webhooks\Events;

use ReyemTech\Hubspot\Webhooks\NormalizedWebhookEvent;
use ReyemTech\Hubspot\Webhooks\ProcessWebhookEventJob;
use ReyemTech\Hubspot\Webhooks\TypedEventMap;

/**
 * Dispatched by {@see ProcessWebhookEventJob} immediately after the generic
 * {@see HubspotWebhookReceived} for a `contact.propertyChange` item specifically -- the one
 * registered specialization ROADMAP.md's own success criterion names by class, resolved
 * most-specific-first ahead of the `*.propertyChange` family fallback {@see ObjectPropertyChanged}
 * (see {@see TypedEventMap}).
 *
 * Deliberately not a subclass of `ObjectPropertyChanged`: both are `final` (STANDARDS §8, decision
 * #5), and exactly one of the two is ever emitted for one item (D-06), so there is nothing here for
 * an `instanceof` check against the family class to ever observe.
 *
 * `final readonly`; a plain value event with no documented extension point.
 */
final readonly class ContactPropertyChanged
{
    public ?string $propertyName;

    public ?string $propertyValue;

    public function __construct(public NormalizedWebhookEvent $event)
    {
        $this->propertyName = $event->propertyName;
        $this->propertyValue = $event->propertyValue;
    }
}
