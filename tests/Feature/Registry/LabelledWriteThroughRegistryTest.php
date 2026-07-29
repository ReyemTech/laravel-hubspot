<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Registry;

use PHPUnit\Framework\Attributes\DataProvider;
use ReyemTech\Hubspot\Exceptions\AssociationTypeException;
use ReyemTech\Hubspot\Exceptions\ObjectTypeException;
use ReyemTech\Hubspot\Facades\Hubspot;
use ReyemTech\Hubspot\Gateway\AssociationPair;
use ReyemTech\Hubspot\Gateway\AssociationType;
use ReyemTech\Hubspot\Gateway\Contracts\AssociationTypeResolver;
use ReyemTech\Hubspot\Gateway\ObjectRef;
use ReyemTech\Hubspot\Registry\AssociationDirection;
use ReyemTech\Hubspot\Registry\AssociationTypeRegistry;
use ReyemTech\Hubspot\Registry\AssociationTypeRow;
use ReyemTech\Hubspot\Registry\Contracts\AssociationTypeStore;
use ReyemTech\Hubspot\Registry\Stores\ArrayAssociationTypeStore;
use ReyemTech\Hubspot\Tests\TestCase;

/**
 * # This plan's headline: a labelled association write that works, offline.
 *
 * Until this file went green, every labelled association in the package threw. It asserts the whole
 * chain end to end and from the outside — facade, gateway, container-bound registry, store, seeded
 * baseline, recorded request — with **no network, no credentials and no database**. Nothing here
 * installs a resolver double: the resolver under test is the one a consumer gets after
 * `composer require`.
 *
 * Three properties, each with its own test below:
 *
 * 1. The id that reaches the wire is the one registered for the requested direction, read out of the
 *    decoded recorded request body rather than out of the resolver's return value. A registry that
 *    resolved correctly and then sent something else would satisfy any assertion made against the
 *    resolver; only the wire tells the truth.
 * 2. A direction the registry cannot answer for issues **zero requests**. A throw alone would also be
 *    produced by an implementation that wrote a wrong id first and threw afterwards — by which point
 *    the CRM already holds a backwards association.
 * 3. `inverse_type_id` is unreachable from every write path, proved by seeding one that matches no
 *    type id anywhere and asserting it never appears in any recorded request.
 */
mutates(AssociationTypeRegistry::class);

final class LabelledWriteThroughRegistryTest extends TestCase
{
    private static function pair(string $fromType, string $toType): AssociationPair
    {
        return new AssociationPair(
            from: new ObjectRef($fromType, '10'),
            to: new ObjectRef($toType, '20'),
        );
    }

    /**
     * The cited baseline, transcribed from design spec §6 — the same table `NeverTheInverseTest`
     * defends at the Gateway level, now resolved by the real registry instead of a test double.
     *
     * @return array<string, array{string, string, string, int, int}>
     */
    public static function baselineDirectionProvider(): array
    {
        return [
            'Contact -> Company is 279, Company -> Contact is 280' => ['contacts', 'companies', 'Contact to company', 279, 280],
            'Company -> Contact is 280, Contact -> Company is 279' => ['companies', 'contacts', 'Company to contact', 280, 279],
            'Contact -> Primary Company is 1, Company -> Primary Contact is 2' => ['contacts', 'companies', 'Contact to primary company', 1, 2],
            'Company -> Primary Contact is 2, Contact -> Primary Company is 1' => ['companies', 'contacts', 'Company to primary contact', 2, 1],
            'Deal -> Line Item is 19, Line Item -> Deal is 20' => ['deals', 'line_items', 'Deal to line item', 19, 20],
            'Line Item -> Deal is 20, Deal -> Line Item is 19' => ['line_items', 'deals', 'Line item to deal', 20, 19],
            'Note -> Contact is 202, Contact -> Note is 201' => ['notes', 'contacts', 'Note to contact', 202, 201],
            'Contact -> Note is 201, Note -> Contact is 202' => ['contacts', 'notes', 'Contact to note', 201, 202],
        ];
    }

