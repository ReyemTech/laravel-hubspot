<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Support\Webhooks;

use ReyemTech\Hubspot\Webhooks\Contracts\WebhookHandler;
use ReyemTech\Hubspot\Webhooks\NormalizedWebhookEvent;

final class RecordingWebhookHandlerB implements WebhookHandler
{
    public function handle(NormalizedWebhookEvent $event): void
    {
        WebhookHandlerCallLog::record(self::class, $event);
    }
}
