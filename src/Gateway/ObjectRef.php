<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Gateway;

use ReyemTech\Hubspot\Exceptions\ObjectTypeException;

/**
 * One end of an association: a HubSpot object type plus the id of one record of that type. Half of
 * the directed-pair primitive — see {@see AssociationPair} for the other half and for the rule both
 * halves exist to enforce.
 *
 * Package-owned and SDK-free, because it crosses the Gateway boundary inbound: Phase 3's `Registry`
 * and Phase 4's `Sync` construct these, and neither may name a `HubSpot\*` class (R1).
 * `tests/Arch/SdkSurfaceTest.php` proves it stays that way.
 *
 * **Validated at construction, not at use.** An empty or whitespace-only object type or id is a
 * caller mistake, and the whole justification for `declare(strict_types=1)` in this package
 * (STANDARDS §4) is that HubSpot object ids are strings that look like integers — coercive typing
 * makes `"0"`, `0` and `""` silent equivalents, and a wrong object id writes to the wrong CRM
 * record. `ObjectSerializer::toPathValue()` URL-encodes the value and does nothing else, and
 * HubSpot performs no server-side validation on it either (02-RESEARCH.md Pitfall 6), so a blank
 * value is not rejected anywhere downstream: it is encoded into a real-looking request path and
 * answered with a 404 about a route rather than an error about the argument (threat T-02-13).
 *
 * Whitespace-only counts as blank for the same reason. `" "` encodes to `%20`, which is a valid
 * path segment; nothing about it fails loudly.
 *
 * Deliberately NOT validated here: whether the object type actually exists. There is no allow-list
 * in the SDK and none in this layer — normalising `deals`, `line_items` and `p_*` to canonical
 * identifiers is `HubspotObjectType`'s job in Phase 3 (REG-01).
 */
final readonly class ObjectRef
{
    public function __construct(
        public string $objectType,
        public string $id,
    ) {
        if (trim($objectType) === '') {
            throw ObjectTypeException::blankObjectType();
        }

        if (trim($id) === '') {
            throw ObjectTypeException::blankObjectId($objectType);
        }
    }
}
