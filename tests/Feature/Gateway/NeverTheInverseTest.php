<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Gateway;

use PHPUnit\Framework\Attributes\DataProvider;
use ReyemTech\Hubspot\Exceptions\AssociationTypeException;
use ReyemTech\Hubspot\Facades\Hubspot;
use ReyemTech\Hubspot\Gateway\AssociationGateway;
use ReyemTech\Hubspot\Gateway\AssociationPair;
use ReyemTech\Hubspot\Gateway\Contracts\AssociationTypeResolver;
use ReyemTech\Hubspot\Gateway\ObjectRef;
use ReyemTech\Hubspot\Gateway\UnresolvedAssociationTypeResolver;
use ReyemTech\Hubspot\Tests\Support\DirectedMapResolver;
use ReyemTech\Hubspot\Tests\TestCase;

/**
 * # If this file goes red, the package has started writing associations backwards, and every
 * # consumer's CRM data is quietly wrong.
 *
 * That is not a figure of speech and it is not recoverable by a later fix. HubSpot's association
 * type ids are directional and different in each direction — Contact -> Company is 279 and Company
 * -> Contact is 280; Note -> Contact is 202 and Contact -> Note is 201 — and **HubSpot accepts the
 * wrong one without complaint.** No error, no warning, no failed request. The records are simply
 * associated under a relationship nobody chose, in production, silently, for months, in every portal
 * that installed the release. There is no way to tell from the API which writes were wrong, because
 * an inverse id is a perfectly valid id.
 *
 * **The fix for a red run in this file is never to relax the assertion.** If a change here looks
 * necessary, the change is wrong: the assertion is not describing an implementation detail, it is
 * describing the one guarantee this package exists to make. A maintainer who "fixes" a red run by
 * loosening the request-count assertion, or by accepting either type id, has removed the only thing
 * standing between a consumer and silent CRM corruption.
 *
 * ## Why asserting the throw is not enough
 *
 * Every negative test below asserts a throw **and a recorded request count of exactly zero**. The
 * count is the load-bearing half. A test that asserted only the exception would pass against an
 * implementation that resolved the inverse id, sent it to HubSpot, and *then* threw — which is
 * precisely the failure mode being forbidden, since the corrupt write has already landed by the time
 * the exception surfaces. Resolution therefore happens before any request is built, and this file
 * proves it from the outside.
 *
 * ## Why the type id is read from the recorded request body
 *
 * The positive tests decode the recorded outgoing payload rather than inspecting the resolver's
 * return value. A gateway that resolves correctly and then sends something else — a transposed
 * argument, a cached spec, a reversed pair built one line too early — would satisfy any assertion
 * made against the resolver. Only the wire tells the truth.
 */
mutates(
    AssociationGateway::class,
    UnresolvedAssociationTypeResolver::class,
);

final class NeverTheInverseTest extends TestCase
{
    /**
     * The directional table this file defends, from 02-CONTEXT.md and design spec §6. Each row is a
     * real HubSpot type-id pair, and in every row **both** ids are valid ids that HubSpot accepts —
     * which is the entire problem.
     *
     * @return array<string, array{string, string, string, int, int}>
     */
    public static function directionalTypeIdProvider(): array
    {
        return [
            // fromType, toType, label, the id for THAT direction, the id for the INVERSE direction
            'Contact -> Company is 279, Company -> Contact is 280' => ['contacts', 'companies', 'Employer', 279, 280],
            'Contact -> Primary Company is 1, Company -> Primary Contact is 2' => ['contacts', 'companies', 'Primary', 1, 2],
            'Deal -> Line Item is 19, Line Item -> Deal is 20' => ['deals', 'line_items', 'Sold', 19, 20],
            // The pair the design documents call out by name as the canonical mistake.
            'Note -> Contact is 202, Contact -> Note is 201' => ['notes', 'contacts', 'Attached note', 202, 201],
        ];
    }

    private static function pair(string $fromType, string $toType): AssociationPair
    {
        return new AssociationPair(
            from: new ObjectRef($fromType, '10'),
            to: new ObjectRef($toType, '20'),
        );
    }

