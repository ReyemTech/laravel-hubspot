<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Webhooks\Events;

use ReyemTech\Hubspot\Webhooks\NormalizedWebhookEvent;
use ReyemTech\Hubspot\Webhooks\ProcessWebhookEventJob;

/**
 * Dispatched by {@see ProcessWebhookEventJob} immediately after the generic
 * {@see HubspotWebhookReceived} for every `*.creation` and `*.deletion` item, regardless of object
 * type -- both transitions collapse onto this one class rather than two (D-09).
 *
 * `$transition` is derived from `$event->subscriptionType`'s own suffix, the same suffix
 * `Webhooks\TypedEventMap` used to resolve this class in the first place -- never re-guessed from
 * anything else on the normalized event.
 *
 * `final readonly`; a plain value event with no documented extension point.
 */
final readonly class ObjectLifecycleChanged
{
    public WebhookLifecycleTransition $transition;

    public function __construct(public NormalizedWebhookEvent $event)
    {
        $this->transition = WebhookLifecycleTransition::from(self::suffixOf($event->subscriptionType));
    }

    /**
     * The portion of a subscription type after its final `.` -- `"company.creation"` yields
     * `"creation"`. Safe to call `WebhookLifecycleTransition::from()` on unchecked, because this class
     * is only ever constructed by `TypedEventMap::resolve()` having already matched a `*.creation` or
     * `*.deletion` family row for this exact subscription type.
     */
    private static function suffixOf(string $subscriptionType): string
    {
        $separator = strrpos($subscriptionType, '.');

        return $separator === false ? $subscriptionType : substr($subscriptionType, $separator + 1);
    }
}
