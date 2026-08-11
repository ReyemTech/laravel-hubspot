<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Testing;

use PHPUnit\Framework\Assert as PHPUnitAssert;
use ReyemTech\Hubspot\Webhooks\NormalizedWebhookEvent;

/**
 * The inbound receipt record `Hubspot::assertWebhookHandled()` reads (Phase 2's deferred
 * `assertWebhookHandled`, closing `.planning/phases/02-gateway-layer/deferred-items.md` §02-06).
 *
 * **Deliberately a second, independent record from {@see RequestLog}'s outbound Guzzle history.**
 * Inbound webhook handling never travels the outgoing SDK transport, so an assertion on this surface
 * that borrowed the outbound log would pass or fail on unrelated traffic (05-RESEARCH.md Pitfall 5)
 * -- an outbound write and an inbound receipt are recorded here as two disjoint facts, never merged
 * into one.
 *
 * Records only what `Webhooks\ProcessWebhookEventJob::handle()` reports AFTER
 * `Contracts\WebhookEventStore::complete()` returns -- a receipt is a record that the work finished,
 * never that it merely started, so an item whose handler threw leaves no receipt at all
 * (T-05-16, threat register).
 *
 * Keys are not renumbered anywhere in this class, mirroring {@see RequestLog}'s own documented
 * reason: nothing here reads a position, so every collection is `array<int, ...>` rather than
 * `list<...>`, and an `array_values()` call to satisfy a narrower docblock would be a line no test
 * could distinguish from its own absence.
 */
final class WebhookReceiptLog
{
    /**
     * @var array<int, NormalizedWebhookEvent>
     */
    private array $handled = [];

    public function record(NormalizedWebhookEvent $event): void
    {
        $this->handled[] = $event;
    }

    /**
     * Asserts that one single handled receipt carries `$eventKey` as its `subscriptionType` and,
     * when given, every one of `$expected`'s normalized-field values -- mirroring
     * `RequestLog::assertSynced()`'s one-record rule: two receipts that between them carry every
     * expected field, but neither one alone, must not satisfy this.
     *
     * `$expected` accepts the array of normalized fields, or the bare event id the canonical design
     * spec documents (`assertWebhookHandled('deal.creation', $eventId)`) as shorthand for
     * `['eventId' => ...]`.
     *
     * @param  array<string, mixed>|string  $expected
     */
    public function assertWebhookHandled(string $eventKey, string|array $expected = []): void
    {
        // The string shorthand the canonical design spec documents --
        // `assertWebhookHandled('deal.creation', $eventId)` -- normalised ONCE, here, where the
        // only implementation lives. The two public entry points that delegate to this method
        // widen their own signatures and hand the value straight through, so there is one place
        // that decides what the shorthand means.
        if (is_string($expected)) {
            $expected = ['eventId' => $expected];
        }

        $matchingKey = array_filter(
            $this->handled,
            static fn (NormalizedWebhookEvent $event): bool => $event->subscriptionType === $eventKey,
        );

        PHPUnitAssert::assertNotSame(
            [],
            $matchingKey,
            sprintf(
                "Expected a HubSpot webhook of '%s' to have been handled, but none was. %s",
                $eventKey,
                $this->handledSummary(),
            ),
        );

        if ($expected === []) {
            return;
        }

        PHPUnitAssert::assertTrue(
            self::someReceiptCarriesAll($matchingKey, $expected),
            sprintf(
                "Expected a handled HubSpot webhook of '%s' to carry %s on one receipt, but no single receipt did. Handled: %s.",
                $eventKey,
                self::describeExpected($expected),
                self::describeAll($matchingKey),
            ),
        );
    }

    /**
     * @param  array<int, NormalizedWebhookEvent>  $receipts
     * @param  array<string, mixed>  $expected
     */
    private static function someReceiptCarriesAll(array $receipts, array $expected): bool
    {
        foreach ($receipts as $receipt) {
            if (self::receiptCarriesAll($receipt, $expected)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $expected
     */
    private static function receiptCarriesAll(NormalizedWebhookEvent $receipt, array $expected): bool
    {
        $fields = get_object_vars($receipt);

        foreach ($expected as $name => $value) {
            if (! array_key_exists($name, $fields) || $fields[$name] !== $value) {
                return false;
            }
        }

        return true;
    }

    private function handledSummary(): string
    {
        if ($this->handled === []) {
            return 'No webhook was handled at all.';
        }

        return sprintf('Handled: %s.', self::describeAll($this->handled));
    }

    /**
     * @param  array<int, NormalizedWebhookEvent>  $receipts
     */
    private static function describeAll(array $receipts): string
    {
        return implode('; ', array_map(
            static fn (NormalizedWebhookEvent $event): string => sprintf('%s (eventId %s)', $event->subscriptionType, $event->eventId),
            $receipts,
        ));
    }

    /**
     * Wrapped in `sprintf` rather than cast, mirroring `RequestLog::describeRecord()`'s own reason:
     * `json_encode` is typed `string|false`, and a `(string)` cast here would be a mutant nothing
     * could kill.
     *
     * @param  array<string, mixed>  $expected
     */
    private static function describeExpected(array $expected): string
    {
        return sprintf('%s', json_encode($expected));
    }
}
