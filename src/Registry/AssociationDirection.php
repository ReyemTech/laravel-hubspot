<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Registry;

use ReyemTech\Hubspot\Exceptions\ObjectTypeException;

/**
 * **The registry's key: one direction, `from` to `to`, and never a pair-as-a-set.**
 *
 * This is the Registry-side counterpart of `Gateway\AssociationPair`. The pair carries two records;
 * a direction carries only their object types, because a type id is registered per object-type
 * direction and has nothing to do with which two records are being associated.
 *
 * Everything about this class exists to make one mistake unrepresentable. HubSpot's type ids are
 * directional and different in each direction — Contact -> Company is 279 and Company -> Contact is
 * 280 — and HubSpot accepts the wrong one without complaint. A registry keyed on the pair as an
 * unordered set would resolve the row it holds for whichever direction it happens to have, and the
 * records would be associated backwards with no error anywhere.
 *
 * So there is deliberately:
 *
 * - **no `reversed()`**, unlike `AssociationPair`, which needs one because a two-direction write is
 *   two independently resolved writes. Nothing in the registry ever wants the other direction: a miss
 *   is a throw, and the only value available to substitute is the one that must never be substituted;
 * - **no `sides()`, `sorted()`, `toArray()` or iterator**, each of which would hand a caller the two
 *   ends in an order they picked rather than the one they were given.
 *
 * `tests/Unit/Registry/AssociationDirectionTest.php` fails the build if any of them appears.
 */
final readonly class AssociationDirection
{
    // Declared rather than promoted so the named constructor is the only door, and both ends are
    // normalised before either is assigned.
    public HubspotObjectType $from;

    public HubspotObjectType $to;

    private function __construct(HubspotObjectType $from, HubspotObjectType $to)
    {
        $this->from = $from;
        $this->to = $to;
    }

    /**
     * Both ends are normalised on the way in, so `Deals` and `deals` address one row rather than two.
     *
     * The parameters are `mixed` because `HubspotObjectType::normalise()` is where object types are
     * validated, and duplicating the check here would be a second place to be wrong. They are named
     * `from` and `to` so a call site states its direction in the caller's own source, where a
     * reviewer reads it — the same reason `AssociationPair`'s parameter names are pinned by test.
     *
     * @throws ObjectTypeException if either end cannot be normalised
     */
    public static function of(mixed $from, mixed $to): self
    {
        return new self(
            HubspotObjectType::normalise($from),
            HubspotObjectType::normalise($to),
        );
    }

    /**
     * The storage key for this direction under this label.
     *
     * Two properties matter, and both are asserted by test:
     *
     * 1. **The key is asymmetric.** `contacts>companies>…` and `companies>contacts>…` are different
     *    strings, so a lookup for one direction cannot land on the other's row.
     * 2. **A null label is a key of its own.** The unlabelled default row is not the same row as a
     *    row labelled `''`, and most databases permit repeated `NULL`s in a unique index — so the
     *    distinction is encoded here, in the key, rather than delegated to a storage engine that may
     *    not make it. `null` becomes `default:` and a label `x` becomes `label:x`, so no label can
     *    collide with the unlabelled row however it is spelled.
     *
     * An object type can contain neither `>` nor a colon (the canonical set and the custom-object
     * pattern both forbid them), so the first two segments are unambiguous however a label is spelled.
     */
    public function key(?string $label): string
    {
        return sprintf(
            '%s>%s>%s',
            $this->from->value,
            $this->to->value,
            $label === null ? 'default:' : 'label:'.$label,
        );
    }

    /**
     * The direction in the wording every exception message and diagnostic line uses, so a production
     * failure report identifies 202 from 201 rather than naming only a label (D-18).
     */
    public function describe(): string
    {
        return sprintf('%s -> %s', $this->from->value, $this->to->value);
    }
}
