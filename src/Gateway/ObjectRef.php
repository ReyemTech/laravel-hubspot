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
 * **The validation does not depend on the caller's `strict_types` setting, and that distinction is
 * the whole point.** `declare(strict_types=1)` binds at the file making the CALL, never at the file
 * declaring the constructor — so this package declaring it everywhere (STANDARDS §4) buys nothing
 * for a consumer's own file, and a typical Laravel application file does not declare it. Promoted
 * `string` parameters would therefore have coerced before this constructor body ran: `0` to `"0"`,
 * `true` to `"1"`, `1.2345678901234568E+19` to `"1.2345678901235E+19"` — a syntactically valid path
 * segment carrying silent precision loss — and a `Stringable` to whatever it renders. That is
 * precisely the silent equivalence condemned above, arriving through the one door strict types
 * cannot close. The two parameters are therefore native `mixed`, checked with `is_string()` before
 * the blank checks, and assigned to declared `string` properties only once they pass.
 *
 * A non-string is **rejected, never cast.** Casting would reintroduce the `0`/`"0"` blur this
 * docblock condemns, so a consumer holding an id as an integer writes `(string) $id` — which the
 * exception message tells them to do. No narrowing `@param string` docblock sits over the natives
 * either: PHPStan at level max would read the `is_string()` checks as tautologies, and this
 * repository forbids a baseline and permits a per-line suppression only with a written reason. The
 * accepted consequence is that a strict-mode consumer gets a runtime `ObjectTypeException` with a
 * better message where they previously got a static type error.
 *
 * Deliberately NOT validated here: whether the object type actually exists. There is no allow-list
 * in the SDK and none in this layer — normalising `deals`, `line_items` and `p_*` to canonical
 * identifiers is `HubspotObjectType`'s job in Phase 3 (REG-01).
 */
final readonly class ObjectRef
{
    // Declared rather than promoted so the constructor can reject a non-string before anything is
    // assigned. Both are readonly by virtue of the readonly class; repeating the modifier here is
    // redundant. `tests/Unit/Gateway/AssociationPairTest.php` pins the property types, their
    // readonly-ness and the parameters' `mixed`-ness by reflection, because collapsing this back
    // into two promoted `string` parameters reads like a tidy-up and would restore the coercion.
    public string $objectType;

    public string $id;

    public function __construct(mixed $objectType, mixed $id)
    {
        if (! is_string($objectType)) {
            throw ObjectTypeException::nonStringObjectReference('object type', $objectType);
        }

        if (trim($objectType) === '') {
            throw ObjectTypeException::blankObjectType();
        }

        if (! is_string($id)) {
            throw ObjectTypeException::nonStringObjectReference('object id', $id);
        }

        if (trim($id) === '') {
            throw ObjectTypeException::blankObjectId($objectType);
        }

        $this->objectType = $objectType;
        $this->id = $id;
    }
}
