<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Webhooks\Events;

/**
 * Which lifecycle transition a {@see ObjectLifecycleChanged} event carries. HubSpot's `*.creation`
 * and `*.deletion` subscription types both collapse onto that one typed event (D-09 -- the initial
 * typed surface covers core semantic FAMILIES, not one class per subscription type), so a listener
 * needs a way to tell the two apart without re-parsing the raw `subscriptionType` string a
 * `NormalizedWebhookEvent` carries.
 *
 * A backed enum, not a bool: `created: true|false` reads as a property change at the call site, where
 * naming the transition reads as what it is.
 */
enum WebhookLifecycleTransition: string
{
    case Creation = 'creation';

    case Deletion = 'deletion';
}
