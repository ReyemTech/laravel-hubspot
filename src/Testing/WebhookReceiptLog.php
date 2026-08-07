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
 */
final class WebhookReceiptLog
{
    /**
     * @var list<NormalizedWebhookEvent>
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
     * @param  array<string, mixed>  $expected
     */
    public function assertWebhookHandled(string $eventKey, array $expected = []): void
    {
        $matchingKey = array_values(array_filter(
            $this->handled,
            static fn (NormalizedWebhookEvent $event): bool => $event->subscriptionType === $eventKey,
        ));

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
     * @param  list<NormalizedWebhookEvent>  $receipts
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
     * @param  list<NormalizedWebhookEvent>  $receipts
     */
    private static function describeAll(array $receipts): string
    {
        return implode('; ', array_map(
            static fn (NormalizedWebhookEvent $event): string => sprintf('%s (eventId %s)', $event->subscriptionType, $event->eventId),
            $receipts,
        ));
    }

    /**
     * @param  array<string, mixed>  $expected
     */
    private static function describeExpected(array $expected): string
    {
        return (string) json_encode($expected);
    }
}
