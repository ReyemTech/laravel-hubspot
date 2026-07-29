<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Unit\Gateway;

use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use ReyemTech\Hubspot\Exceptions\AssociationTypeException;
use ReyemTech\Hubspot\Gateway\AssociationCategory;
use ReyemTech\Hubspot\Gateway\AssociationDefinition;
use ReyemTech\Hubspot\Gateway\AssociationType;
use ReyemTech\Hubspot\Tests\TestCase;

/**
 * One association type a portal defines for one direction, as a value this package owns.
 *
 * Two properties are pinned here, and both are the kind a tidy-up would silently undo.
 *
 * **The label is `mixed` and checked, not a promoted `?string`.** `declare(strict_types=1)` binds at
 * the file making the CALL, never at the file declaring the constructor, so a promoted parameter
 * would coerce a weak-mode caller's `1` into `'1'` — a label no portal has, registered against a real
 * type id. It is rejected rather than cast, following the merged precedent in `Gateway\ObjectRef` and
 * `Gateway\AssociationType`.
 *
 * **There is no inverse field, and there must not be one.** `DefinitionsApi::getPage()` answers for
 * one direction, and the two directions' responses share no join key at all — see the class's own
 * docblock. A field here would be an invitation to fill it in by inference, and an inferred inverse
 * id is a real, valid, wrong association id HubSpot accepts without complaint.
 */
mutates(AssociationDefinition::class);

final class AssociationDefinitionTest extends TestCase
{
    private static function type(): AssociationType
    {
        return new AssociationType(typeId: 1, category: AssociationCategory::UserDefined);
    }

    public function test_it_carries_the_type_and_the_label_it_was_given(): void
    {
        $definition = new AssociationDefinition(type: self::type(), label: 'Deals');

        self::assertSame(1, $definition->type->typeId);
        self::assertSame(AssociationCategory::UserDefined, $definition->type->category);
        self::assertSame('Deals', $definition->label);
    }

    /**
     * A null label is HubSpot's own answer for a `HUBSPOT_DEFINED` type — measured twice in FOUND-03
     * — so it is a legitimate value here rather than a missing one.
     */
    public function test_a_null_label_is_accepted_because_hubspot_returns_one_for_its_own_types(): void
    {
        self::assertNull((new AssociationDefinition(type: self::type(), label: null))->label);
    }

    public function test_a_non_string_label_is_rejected_rather_than_coerced(): void
    {
        try {
            new AssociationDefinition(type: self::type(), label: 1);
            self::fail('Expected a non-string label to be rejected rather than coerced to "1".');
        } catch (AssociationTypeException $exception) {
            self::assertSame(
                'An association label was given as type int. Pass the label as a string, exactly as '
                .'the portal spells it -- a paired label carries a DIFFERENT name in each direction '
                .'("Deals" one way, "People" the other), so a label is never derived and never '
                .'coerced. Pass null only for the unlabelled default type, which resolves through '
                .'createDefault() and needs no type id at all.',
                $exception->getMessage(),
            );
        }
    }

    /**
     * Collapsing the declared property and `mixed` parameter back into one promoted `?string` reads
     * like a tidy-up and would restore the coercion the test above forbids, so both are pinned by
     * reflection — the same mechanism `AssociationPairTest` uses on `ObjectRef`.
     */
    public function test_the_label_parameter_is_mixed_and_the_property_is_a_nullable_string(): void
    {
        $constructor = (new ReflectionClass(AssociationDefinition::class))->getConstructor();

        self::assertNotNull($constructor);

        $parameters = $constructor->getParameters();

        self::assertSame(['type', 'label'], array_map(
            static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
            $parameters,
        ));

        $labelParameterType = $parameters[1]->getType();

        self::assertInstanceOf(ReflectionNamedType::class, $labelParameterType);
        self::assertSame(
            'mixed',
            $labelParameterType->getName(),
            'The label parameter must be native `mixed`, so a non-string reaches the check rather than being coerced before it.',
        );

        $property = new ReflectionProperty(AssociationDefinition::class, 'label');
        $type = $property->getType();

        self::assertInstanceOf(ReflectionNamedType::class, $type);
        self::assertSame('string', $type->getName());
        self::assertTrue($type->allowsNull());
    }

    /**
     * The absent field, pinned. Nothing on this value may carry, name or imply an inverse type id:
     * the honest source for a pairing is observation (`hubspot:associations:doctor`), never a second
     * directional read.
     */
    public function test_it_carries_nothing_from_which_an_inverse_type_id_could_be_taken(): void
    {
        $definition = new AssociationDefinition(type: self::type(), label: 'Deals');

        foreach (array_keys(get_object_vars($definition)) as $property) {
            self::assertDoesNotMatchRegularExpression(
                '/inverse|reverse|opposite/i',
                $property,
                "AssociationDefinition::\${$property} names the opposite direction. Two directional "
                .'reads share no join key, so any value here would be inferred rather than read.',
            );
        }

        self::assertSame(
            ['__construct'],
            array_map(
                static fn (\ReflectionMethod $method): string => $method->getName(),
                (new ReflectionClass(AssociationDefinition::class))->getMethods(),
            ),
            'A method beyond the constructor would be somewhere to derive the pairing that the fields deliberately cannot express.',
        );
    }
}
