<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Unit\Registry;

use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionNamedType;
use ReyemTech\Hubspot\Exceptions\AssociationTypeException;
use ReyemTech\Hubspot\Gateway\AssociationCategory;
use ReyemTech\Hubspot\Gateway\AssociationType;
use ReyemTech\Hubspot\Registry\AssociationDirection;
use ReyemTech\Hubspot\Registry\AssociationTypeRow;
use ReyemTech\Hubspot\Tests\TestCase;

/**
 * One row of the registry: the direction, the resolved type, the label it answers under, the inverse
 * id recorded for traversal, and whether it is the default.
 *
 * **Every scalar parameter is native `mixed` and checked**, following the merged precedent in
 * `Gateway\ObjectRef` and `Gateway\AssociationType`: `declare(strict_types=1)` binds at the file
 * making the CALL, and a Laravel consumer file without it coerces silently. That matters more on a
 * row than almost anywhere else, because a row is what a portal sync writes and what the cache
 * rehydrates — two paths where a value's provenance is invisible by the time it arrives.
 *
 * The type id and the category are not re-validated here: they arrive as an `AssociationType`, which
 * already rejects a non-int, a non-positive id and an unknown category. Re-checking them would be a
 * second place to be wrong (STANDARDS §6b).
 */
mutates(AssociationTypeRow::class);

final class AssociationTypeRowTest extends TestCase
{
    private static function direction(): AssociationDirection
    {
        return AssociationDirection::of(from: 'contacts', to: 'companies');
    }

    private static function type(int $typeId = 279): AssociationType
    {
        return new AssociationType(typeId: $typeId, category: AssociationCategory::HubspotDefined);
    }

    public function test_it_carries_every_column_the_design_spec_schema_names(): void
    {
        $row = new AssociationTypeRow(
            direction: self::direction(),
            type: self::type(),
            label: 'Contact to company',
            inverseTypeId: 280,
            isDefault: null,
        );

        self::assertSame('contacts', $row->direction->from->value);
        self::assertSame('companies', $row->direction->to->value);
        self::assertSame(279, $row->type->typeId);
        self::assertSame(AssociationCategory::HubspotDefined, $row->type->category);
        self::assertSame('Contact to company', $row->label);
        self::assertSame(280, $row->inverseTypeId);
        self::assertNull($row->isDefault);
    }

