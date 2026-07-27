<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Gateway\Contracts;

use ReyemTech\Hubspot\Exceptions\AssociationTypeException;
use ReyemTech\Hubspot\Gateway\AssociationPair;
use ReyemTech\Hubspot\Gateway\AssociationType;

/**
 * **The seam Phase 3's association-type registry plugs into, and the reason this package exists in
 * its current shape.**
 *
 * The Gateway resolves nothing about type ids on its own. It accepts a directed pair and a label,
 * asks whatever implementation is bound to this interface, and sends exactly what comes back.
 * Phase 3 (REG-02) implements this with a registry holding a seeded baseline map; until then the
 * default binding is `Gateway\UnresolvedAssociationTypeResolver`, which resolves nothing and throws
 * honestly. Named in prose rather than through an `{@see}` tag deliberately: a tag would have this
 * interface importing its own default implementation, which inverts the dependency the interface
 * exists to express.
 *
 * **Declared here, in `Gateway`, and not in `Registry` — this is load-bearing, not filing.** R2 says
 * `Registry` may depend on `Gateway` and not the reverse. An interface declared on the Registry side
 * would force `AssociationGateway` to name a `Registry` type in order to type its own collaborator,
 * which is the layer violation R2 exists to catch. Declaring the contract here means Phase 3 adds an
 * implementation and rebinds one container key, and no signature anywhere in the Gateway changes.
 *
 * ## What an implementation must do, and must never do
 *
 * **Resolve for the requested direction only.** HubSpot's association type ids are directional and
 * different in each direction — Note -> Contact is 202 and Contact -> Note is 201, Contact ->
 * Company is 279 and Company -> Contact is 280 — and FOUND-03's probe confirmed on 2026-07-27 that
 * reading an association back does not hand you the id you wrote. `$pair` states the direction; the
 * answer must be the id registered for that direction and nothing else.
 *
 * **Never return the id registered for the opposite direction.** Not as a fallback, not as a
 * best-effort guess, not on the reasoning that the pair "looks reversed". Writing the wrong
 * direction raises no error at HubSpot: the records are simply associated backwards, and nobody
 * notices for months. `tests/Feature/Gateway/NeverTheInverseTest.php` fails the build if the gateway
 * ever writes an inverse id, and an implementation of this interface is the other place that failure
 * could originate.
 *
 * **A miss is a throw, and there is no other way to express one.** The return type is a
 * non-nullable `AssociationType`, deliberately. A nullable return, a `false`, or a "not found"
 * sentinel would each hand the caller something to write `?? $inverseTypeId` against — and on this
 * seam the only value available to substitute is the one that must never be substituted. If the
 * requested direction is not registered, throw {@see AssociationTypeException} with a message naming
 * the from type, the to type and the label, so a production failure report identifies the direction
 * rather than only the label (D-18).
 *
 * This is the documented extension point (decision #5): a consumer who wants different resolution
 * behaviour implements this interface and rebinds it, rather than subclassing anything.
 */
interface AssociationTypeResolver
{
    /**
     * Resolves the association type registered for this exact direction under this label.
     *
     * `$pair` is a direction, not two objects — see {@see AssociationPair} for why no signature in
     * this package accepts two object references without an order. `$label` is non-nullable because
     * an unlabelled association takes an entirely different route
     * (`AssociationGatewayContract::associate()`, which calls HubSpot's `createDefault()` and
     * resolves no type id at all), so this method never sees that case and must never be asked to
     * invent a default for it.
     *
     * @throws AssociationTypeException if no type is registered for this direction under this label.
     *                                  Never returns the opposite direction's id in its place.
     */
    public function resolve(AssociationPair $pair, string $label): AssociationType;
}
