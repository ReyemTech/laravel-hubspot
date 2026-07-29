<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Registry;

use PHPUnit\Framework\Attributes\DataProvider;
use ReyemTech\Hubspot\Exceptions\AssociationTypeException;
use ReyemTech\Hubspot\Exceptions\ObjectTypeException;
use ReyemTech\Hubspot\Gateway\AssociationCategory;
use ReyemTech\Hubspot\Gateway\AssociationPair;
use ReyemTech\Hubspot\Gateway\AssociationType;
use ReyemTech\Hubspot\Gateway\Contracts\AssociationTypeResolver;
use ReyemTech\Hubspot\Gateway\ObjectRef;
use ReyemTech\Hubspot\Registry\AssociationDirection;
use ReyemTech\Hubspot\Registry\AssociationTypeRegistry;
use ReyemTech\Hubspot\Registry\AssociationTypeRow;
use ReyemTech\Hubspot\Registry\Stores\ArrayAssociationTypeStore;
use ReyemTech\Hubspot\Tests\TestCase;

/**
 * **The real resolver, and the layer where the fallback this package forbids could reappear.**
 *
 * Phase 2 forbade the Gateway from falling back to the inverse type id and proved it with
 * `tests/Feature/Gateway/NeverTheInverseTest.php`. This class is the thing that *holds* both ids, so
 * it is where the fallback could come back — a lookup that misses the requested direction and finds
 * the row belonging to the other one is one `??` away. Every negative test below therefore arranges
 * the inverse id to be present, correctly typed, one lookup from the answer, and requires a throw.
 *
 * **The fix for a red run here is never to relax the assertion.**
 */
mutates(AssociationTypeRegistry::class);

final class AssociationTypeRegistryTest extends TestCase
{
    private static function pair(string $fromType, string $toType): AssociationPair
    {
        return new AssociationPair(
            from: new ObjectRef($fromType, '10'),
            to: new ObjectRef($toType, '20'),
        );
    }

    private static function registryKnowing(?AssociationTypeRow $row = null): AssociationTypeRegistry
    {
        $store = new ArrayAssociationTypeStore;

        if ($row instanceof AssociationTypeRow) {
            $store->upsert($row);
        }

        return new AssociationTypeRegistry($store);
    }

    private static function row(
        string $fromType,
        string $toType,
        string $label,
        int $typeId,
        ?int $inverseTypeId = null,
    ): AssociationTypeRow {
        return new AssociationTypeRow(
            direction: AssociationDirection::of(from: $fromType, to: $toType),
            type: new AssociationType(typeId: $typeId, category: 'USER_DEFINED'),
            label: $label,
            inverseTypeId: $inverseTypeId,
            isDefault: null,
        );
    }

    public function test_it_is_the_seam_the_gateway_asks(): void
    {
        self::assertInstanceOf(AssociationTypeResolver::class, self::registryKnowing());
    }

    /**
     * @return array<string, array{string, string, string, int, int}>
     */
    public static function seededDirectionProvider(): array
    {
        return [
            'Contact -> Company is 279' => ['contacts', 'companies', 'Contact to company', 279, 280],
            'Company -> Contact is 280' => ['companies', 'contacts', 'Company to contact', 280, 279],
            'Deal -> Line Item is 19' => ['deals', 'line_items', 'Deal to line item', 19, 20],
            'Note -> Contact is 202' => ['notes', 'contacts', 'Note to contact', 202, 201],
        ];
    }

    /**
     * The offline promise: no store rows, no network, no credentials, no database — the seeded
     * baseline alone answers.
     */
    #[DataProvider('seededDirectionProvider')]
    public function test_it_resolves_a_seeded_direction_with_an_empty_store(
        string $fromType,
        string $toType,
        string $label,
        int $typeId,
        int $inverseTypeId,
    ): void {
        $type = self::registryKnowing()->resolve(self::pair($fromType, $toType), $label);

        self::assertSame($typeId, $type->typeId);
        self::assertNotSame($inverseTypeId, $type->typeId);
        self::assertSame(AssociationCategory::HubspotDefined, $type->category);
    }

    public function test_it_resolves_a_reconciled_row_from_the_store(): void
    {
        $type = self::registryKnowing(self::row('tickets', 'companies', 'Escalated to', 4242, 4243))
            ->resolve(self::pair('tickets', 'companies'), 'Escalated to');

        self::assertSame(4242, $type->typeId);
        self::assertSame(AssociationCategory::UserDefined, $type->category);
    }

