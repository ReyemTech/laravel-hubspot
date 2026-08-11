<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Webhooks\Contracts;

use ReyemTech\Hubspot\Webhooks\NormalizedWebhookEvent;

/**
 * The same inverted arrow R3 forced for `Sync\SyncStateContract`, for the identical reason:
 * `Webhooks` may not depend on `ReyemTech\Hubspot\Testing` (R4), so the layer that needs the
 * capability -- recording that an item finished, for `Hubspot::assertWebhookHandled()` to read --
 * declares the port and the composition root (`HubspotManager`) implements it.
 * `ServiceProvider::register()` binds the two together, on the line beside the existing
 * `SyncStateContract` binding.
 */
interface WebhookReceiptRecorder
{
    /**
     * Records that this event's handling finished. Called only after
     * `Contracts\WebhookEventStore::complete()` returns -- a receipt is a record that the work
     * finished, never that it merely started.
     */
    public function recordWebhookHandled(NormalizedWebhookEvent $event): void;
}
