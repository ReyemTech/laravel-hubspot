<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Registry;

use ReyemTech\Hubspot\Exceptions\AssociationTypeException;
use ReyemTech\Hubspot\Exceptions\ObjectTypeException;
use ReyemTech\Hubspot\Gateway\AssociationPair;
use ReyemTech\Hubspot\Gateway\AssociationType;
use ReyemTech\Hubspot\Gateway\Contracts\AssociationTypeResolver;
use ReyemTech\Hubspot\Registry\Contracts\AssociationTypeStore;

/**
 * **The real association-type resolver: the thing that turns Phase 2's honest throw into a working
 * labelled write.**
 *
 * It replaces `Gateway\UnresolvedAssociationTypeResolver` by rebinding one container key, and no
 * signature anywhere in the Gateway changes when it does — the gateway takes its resolver from the
 * container, so the contract declared on the Gateway side is the whole seam (R2 forbids the reverse
 * dependency, which is why the interface lives there and this implementation lives here).
 *
 * ## The rule this class exists to hold
 *
 * **A miss throws, names the direction, and never returns the inverse id.**
 *
 * This is the layer where that fallback could reappear, one layer below where Phase 2 forbade it: the
 * store holds both directions' rows, and a lookup that misses the requested direction and finds the
 * other one is a single `??` away. Three things make it unwritable rather than merely forbidden:
 *
 * 1. The lookup key is a {@see AssociationDirection}, which is asymmetric by construction and offers
 *    no way to get its two ends back without the order they were given in — no `reversed()`, no
 *    `sides()`, no iterator.
 * 2. The reversed key is never computed anywhere in this class, in
 *    `Registry\Stores\ArrayAssociationTypeStore`, or in `Registry\BaselineAssociationTypes`. There is
 *    nothing for a fallback to reach for even by accident.
 * 3. `inverse_type_id` is on the row this class reads, and this class never touches it. It is
 *    recorded for traversal and verification (design spec §6.2), and
 *    `tests/Feature/Registry/LabelledWriteThroughRegistryTest.php` proves it never reaches the wire
 *    by seeding one that matches no type id anywhere.
 *
 * `tests/Feature/Registry/AssociationTypeRegistryTest.php` arranges the inverse id to be present,
 * correctly typed and one lookup from the answer, and requires a throw. The fix for a red run there
 * is never to relax the assertion.
 *
 * ## Where the answer comes from
 *
 * From the bound {@see AssociationTypeStore}: rows a portal reconciled first, and the seeded
 * HubSpot-defined baseline behind them for the same direction and label. That is what makes a
 * labelled write resolve offline, with no network, no credentials and no database, straight after
 * `composer require`.
 */
final class AssociationTypeRegistry implements AssociationTypeResolver
{
    public function __construct(private readonly AssociationTypeStore $store) {}

    /**
     * @throws AssociationTypeException if no type is registered for this direction under this label
     * @throws ObjectTypeException if either of the pair's object types cannot be normalised — a
     *                             caller mistake about an argument rather than a registry miss, and
     *                             reported as one so the reader is not sent off to register a
     *                             direction that cannot exist. Also raised when the pair spells an
     *                             object type in an accepted ALIAS rather than its canonical form,
     *                             for the reason the body explains. Both are raised before any
     *                             request is built, so neither writes anything.
     */
    public function resolve(AssociationPair $pair, string $label): AssociationType
    {
        $direction = AssociationDirection::of(
            from: $pair->from->objectType,
            to: $pair->to->objectType,
        );

        // Normalising the LOOKUP is not enough, and the gap is not obvious. `AssociationGateway`
        // builds the request URI from the pair's own `ObjectRef::objectType`, never from the
        // direction resolved here -- so a pair carrying `Deal` would resolve the row for `deals` and
        // then PUT to `/crm/v4/objects/Deal/...`, producing exactly the 404-about-a-route that
        // normalisation exists to prevent (Codex P1, PR #24). The Registry cannot rewrite the pair
        // either: it is a `Gateway` value, and `Gateway` may not name a `Registry` class (R2), so
        // `ObjectRef` cannot normalise itself. Refusing is the safe answer left, and it is refused
        // before any request is built.
        self::assertCanonical($pair->from->objectType, $direction->from);
        self::assertCanonical($pair->to->objectType, $direction->to);

        // No `?? $this->store->resolve($direction->reversed(), ...)`, and nowhere to write one: the
        // reversed direction is not a value this package can construct from a direction.
        $row = $this->store->resolve($direction, $label);

        if ($row === null) {
            throw AssociationTypeException::directionNotResolvable(
                $direction->from->value,
                $direction->to->value,
                $label,
            );
        }

        // The row's own type, and nothing derived from its inverse id.
        return $row->type;
    }

    /**
     * @throws ObjectTypeException if the spelling the caller used is not the one HubSpot addresses
     */
    private static function assertCanonical(string $given, HubspotObjectType $canonical): void
    {
        if ($given !== $canonical->value) {
            throw ObjectTypeException::nonCanonicalObjectType($given, $canonical->value);
        }
    }
}
