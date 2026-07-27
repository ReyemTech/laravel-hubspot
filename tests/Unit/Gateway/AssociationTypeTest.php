<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Unit\Gateway;

use HubSpot\Client\Crm\Associations\V4\Model\AssociationSpec;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionEnum;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use ReyemTech\Hubspot\Exceptions\AssociationTypeException;
use ReyemTech\Hubspot\Exceptions\HubspotException;
use ReyemTech\Hubspot\Gateway\AssociationCategory;
use ReyemTech\Hubspot\Gateway\AssociationPair;
use ReyemTech\Hubspot\Gateway\AssociationType;
use ReyemTech\Hubspot\Gateway\Contracts\AssociationTypeResolver;
use ReyemTech\Hubspot\Gateway\ObjectRef;
use ReyemTech\Hubspot\Gateway\UnresolvedAssociationTypeResolver;
use ReyemTech\Hubspot\Tests\TestCase;
use Throwable;

/**
 * **The resolver seam, and the shape that makes a wrong-direction fallback unwritable.**
 *
 * A type id is the one value in this package that cannot be checked by looking at it. `279` and
 * `280` are both valid, both plausible and both silently accepted by HubSpot — the only thing that
 * distinguishes them is the direction they were registered for. So the seam is designed around one
 * property: **a resolver that cannot answer for the requested direction has no way to say so except
 * by throwing.** There is no null return, no sentinel and no boolean, because every one of those
 * shapes invites the caller to write `?: $inverse`, and that fallback is how 202 gets written where
 * 201 belongs.
 *
 * Three things are pinned here, and a red run means one of them has eroded:
 *
 * 1. `AssociationTypeResolver::resolve()` returns a non-nullable `AssociationType` — a miss is a
 *    throw. If this goes red, a caller can now distinguish "no type" from "an error" without
 *    catching anything, which is the first step towards a fallback.
 * 2. `AssociationType` validates its own parameter types, so a weak-mode consumer file cannot
 *    smuggle `true` in and have it coerce to type id `1` — which is a real HubSpot id
 *    (Contact -> Primary Company), not a value that fails loudly anywhere.
 * 3. The contract lives in the `Gateway` namespace, not `Registry`. Registry may depend on Gateway
 *    and not the reverse (R2), so an interface on the Registry side would make Phase 3's registry
 *    unimplementable without breaking the layer rule.
 *
 * **The fix is never to relax the assertion.**
 */
mutates(
    AssociationType::class,
    AssociationCategory::class,
    UnresolvedAssociationTypeResolver::class,
    AssociationTypeException::class,
);

final class AssociationTypeTest extends TestCase
{
    /**
     * The canonical mistake, in the pair the design documents name: Note -> Contact is 202 and
     * Contact -> Note is 201.
     */
    private static function notePair(): AssociationPair
    {
        return new AssociationPair(
            from: new ObjectRef('notes', '10'),
            to: new ObjectRef('contacts', '20'),
        );
    }

    public function test_an_association_type_carries_a_type_id_and_a_category(): void
    {
        $type = new AssociationType(typeId: 279, category: 'USER_DEFINED');

        self::assertSame(279, $type->typeId);
        self::assertSame(AssociationCategory::UserDefined, $type->category);
    }

    /**
     * The category is carried as an enum case rather than as a validated string, so every consumer
     * downstream of the resolver holds a value that cannot be invalid — there is no second place
     * where the string has to be re-checked or trusted. An `AssociationCategory` case is therefore
     * accepted directly too: Phase 3's registry reads a string out of storage, but a caller already
     * holding a case must not have to unwrap it to `->value` and have it re-validated.
     */
    public function test_a_category_case_is_accepted_as_readily_as_its_string_value(): void
    {
        $fromString = new AssociationType(typeId: 1, category: 'HUBSPOT_DEFINED');
        $fromCase = new AssociationType(typeId: 1, category: AssociationCategory::HubspotDefined);

        self::assertSame($fromString->category, $fromCase->category);
        self::assertSame('HUBSPOT_DEFINED', $fromCase->category->value);
    }

