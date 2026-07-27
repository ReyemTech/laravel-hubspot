<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Unit\Gateway;

use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use ReyemTech\Hubspot\Exceptions\HubspotException;
use ReyemTech\Hubspot\Exceptions\ObjectTypeException;
use ReyemTech\Hubspot\Gateway\AssociationPair;
use ReyemTech\Hubspot\Gateway\ObjectRef;
use ReyemTech\Hubspot\Tests\TestCase;
use Throwable;
use Traversable;

/**
 * The mechanical form of the first of 02-CONTEXT.md's four association rules: **the primitive is a
 * directed pair, and no API in this package may accept two objects without an order.** A rule
 * written only in prose erodes; the reflection tests below turn it into a build failure.
 *
 * The specific mistake being made unrepresentable is a note↔contact association written backwards.
 * HubSpot's association type ids are directional and different in each direction (Note→Contact is
 * 202, Contact→Note is 201; Contact→Company is 279, Company→Contact is 280), and FOUND-03's probe
 * confirmed empirically on 2026-07-27 that reading an association back does NOT hand you the id you
 * wrote. The failure mode is silent: the wrong direction reaches HubSpot, no error is raised, and
 * nobody notices for months.
 *
 * A red run in this file means one of two things. Either the pair's two sides have stopped being
 * named `from` and `to` in that order — in which case a future refactor has quietly made a
 * transposition survivable — or an accessor has appeared that hands back both sides without
 * distinguishing them, which reopens the same hole from the read side. **The fix is never to relax
 * the assertion.**
 *
 * This is the first file under `tests/Unit/`. The `Unit` testsuite was registered in
 * `phpunit.xml.dist` in the same commit as this file, because `failOnWarning` turns a declared
 * testsuite with no test files into a build failure — see `tests/Ci/PhpunitTestsuitesTest.php`.
 */
mutates(
    ObjectRef::class,
    AssociationPair::class,
);

final class AssociationPairTest extends TestCase
{
    public function test_an_object_ref_carries_an_object_type_and_an_object_id(): void
    {
        $ref = new ObjectRef('contacts', '527152015051');

        self::assertSame('contacts', $ref->objectType);
        self::assertSame('527152015051', $ref->id);
    }

    /**
     * Both blank forms, because `strict_types` is not a defence against either. A HubSpot object id
     * is a string that looks like an integer (D-34/STANDARDS §4), and a whitespace-only value
     * URL-encodes into a real-looking path segment — `/objects/%20/...` — rather than failing
     * loudly. Rejecting at construction is what keeps a coerced empty value from writing to the
     * wrong record (threat T-02-13).
     *
     * @return array<string, array{string}>
     */
    public static function blankValueProvider(): array
    {
        return [
            'an empty string' => [''],
            'a single space' => [' '],
            'whitespace only' => ["  \t\n "],
        ];
    }

    #[DataProvider('blankValueProvider')]
    public function test_a_blank_object_type_is_rejected_and_the_message_names_that_side(string $blank): void
    {
        try {
            new ObjectRef($blank, '527152015051');
            self::fail('Expected a blank object type to be rejected at construction.');
        } catch (ObjectTypeException $exception) {
            self::assertStringContainsString('object type', $exception->getMessage());
            self::assertStringNotContainsString('object id', $exception->getMessage());
        }
    }

    #[DataProvider('blankValueProvider')]
    public function test_a_blank_object_id_is_rejected_and_the_message_names_that_side(string $blank): void
    {
        try {
            new ObjectRef('contacts', $blank);
            self::fail('Expected a blank object id to be rejected at construction.');
        } catch (ObjectTypeException $exception) {
            self::assertStringContainsString('object id', $exception->getMessage());
            self::assertStringContainsString('contacts', $exception->getMessage());
        }
    }