    /**
     * **The positive half.** With the resolver knowing the requested direction, the id that reaches
     * the wire is that direction's id — read out of the decoded recorded request body — and the
     * inverse id appears nowhere in the raw payload at all.
     */
    #[DataProvider('directionalTypeIdProvider')]
    public function test_a_labelled_write_sends_the_requested_directions_type_id_and_nothing_else(
        string $fromType,
        string $toType,
        string $label,
        int $directionalTypeId,
        int $inverseTypeId,
    ): void {
        $fake = Hubspot::fake();

        app()->instance(
            AssociationTypeResolver::class,
            DirectedMapResolver::knowing($fromType, $toType, $label, $directionalTypeId),
        );

        Hubspot::associations()->associateWithLabel(self::pair($fromType, $toType), label: $label);

        Hubspot::assertRequestCount(1);

        $request = $fake->recordedRequests()[0]['request'];

        self::assertSame('PUT', $request->getMethod());
        self::assertSame(
            sprintf('/crm/v4/objects/%s/10/associations/%s/20', $fromType, $toType),
            $request->getUri()->getPath(),
            'The labelled route carries no `default` segment — that segment is what marks the unlabelled path.',
        );

        $raw = (string) $request->getBody();

        /** @var list<array{associationCategory: string, associationTypeId: int}> $body */
        $body = json_decode($raw, true);

        self::assertSame(
            [['associationCategory' => 'USER_DEFINED', 'associationTypeId' => $directionalTypeId]],
            $body,
            'The type id on the wire must be the one registered for the requested direction.',
        );

        // The inverse id must not appear anywhere in the payload, in any field, under any key. Both
        // ids are valid ids, so a structural assertion alone would not notice one riding along.
        self::assertStringNotContainsString(
            (string) $inverseTypeId,
            $raw,
            "The inverse type id {$inverseTypeId} reached the wire alongside the correct one.",
        );
    }

    /**
     * **The negative half, and the single most valuable assertion in this package.** The resolver
     * knows the OPPOSITE direction and nothing else. Requesting the direction it does not know must
     * throw and issue zero requests — never fall back to the id it does hold.
     *
     * Note what the resolver double is set up with: `knowing($toType, $fromType, ...)`, the reverse
     * of what the write asks for, mapped to the inverse id. That id is sitting right there, correctly
     * typed, one array lookup away. Every silent-corruption bug of this class is a `??` away from
     * this exact state.
     */
    #[DataProvider('directionalTypeIdProvider')]
    public function test_a_resolver_that_knows_only_the_opposite_direction_throws_and_writes_nothing(
        string $fromType,
        string $toType,
        string $label,
        int $directionalTypeId,
        int $inverseTypeId,
    ): void {
        $fake = Hubspot::fake();

        app()->instance(
            AssociationTypeResolver::class,
            DirectedMapResolver::knowing($toType, $fromType, $label, $inverseTypeId),
        );

        try {
            Hubspot::associations()->associateWithLabel(self::pair($fromType, $toType), label: $label);
            self::fail(sprintf(
                'Expected %s -> %s to throw when only %s -> %s is registered. If this line is reached, the '
                .'package resolved the inverse type id %d and wrote it where %d belonged.',
                $fromType,
                $toType,
                $toType,
                $fromType,
                $inverseTypeId,
                $directionalTypeId,
            ));
        } catch (AssociationTypeException $exception) {
            // The direction that failed, so a production report tells 202 from 201 rather than
            // naming only the label (D-18, threat T-02-16).
            self::assertStringContainsString(
                sprintf('%s -> %s', $fromType, $toType),
                $exception->getMessage(),
                'The message must name the direction that failed, not merely the label.',
            );
            self::assertStringContainsString($label, $exception->getMessage());
        }

        // THE load-bearing assertion. A throw alone would also be produced by an implementation that
        // wrote the inverse id first and threw afterwards — by which point the CRM already holds a
        // backwards association, and the exception is cold comfort.
        Hubspot::assertRequestCount(0);
        self::assertSame(
            [],
            $fake->recordedRequests(),
            'Not one byte may leave the process when the requested direction cannot be resolved.',
        );
    }