    /**
     * Read from the SDK's own allow-list rather than from a hand-copied one. The SDK validates
     * `association_category` against this exact list inside `setAssociationCategory()` and throws a
     * raw `InvalidArgumentException` for anything else — which no `catch (HubspotException)` block
     * would ever see (STANDARDS §9). This package's enum must therefore be neither narrower than the
     * SDK's list (rejecting a category HubSpot accepts) nor wider (letting a raw SDK exception
     * through).
     *
     * Note the count: **four**, not the three that HubSpot's own public documentation lists. `WORK`
     * is in the pinned SDK major's allow-list, so it is in the enum.
     */
    public function test_the_enum_carries_exactly_the_categories_the_sdk_accepts_no_more_no_fewer(): void
    {
        $sdkAllowed = (new AssociationSpec)->getAssociationCategoryAllowableValues();
        sort($sdkAllowed);

        $ours = AssociationCategory::values();
        sort($ours);

        self::assertSame($sdkAllowed, $ours);
        self::assertCount(4, $ours);
    }

    public function test_an_unknown_category_is_rejected_and_the_message_lists_every_valid_value(): void
    {
        try {
            new AssociationType(typeId: 279, category: 'USER_DEFINEDD');
            self::fail('Expected an unknown association category to be rejected at construction.');
        } catch (AssociationTypeException $exception) {
            self::assertStringContainsString('"USER_DEFINEDD"', $exception->getMessage());

            foreach (AssociationCategory::values() as $valid) {
                self::assertStringContainsString($valid, $exception->getMessage());
            }
        }
    }

    /**
     * The same weak-mode hole `ObjectRef` closed in plan 02-04, on the other half of the spec.
     * `declare(strict_types=1)` binds at the CALLING file, so a consumer file without it would have
     * had `true` coerced to `'1'` by a `string` parameter and `1` coerced to `'1'` as well — and
     * `'1'` is not a category at all, so it would have surfaced as an unknown-category error
     * complaining about a value the caller never wrote.
     *
     * @return array<string, array{mixed, string}>
     */
    public static function nonStringCategoryProvider(): array
    {
        return [
            'an integer' => [279, 'int'],
            'true' => [true, 'bool'],
            'null' => [null, 'null'],
            'a float' => [1.5, 'float'],
            'an array' => [['USER_DEFINED'], 'array'],
            'a Stringable, which weak mode accepts outright' => [
                new class
                {
                    public function __toString(): string
                    {
                        return 'USER_DEFINED';
                    }
                },
                'class@anonymous',
            ],
        ];
    }

    #[DataProvider('nonStringCategoryProvider')]
    public function test_a_non_string_category_is_rejected_and_the_message_names_what_arrived(mixed $notACategory, string $expectedDebugType): void
    {
        try {
            new AssociationType(typeId: 279, category: $notACategory);
            self::fail('Expected a non-string association category to be rejected at construction.');
        } catch (AssociationTypeException $exception) {
            self::assertStringContainsString($expectedDebugType, $exception->getMessage());
            self::assertStringContainsString(AssociationCategory::class, $exception->getMessage());
        }
    }

    /**
     * **The one that matters most.** A weak-mode caller passing `true` where a `string`-typed or
     * `int`-typed parameter sat would have had it coerced to `1` — and `1` is a real HubSpot
     * association type id, Contact -> Primary Company. Nothing about that fails loudly: the write
     * succeeds and associates the wrong records under the wrong type. `19.9` coerces to `19`,
     * Deal -> Line Item, with the same outcome.
     *
     * @return array<string, array{mixed, string}>
     */
    public static function nonIntegerTypeIdProvider(): array
    {
        return [
            'true, which coerces to 1 -- a real type id' => [true, 'bool'],
            'false, which coerces to 0' => [false, 'bool'],
            'a numeric string' => ['279', 'string'],
            'a float that truncates to a real type id' => [19.9, 'float'],
            'null' => [null, 'null'],
            'an array' => [[279], 'array'],
        ];
    }