    /**
     * **The proof the phase's goal is met.**
     */
    #[DataProvider('baselineDirectionProvider')]
    public function test_a_labelled_write_resolves_offline_and_reaches_the_wire_with_the_directional_id(
        string $fromType,
        string $toType,
        string $label,
        int $directionalTypeId,
        int $inverseTypeId,
    ): void {
        $fake = Hubspot::fake();

        // No credentials, and no resolver double. Both are the point.
        self::assertNull(config('hubspot.token'));

        Hubspot::associations()->associateWithLabel(self::pair($fromType, $toType), label: $label);

        Hubspot::assertRequestCount(1);

        $request = $fake->recordedRequests()[0]['request'];

        self::assertSame('PUT', $request->getMethod());
        self::assertSame(
            sprintf('/crm/v4/objects/%s/10/associations/%s/20', $fromType, $toType),
            $request->getUri()->getPath(),
        );

        $raw = (string) $request->getBody();

        /** @var list<array{associationCategory: string, associationTypeId: int}> $body */
        $body = json_decode($raw, true);

        self::assertSame(
            [['associationCategory' => 'HUBSPOT_DEFINED', 'associationTypeId' => $directionalTypeId]],
            $body,
            'The type id on the wire must be the one the baseline registers for the requested direction.',
        );

        self::assertStringNotContainsString(
            (string) $inverseTypeId,
            $raw,
            "The inverse type id {$inverseTypeId} reached the wire alongside the correct one.",
        );
    }

    /**
     * The same guarantee `NeverTheInverseTest` makes at the Gateway level, now made against the real
     * registry: the direction is seeded in reverse, its id is one lookup away, and the requested
     * direction still throws with **zero** requests issued.
     */
    #[DataProvider('baselineDirectionProvider')]
    public function test_the_opposite_directions_name_never_resolves_the_requested_direction(
        string $fromType,
        string $toType,
        string $label,
        int $directionalTypeId,
        int $inverseTypeId,
    ): void {
        $fake = Hubspot::fake();

        try {
            // The inverse direction's own name, asked for on THIS direction. Both the name and the id
            // it belongs to are in the registry; neither is this direction's.
            Hubspot::associations()->associateWithLabel(
                self::pair($toType, $fromType),
                label: $label,
            );
            self::fail(sprintf(
                'Expected %s -> %s under the name "%s" to throw. If this line is reached, the registry answered '
                .'with type id %d for a direction whose id is %d.',
                $toType,
                $fromType,
                $label,
                $directionalTypeId,
                $inverseTypeId,
            ));
        } catch (AssociationTypeException $exception) {
            self::assertSame(
                AssociationTypeException::directionNotResolvable($toType, $fromType, $label)->getMessage(),
                $exception->getMessage(),
            );
        }

        // THE load-bearing assertion. A throw alone would also be produced by an implementation that
        // wrote the wrong id first and threw afterwards.
        Hubspot::assertRequestCount(0);
        self::assertSame(
            [],
            $fake->recordedRequests(),
            'Not one byte may leave the process when the requested direction cannot be resolved.',
        );
    }

    /**
     * A direction the baseline does not carry at all. The correct behaviour is a miss, not an
     * invention: the baseline is deliberately incomplete, and `hubspot:associations:sync` is how a
     * portal fills the gap.
     */
    public function test_a_direction_the_baseline_does_not_carry_throws_and_writes_nothing(): void
    {
        $fake = Hubspot::fake();

        try {
            Hubspot::associations()->associateWithLabel(self::pair('tickets', 'companies'), label: 'Escalated to');
            self::fail('Expected an unseeded direction to throw rather than resolve.');
        } catch (AssociationTypeException $exception) {
            self::assertStringContainsString('tickets -> companies', $exception->getMessage());
            self::assertStringContainsString('Escalated to', $exception->getMessage());
        }

        Hubspot::assertRequestCount(0);
        self::assertSame([], $fake->recordedRequests());
    }

    /**
     * **The alias that resolves must also be the alias that reaches the wire.**
     *
     * `AssociationDirection` normalises both ends so `Deal` and `deals` address one registry row.
     * That normalisation is invisible to `AssociationGateway`, which builds the request URI from the
     * pair's own `ObjectRef::objectType`. So a pair carrying an alias would resolve type id 19 and
     * then PUT to `/crm/v4/objects/Deal/10/associations/lineItems/20` — the 404 about a route rather
     * than an error about the argument that normalisation exists to prevent (Codex P1 on PR #24).
     *
     * The registry therefore refuses a pair whose object types are not already canonical, naming the
     * canonical form, and issues nothing. Refusing is the only safe answer available at this seam:
     * the pair is a `Gateway` value and the Registry cannot rewrite it — `Gateway` may not name a
     * `Registry` class (R2), so `ObjectRef` cannot normalise itself either.
     *
     * @param  array{string, string}  $pairTypes
     */
    #[DataProvider('aliasedPairProvider')]
    public function test_a_pair_carrying_an_alias_is_refused_rather_than_put_on_the_wire(
        array $pairTypes,
        string $offending,
        string $canonical,
    ): void {
        $fake = Hubspot::fake();

        try {
            Hubspot::associations()->associateWithLabel(
                self::pair($pairTypes[0], $pairTypes[1]),
                label: 'Deal to line item',
            );
            self::fail(sprintf(
                'Expected the alias "%s" to be refused. If this line is reached, the registry resolved the row '
                .'for "%s" and the gateway addressed "%s" in the request path.',
                $offending,
                $canonical,
                $offending,
            ));
        } catch (ObjectTypeException $exception) {
            self::assertSame(
                ObjectTypeException::nonCanonicalObjectType($offending, $canonical)->getMessage(),
                $exception->getMessage(),
            );
            self::assertStringContainsString($offending, $exception->getMessage());
            self::assertStringContainsString($canonical, $exception->getMessage());
        }

        Hubspot::assertRequestCount(0);
        self::assertSame([], $fake->recordedRequests());
    }