    /**
     * The values a weak-mode caller can smuggle past a `string` parameter. `declare(strict_types=1)`
     * binds at the CALLING file, never at the declaring one, so a typical Laravel consumer file —
     * which does not declare it — gets every one of these coerced *before* the constructor body runs:
     * `0` becomes `"0"`, `true` becomes `"1"`, and a large float becomes `"1.2345678901235E+19"`,
     * a real-looking path segment with silent precision loss. Two of them (`false`, `null`) were
     * already rejected, but for the wrong reason and with the wrong message — an empty-string
     * complaint and a raw `TypeError` respectively.
     *
     * `1.2345678901234568E+19` is included precisely because it is the case with no loud failure
     * anywhere: it coerces to a syntactically valid id addressing a record nobody meant.
     *
     * @return array<string, array{mixed, string}>
     */
    public static function nonStringValueProvider(): array
    {
        return [
            'an integer id' => [527152015051, 'int'],
            'the integer zero, which coerces to the string "0"' => [0, 'int'],
            'true, which coerces to the string "1"' => [true, 'bool'],
            'false, which coerces to the empty string' => [false, 'bool'],
            'null, which raises a raw TypeError instead' => [null, 'null'],
            'a float that loses precision as a string' => [1.2345678901234568E+19, 'float'],
            'an array' => [['527152015051'], 'array'],
            'a Stringable, which weak mode accepts outright' => [
                new class
                {
                    public function __toString(): string
                    {
                        return '527152015051';
                    }
                },
                'class@anonymous',
            ],
        ];
    }

    #[DataProvider('nonStringValueProvider')]
    public function test_a_non_string_object_type_is_rejected_and_the_message_names_that_side(mixed $notAString, string $expectedDebugType): void
    {
        try {
            new ObjectRef($notAString, '527152015051');
            self::fail('Expected a non-string object type to be rejected at construction.');
        } catch (ObjectTypeException $exception) {
            self::assertStringContainsString('object type', $exception->getMessage());
            self::assertStringNotContainsString('object id', $exception->getMessage());
            self::assertStringContainsString($expectedDebugType, $exception->getMessage());
            self::assertStringContainsString('Pass it as a string', $exception->getMessage());
        }
    }

    #[DataProvider('nonStringValueProvider')]
    public function test_a_non_string_object_id_is_rejected_and_the_message_names_that_side(mixed $notAString, string $expectedDebugType): void
    {
        try {
            new ObjectRef('contacts', $notAString);
            self::fail('Expected a non-string object id to be rejected at construction.');
        } catch (ObjectTypeException $exception) {
            self::assertStringContainsString('object id', $exception->getMessage());
            self::assertStringContainsString($expectedDebugType, $exception->getMessage());
            self::assertStringContainsString('Pass it as a string', $exception->getMessage());
        }
    }

    /**
     * The deliberate DX consequence, stated as a test so it is a decision rather than an oversight:
     * an integer id is **rejected, not cast**. Casting would reintroduce exactly the `0`/`"0"` blur
     * this class's docblock condemns, and this package's doctrine is to throw rather than guess. A
     * consumer holding an id as an integer writes `(string) $id`, which the message tells them to do.
     */
    public function test_an_integer_id_is_rejected_rather_than_cast_and_the_message_names_the_remedy(): void
    {
        try {
            new ObjectRef('contacts', 527152015051);
            self::fail('Expected an integer object id to be rejected rather than cast.');
        } catch (ObjectTypeException $exception) {
            self::assertStringContainsString('(string)', $exception->getMessage());
        }

        $ref = new ObjectRef('contacts', (string) 527152015051);

        self::assertSame('527152015051', $ref->id);
    }

    /**
     * **The invariant cannot be delegated to `declare(strict_types=1)`, so it is not.**
     * That declaration binds at the file making the call, not at the file declaring the constructor,
     * which means promoted `string` parameters would enforce the rule only for consumers who happen
     * to declare strict types in the file where they build the reference — and coerce silently for
     * everyone else. Native `mixed` plus an explicit `is_string()` check is what makes the rejection
     * unconditional.
     *
     * Pinned by reflection because the tempting refactor — collapsing this back to two promoted
     * `string` parameters — reads like a tidy-up and would silently restore the coercion. Note that
     * no narrowing `@param string` docblock may be added over the natives either: PHPStan at level
     * max would then constant-fold the `is_string()` checks into tautologies, and this repository
     * forbids a baseline and allows a per-line suppression only with a written reason.
     */
    public function test_an_object_ref_validates_its_own_types_rather_than_trusting_the_callers_strict_types_mode(): void
    {
        $constructor = (new ReflectionClass(ObjectRef::class))->getConstructor();

        self::assertNotNull($constructor);

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();

            self::assertInstanceOf(ReflectionNamedType::class, $type);
            self::assertSame(
                'mixed',
                $type->getName(),
                "ObjectRef::__construct()'s \${$parameter->getName()} is declared as a native type, so whether a "
                .'non-string is rejected or silently coerced now depends on the CALLING file\'s strict_types setting.',
            );
        }

