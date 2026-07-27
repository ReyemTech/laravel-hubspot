<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Gateway;

use ReyemTech\Hubspot\Exceptions\AssociationTypeException;

/**
 * The category half of an association spec: who defined the association type HubSpot is being asked
 * to write.
 *
 * **Modelled as a backed enum rather than as a validated string, deliberately.** The alternative —
 * a `string` property checked in the constructor — validates once and then hands every consumer
 * downstream a plain `string` that PHPStan knows nothing about, so each of them either re-checks it
 * or trusts it. An enum case makes the invalid value unrepresentable past this door: once an
 * {@see AssociationType} holds a case, no later code path can produce a category HubSpot would
 * reject, and level max can prove it.
 *
 * **Four cases, not the three HubSpot's public documentation lists.** The set is read from the
 * pinned SDK major's own allow-list (`AssociationSpec::getAssociationCategoryAllowableValues()`),
 * and `tests/Unit/Gateway/AssociationTypeTest.php` asserts the two agree exactly. That equality
 * matters in both directions: a narrower enum would reject a category HubSpot accepts, and a wider
 * one would let `AssociationSpec::setAssociationCategory()` raise a raw
 * `InvalidArgumentException` — an SDK exception reaching userland, which STANDARDS §9 forbids.
 *
 * String-backed so the case survives a round trip through storage and onto the wire unchanged:
 * Phase 3's registry persists the category as text, and the SDK sends `->value` verbatim.
 *
 * Package-owned and SDK-free — it crosses the Gateway boundary inbound from `Registry` (R1).
 */
enum AssociationCategory: string
{
    case HubspotDefined = 'HUBSPOT_DEFINED';

    case IntegratorDefined = 'INTEGRATOR_DEFINED';

    case UserDefined = 'USER_DEFINED';

    case Work = 'WORK';

    /**
     * The validated door a stored or configured string comes through.
     *
     * Takes `mixed` rather than `string` for the reason `ObjectRef` does: `declare(strict_types=1)`
     * binds at the file making the CALL, so a consumer file without it would have had `1` coerced to
     * `'1'` and `true` to `'1'` — surfacing as an unknown-category complaint about a value the
     * caller never wrote. The failure is reported for what it is instead.
     *
     * There is no `tryFromValue()` companion, and there will not be one. A nullable resolution
     * result is the shape that invites a caller to substitute something for the miss, and on this
     * seam the only thing available to substitute is the wrong direction's type id.
     */
    public static function fromValue(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (! is_string($value)) {
            throw AssociationTypeException::nonStringAssociationCategory($value, self::values());
        }

        return self::tryFrom($value)
            ?? throw AssociationTypeException::unknownAssociationCategory($value, self::values());
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