    /**
     * `label`, `inverse_type_id` and `is_default` are all nullable, and each null means a different
     * "not stated": no label (the unlabelled default type), no observed inverse, and no measured
     * answer to which type a bare association resolves to.
     */
    public function test_the_three_nullable_columns_accept_null(): void
    {
        $row = new AssociationTypeRow(
            direction: self::direction(),
            type: self::type(),
            label: null,
            inverseTypeId: null,
            isDefault: null,
        );

        self::assertNull($row->label);
        self::assertNull($row->inverseTypeId);
        self::assertNull($row->isDefault);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function nonStringLabelProvider(): array
    {
        return [
            'an int' => [1],
            'a bool, which weak mode renders as "1"' => [true],
            'a float' => [1.5],
            'an array' => [['Employer']],
        ];
    }

    #[DataProvider('nonStringLabelProvider')]
    public function test_a_label_that_is_not_a_string_is_rejected_rather_than_coerced(mixed $label): void
    {
        try {
            new AssociationTypeRow(
                direction: self::direction(),
                type: self::type(),
                label: $label,
                inverseTypeId: null,
                isDefault: null,
            );
            self::fail('Expected a non-string label to be rejected, not coerced.');
        } catch (AssociationTypeException $exception) {
            self::assertSame(
                AssociationTypeException::nonStringLabel($label)->getMessage(),
                $exception->getMessage(),
            );
            self::assertStringContainsString(get_debug_type($label), $exception->getMessage());
        }
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidInverseTypeIdProvider(): array
    {
        return [
            'a string that looks like an id' => ['280'],
            'true, which weak mode renders as the real type id 1' => [true],
            'a float, which weak mode truncates to the real type id 19' => [19.9],
            'zero, the shape a defaulted variable takes' => [0],
            'a negative id' => [-1],
        ];
    }

    /**
     * The inverse id is recorded for traversal and verification and is never written, but a wrong
     * one still misleads a doctor into reporting an association is present when the id it searched
     * for was never HubSpot's.
     */
    #[DataProvider('invalidInverseTypeIdProvider')]
    public function test_an_invalid_inverse_type_id_is_rejected(mixed $inverseTypeId): void
    {
        try {
            new AssociationTypeRow(
                direction: self::direction(),
                type: self::type(),
                label: 'Contact to company',
                inverseTypeId: $inverseTypeId,
                isDefault: null,
            );
            self::fail('Expected an invalid inverse type id to be rejected.');
        } catch (AssociationTypeException $exception) {
            self::assertSame(
                AssociationTypeException::invalidInverseTypeId($inverseTypeId)->getMessage(),
                $exception->getMessage(),
            );
        }
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function nonBooleanDefaultProvider(): array
    {
        return [
            'the int 1, which weak mode renders as true' => [1],
            'the int 0, which weak mode renders as false' => [0],
            'the string "false", which weak mode renders as TRUE' => ['false'],
            'an empty string' => [''],
        ];
    }

    /**
     * `'false'` is the case this check exists for: a non-empty string is `true` in weak mode, so a
     * cache payload that round-tripped the flag through text would flip a "not the default" row into
     * a default one with no error anywhere.
     */
    #[DataProvider('nonBooleanDefaultProvider')]
    public function test_a_default_flag_that_is_not_a_boolean_is_rejected(mixed $isDefault): void
    {
        try {
            new AssociationTypeRow(
                direction: self::direction(),
                type: self::type(),
                label: null,
                inverseTypeId: null,
                isDefault: $isDefault,
            );
            self::fail('Expected a non-boolean default flag to be rejected.');
        } catch (AssociationTypeException $exception) {
            self::assertSame(
                AssociationTypeException::nonBooleanDefaultFlag($isDefault)->getMessage(),
                $exception->getMessage(),
            );
        }
    }

    public function test_a_row_survives_a_round_trip_through_its_array_form_unchanged(): void
    {
        $row = new AssociationTypeRow(
            direction: AssociationDirection::of(from: 'notes', to: 'contacts'),
            type: new AssociationType(typeId: 202, category: 'HUBSPOT_DEFINED'),
            label: 'Note to contact',
            inverseTypeId: 201,
            isDefault: true,
        );

        $restored = AssociationTypeRow::fromArray($row->toArray());

        self::assertEquals($row, $restored);
        self::assertSame($row->toArray(), $restored->toArray());
    }

    /**
     * A round trip must not quietly turn a "not stated" into a stated value — the failure that would
     * make a cache rehydration claim an inverse id or a default flag nobody measured.
     */
    public function test_the_round_trip_preserves_a_row_that_states_nothing_optional(): void
    {
        $row = new AssociationTypeRow(
            direction: AssociationDirection::of(from: 'deals', to: 'line_items'),
            type: new AssociationType(typeId: 19, category: 'HUBSPOT_DEFINED'),
            label: null,
            inverseTypeId: null,
            isDefault: null,
        );

        $restored = AssociationTypeRow::fromArray($row->toArray());

        self::assertNull($restored->label);
        self::assertNull($restored->inverseTypeId);
        self::assertNull($restored->isDefault);
    }

    /**
     * A corrupted or truncated cache payload is a package exception naming the fault, never a
     * `TypeError` from a property assignment nobody can catch.
     */
    public function test_a_corrupt_array_payload_raises_the_packages_own_exception(): void
    {
        $payload = [
            'from' => 'contacts',
            'to' => 'companies',
            'type_id' => '279',
            'category' => 'HUBSPOT_DEFINED',
            'label' => null,
            'inverse_type_id' => null,
            'is_default' => null,
        ];

        $this->expectException(AssociationTypeException::class);

        AssociationTypeRow::fromArray($payload);
    }

    /**
     * Pinned by reflection, because collapsing the three `mixed` parameters into native `?string`,
     * `?int` and `?bool` reads like a tidy-up and would restore the coercion every test above
     * forbids.
     */
    public function test_the_three_scalar_parameters_are_native_mixed(): void
    {
        $constructor = (new ReflectionClass(AssociationTypeRow::class))->getConstructor();

        self::assertNotNull($constructor);

        $types = [];

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            self::assertInstanceOf(ReflectionNamedType::class, $type);
            $types[$parameter->getName()] = $type->getName();
        }

        self::assertSame('mixed', $types['label']);
        self::assertSame('mixed', $types['inverseTypeId']);
        self::assertSame('mixed', $types['isDefault']);
        self::assertSame(AssociationDirection::class, $types['direction']);
        self::assertSame(AssociationType::class, $types['type']);
    }
}
