<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Webhooks\Events;

use ReyemTech\Hubspot\Webhooks\NormalizedWebhookEvent;
use ReyemTech\Hubspot\Webhooks\ProcessWebhookEventJob;

/**
 * Dispatched by {@see ProcessWebhookEventJob} immediately after the generic
 * {@see HubspotWebhookReceived} for every `*.associationChange` item, regardless of object type
 * (D-09).
 *
 * `$associatedObjectId` is HubSpot's `toObjectId` when present -- the object on the other end of the
 * association from this item's own `$event->objectId` -- falling back to `fromObjectId` only when
 * `toObjectId` is absent, so a listener always has one id to act on rather than having to check both
 * fields on the underlying `NormalizedWebhookEvent` itself.
 *
 * `final readonly`; a plain value event with no documented extension point.
 */
final readonly class ObjectAssociationChanged
{
    public ?string $associationType;

    public ?string $associatedObjectId;

    public function __construct(public NormalizedWebhookEvent $event)
    {
        $this->associationType = $event->associationType;
        $this->associatedObjectId = $event->toObjectId ?? $event->fromObjectId;
    }
}