    /**
     * The same guarantee stated as an absence rather than as a count: whatever else the failed call
     * did, the inverse id never appeared in an outgoing payload. Kept separate from the count
     * assertion above because the two would fail for different reasons — a count of 1 says a write
     * happened, this says which id it carried — and a future implementation could conceivably break
     * one without the other.
     */
    #[DataProvider('directionalTypeIdProvider')]
    public function test_the_inverse_type_id_never_reaches_the_wire_when_only_it_is_resolvable(
        string $fromType,
        string $toType,
        string $label,
        int $directionalTypeId,
        int $inverseTypeId,
    ): void {
        $fake = Hubspot::fake();

        app()->instance(
            AssociationTypeResolver::class,
            DirectedMapResolver::knowing($toType, $fromType, $label, $inverseTypeId),
        );

        try {
            Hubspot::associations()->associateWithLabel(self::pair($fromType, $toType), label: $label);
        } catch (AssociationTypeException) {
            // Asserted in full by the test above; here the throw is only the precondition.
        }

        foreach ($fake->recordedRequests() as $entry) {
            self::assertStringNotContainsString(
                (string) $inverseTypeId,
                (string) $entry['request']->getBody(),
                "The inverse type id {$inverseTypeId} reached the wire for the {$fromType} -> {$toType} direction.",
            );
        }
    }

    /**
     * Reversing the pair reverses the outcome, which proves the guard is directional rather than a
     * blanket refusal. The same resolver, the same label, the same two records: one direction writes
     * 201 and the other throws. If a future change made the resolver pair-insensitive, this test
     * would go green in both halves and the negative tests above would go red — so this one exists to
     * make the *positive* case's directionality explicit too.
     */
    public function test_the_same_resolver_writes_one_direction_and_refuses_the_other(): void
    {
        $fake = Hubspot::fake();

        // Knows Contact -> Note (201) only. Note -> Contact (202) is deliberately absent.
        app()->instance(
            AssociationTypeResolver::class,
            DirectedMapResolver::knowing('contacts', 'notes', 'Attached note', 201),
        );

        $contactToNote = new AssociationPair(
            from: new ObjectRef('contacts', '20'),
            to: new ObjectRef('notes', '10'),
        );

        Hubspot::associations()->associateWithLabel($contactToNote, label: 'Attached note');

        Hubspot::assertRequestCount(1);

        /** @var list<array{associationCategory: string, associationTypeId: int}> $body */
        $body = json_decode((string) $fake->recordedRequests()[0]['request']->getBody(), true);

        self::assertSame(201, $body[0]['associationTypeId']);

        try {
            Hubspot::associations()->associateWithLabel($contactToNote->reversed(), label: 'Attached note');
            self::fail('Expected the unregistered Note -> Contact direction to throw rather than reuse 201.');
        } catch (AssociationTypeException $exception) {
            self::assertStringContainsString('notes -> contacts', $exception->getMessage());
        }

        // Still one. The refused direction added nothing.
        Hubspot::assertRequestCount(1);
    }

    /**
     * The shipped default, which is the state every consumer is in until Phase 3's registry exists.
     * With nothing resolvable, a labelled write is a throw and zero requests — not a guessed type id,
     * and not HubSpot's default association quietly substituted for the label that was asked for.
     */
    public function test_with_the_default_resolver_bound_every_labelled_write_throws_and_writes_nothing(): void
    {
        $fake = Hubspot::fake();

        self::assertInstanceOf(
            UnresolvedAssociationTypeResolver::class,
            app(AssociationTypeResolver::class),
            'This test asserts the SHIPPED default, so it must not install a resolver double.',
        );

        $calls = [
            'associateWithLabel' => static function (): void {
                Hubspot::associations()->associateWithLabel(self::pair('notes', 'contacts'), label: 'Attached note');
            },
            'associateWithLabels' => static function (): void {
                Hubspot::associations()->associateWithLabels(self::pair('notes', 'contacts'), labels: ['Attached note']);
            },
            'associateWithLabel, bidirectional' => static function (): void {
                Hubspot::associations()->associateWithLabel(
                    self::pair('notes', 'contacts'),
                    label: 'Attached note',
                    bidirectional: true,
                );
            },
        ];

        foreach ($calls as $description => $call) {
            try {
                $call();
                self::fail("Expected {$description} to throw with no resolver installed.");
            } catch (AssociationTypeException $exception) {
                self::assertStringContainsString('notes -> contacts', $exception->getMessage());
                self::assertStringContainsString('Attached note', $exception->getMessage());
                self::assertStringContainsString(AssociationTypeResolver::class, $exception->getMessage());
            }
        }

        Hubspot::assertRequestCount(0);
        self::assertSame([], $fake->recordedRequests());
    }
}
