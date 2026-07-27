<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Gateway;

/**
 * Package-owned. One association HubSpot reported for one directed read, in place of the SDK's
 * `MultiAssociatedObjectWithLabel` and `AssociationSpecWithLabel` — no `HubSpot\*` type may appear
 * in a Gateway return type (R1), or Phase 3's `Registry` and Phase 4's `Sync` would violate the rule
 * merely by consuming one. `tests/Arch/SdkSurfaceTest.php` proves this class stays SDK-free.
 *
 * **One row per reported association TYPE, not per related record.** The SDK's read model nests a
 * list of types inside each related record, and FOUND-03's probe (2026-07-27) showed that list
 * routinely holds more than one entry: a labelled write also materialises HubSpot's own default
 * association, so reading back a labelled `deals → contacts` returned `typeId 1` (the label) *and*
 * `typeId 3` (the default) for a single record, in an order HubSpot does not guarantee. Flattening
 * is what keeps every reported type visible; collapsing to one type per record would force a choice
 * between "the first" and "the only", and either would report success regardless of which id was
 * actually written.
 *
 * `$typeId` is **the id HubSpot reported for the direction that was read**, and it is not the id that
 * was written in the other direction: the probe measured `3 → 4` unlabelled and `1 → 2` labelled.
 * This value is for traversal and verification only. It is never fed back into a write — the inverse
 * is stored, never assumed (02-CONTEXT.md rule 4).
 *
 * `$label` is null wherever HubSpot supplied none, which is always the case for its own
 * `HUBSPOT_DEFINED` types.
 */
final readonly class AssociationRow
{
    public function __construct(
        public string $toObjectId,
        public int $typeId,
        public string $category,
        public ?string $label = null,
    ) {}
}
