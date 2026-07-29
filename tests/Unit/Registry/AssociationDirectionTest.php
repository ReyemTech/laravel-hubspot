<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Unit\Registry;

use ReflectionClass;
use ReyemTech\Hubspot\Exceptions\ObjectTypeException;
use ReyemTech\Hubspot\Registry\AssociationDirection;
use ReyemTech\Hubspot\Registry\HubspotObjectType;
use ReyemTech\Hubspot\Tests\TestCase;

/**
 * **The key the registry is keyed on, and the reason a miss cannot find the inverse.**
 *
 * `AssociationDirection` is the `(from, to)` half of a registry row. Every assertion in this file is
 * about one property: the value it derives for `contacts -> companies` is not, and cannot be made to
 * be, the value it derives for `companies -> contacts`. A registry keyed on the pair-as-a-set would
 * satisfy every other test in this plan and still write 280 where 279 belonged.
 *
 * It deliberately has no `reversed()`, no `sides()`, no `sorted()` and no iterator — the same
 * absences `Gateway\AssociationPair` carries, for the same reason. A reflection test below fails the
 * build if one appears, because each of them hands a caller the two ends in an order they chose,
 * which is the unordered pair this class exists to refuse.
 */
mutates(AssociationDirection::class);

final class AssociationDirectionTest extends TestCase
{
    public function test_it_normalises_both_ends_through_hubspot_object_type(): void
    {
        $direction = AssociationDirection::of(from: 'Deals', to: 'lineItems');

        self::assertInstanceOf(HubspotObjectType::class, $direction->from);
        self::assertInstanceOf(HubspotObjectType::class, $direction->to);
        self::assertSame('deals', $direction->from->value);
        self::assertSame('line_items', $direction->to->value);
    }

    public function test_an_unnormalisable_end_throws_rather_than_producing_a_key_nobody_can_hit(): void
    {
        try {
            AssociationDirection::of(from: 'contacts', to: 'widgets');
            self::fail('Expected an unnormalisable to side to throw.');
        } catch (ObjectTypeException $exception) {
            self::assertSame(ObjectTypeException::unmappable('widgets')->getMessage(), $exception->getMessage());
        }
    }

    /**
     * **The single most important assertion in this file.** Both directions of one pair produce
     * different keys, so a lookup for one can never land on the row stored for the other.
     */
    public function test_the_two_directions_of_one_pair_never_share_a_key(): void
    {
        $forward = AssociationDirection::of(from: 'contacts', to: 'companies');
        $inverse = AssociationDirection::of(from: 'companies', to: 'contacts');

        self::assertNotSame($forward->key('Employer'), $inverse->key('Employer'));
        self::assertNotSame($forward->key(null), $inverse->key(null));
    }

    /**
     * Aliases of the same object type reach the same key, or a portal that writes `Deals` and a
     * consumer that writes `deals` would be looking at two different rows for one direction.
     */
    public function test_aliases_of_the_same_direction_reach_the_same_key(): void
    {
        self::assertSame(
            AssociationDirection::of(from: 'deals', to: 'line_items')->key('Sold'),
            AssociationDirection::of(from: 'Deal', to: 'lineItems')->key('Sold'),
        );
    }

    /**
     * A label is part of the key, so two labelled types on one direction are two rows rather than
     * one — the ambiguity REQUIREMENTS.md's REG-02 correction of 2026-07-28 was raised about.
     */
    public function test_two_labels_on_one_direction_are_two_keys(): void
    {
        $direction = AssociationDirection::of(from: 'contacts', to: 'companies');

        self::assertNotSame($direction->key('Employer'), $direction->key('Partner'));
    }

    /**
     * The unlabelled default row's key is distinct from every labelled one, including from a label
     * that is the empty string and from a label spelled like the encoding itself. Most databases
     * permit repeated NULLs in a unique index, which is why this distinction is made in the key
     * rather than left to the storage layer.
     */
    public function test_the_null_label_is_a_key_of_its_own_and_collides_with_no_label(): void
    {
        $direction = AssociationDirection::of(from: 'contacts', to: 'companies');

        $keys = [
            $direction->key(null),
            $direction->key(''),
            $direction->key('default:'),
            $direction->key('label:'),
        ];

        self::assertSame($keys, array_unique($keys), 'Two different labels produced one key.');
    }

    /**
     * The direction, in the wording every exception message and every doctor line uses. Asserted on
     * the whole string: a message that named the objects in the wrong order would read as a real
     * direction and send the reader to check the wrong row.
     */
    public function test_it_describes_itself_in_the_from_to_wording_the_exceptions_use(): void
    {
        self::assertSame(
            'notes -> contacts',
            AssociationDirection::of(from: 'notes', to: 'contacts')->describe(),
        );
        self::assertSame(
            'contacts -> notes',
            AssociationDirection::of(from: 'contacts', to: 'notes')->describe(),
        );
    }

    /**
     * The absences that keep the direction directional. Each of these would hand a caller the two
     * ends without the order they were given in, which is the pair `AssociationPair` and this class
     * both exist to make unrepresentable.
     */
    public function test_it_offers_no_way_to_get_the_two_ends_back_without_their_order(): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(AssociationDirection::class))->getMethods(),
        );

        foreach (['reversed', 'sides', 'sorted', 'all', 'toArray', 'getIterator'] as $forbidden) {
            self::assertNotContains($forbidden, $methods, "AssociationDirection must not offer {$forbidden}().");
        }
    }
}
