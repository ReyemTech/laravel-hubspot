<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Support\Webhooks;

use ReyemTech\Hubspot\Webhooks\Contracts\WebhookHandler;
use ReyemTech\Hubspot\Webhooks\NormalizedWebhookEvent;
use RuntimeException;

final class ThrowingWebhookHandler implements WebhookHandler
{
    public function handle(NormalizedWebhookEvent $event): void
    {
        throw new RuntimeException('ThrowingWebhookHandler always throws.');
    }
}
