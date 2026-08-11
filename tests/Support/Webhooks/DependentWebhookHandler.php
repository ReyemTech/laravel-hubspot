<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Support\Webhooks;

use ReyemTech\Hubspot\Webhooks\Contracts\WebhookHandler;
use ReyemTech\Hubspot\Webhooks\NormalizedWebhookEvent;
use RuntimeException;

final class DependentWebhookHandler implements WebhookHandler
{
    public function __construct(private readonly GreetingService $service) {}

    public function handle(NormalizedWebhookEvent $event): void
    {
        // Proves the dependency actually resolved, rather than merely that construction did not throw.
        if ($this->service->greeting() !== 'hello') {
            throw new RuntimeException('GreetingService did not resolve correctly.');
        }

        WebhookHandlerCallLog::record(self::class, $event);
    }
}
