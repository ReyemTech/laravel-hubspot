<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Gateway\Contracts;

use ReyemTech\Hubspot\Exceptions\ApiException;
use ReyemTech\Hubspot\Gateway\AssociationDefinition;

/**
 * **What association types a portal defines for one direction between two object types.**
 *
 * Its one consumer is `php artisan hubspot:associations:sync`, which reconciles a portal's own
 * `USER_DEFINED` labels into the registry. That command lives in `Registry`, and `Registry` may not
 * name a `HubSpot\*` class (R1) — so the read is a Gateway capability handing back package-owned
 * {@see AssociationDefinition} values, and the command consumes those.
 *
 * ## Its own class, not a method on `AssociationGatewayContract`
 *
 * Three reasons, in order of weight. It answers a different question: `AssociationGatewayContract` is
 * about associating two RECORDS, and every method on it takes an `AssociationPair` carrying two record
 * ids, while a definition read has no records in it at all — forcing this onto that contract would
 * mean either a fifth method whose first parameter is not a pair (breaking the invariant
 * `AssociationGatewayTest` pins by reflection) or inventing record ids nobody has. It also reaches a
 * different SDK namespace, `Associations\V4\Schema`, with its own `ApiException` class. And
 * `AssociationGateway` already carries the whole association write path, with `ObjectGateway` at
 * 426 lines against the 500-line gate as the standing warning about appending (STANDARDS §6b).
 *
 * ## The direction lives in the parameter names
 *
 * There is no directed-pair value object to take here. `Gateway\AssociationPair` carries two records,
 * and `Registry\AssociationDirection` is a `Registry` class this layer may not name. So the two object
 * types are named `fromObjectType` and `toObjectType`, in that order, and
 * `tests/Feature/Gateway/AssociationDefinitionsGatewayTest.php` pins both names and their order by
 * reflection — the same mechanism `AssociationPair`'s `from`/`to` are pinned by, and for the same
 * reason: a rename to something positional would make a transposition survivable again at every call
 * site. `Registry\AssociationDirection::of(from:, to:)` states its direction exactly this way one
 * layer up.
 */
interface AssociationDefinitionsGatewayContract
{
    /**
     * Every association type this portal defines for `$fromObjectType -> $toObjectType`.
     *
     * **Directional, and answering for that direction only.** HubSpot's endpoint is
     * `/crm/associations/v4/{fromObjectType}/{toObjectType}/labels`, and reversing the two arguments
     * addresses a different route with a different answer. A paired label carries a different NAME in
     * each direction (FOUND-03 run 2: `Deals` forward, `People` inverse), so a caller wanting both
     * directions issues two calls and keeps the two answers apart. Deriving one from the other is not
     * possible — see {@see AssociationDefinition} for why the two responses share no join key.
     *
     * An empty list is a legitimate answer: a portal need not define any label for a pair. It is
     * distinguishable from a failure, which throws.
     *
     * @return list<AssociationDefinition>
     *
     * @throws ApiException if HubSpot rejected the read or was never reached
     */
    public function listFor(string $fromObjectType, string $toObjectType): array;
}
