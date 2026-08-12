<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Testing;

use DateTimeInterface;
use PHPUnit\Framework\Assert as PHPUnitAssert;

/**
 * The inbound receipt record `Hubspot::assertSignalRecorded()`, `assertSignalFlushed()` and
 * `assertPropertyRolledUp()` read (SIG-08) -- the THIRD instance of the shape
 * {@see WebhookReceiptLog} established: a per-concern log, owned by `HubspotManager`, reset in
 * `flushState()`, written to only while a fake is installed (see
 * `Signals\Contracts\SignalReceiptRecorder`'s own docblock for the inversion this rests on).
 *
 * **Deliberately a second, independent record from {@see RequestLog}'s outbound Guzzle history**,
 * for the identical reason {@see WebhookReceiptLog}'s own docblock gives: a signal is buffered and
 * flushed entirely inside this process (SIG-02), and `FlushSignalsJob`'s own write is the ONLY
 * outbound HTTP in the phase -- an assertion on either surface that borrowed the other's log would
 * pass or fail on unrelated traffic.
 *
 * `recordFlushed()` is written to only AFTER `FlushSignalsJob` confirms a subject's write and
 * appends its trail -- a receipt records that work FINISHED, never that it merely started,
 * mirroring `WebhookReceiptLog`'s own rule.
 *
 * **`assertPropertyRolledUp()` requires that ONE flushed record carried both the property and the
 * expected value together.** This is the Codex P1 finding on PR #20 that
 * `RequestLog::assertSynced()` already carries, reproduced here for a single property/value pair:
 * checking "was this property ever present" and "was this value ever present anywhere" as two
 * INDEPENDENT facts would let a value recorded under a completely different property satisfy an
 * expectation it never carried. The per-property value search below is scoped to the exact
 * property key on every record, never to the record's values as a whole, which is what closes
 * that gap.
 *
 * Failure messages name the signal, the visitor id, the subject and the property under assertion
 * -- never the whole recorded log. It holds the consumer's own customers' behavioural payloads for
 * the duration of a test, and a CI transcript outlives the test run (T-06-33).
 *
 * Keys are not renumbered anywhere in this class, mirroring {@see RequestLog}'s and
 * {@see WebhookReceiptLog}'s own documented reason: nothing here reads a position, so every
 * collection is `array<int, ...>` rather than `list<...>`.
 */
final class SignalReceiptLog
{
    /**
     * @var array<int, array{visitorId: string, signalName: string, properties: array<string, mixed>, occurredAt: DateTimeInterface}>
     */
    private array $buffered = [];

    /**
     * @var array<int, array{subjectType: string, subjectId: string, properties: array<string, mixed>}>
     */
    private array $flushed = [];