        foreach (['objectType', 'id'] as $name) {
            $property = new ReflectionProperty(ObjectRef::class, $name);
            $type = $property->getType();

            self::assertInstanceOf(ReflectionNamedType::class, $type);
            self::assertSame('string', $type->getName(), "ObjectRef::\${$name} must still be a string once assigned.");
            self::assertTrue($property->isReadOnly(), "ObjectRef::\${$name} must remain readonly.");
        }
    }

    /**
     * Every rejection an `ObjectRef` or an `AssociationPair` can raise is catchable through the
     * package's own root interface. A consumer writing one `catch (HubspotException)` block must
     * not have a blank id escape it while a blank type is caught (STANDARDS §9).
     */
    public function test_every_construction_rejection_is_a_package_exception(): void
    {
        $rejections = [
            static fn (): mixed => new ObjectRef('', '1'),
            static fn (): mixed => new ObjectRef('contacts', ''),
            // The non-string forms belong in this list too: a consumer's single `catch` block must
            // not catch a blank id and miss an integer one. `null` in particular used to escape as a
            // raw TypeError, which no `catch (HubspotException)` block would ever see.
            static fn (): mixed => new ObjectRef(0, '1'),
            static fn (): mixed => new ObjectRef('contacts', 1),
            static fn (): mixed => new ObjectRef('contacts', null),
            static fn (): mixed => new AssociationPair(
                from: new ObjectRef('contacts', '1'),
                to: new ObjectRef('contacts', '1'),
            ),
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
     * The cheapest possible defence against a future refactor renaming the pair's two sides to
     * something positional and meaningless. `from` and `to`, in that order, is the whole primitive:
     * PHP named arguments make `new AssociationPair(from: $note, to: $contact)` self-documenting at
     * every call site, and that only holds while the names hold.
     */
    public function test_the_pair_constructor_parameters_are_named_from_and_to_in_that_order(): void
    {
        $constructor = (new ReflectionClass(AssociationPair::class))->getConstructor();

        self::assertNotNull($constructor);

        $parameters = $constructor->getParameters();

        self::assertCount(
            2,
            $parameters,
            'An AssociationPair is exactly two sides. A third parameter belongs on a method, not on the primitive.',
        );

        self::assertSame(
            ['from', 'to'],
            [$parameters[0]->getName(), $parameters[1]->getName()],
            'The pair\'s two sides must be named `from` and `to`, in that order — renaming or reordering them '
            .'makes a transposed association survivable, which is the one mistake this package exists to prevent.',
        );

        foreach ($parameters as $parameter) {
            $type = $parameter->getType();

            self::assertInstanceOf(ReflectionNamedType::class, $type);
            self::assertSame(
                ObjectRef::class,
                $type->getName(),
                'Both sides are ObjectRef values, so neither side can be assembled from bare strings at the call site.',
            );
        }
    }

    public function test_the_pair_reports_its_two_sides_through_distinctly_named_accessors(): void
    {
        $note = new ObjectRef('notes', '10');
        $contact = new ObjectRef('contacts', '20');

        $pair = new AssociationPair(from: $note, to: $contact);

        self::assertSame($note, $pair->from);
        self::assertSame($contact, $pair->to);
    }

    /**
     * The read side of the same rule. An accessor returning both sides in one collection — a
     * `sides()`, an `all()`, an `IteratorAggregate` — would let a caller pull the two refs out
     * positionally and pass them onward in either order, which is precisely the unordered pair the
     * constructor refuses to accept.
     */
    public function test_no_accessor_hands_back_both_sides_without_distinguishing_them(): void
    {
        $reflection = new ReflectionClass(AssociationPair::class);

        self::assertFalse(
            $reflection->implementsInterface(Traversable::class),
            'A traversable pair is an unordered pair with extra steps.',
        );
        self::assertFalse($reflection->implementsInterface(\ArrayAccess::class));

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $returnType = $method->getReturnType();

            if (! $returnType instanceof ReflectionNamedType) {
                continue;
            }

            self::assertNotContains(
                $returnType->getName(),
                ['array', 'iterable'],
                "AssociationPair::{$method->getName()}() returns a collection. No accessor may hand back "
                .'both sides without distinguishing them — name the side instead.',
            );
        }
    }

    /**
     * Reversal exists because plan 02-05's `bidirectional` option performs two independently
     * resolved directed writes, and the second one needs the reversed pair as a first-class value.
     * It is a named operation returning a NEW pair precisely so the reversal is visible in the
     * calling code — a mutating `reverse()` would turn a direction change into a side effect on a
     * value someone else is still holding.
     */
    public function test_reversing_a_pair_returns_a_new_pair_and_leaves_the_original_untouched(): void
    {
        $note = new ObjectRef('notes', '10');
        $contact = new ObjectRef('contacts', '20');

        $forward = new AssociationPair(from: $note, to: $contact);
        $reversed = $forward->reversed();

        self::assertNotSame($forward, $reversed);
        self::assertSame($contact, $reversed->from);
        self::assertSame($note, $reversed->to);

        self::assertSame($note, $forward->from, 'Reversal must not mutate the pair it was called on.');
        self::assertSame($contact, $forward->to);

        self::assertSame($note, $reversed->reversed()->from, 'Reversing twice returns to the original direction.');
    }

    /**
     * HubSpot cannot associate a record with itself, so the pair refuses to represent one rather
     * than letting the API reject it a request later.
     */
    public function test_a_pair_of_one_record_with_itself_is_rejected(): void
    {
        try {
            new AssociationPair(
                from: new ObjectRef('contacts', '527152015051'),
                to: new ObjectRef('contacts', '527152015051'),
            );
            self::fail('Expected a self-pair to be rejected at construction.');
        } catch (ObjectTypeException $exception) {
            self::assertStringContainsString('contacts', $exception->getMessage());
            self::assertStringContainsString('527152015051', $exception->getMessage());
        }
    }

    /**
     * The self-pair rejection is about one RECORD, not one object type. HubSpot associates two
     * companies with each other (parent/child) and two contacts with each other, and a check that
     * compared only the object types would make those unrepresentable.
     */
    public function test_two_different_records_of_the_same_object_type_are_a_valid_pair(): void
    {
        $pair = new AssociationPair(
            from: new ObjectRef('companies', '1'),
            to: new ObjectRef('companies', '2'),
        );

        self::assertSame('1', $pair->from->id);
        self::assertSame('2', $pair->to->id);
    }

    /**
     * Same id, different object types is also a valid pair: HubSpot record ids are only unique
     * within an object type, so a contact 1 and a company 1 are two different records.
     */
    public function test_the_same_id_under_two_object_types_is_a_valid_pair(): void
    {
        $pair = new AssociationPair(
            from: new ObjectRef('contacts', '1'),
            to: new ObjectRef('companies', '1'),
        );

        self::assertSame('contacts', $pair->from->objectType);
        self::assertSame('companies', $pair->to->objectType);
    }

    /**
     * A test placed under `tests/Unit/` has to actually run in a bare `vendor/bin/pest` — the same
     * class of bug plan 01-07 fixed for the `Arch` suite, where an unregistered testsuite meant
     * every architecture test was silently skipped. This assertion is trivially true when this
     * file executes at all; its value is that it executes, which the Ci lock test guards from the
     * other side.
     */
    public function test_this_file_runs_in_the_registered_unit_testsuite(): void
    {
        self::assertStringContainsString(
            'tests'.DIRECTORY_SEPARATOR.'Unit'.DIRECTORY_SEPARATOR,
            (string) (new ReflectionClass(self::class))->getFileName(),
        );
    }
}