    #[DataProvider('nonIntegerTypeIdProvider')]
    public function test_a_non_integer_type_id_is_rejected_rather_than_coerced(mixed $notAnInt, string $expectedDebugType): void
    {
        try {
            new AssociationType(typeId: $notAnInt, category: 'USER_DEFINED');
            self::fail('Expected a non-integer association type id to be rejected rather than coerced.');
        } catch (AssociationTypeException $exception) {
            self::assertStringContainsString($expectedDebugType, $exception->getMessage());
            self::assertStringContainsString('(int)', $exception->getMessage());
            self::assertStringContainsString('strict_types', $exception->getMessage());
        }
    }

    /**
     * A zero or negative id is a value that was defaulted rather than resolved. HubSpot's own ids
     * start at 1, so `0` is the shape an uninitialised variable or a missing registry row takes —
     * and sending it writes an association under a type that does not exist.
     *
     * @return array<string, array{int}>
     */
    public static function nonPositiveTypeIdProvider(): array
    {
        return [
            'zero, the shape a defaulted variable takes' => [0],
            'a negative id' => [-279],
        ];
    }

    #[DataProvider('nonPositiveTypeIdProvider')]
    public function test_a_zero_or_negative_type_id_is_rejected(int $notPositive): void
    {
        try {
            new AssociationType(typeId: $notPositive, category: 'USER_DEFINED');
            self::fail('Expected a non-positive association type id to be rejected at construction.');
        } catch (AssociationTypeException $exception) {
            self::assertStringContainsString((string) $notPositive, $exception->getMessage());
            self::assertStringContainsString('start at 1', $exception->getMessage());
        }
    }

    /**
     * The lowest real type id HubSpot issues is 1, so the boundary check must reject 0 and accept 1
     * rather than rejecting anything below some rounder number.
     */
    public function test_type_id_one_is_valid_because_hubspot_issues_it(): void
    {
        $type = new AssociationType(typeId: 1, category: 'HUBSPOT_DEFINED');

        self::assertSame(1, $type->typeId);
    }

    /**
     * Pinned by reflection for the same reason `ObjectRef`'s equivalent is: collapsing these back
     * into promoted native parameters reads like a tidy-up and would silently restore the coercion
     * this class exists to reject. No narrowing `@param` docblock may sit over the natives either —
     * PHPStan at level max would fold the checks into tautologies, and this repository forbids a
     * baseline.
     */
    public function test_an_association_type_validates_its_own_parameter_types(): void
    {
        $constructor = (new ReflectionClass(AssociationType::class))->getConstructor();

        self::assertNotNull($constructor);

        $parameters = $constructor->getParameters();

        self::assertSame(['typeId', 'category'], array_map(
            static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
            $parameters,
        ));

        foreach ($parameters as $parameter) {
            $type = $parameter->getType();

            self::assertInstanceOf(ReflectionNamedType::class, $type);
            self::assertSame(
                'mixed',
                $type->getName(),
                "AssociationType::__construct()'s \${$parameter->getName()} is declared as a native type, so whether "
                .'a wrong-typed value is rejected or silently coerced now depends on the CALLING file\'s '
                .'strict_types setting.',
            );
        }

        $typeId = new ReflectionProperty(AssociationType::class, 'typeId');
        $typeIdType = $typeId->getType();

        self::assertInstanceOf(ReflectionNamedType::class, $typeIdType);
        self::assertSame('int', $typeIdType->getName());
        self::assertTrue($typeId->isReadOnly());

        $category = new ReflectionProperty(AssociationType::class, 'category');
        $categoryType = $category->getType();

        self::assertInstanceOf(ReflectionNamedType::class, $categoryType);
        self::assertSame(
            AssociationCategory::class,
            $categoryType->getName(),
            'The category must be carried as an enum case, so no consumer downstream has to re-validate a string.',
        );
        self::assertTrue($category->isReadOnly());
    }

