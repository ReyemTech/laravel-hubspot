<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Webhooks\Contracts;

use ReyemTech\Hubspot\Webhooks\NormalizedWebhookEvent;

/**
 * The package-owned interface a `hubspot.webhooks.handlers` entry must implement (D-07).
 * `Webhooks\HandlerMap::validate()` proves every configured entry satisfies this before any claim is
 * taken, so a caller resolving one through the container always gets an object it can call
 * `handle()` on.
 *
 * **Implementations MUST be idempotent.** D-08 makes a throwing handler retryable, and a retry
 * re-runs every handler configured for that item -- including one that already completed
 * successfully on an earlier attempt.
 */
interface WebhookHandler
{
    public function handle(NormalizedWebhookEvent $event): void;
}