    /**
     * @return array<string, array{array{string, string}, string, string}>
     */
    public static function aliasedPairProvider(): array
    {
        return [
            'a singular from side' => [['Deal', 'line_items'], 'Deal', 'deals'],
            'a camelCase to side' => [['deals', 'lineItems'], 'lineItems', 'line_items'],
            'surrounding whitespace, which url-encodes to %20 in the path' => [['deals', ' line_items '], ' line_items ', 'line_items'],
        ];
    }

    /**
     * The canonical spelling still resolves and still reaches the wire, so the guard above rejects
     * aliases rather than rejecting everything.
     */
    public function test_the_canonical_spelling_of_the_same_direction_still_reaches_the_wire(): void
    {
        $fake = Hubspot::fake();

        Hubspot::associations()->associateWithLabel(self::pair('deals', 'line_items'), label: 'Deal to line item');

        Hubspot::assertRequestCount(1);
        self::assertSame(
            '/crm/v4/objects/deals/10/associations/line_items/20',
            $fake->recordedRequests()[0]['request']->getUri()->getPath(),
        );
    }

    /**
     * **`inverse_type_id` is unreachable from every write path.**
     *
     * A row is seeded whose inverse id — `4243` — belongs to no type id anywhere in the registry, so
     * if it ever appears in an outgoing payload it can only have come from this column. Every write
     * path the package offers is exercised, including the two-direction form, whose reverse leg is
     * exactly where "we already know the inverse id, use it" would be written.
     */
    public function test_no_write_path_can_reach_the_recorded_inverse_type_id(): void
    {
        $store = new ArrayAssociationTypeStore;

        $store->upsert(new AssociationTypeRow(
            direction: AssociationDirection::of(from: 'tickets', to: 'companies'),
            type: new AssociationType(typeId: 4242, category: 'USER_DEFINED'),
            label: 'Escalated to',
            inverseTypeId: 4243,
            isDefault: null,
        ));

        // Bound through the container rather than constructed inline, so this test also proves the
        // store is the seam the registry takes its rows from.
        app()->instance(AssociationTypeStore::class, $store);
        app()->instance(AssociationTypeResolver::class, new AssociationTypeRegistry($store));

        $fake = Hubspot::fake();
        $pair = self::pair('tickets', 'companies');

        $calls = [
            'associate' => static fn () => Hubspot::associations()->associate($pair),
            'associate, bidirectional' => static fn () => Hubspot::associations()->associate($pair, bidirectional: true),
            'associateWithLabel' => static fn () => Hubspot::associations()->associateWithLabel($pair, label: 'Escalated to'),
            'associateWithLabels' => static fn () => Hubspot::associations()->associateWithLabels($pair, labels: ['Escalated to']),
            'associateWithLabel, with an inverse label' => static fn () => Hubspot::associations()->associateWithLabel(
                $pair,
                label: 'Escalated to',
                inverseLabel: 'Escalated from',
            ),
        ];

        foreach ($calls as $call) {
            try {
                $call();
            } catch (AssociationTypeException) {
                // The reverse leg of the last call is deliberately unresolvable — that direction is
                // not registered under any name, which is precisely the state in which an
                // implementation would be tempted to reach for the inverse id it already holds.
            }
        }

        // Flattened into one string rather than asserted per request: iterating a request log performs
        // no assertions when it is empty, which `failOnRisky` is meant to fail. One unconditional
        // assertion says the same thing whether there were zero requests or ten.
        $outgoing = implode("\n", array_map(
            static fn (array $entry): string => sprintf(
                '%s %s %s',
                $entry['request']->getMethod(),
                $entry['request']->getUri(),
                $entry['request']->getBody(),
            ),
            $fake->recordedRequests(),
        ));

        self::assertStringNotContainsString(
            '4243',
            $outgoing,
            'The recorded inverse type id left the process. It is stored for traversal and verification '
            .'and is never used for writes (design spec §6.2).',
        );

        self::assertStringContainsString(
            '4242',
            $outgoing,
            'No labelled write reached the wire at all, so the assertion above would have passed vacuously.',
        );
    }
}
