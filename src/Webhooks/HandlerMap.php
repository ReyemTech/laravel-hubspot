<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Webhooks;

use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Webhooks\Contracts\WebhookHandler;

/**
 * `hubspot.webhooks.handlers`, validated and resolved (D-07). Reading the config array is the whole
 * of this class's job -- it never resolves a handler instance itself; `ProcessWebhookEventJob`
 * resolves each class name it returns through the container at execution time, the same reason
 * `Registry\Console\SyncAssociationsCommand` resolves its gateway inside `handle()` rather than a
 * constructor.
 *
 * `'*'` is a valid key like any other. It carries no special storage here -- {@see self::resolve()}
 * is what gives it its meaning, by appending its handlers after the requested key's own.
 */
final class HandlerMap
{
    private const string WILDCARD = '*';

    /**
     * @param  array<array-key, mixed>  $configured  `hubspot.webhooks.handlers`, exactly as config()
     *                                               returns it
     */
    public function __construct(private readonly array $configured) {}

    /**
     * Walks the WHOLE configured map once, raising on the first entry that is not a class string, is
     * not an existing class, or does not implement {@see WebhookHandler}. Called from
     * `ProcessWebhookEventJob::handle()` before the claim is taken, so a configuration typo never
     * burns a claim and never emits half an item's events before failing.
     */
    public function validate(): void
    {
        foreach ($this->configured as $eventKey => $entry) {
            foreach (self::normalize($entry) as $handlerClass) {
                self::validateOne((string) $eventKey, $handlerClass);
            }
        }
    }

    /**
     * The ordered, de-duplicated handler class list for one event key: the key's own handlers
     * first, then the `'*'` handlers, with a class already present from the first group not
     * repeated in the second.
     *
     * @return list<class-string<WebhookHandler>>
     */
    public function resolve(string $eventKey): array
    {
        /** @var list<class-string<WebhookHandler>> $keyHandlers */
        $keyHandlers = self::normalize($this->configured[$eventKey] ?? []);
        /** @var list<class-string<WebhookHandler>> $wildcardHandlers */
        $wildcardHandlers = self::normalize($this->configured[self::WILDCARD] ?? []);

        $merged = $keyHandlers;

        foreach ($wildcardHandlers as $handlerClass) {
            if (! in_array($handlerClass, $merged, true)) {
                $merged[] = $handlerClass;
            }
        }

        return $merged;
    }

    /**
     * A bare string and a list are both accepted for one key.
     *
     * The early `is_string()` return and the `array_values()` call are both equivalent mutants
     * `pest --mutate` will report as survivors: a string falls through to the same `[$entry]` either
     * way, and nothing downstream reads a key, only a value, so reindexing an associative entry
     * changes no observable behaviour. Left in for the reason PHPStan's `list<mixed>` return type
     * states honestly, not as a line waiting for a test that could never kill its own mutant.
     *
     * @return list<mixed>
     */
    private static function normalize(mixed $entry): array
    {
        if (is_string($entry)) {
            return [$entry];
        }

        return is_array($entry) ? array_values($entry) : [$entry];
    }

    private static function validateOne(string $eventKey, mixed $handlerClass): void
    {
        if (! is_string($handlerClass) || ! class_exists($handlerClass)) {
            throw ConfigurationException::invalidWebhookHandler($handlerClass, $eventKey);
        }

        if (! is_a($handlerClass, WebhookHandler::class, true)) {
            throw ConfigurationException::invalidWebhookHandler($handlerClass, $eventKey);
        }
    }
}