    public function test_the_category_enum_is_string_backed_so_it_can_be_stored_and_sent_verbatim(): void
    {
        $backing = (new ReflectionEnum(AssociationCategory::class))->getBackingType();

        self::assertInstanceOf(ReflectionNamedType::class, $backing);
        self::assertSame('string', $backing->getName());
    }

    /**
     * **The shape of the seam, and the whole point of it.** A resolver has exactly one way to
     * report that it cannot answer for the requested direction: throw. A nullable return, a `false`,
     * or a "not found" sentinel would each hand the caller something to write a fallback against —
     * `?? $this->inverseOf($pair)` — and that fallback is the bug this package exists to prevent.
     */
    public function test_the_resolver_contract_cannot_express_a_miss_as_anything_but_a_throw(): void
    {
        $reflection = new ReflectionClass(AssociationTypeResolver::class);

        self::assertTrue($reflection->isInterface(), 'The resolver seam must be an interface Phase 3 can implement.');

        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

        self::assertCount(
            1,
            $methods,
            'One method. A second one is a second way to ask the same question, and two answers can disagree.',
        );

        $resolve = $methods[0];
        $returnType = $resolve->getReturnType();

        self::assertInstanceOf(ReflectionNamedType::class, $returnType);
        self::assertSame(
            AssociationType::class,
            $returnType->getName(),
            'resolve() must return an AssociationType and nothing else — not a union with false, not an array.',
        );
        self::assertFalse(
            $returnType->allowsNull(),
            'A nullable return is precisely the shape that invites `?: $inverseTypeId`. A miss is a throw.',
        );
    }

    /**
     * The resolver is asked about a DIRECTION and a label, never about two objects it could consider
     * in either order. Same rule as the gateway contract's, one layer earlier: an implementation
     * handed two loose object references could resolve the pair it preferred.
     */
    public function test_the_resolver_is_asked_about_a_directed_pair_and_a_label(): void
    {
        $parameters = (new ReflectionMethod(AssociationTypeResolver::class, 'resolve'))->getParameters();

        self::assertSame(['pair', 'label'], array_map(
            static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
            $parameters,
        ));

        $first = $parameters[0]->getType();

        self::assertInstanceOf(ReflectionNamedType::class, $first);
        self::assertSame(AssociationPair::class, $first->getName());

        $second = $parameters[1]->getType();

        self::assertInstanceOf(ReflectionNamedType::class, $second);
        self::assertSame('string', $second->getName());
        self::assertFalse(
            $second->allowsNull(),
            'A null label would mean "the unlabelled association", which takes a different route entirely and '
            .'resolves no type id at all. The resolver never sees that case.',
        );
    }

    /**
     * R2 says Registry may depend on Gateway and not the reverse. Phase 3's registry implements this
     * interface, so the interface has to sit on the Gateway side — a contract declared in
     * `Registry` would make the Gateway's own dependency on it a layer violation, and the gateway
     * would have to reach upward to type its collaborator.
     */
    public function test_the_resolver_contract_lives_in_the_gateway_namespace_so_phase_3_can_implement_it(): void
    {
        self::assertSame(
            'ReyemTech\Hubspot\Gateway\Contracts',
            (new ReflectionClass(AssociationTypeResolver::class))->getNamespaceName(),
        );
    }

    /**
     * The honest state of a package whose registry does not exist yet. This is not a stub standing
     * in for missing work — resolving nothing and throwing is the CORRECT behaviour for this phase,
     * and it is what makes the never-the-inverse guarantee provable today rather than in Phase 3.
     *
     * @return array<string, array{AssociationPair, string}>
     */
    public static function unresolvableRequestProvider(): array
    {
        $notePair = new AssociationPair(
            from: new ObjectRef('notes', '10'),
            to: new ObjectRef('contacts', '20'),
        );

        return [
            'the forward direction' => [$notePair, 'Attached note'],
            'the reverse direction' => [$notePair->reversed(), 'Attached note'],
            'a different pair entirely' => [
                new AssociationPair(
                    from: new ObjectRef('contacts', '1'),
                    to: new ObjectRef('companies', '2'),
                ),
                'buyer',
            ],
        ];
    }

