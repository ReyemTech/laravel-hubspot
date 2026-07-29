<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Gateway;

use ReyemTech\Hubspot\Exceptions\AssociationTypeException;

/**
 * One association type HubSpot defines **for one direction between two object types** — the shape
 * `HubSpot\Client\Crm\Associations\V4\Schema\Model\AssociationSpecWithLabel` carries, in a value this
 * package owns.
 *
 * Package-owned and SDK-free because it crosses the Gateway boundary OUTBOUND into `Registry`, which
 * may not name a `HubSpot\*` class (R1) — `php artisan hubspot:associations:sync` consumes a list of
 * these and would violate the rule merely by consuming an SDK model.
 * `tests/Arch/SdkSurfaceTest.php` proves it stays that way.
 *
 * ## Three fields, and the fourth that is deliberately absent
 *
 * The SDK model declares exactly `category`, `label` and `type_id` — verified against the pinned
 * 14.1.0 — and this class carries exactly those, with the first and last folded into an
 * {@see AssociationType} so a definition cannot exist holding a type id the rest of the package would
 * reject.
 *
 * **There is no `inverseTypeId`, and there is nothing here from which one could be derived.** That is
 * the load-bearing absence. `DefinitionsApi::getPage($from, $to)` answers for one direction, and a
 * paired label carries a DIFFERENT NAME in each direction — FOUND-03 run 2 measured `Deals` forward
 * and `People` inverse — so two directional reads share no join key at all. Given the forward list
 * `[1: "Deals", 5: "Sponsor"]` and the reverse list `[2: "People", 6: "Sponsored by"]`, nothing in
 * either response says which pairs with which; matching by array position or by "the only other
 * user-defined one" would silently persist another label's id as the inverse. A field here would be
 * an invitation to fill it in by inference, so the field does not exist and
 * `Registry\AssociationTypeRow::$inverseTypeId` stays null until the pairing is actually observed.
 *
 * ## Why `$label` is `mixed` and checked
 *
 * The same reason {@see ObjectRef} and {@see AssociationType} are: `declare(strict_types=1)` binds at
 * the file making the CALL, never at this one, so a promoted `?string $label` would have coerced a
 * weak-mode caller's `1` into `'1'` — a label no portal has, silently registered against a real type
 * id. It is rejected rather than cast, and no narrowing `@param` docblock sits over the native
 * (PHPStan at level max would fold the check into a tautology, and a baseline is forbidden).
 *
 * A null label is HubSpot's own answer for a `HUBSPOT_DEFINED` type — measured twice in FOUND-03 — so
 * it is a legitimate value here, not a missing one.
 */
final readonly class AssociationDefinition
{
    // Declared rather than promoted so the constructor can reject a non-string before anything is
    // assigned.
    public ?string $label;

    public function __construct(
        public AssociationType $type,
        mixed $label,
    ) {
        if ($label !== null && ! is_string($label)) {
            throw AssociationTypeException::nonStringLabel($label);
        }

        $this->label = $label;
    }
}