    /**
     * Aliases reach the same row, so a consumer writing `Deals` and a portal that reconciled `deals`
     * are looking at one direction rather than two.
     */
    public function test_it_normalises_the_pairs_object_types_before_looking_anything_up(): void
    {
        $type = self::registryKnowing()->resolve(self::pair('Deal', 'lineItems'), 'Deal to line item');

        self::assertSame(19, $type->typeId);
    }

    /**
     * A miss is a throw and there is no other way to express one — the contract's return type is
     * non-nullable precisely so no caller is handed something to write `?? $inverseTypeId` against.
     */
    public function test_an_unknown_direction_throws_naming_the_direction_and_the_label(): void
    {
        try {
            self::registryKnowing()->resolve(self::pair('tickets', 'companies'), 'Escalated to');
            self::fail('Expected an unregistered direction to throw rather than resolve.');
        } catch (AssociationTypeException $exception) {
            // The whole message, not a substring: a substring assertion leaves every concatenation
            // mutant in the named constructor alive.
            self::assertSame(
                AssociationTypeException::directionNotResolvable('tickets', 'companies', 'Escalated to')->getMessage(),
                $exception->getMessage(),
            );
            self::assertStringContainsString('tickets -> companies', $exception->getMessage());
            self::assertStringContainsString('Escalated to', $exception->getMessage());
        }
    }

    /**
     * The message names the NORMALISED direction, so a report reads in the same identifiers the
     * registry is keyed on rather than in whatever spelling the call site happened to use.
     */
    public function test_the_miss_message_names_the_normalised_direction(): void
    {
        try {
            self::registryKnowing()->resolve(self::pair('Tickets', 'Company'), 'Escalated to');
            self::fail('Expected an unregistered direction to throw.');
        } catch (AssociationTypeException $exception) {
            self::assertStringContainsString('tickets -> companies', $exception->getMessage());
        }
    }

    /**
     * **The single most valuable assertion at this layer.** The registry knows the OPPOSITE direction
     * and nothing else. The id it holds is a real id, correctly typed, one array lookup from the
     * answer — and the requested direction must still throw.
     *
     * The pair is `tickets <-> companies`, which the seeded baseline deliberately does not carry, so
     * the only thing in the registry is the inverse row this test installed. The second half of the
     * test resolves that inverse row successfully, which is what proves the id really was sitting
     * there rather than the registry being empty.
     */
    public function test_a_registry_that_knows_only_the_opposite_direction_throws(): void
    {
        $registry = self::registryKnowing(self::row('companies', 'tickets', 'Escalated to', 4243, 4242));

        try {
            $registry->resolve(self::pair('tickets', 'companies'), 'Escalated to');
            self::fail(
                'Expected tickets -> companies to throw when only companies -> tickets is registered. If this line '
                .'is reached, the registry resolved the inverse type id 4243 where 4242 belonged.',
            );
        } catch (AssociationTypeException $exception) {
            self::assertSame(
                AssociationTypeException::directionNotResolvable('tickets', 'companies', 'Escalated to')->getMessage(),
                $exception->getMessage(),
            );
        }

        self::assertSame(
            4243,
            $registry->resolve(self::pair('companies', 'tickets'), 'Escalated to')->typeId,
            'The inverse id was one lookup away the whole time, which is what makes the throw above meaningful.',
        );
    }

    /**
     * The label half of the same guarantee. A paired label carries a different NAME in each direction
     * (FOUND-03 run 2: `Deals` one way, `People` the other), so a seeded direction asked for under
     * another direction's name must miss even though the direction itself is known.
     */
    public function test_a_known_direction_asked_for_under_another_directions_name_throws(): void
    {
        $this->expectException(AssociationTypeException::class);

        self::registryKnowing()->resolve(self::pair('contacts', 'companies'), 'Company to contact');
    }

    /**
     * An object type that cannot be normalised is reported as what it is — a caller mistake about an
     * argument — rather than as a registry miss, which would send the reader off to add a row for a
     * direction that cannot exist.
     */
    public function test_an_unnormalisable_object_type_is_reported_as_one(): void
    {
        $this->expectException(ObjectTypeException::class);

        self::registryKnowing()->resolve(self::pair('contacts', 'widgets'), 'Contact to widget');
    }
}
