<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Unit\Registry;

use HubSpot\Crm\ObjectType as SdkObjectType;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use ReyemTech\Hubspot\Exceptions\HubspotException;
use ReyemTech\Hubspot\Exceptions\ObjectTypeException;
use ReyemTech\Hubspot\Registry\HubspotObjectType;
use ReyemTech\Hubspot\Tests\TestCase;

/**
 * **REG-01's Phase 3 half: object type normalisation, and nothing else.**
 *
 * REG-01 has no acceptance criteria in any source document — the specs state `HubspotObjectType`'s
 * job and stop. The four criteria this file asserts were **derived at planning time in
 * `03-01-PLAN.md` and recorded there as derived rather than sourced**:
 *
 * 1. The documented aliases of a standard object type normalise to one canonical identifier.
 * 2. A `p_*` custom object identifier normalises to itself, case-normalised, and is not rejected for
 *    being unknown — there is no allow-list of custom objects, and inventing one would break every
 *    portal that has any.
 * 3. Anything that cannot be normalised throws {@see ObjectTypeException} naming what was passed. It
 *    is never passed through unchanged: a silent pass-through is how a typo reaches a request path
 *    and comes back as a 404 about a route rather than an error about the argument.
 * 4. Normalisation is total and deterministic, and the canonical form is itself canonical.
 *
 * **Resolving the local id column for a bound model is deliberately absent.** That is the other half
 * of REG-01 and it needs model binding (SYNC-01), which does not exist until Phase 4. REG-01 is
 * therefore NOT ticked by this plan.
 *
 * ## Why the canonical set is asserted against the SDK rather than written down twice
 *
 * The canonical identifiers are transcribed from `HubSpot\Crm\ObjectType` in the pinned SDK major,
 * which is the only authoritative list of the strings HubSpot's own path segments take. `Registry`
 * may not name a `HubSpot\*` class (R1), so the values are transcribed into the package rather than
 * imported — and the first test below asserts the transcription is exact, in both directions, so a
 * drift between the SDK and this package fails the build instead of surfacing as a 404 in a
 * consumer's portal. This is the same mechanism `AssociationCategory` already uses against
 * `AssociationSpec::getAssociationCategoryAllowableValues()`.
 */
mutates(HubspotObjectType::class);

