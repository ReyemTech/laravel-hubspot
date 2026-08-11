<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Support\Webhooks;

/**
 * A real, existing class that does NOT implement `Webhooks\Contracts\WebhookHandler` -- proves
 * `HandlerMap::validate()` rejects a configured entry naming a class that exists but does not
 * satisfy the interface, distinctly from a class that does not exist at all.
 */
final class NotAWebhookHandler
{
    public function handle(): void {}
}