    #[DataProvider('unresolvableRequestProvider')]
    public function test_the_default_resolver_throws_for_every_request_naming_the_direction_the_label_and_the_fix(AssociationPair $pair, string $label): void
    {
        $resolver = new UnresolvedAssociationTypeResolver;

        try {
            $resolver->resolve($pair, $label);
            self::fail('Expected the default resolver to resolve nothing and throw.');
        } catch (AssociationTypeException $exception) {
            $message = $exception->getMessage();

            // The direction, both sides, in order — so a production failure report identifies 202
            // from 201 rather than only naming the label (D-18, threat T-02-16).
            self::assertStringContainsString($pair->from->objectType, $message);
            self::assertStringContainsString($pair->to->objectType, $message);
            self::assertStringContainsString(
                sprintf('%s -> %s', $pair->from->objectType, $pair->to->objectType),
                $message,
                'The message must state the failed direction as a direction, not as two nouns in a sentence.',
            );
            self::assertStringContainsString($label, $message);

            // What would fix it. A message that says only "cannot resolve" leaves the reader with
            // no next step, and the next step here is not guessable.
            self::assertStringContainsString(AssociationTypeResolver::class, $message);
            self::assertStringContainsString('associate()', $message);
        }
    }

    /**
     * The negative half of the same guarantee, at the resolver rather than at the gateway: the
     * default resolver does not become resolvable by asking it about the other direction. It holds
     * no map at all, so there is nothing for it to fall back to — which is exactly why it is the
     * right default for a package with no registry.
     */
    public function test_the_default_resolver_holds_no_map_and_therefore_has_nothing_to_fall_back_to(): void
    {
        $reflection = new ReflectionClass(UnresolvedAssociationTypeResolver::class);

        self::assertTrue($reflection->isFinal());
        self::assertNull($reflection->getConstructor(), 'A resolver that resolves nothing needs no state.');
        self::assertSame([], $reflection->getProperties());
    }

    /**
     * A consumer writing one `catch (HubspotException)` block must not have a bad type id escape it
     * while a bad category is caught (STANDARDS §9). `null` in particular must not surface as a raw
     * `TypeError`, which no such block would ever see.
     */
    public function test_every_rejection_on_this_seam_is_a_package_exception(): void
    {
        $rejections = [
            static fn (): mixed => new AssociationType(typeId: 279, category: 'nonsense'),
            static fn (): mixed => new AssociationType(typeId: 279, category: null),
            static fn (): mixed => new AssociationType(typeId: 279, category: 3),
            static fn (): mixed => new AssociationType(typeId: '279', category: 'USER_DEFINED'),
            static fn (): mixed => new AssociationType(typeId: null, category: 'USER_DEFINED'),
            static fn (): mixed => new AssociationType(typeId: 0, category: 'USER_DEFINED'),
            static fn (): mixed => (new UnresolvedAssociationTypeResolver)->resolve(self::notePair(), 'buyer'),
        ];

        foreach ($rejections as $index => $rejection) {
            try {
                $rejection();
                self::fail("Expected rejection {$index} to throw.");
            } catch (Throwable $exception) {
                self::assertInstanceOf(HubspotException::class, $exception);
            }
        }
    }

    /**
     * `final` by default (decision #5). Extension happens by implementing
     * `AssociationTypeResolver` and rebinding it — which is precisely what Phase 3 does — never by
     * subclassing either of these.
     */
    public function test_the_seam_ships_final_because_extension_happens_through_the_interface(): void
    {
        self::assertTrue((new ReflectionClass(AssociationType::class))->isFinal());
        self::assertTrue((new ReflectionClass(AssociationType::class))->isReadOnly());
        self::assertTrue((new ReflectionClass(UnresolvedAssociationTypeResolver::class))->isFinal());
    }
}
