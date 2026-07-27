<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Signals;

use ReyemTech\Hubspot\Exceptions\AssociationTypeException;

/**
 * A permanent, committed proof that `Signals` may throw the package's exception hierarchy — required to
 * PASS R5. See `Registry/RegistryResolverThrowingOnAMiss.php` for why this directory exists.
 *
 * It throws `AssociationTypeException` rather than a `Signals`-specific member because there is no
 * `Signals`-specific member: STANDARDS §9's hierarchy has exactly four, and every layer that touches
 * associations raises this one. That is the whole point of the widening — the hierarchy is
 * cross-cutting, so a layer allow-list that omits it makes "a single shared hierarchy consumers catch"
 * and "no raw SDK exception reaches userland" mutually impossible.
 *
 * It depends on nothing in `Sync` or `Webhooks`, so R7 (Signals is a peer, not a consumer) stays green
 * with this file in place too.
 */
final class SignalsThrowingAnAssociationTypeException
{
    /**
     * @param  list<string>  $labels
     */
    public function assertLabelled(array $labels): void
    {
        if ($labels === []) {
            throw AssociationTypeException::noLabelsGiven();
        }
    }
}