final class HubspotObjectTypeTest extends TestCase
{
    /**
     * The transcription guard. A canonical identifier this package holds that HubSpot does not, or
     * one HubSpot holds that this package does not, is a normalisation that either rejects a real
     * object type or accepts an unreal one.
     */
    public function test_the_canonical_set_is_exactly_the_pinned_sdks_own_object_type_list(): void
    {
        $sdkValues = array_values((new ReflectionClass(SdkObjectType::class))->getConstants());

        sort($sdkValues);
        $canonical = HubspotObjectType::canonicalTypes();
        sort($canonical);

        self::assertSame(
            $sdkValues,
            $canonical,
            'The canonical object type set must equal HubSpot\Crm\ObjectType exactly. A value here that '
            .'the SDK does not have accepts an object type HubSpot will 404 on; a value the SDK has and '
            .'this package does not rejects a real one.',
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function aliasProvider(): array
    {
        return [
            // Criterion 1, in the exact words it was derived in: deals, Deals, deal and DEAL
            // normalise to one canonical identifier.
            'deals is already canonical' => ['deals', 'deals'],
            'Deals differs only in case' => ['Deals', 'deals'],
            'deal is the singular' => ['deal', 'deals'],
            'DEAL is the singular, shouted' => ['DEAL', 'deals'],
            // ... and so do line_items, lineItems and line item.
            'line_items is already canonical' => ['line_items', 'line_items'],
            'lineItems is the camelCase form' => ['lineItems', 'line_items'],
            'line item is the spaced form' => ['line item', 'line_items'],
            'Line-Items is the hyphenated form' => ['Line-Items', 'line_items'],
            'line_item is the singular' => ['line_item', 'line_items'],
            // The irregular plural, which is why the singular map is derived rather than assumed to
            // be "the canonical minus a trailing s".
            'companies is already canonical' => ['companies', 'companies'],
            'company is the irregular singular' => ['company', 'companies'],
            'Company is the irregular singular, capitalised' => ['Company', 'companies'],
            // Surrounding whitespace is not a different object type.
            'contacts with surrounding whitespace' => ['  contacts  ', 'contacts'],
            'notes, the from side of the canonical 202/201 mistake' => ['Note', 'notes'],
            // HubSpot's own constant for orders is SINGULAR (ObjectType::ORDERS = 'order'), so the
            // canonical form here is singular too. Transcribed, not corrected: a package that
            // "fixed" it to `orders` would send a path segment HubSpot does not serve.
            'order is canonical in the singular, because the SDK says so' => ['order', 'order'],
            'commerce_payments keeps the SDK spelling of the payments object' => ['Commerce Payments', 'commerce_payments'],
        ];
    }

    #[DataProvider('aliasProvider')]
    public function test_it_normalises_the_documented_aliases_to_one_canonical_identifier(string $input, string $expected): void
    {
        self::assertSame($expected, HubspotObjectType::normalise($input)->value);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function customObjectProvider(): array
    {
        return [
            'the fully-qualified form the exception message documents' => ['p12345_my_object', 'p12345_my_object'],
            'the portal-id-less form' => ['p_my_object', 'p_my_object'],
            'case-normalised to itself, not rejected' => ['P12345_My_Object', 'p12345_my_object'],
            'surrounding whitespace is trimmed like any other input' => [' p999_pets ', 'p999_pets'],
        ];
    }

    /**
     * Criterion 2. There is deliberately no allow-list of custom object types: a portal invents its
     * own, and any list this package held would be correct only for the account it was written in.
     */
    #[DataProvider('customObjectProvider')]
    public function test_a_custom_object_identifier_normalises_to_itself_rather_than_being_rejected(string $input, string $expected): void
    {
        self::assertSame($expected, HubspotObjectType::normalise($input)->value);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unnormalisableProvider(): array
    {
        return [
            'a plausible-looking object type HubSpot does not have' => ['widgets'],
            // The SDK's own constant is the singular `order`, so the natural-looking plural is NOT
            // an alias of it. Rejecting it is the safe outcome: an absent mapping throws, and a
            // guessed one addresses a route that does not exist.
            'the plural of an object HubSpot names in the singular' => ['orders'],
            'a typo one character away from a real type' => ['contact_s'],
            'nothing at all' => [''],
            'whitespace only' => ['   '],
            'the custom-object prefix with no object after it' => ['p_'],
            'a custom-object identifier carrying a space' => ['p12345_my object'],
        ];
    }

    /**
     * Criterion 3. The value is named in the message and is NOT returned: a pass-through would be
     * encoded into a real-looking request path, and HubSpot performs no server-side validation on an
     * object type at all (02-RESEARCH.md Pitfall 6).
     */
    #[DataProvider('unnormalisableProvider')]
    public function test_an_unnormalisable_object_type_throws_naming_what_was_passed(string $input): void
    {
        try {
            HubspotObjectType::normalise($input);
            self::fail("Expected \"{$input}\" to throw rather than be passed through unchanged.");
        } catch (ObjectTypeException $exception) {
            // The whole message, not a substring of it: a substring assertion leaves every
            // concatenation mutant in the named constructor alive (31 of them survived an earlier
            // plan for exactly this reason).
            self::assertSame(ObjectTypeException::unmappable($input)->getMessage(), $exception->getMessage());
            self::assertStringContainsString($input, $exception->getMessage());
            self::assertInstanceOf(HubspotException::class, $exception);
        }
    }

    /**
     * Criterion 4, the half that a normalisation function most often gets wrong: normalising an
     * already-canonical identifier must change nothing, or a value that survived one round trip
     * through the registry would come out different on the second.
     */
    public function test_normalising_a_canonical_identifier_returns_it_unchanged(): void
    {
        foreach (HubspotObjectType::canonicalTypes() as $canonical) {
            self::assertSame($canonical, HubspotObjectType::normalise($canonical)->value);
        }
    }

    /**
     * Criterion 4's other half. Stated separately from the test above because the two fail for
     * different reasons: that one says the canonical set is a fixed point, this one says every
     * accepted input reaches it in exactly one step.
     */
    public function test_normalisation_is_idempotent_for_every_accepted_input(): void
    {
        $inputs = [
            ...array_map(static fn (array $case): string => $case[0], array_values(self::aliasProvider())),
            ...array_map(static fn (array $case): string => $case[0], array_values(self::customObjectProvider())),
        ];

        foreach ($inputs as $input) {
            $once = HubspotObjectType::normalise($input)->value;
            $twice = HubspotObjectType::normalise($once)->value;

            self::assertSame($once, $twice, "Normalising \"{$input}\" twice changed the answer.");
        }
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function nonStringProvider(): array
    {
        return [
            'an int, which is what a coerced object type most often is' => [0],
            'a bool, which weak mode renders as "1"' => [true],
            'null' => [null],
            'a float' => [1.5],
            'an array' => [['deals']],
        ];
    }

    /**
     * The `ObjectRef` precedent, applied here for the same reason: `declare(strict_types=1)` binds at
     * the file making the CALL, so a Laravel consumer file without it would have had the value
     * coerced before this method's body ran. The parameter is therefore native `mixed`, checked, and
     * the wrong type is rejected rather than cast.
     */
    #[DataProvider('nonStringProvider')]
    public function test_a_non_string_object_type_is_rejected_rather_than_coerced(mixed $input): void
    {
        try {
            HubspotObjectType::normalise($input);
            self::fail('Expected a non-string object type to be rejected, not coerced.');
        } catch (ObjectTypeException $exception) {
            self::assertSame(
                ObjectTypeException::nonStringObjectType($input)->getMessage(),
                $exception->getMessage(),
            );
            self::assertStringContainsString(get_debug_type($input), $exception->getMessage());
        }
    }

    /**
     * Pinned by reflection, because collapsing the `mixed` parameter into a native `string` one reads
     * like a tidy-up and would restore exactly the coercion the test above forbids — the same guard
     * `AssociationPairTest` puts on `ObjectRef`.
     */
    public function test_the_parameter_is_native_mixed_and_the_property_is_a_declared_string(): void
    {
        $parameter = (new ReflectionClass(HubspotObjectType::class))->getMethod('normalise')->getParameters()[0];
        $parameterType = $parameter->getType();

        self::assertInstanceOf(ReflectionNamedType::class, $parameterType);
        self::assertSame('mixed', $parameterType->getName());

        $property = new ReflectionProperty(HubspotObjectType::class, 'value');
        $propertyType = $property->getType();

        self::assertInstanceOf(ReflectionNamedType::class, $propertyType);
        self::assertSame('string', $propertyType->getName());
        self::assertTrue($property->isReadOnly());
        self::assertTrue($property->isPublic());
    }

    /**
     * `final` by default (decision #5), and the constructor is private so the only way to hold one of
     * these is through the normalising door. A public constructor would let a caller build a
     * `HubspotObjectType` around an unnormalised string and skip every criterion above.
     */
    public function test_the_class_is_final_and_can_only_be_built_through_normalisation(): void
    {
        $reflection = new ReflectionClass(HubspotObjectType::class);

        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->getConstructor()?->isPrivate());
    }
}
