<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Testing;

use PHPUnit\Framework\Assert as PHPUnitAssert;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use ReyemTech\Hubspot\Gateway\AssociationPair;
use ReyemTech\Hubspot\Gateway\Contracts\AssociationTypeResolver;

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
     * Keys are not renumbered anywhere in this class, so every collection here is `array<int, ...>`
     * rather than `list<...>`. Nothing reads a position: the assertions ask whether a filtered set is
     * empty and the messages implode its values. An `array_values()` call to satisfy a narrower docblock
     * would be a line no test could distinguish from its own absence — five of them survived the mutation
     * run that first flagged this, and a surviving mutant on a line that exists only to please the type
     * checker is a line to delete rather than a test to write.
     *
     * @param  array<int, RecordedRequest>  $requests
     */
    private function __construct(
        private array $requests,
        private AssociationTypeResolver $typeResolver,
    ) {}

    /**
     * The resolver is taken as a dependency rather than reached for through the container, and it is the
     * one the container held **at the moment of the assertion** ({@see HubspotFake} rebuilds this log per
     * call). That late binding is what lets a test say "the registry holds 202 for this direction, and the
     * wire carried 201" — the two halves of a real inverse-id defect — rather than only being able to
     * express a missing write.
     *
     * @param  array<int, array{request: RequestInterface, response: ResponseInterface|null}>  $history
     */
    public static function fromHistory(array $history, AssociationTypeResolver $typeResolver): self
    {
        return new self(
            array_map(
                static fn (array $entry): RecordedRequest => RecordedRequest::fromPsr($entry['request']),
                $history,
            ),
            $typeResolver,
        );
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
     * **Carried by ONE record.** Two assertions do that work, in this order, because they diagnose
     * different failures and the first one is by far the common case:
     *
     * 1. Per property, was this value written **anywhere**? A "no" is almost always a wrong value or a
     *    property the package never sent, and the message names the property, the expected value with its
     *    type, and every value actually recorded for it.
     * 2. Then: did **one** record carry all of them **together**? Checking only step 1 is a false positive
     *    Codex caught on PR #20 — writes of `{dealname: One, amount: 10}` and `{dealname: Two, amount: 20}`
     *    would satisfy an expectation of `{dealname: One, amount: 20}`, a combination neither record holds.
     *    A multi-record sync that transposed two records' fields, or wrote the right values against the
     *    wrong ids, would pass while the CRM holds neither record the caller described. This second
     *    message has to be its own, because at that point no single property is to blame: every one of
     *    them was written.
     *
     * @param  array<string, mixed>  $properties
     */
    public function assertSynced(string $objectType, array $properties = []): void
    {
        $writes = array_filter(
            $this->requests,
            static fn (RecordedRequest $request): bool => $request->isObjectWriteOf($objectType),
        );

        PHPUnitAssert::assertNotSame(
            [],
            $writes,
            sprintf(
                "Expected HubSpot to have synced object type '%s', but no write of that type was recorded. %s",
                $objectType,
                $this->trafficSummary(),
            ),
        );

        if ($properties === []) {
            return;
        }

        $records = self::recordsWrittenBy($writes);

        foreach ($properties as $name => $expected) {
            $recorded = self::valuesRecordedFor($records, $name);

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

        PHPUnitAssert::assertTrue(
            self::someRecordCarriesAll($records, $properties),
            sprintf(
                "Expected HubSpot to have synced object type '%s' with %s on one record, but no single record carried all of them. Records written: %s.",
                $objectType,
                self::describeRecord($properties),
                self::describeRecords($records),
            ),
        );
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
     * Asserts that the pair's **stated direction** was associated — and, when `$label` is given, that the
     * request body carried the type id that label resolves to **for that direction**.
     *
     * The design spec calls this assertion failing when the inverse type id was used the single most
     * valuable test in the package (§10), and three properties are what make it able to:
     *
     * 1. **The direction comes from the request path and the type id from the request body.** A gateway
     *    that resolves correctly and then sends something else satisfies every other kind of assertion
     *    (threat T-02-02). No response is read anywhere — see {@see RecordedRequest} for why an
     *    association read cannot answer this question even in principle.
     * 2. **The expected id comes from the container-bound resolver, asked about the stated direction and
     *    nothing else.** The label→id mapping belongs to the registry (Phase 3), and this assertion never
     *    consults the reversed direction for any reason, including to improve its own message
     *    (02-CONTEXT.md rule 3, which binds the test surface exactly as it binds the write surface). An
     *    unresolvable direction therefore propagates the resolver's own throw, naming the direction, the
     *    label and the container key that would fix it — far more useful than "not associated", which
     *    would be true of the assertion and false of the package.
     * 3. **It takes an `AssociationPair`, not two object references.** Design spec §10's example reads
     *    `assertAssociated($deal, $contact, label: 'buyer')`; no API in this package accepts two objects
     *    without an order, and an assertion whose own signature could be transposed could not be trusted
     *    to mean what it says. Phase 4 can add a factory that builds a pair from two bound models, which
     *    brings the call site back to the spec's shape while keeping the direction explicit.
     */
    public function assertAssociated(AssociationPair $pair, ?string $label = null): void
    {
        $expectedTypeId = $label === null
            ? null
            : $this->typeResolver->resolve($pair, $label)->typeId;

        PHPUnitAssert::assertNotSame(
            [],
            array_filter(
                $this->requests,
                static fn (RecordedRequest $request): bool => $request->associated($pair, $expectedTypeId),
            ),
            sprintf(
                'Expected HubSpot to have associated %s:%s -> %s:%s %s, but no such write was recorded. %s',
                $pair->from->objectType,
                $pair->from->id,
                $pair->to->objectType,
                $pair->to->id,
                $this->expectationOf($label, $expectedTypeId),
                $this->associationSummary(),
            ),
        );
    }

    /**
     * Names the relationship that was expected, and for a labelled one names the id it resolved to and
     * the fact that the id belongs to the direction just stated. Both halves matter in a failure message:
     * a reader who knows only "202 was expected" cannot tell a wrong write from a wrong registry row.
     */
    private function expectationOf(?string $label, ?int $expectedTypeId): string
    {
        if ($label === null) {
            return 'using the default association type';
        }

        return sprintf(
            "under label '%s', which the bound resolver resolves to association type id %d for that direction",
            $label,
            $expectedTypeId,
        );
    }

    private function associationSummary(): string
    {
        $associationWrites = array_filter(
            $this->requests,
            static fn (RecordedRequest $request): bool => $request->isAssociationWrite(),
        );

        if ($associationWrites === []) {
            return 'No association write was recorded at all.';
        }

        return sprintf('Association writes recorded: %s.', self::describeAll($associationWrites));
    }

    /**
     * Every property set submitted by these writes, flattened: one entry per **record**, since a batch
     * write submits several in one request and a single write submits one.
     *
     * @param  array<int, RecordedRequest>  $writes
     * @return list<array<string, mixed>>
     */
    private static function recordsWrittenBy(array $writes): array
    {
        $records = [];

        foreach ($writes as $write) {
            foreach ($write->submittedProperties() as $record) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * Every value recorded for one property name, across every record — which is what makes the
     * per-property failure message useful, and is deliberately NOT sufficient on its own to satisfy the
     * assertion. See {@see self::assertSynced()} for why.
     *
     * @param  list<array<string, mixed>>  $records
     * @return list<mixed>
     */
    private static function valuesRecordedFor(array $records, string $name): array
    {
        $values = [];

        foreach ($records as $record) {
            if (array_key_exists($name, $record)) {
                $values[] = $record[$name];
            }
        }

        return $values;
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @param  array<string, mixed>  $properties
     */
    private static function someRecordCarriesAll(array $records, array $properties): bool
    {
        foreach ($records as $record) {
            if (self::recordCarriesAll($record, $properties)) {
                return true;
            }
        }

        return false;
    }

    /**
     * `array_key_exists` before the comparison rather than `($record[$name] ?? null) === $expected`: a
     * property the package never sent and a property it sent as `null` are different facts, and conflating
     * them would let an expectation of `null` be satisfied by an absence.
     *
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $properties
     */
    private static function recordCarriesAll(array $record, array $properties): bool
    {
        foreach ($properties as $name => $expected) {
            if (! array_key_exists($name, $record)) {
                return false;
            }

            if ($record[$name] !== $expected) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, RecordedRequest>
     */
    private function writes(): array
    {
        return array_filter(
            $this->requests,
            static fn (RecordedRequest $request): bool => $request->isWrite(),
        );
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
     * @param  array<int, RecordedRequest>  $requests
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

    /**
     * @param  list<array<string, mixed>>  $records
     */
    private static function describeRecords(array $records): string
    {
        return implode('; ', array_map(self::describeRecord(...), $records));
    }

    /**
     * A whole property set, rendered without the `(array)` suffix {@see self::describeValue()} would add —
     * the type of a property SET is never the question, only the types of the values inside it, which the
     * JSON itself distinguishes. Wrapped in `sprintf` rather than cast, because `json_encode` is typed
     * `string|false` and a `(string)` cast would be a mutant nothing can kill.
     *
     * @param  array<string, mixed>  $record
     */
    private static function describeRecord(array $record): string
    {
        return sprintf('%s', json_encode($record));
    }
}
