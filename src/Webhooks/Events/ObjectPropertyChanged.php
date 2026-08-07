<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Webhooks\Events;

use ReyemTech\Hubspot\Webhooks\NormalizedWebhookEvent;
use ReyemTech\Hubspot\Webhooks\ProcessWebhookEventJob;
use ReyemTech\Hubspot\Webhooks\TypedEventMap;

/**
 * Dispatched by {@see ProcessWebhookEventJob} immediately after the generic
 * {@see HubspotWebhookReceived} for every `*.propertyChange` item whose object type has no more
 * specific typed event of its own (D-09) -- `contact.propertyChange` resolves the more specific
 * {@see ContactPropertyChanged} instead; see {@see TypedEventMap}'s own docblock for the
 * most-specific-first resolution this class is the family fallback of.
 *
 * `$propertyName` and `$propertyValue` are read straight from the same
 * `NormalizedWebhookEvent` every listener already has through `$event` -- carried here as their own
 * properties purely so a listener narrowly interested in a property change does not have to know
 * which of `NormalizedWebhookEvent`'s many optional fields apply to this family.
 *
 * `final readonly`; a plain value event with no documented extension point (STANDARDS §8).
 */
final readonly class ObjectPropertyChanged
{
    public ?string $propertyName;

    public ?string $propertyValue;

    public function __construct(public NormalizedWebhookEvent $event)
    {
        $this->propertyName = $event->propertyName;
        $this->propertyValue = $event->propertyValue;
    }
}