    /**
     * @param  array<string, mixed>  $properties
     */
    public function recordBuffered(string $visitorId, string $signalName, array $properties, DateTimeInterface $occurredAt): void
    {
        $this->buffered[] = [
            'visitorId' => $visitorId,
            'signalName' => $signalName,
            'properties' => $properties,
            'occurredAt' => $occurredAt,
        ];
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public function recordFlushed(string $subjectType, string $subjectId, array $properties): void
    {
        $this->flushed[] = [
            'subjectType' => $subjectType,
            'subjectId' => $subjectId,
            'properties' => $properties,
        ];
    }

    /**
     * Asserts that one single buffered entry carries `$visitorId`/`$signalName` and, when given,
     * every one of `$expected`'s property values -- mirroring `WebhookReceiptLog::assertWebhookHandled()`'s
     * exact two-step structure: two entries that between them carry every expected field, but
     * neither one alone, must not satisfy this.
     *
     * @param  array<string, mixed>  $expected
     */
    public function assertSignalRecorded(string $visitorId, string $signalName, array $expected = []): void
    {
        $matching = array_filter(
            $this->buffered,
            static fn (array $entry): bool => $entry['visitorId'] === $visitorId && $entry['signalName'] === $signalName,
        );

        PHPUnitAssert::assertNotSame(
            [],
            $matching,
            sprintf(
                "Expected signal '%s' to have been recorded for visitor '%s', but none was. %s",
                $signalName,
                $visitorId,
                $this->bufferedSummary(),
            ),
        );

        if ($expected === []) {
            return;
        }

        PHPUnitAssert::assertTrue(
            self::someEntryCarriesAll($matching, $expected),
            sprintf(
                "Expected a recorded signal '%s' for visitor '%s' to carry %s on one entry, but no single entry did.",
                $signalName,
                $visitorId,
                self::describeExpected($expected),
            ),
        );
    }

    /**
     * Asserts that a flushed record exists for `$subjectType`/`$subjectId` and, when given, that
     * one single flushed record carries every one of `$expected`'s property values.
     *
     * @param  array<string, mixed>  $expected
     */
    public function assertSignalFlushed(string $subjectType, string $subjectId, array $expected = []): void
    {
        $matching = self::flushedFor($this->flushed, $subjectType, $subjectId);

        PHPUnitAssert::assertNotSame(
            [],
            $matching,
            sprintf(
                "Expected subject '%s#%s' to have been flushed, but it was not. %s",
                $subjectType,
                $subjectId,
                $this->flushedSummary(),
            ),
        );

        if ($expected === []) {
            return;
        }

        PHPUnitAssert::assertTrue(
            self::someEntryCarriesAll($matching, $expected),
            sprintf(
                "Expected the flushed record for subject '%s#%s' to carry %s on one record, but no single record did.",
                $subjectType,
                $subjectId,
                self::describeExpected($expected),
            ),
        );
    }

    /**
     * Asserts that ONE flushed record for `$subjectType`/`$subjectId` carried `$property` with
     * `$value` -- never a value assembled by checking the property's presence and the value's
     * presence as two independent facts (see the class docblock).
     *
     * The per-property search below exists only to produce a useful diagnosis in the failure
     * message ("the value(s) recorded for it were: ..."); it is never what decides the assertion
     * -- {@see self::someEntryCarriesAll()}, scoped to the single `[$property => $value]` pair, is.
     */
    public function assertPropertyRolledUp(string $subjectType, string $subjectId, string $property, string $value): void
    {
        $matching = self::flushedFor($this->flushed, $subjectType, $subjectId);

        PHPUnitAssert::assertNotSame(
            [],
            $matching,
            sprintf(
                "Expected subject '%s#%s' to have a flushed record carrying '%s' => %s, but the subject was never flushed. %s",
                $subjectType,
                $subjectId,
                $property,
                self::describeValue($value),
                $this->flushedSummary(),
            ),
        );

        $recordedValues = self::valuesRecordedFor($matching, $property);

        PHPUnitAssert::assertTrue(
            self::someEntryCarriesAll($matching, [$property => $value]),
            sprintf(
                "Expected subject '%s#%s' to have property '%s' => %s, but the value(s) recorded for it were: %s.",
                $subjectType,
                $subjectId,
                $property,
                self::describeValue($value),
                self::describeValues($recordedValues),
            ),
        );
    }

    /**
     * @param  array<int, array{subjectType: string, subjectId: string, properties: array<string, mixed>}>  $flushed
     * @return array<int, array{subjectType: string, subjectId: string, properties: array<string, mixed>}>
     */
    private static function flushedFor(array $flushed, string $subjectType, string $subjectId): array
    {
        return array_filter(
            $flushed,
            static fn (array $entry): bool => $entry['subjectType'] === $subjectType && $entry['subjectId'] === $subjectId,
        );
    }

    /**
     * @param  array<int, array{properties: array<string, mixed>}>  $entries
     * @param  array<string, mixed>  $expected
     */
    private static function someEntryCarriesAll(array $entries, array $expected): bool
    {
        foreach ($entries as $entry) {
            if (self::propertiesCarryAll($entry['properties'], $expected)) {
                return true;
            }
        }

        return false;
    }

    /**
     * `array_key_exists` before the comparison, never `($properties[$name] ?? null) === $expected`
     * -- a property never recorded and a property recorded as `null` are different facts, and
     * conflating them would let an expectation of `null` be satisfied by an absence. Mirrors
     * `RequestLog::recordCarriesAll()` exactly.
     *
     * @param  array<string, mixed>  $properties
     * @param  array<string, mixed>  $expected
     */
    private static function propertiesCarryAll(array $properties, array $expected): bool
    {
        foreach ($expected as $name => $value) {
            if (! array_key_exists($name, $properties) || $properties[$name] !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every value recorded for one property name, across every matching record -- scoped strictly
     * to `$record[$name]`, never to a record's values as a whole. This is what stops a value
     * recorded under an unrelated property from satisfying the search (class docblock).
     *
     * @param  array<int, array{properties: array<string, mixed>}>  $entries
     * @return list<mixed>
     */
    private static function valuesRecordedFor(array $entries, string $name): array
    {
        $values = [];

        foreach ($entries as $entry) {
            if (array_key_exists($name, $entry['properties'])) {
                $values[] = $entry['properties'][$name];
            }
        }

        return $values;
    }

    private function bufferedSummary(): string
    {
        if ($this->buffered === []) {
            return 'No signal was recorded at all.';
        }

        return sprintf('Recorded: %s.', self::describeBuffered($this->buffered));
    }

    private function flushedSummary(): string
    {
        if ($this->flushed === []) {
            return 'No subject was flushed at all.';
        }

        return sprintf('Flushed: %s.', self::describeFlushed($this->flushed));
    }

    /**
     * Identifiers only -- signal name and visitor id -- never the recorded `properties`. See the
     * class docblock's data-handling rule.
     *
     * @param  array<int, array{visitorId: string, signalName: string}>  $entries
     */
    private static function describeBuffered(array $entries): string
    {
        return implode('; ', array_map(
            static fn (array $entry): string => sprintf('%s (visitor %s)', $entry['signalName'], $entry['visitorId']),
            $entries,
        ));
    }

    /**
     * Identifiers only -- subject type and id -- never the recorded `properties`. See the class
     * docblock's data-handling rule.
     *
     * @param  array<int, array{subjectType: string, subjectId: string}>  $entries
     */
    private static function describeFlushed(array $entries): string
    {
        return implode('; ', array_map(
            static fn (array $entry): string => sprintf('%s#%s', $entry['subjectType'], $entry['subjectId']),
            $entries,
        ));
    }

    /**
     * @param  array<string, mixed>  $expected
     */
    private static function describeExpected(array $expected): string
    {
        return sprintf('%s', json_encode($expected));
    }

    /**
     * @param  list<mixed>  $values
     */
    private static function describeValues(array $values): string
    {
        if ($values === []) {
            return 'not recorded';
        }

        return implode(', ', array_map(self::describeValue(...), $values));
    }

    /**
     * The value and its type -- HubSpot property values are strings, so a message that printed
     * `3` for both an integer and the string `'3'` would hide the one difference `assertSame`'s
     * strict comparison exists to catch. Mirrors `RequestLog::describeValue()`.
     */
    private static function describeValue(mixed $value): string
    {
        return sprintf('%s (%s)', json_encode($value), get_debug_type($value));
    }
}
