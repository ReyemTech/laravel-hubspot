<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Testing;

use PHPUnit\Framework\Assert as PHPUnitAssert;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * **The assertions, and the messages they fail with.**
 *
 * Every one of them reads the `Middleware::history()` request log installed in plan 02-01 — the one
 * record of what left the process — through {@see RecordedRequest}. There is deliberately no second
 * bookkeeping mechanism anywhere in the gateways: a parallel record of what a gateway *believes* it
 * synced can drift from what it sent, and the entire value of `Hubspot::fake()` is that it observes the
 * wire.
 *
 * ## The messages are the deliverable, not decoration
 *
 * A failed `assertSynced` names what was written instead. A failed `assertNothingSynced` names what was
 * written at all. A failed `assertRequestCount` names both the expected and the actual count and then
 * lists the requests. Without that, the first thing every developer does on a red run is add the
 * debugging output the assertion should have produced — and an N+1 reported as "the count was wrong" is
 * a code smell, where the same N+1 reported as "expected 1, made 11, here they are" is a legible test
 * failure (STANDARDS §11, threat T-02-11).
 *
 * Each message is built as a **single line**, and that is a requirement rather than a style choice.
 * PHPUnit appends its own explanation on the following line whenever a custom message is passed to one
 * of its assertions, so a single-line message lets this package's own suite assert the whole thing
 * exactly — see `tests/Support/FailedAssertion.php`, named in prose rather than with an `{@see}` tag on
 * purpose, since Pint's `fully_qualified_strict_types` fixer turns such a tag into a real `use`
 * statement and a production file must not import a test-only class — without asserting on a
 * dependency's wording, which is the mistake 02-05 hit four times in CI.
 *
 * Extracted from {@see HubspotFake} rather than added to it: the fake's job is to be a transport, this
 * class's job is to read the log, and the two together would have taken one file past the 500-line hard
 * gate (STANDARDS §6b — extract immediately, not on the third occurrence).
 *
 * Must NOT name any `HubSpot\*` class (R1): `src/Testing/` is not the Gateway layer.
 */
