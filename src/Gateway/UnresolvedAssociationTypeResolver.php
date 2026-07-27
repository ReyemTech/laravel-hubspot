<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Gateway;

use ReyemTech\Hubspot\Exceptions\AssociationTypeException;
use ReyemTech\Hubspot\Gateway\Contracts\AssociationTypeResolver;

/**
 * The default `AssociationTypeResolver` binding: it resolves nothing and throws for every request.
 *
 * **This is not a stub standing in for missing work.** It is the correct behaviour for a package
 * whose association-type registry does not exist yet, and it is what makes the never-the-inverse
 * guarantee provable today rather than in Phase 3. The alternative shapes are all worse:
 *
 * - A resolver holding a small hardcoded map would put the seeded baseline type map in two places
 *   the moment Phase 3 ships the real one (REG-02), and the copy in the wrong place would be the one
 *   nobody updates.
 * - A resolver returning `null` for everything would hand every caller a value to write a fallback
 *   against, and the only thing available to fall back to on this seam is the opposite direction's
 *   id.
 * - No binding at all would surface as a container `BindingResolutionException` naming an interface,
 *   which tells a consumer nothing about what they were supposed to do.
 *
 * Throwing with a message that names the failed direction, the label, and the one container key that
 * would fix it is the honest answer. It is also the answer that keeps a labelled write from ever
 * guessing a type id: with this bound, `associateWithLabel()` issues **zero** requests rather than a
 * plausible-looking wrong one.
 *
 * Stateless by construction — no constructor and no properties, so there is no map here for anything
 * to fall back to even by accident. Phase 3 replaces it by rebinding
 * {@see AssociationTypeResolver}; nothing about the gateway's public shape changes when it does.
 */
final class UnresolvedAssociationTypeResolver implements AssociationTypeResolver
{
    public function resolve(AssociationPair $pair, string $label): AssociationType
    {
        throw AssociationTypeException::noResolverInstalled(
            $pair->from->objectType,
            $pair->to->objectType,
            $label,
        );
    }
}
