<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Support\Webhooks;

use ReyemTech\Hubspot\Webhooks\NormalizedWebhookEvent;

/**
 * A process-wide, test-only record of which fixture handler classes ran and in what order --
 * `HandlerMapTest`'s way of observing `Webhooks\HandlerMap`'s ordering and de-duplication contract
 * without reaching into its private state.
 */
final class WebhookHandlerCallLog
{
    /**
     * @var list<array{handler: class-string, eventId: string}>
     */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$calls = [];
    }

    /**
     * @param  class-string  $handlerClass
     */
    public static function record(string $handlerClass, NormalizedWebhookEvent $event): void
    {
        self::$calls[] = ['handler' => $handlerClass, 'eventId' => $event->eventId];
    }
}
