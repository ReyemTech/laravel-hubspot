<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Gateway;

use ReyemTech\Hubspot\Exceptions\ObjectTypeException;

/**
 * **The directed-pair primitive, and the reason this package exists in its current shape.**
 *
 * The first of 02-CONTEXT.md's four association rules: no API in this package may accept two objects
 * without an order. HubSpot's association type ids are directional and *different in each
 * direction* — Note→Contact is 202 and Contact→Note is 201, Contact→Company is 279 and
 * Company→Contact is 280 — and FOUND-03's probe confirmed on 2026-07-27 that reading an association
 * back does not hand you the id you wrote. Writing the wrong direction raises no error at all, so
 * the mistake has to be made *unrepresentable* rather than merely discouraged.
 *
 * That is what the two parameter names buy. `new AssociationPair(from: $note, to: $contact)` states
 * the direction at the call site, in the caller's own source, where a reviewer reads it. The names
 * and their order are pinned by `tests/Unit/Gateway/AssociationPairTest.php`, so a refactor that
 * renames them to something positional fails the build instead of quietly making a transposition
 * survivable again.
 *
 * Two things this class deliberately does NOT have:
 *
 * - **No accessor that returns both sides in one collection.** A `sides()`, an `all()` or an
 *   `IteratorAggregate` would let a caller pull the two refs out positionally and pass them onward
 *   in either order — the unordered pair the constructor refuses to accept, reintroduced through the
 *   read side. A reflection test fails the build if one appears.
 * - **No type id, and no way to hold one.** Resolving a type id is plan 02-05's labelled path; the
 *   unlabelled path this pair serves first goes through `createDefault()` and resolves nothing
 *   (02-CONTEXT.md rule 2).
 *
 * Package-owned and SDK-free — it crosses the Gateway boundary inbound from `Registry` and `Sync`,
 * neither of which may name a `HubSpot\*` class (R1).
 */
final readonly class AssociationPair
{
    public function __construct(
        public ObjectRef $from,
        public ObjectRef $to,
    ) {
        // Same object type AND same id. Comparing only the object types would make a
        // company↔company parent/child association — and a contact↔contact one — unrepresentable,
        // and both are real HubSpot associations. Record ids are unique only within an object type,
        // so comparing only the ids would reject a contact and a company that happen to share one.
        if ($from->objectType === $to->objectType && $from->id === $to->id) {
            throw ObjectTypeException::selfAssociation($from->objectType, $from->id);
        }
    }

    /**
     * The reversed pair, as a new value. Named so that reversing a direction is a visible act in the
     * calling code rather than a side effect on a value someone else is still holding — `readonly`
     * makes mutation impossible anyway, and the name is what makes the intent legible.
     *
     * Writing the opposite direction as well is the caller this exists for — `associate()`'s
     * `bidirectional`, and the labelled writes' `inverseLabel`/`inverseLabels`. Each performs two
     * independently resolved directed writes, and the second one needs the reversed pair as a
     * first-class value. Note what reversal does NOT do — it does not carry, derive or assume a type
     * id for the opposite direction, and it says nothing about what that direction's label is called
     * either. The inverse type id is stored, never assumed (02-CONTEXT.md rule 4); the reversed pair
     * resolves its own, under labels its own caller named.
     */
    public function reversed(): self
    {
        return new self(from: $this->to, to: $this->from);
    }
}