final readonly class RequestLog
{
    /**
     * Said once and used twice, in `assertSynced` and in `assertRequestCount`. Two copies of a
     * sentence a test asserts exactly is two places for a rewording to be applied to one of.
     */
    private const NOTHING_RECORDED = 'No request was recorded at all.';

    /**
     * @param  list<RecordedRequest>  $requests
     */
    private function __construct(private array $requests) {}

    /**
     * @param  array<int, array{request: RequestInterface, response: ResponseInterface|null}>  $history
     */
    public static function fromHistory(array $history): self
    {
        return new self(array_map(
            static fn (array $entry): RecordedRequest => RecordedRequest::fromPsr($entry['request']),
            array_values($history),
        ));
    }

    public function assertRequestCount(int $expected): void
    {
        PHPUnitAssert::assertSame(
            $expected,
            count($this->requests),
            sprintf(
                'Expected %d HubSpot request(s), but %d were made. %s',
                $expected,
                count($this->requests),
                $this->requestSummary(),
            ),
        );
    }

    /**
     * Asserts that a record of `$objectType` was written, and — when `$properties` is given — that some
     * written record carried every one of those property values.
     *
     * A **subset**, not the whole set: a consumer asserting one property must not have to restate every
     * property the package sends alongside it, or the assertion becomes a change-detector nobody keeps
     * up to date.
     *
     * Compared **strictly**. Every property HubSpot accepts and returns is a string, numeric and boolean
     * ones included, so a loose comparison would report success for a package that sent the integer
     * `100` where the string `'100'` belonged — the exact silent equivalence `declare(strict_types=1)`
     * exists here to prevent (STANDARDS §4). The failure message names the type of both sides for the
     * same reason: `100` and `"100"` are indistinguishable in a message that prints only the value.
     *
     * @param  array<string, mixed>  $properties
     */
    public function assertSynced(string $objectType, array $properties = []): void
    {
        $writes = array_values(array_filter(
            $this->requests,
            static fn (RecordedRequest $request): bool => $request->isObjectWriteOf($objectType),
        ));

        PHPUnitAssert::assertNotSame(
            [],
            $writes,
            sprintf(
                "Expected HubSpot to have synced object type '%s', but no write of that type was recorded. %s",
                $objectType,
                $this->trafficSummary(),
            ),
        );

        foreach ($properties as $name => $expected) {
            $recorded = $this->valuesRecordedFor($writes, $name);

            PHPUnitAssert::assertTrue(
                in_array($expected, $recorded, true),
                sprintf(
                    "Expected HubSpot to have synced object type '%s' with property '%s' => %s, but the value(s) recorded for it were: %s.",
                    $objectType,
                    $name,
                    self::describeValue($expected),
                    self::describeValues($recorded),
                ),
            );
        }
    }

    /**
     * Asserts that nothing was written at all.
     *
     * **Inclusive on purpose, where {@see self::assertSynced()} is precise on purpose.** This covers
     * association writes as well as object writes, even though `assertSynced('notes')` deliberately does
     * not treat an association write from a note as a sync of the note. The two are not symmetric in
     * cost: a positive claim that matches too much reports a sync that never happened, while a negative
     * claim that matches too little is a vacuous pass — and a vacuous pass here means a whole test file
     * proving nothing while reporting green (threat T-02-17).
     */
    public function assertNothingSynced(): void
    {
        $writes = $this->writes();

        PHPUnitAssert::assertSame(
            [],
            $writes,
            sprintf(
                'Expected HubSpot to have synced nothing, but %d write(s) were recorded: %s.',
                count($writes),
                self::describeAll($writes),
            ),
        );
    }

    /**
     * @param  list<RecordedRequest>  $writes
     * @return list<mixed>
     */
    private function valuesRecordedFor(array $writes, string $name): array
    {
        $values = [];

        foreach ($writes as $write) {
            // One entry per record, because a batch write submits several in one request and the
            // property being asserted may legitimately belong to any of them.
            foreach ($write->submittedProperties() as $record) {
                if (array_key_exists($name, $record)) {
                    $values[] = $record[$name];
                }
            }
        }

        return $values;
    }

    /**
     * @return list<RecordedRequest>
     */
    private function writes(): array
    {
        return array_values(array_filter(
            $this->requests,
            static fn (RecordedRequest $request): bool => $request->isWrite(),
        ));
    }

    /**
     * What a failed `assertSynced` reports about the traffic it did see: the writes if there were any,
     * otherwise the reads, otherwise the plain fact that nothing was recorded. Naming the reads matters
     * — "nothing was synced" and "you read instead of writing" send the reader to different places.
     */
    private function trafficSummary(): string
    {
        $writes = $this->writes();

        if ($writes !== []) {
            return sprintf('Writes recorded: %s.', self::describeAll($writes));
        }

        if ($this->requests !== []) {
            return sprintf('No write was recorded; the only traffic was: %s.', self::describeAll($this->requests));
        }

        return self::NOTHING_RECORDED;
    }

    private function requestSummary(): string
    {
        if ($this->requests === []) {
            return self::NOTHING_RECORDED;
        }

        return sprintf('Requests recorded: %s.', self::describeAll($this->requests));
    }

    /**
     * @param  list<RecordedRequest>  $requests
     */
    private static function describeAll(array $requests): string
    {
        return implode('; ', array_map(
            static fn (RecordedRequest $request): string => $request->describe(),
            $requests,
        ));
    }

    /**
     * @param  list<mixed>  $values
     */
    private static function describeValues(array $values): string
    {
        if ($values === []) {
            return 'not written';
        }

        return implode(', ', array_map(self::describeValue(...), $values));
    }

    /**
     * The value and its type. The type is not padding: HubSpot property values are strings, and a
     * message that printed `100` for both the integer and the string would hide the one difference the
     * strict comparison above exists to catch.
     */
    private static function describeValue(mixed $value): string
    {
        return sprintf('%s (%s)', json_encode($value), get_debug_type($value));
    }
}
