<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Registry;

use ReyemTech\Hubspot\Gateway\AssociationCategory;
use ReyemTech\Hubspot\Gateway\AssociationType;

/**
 * **The seeded HubSpot-defined baseline: what this package can resolve with no network, no
 * credentials and no database.**
 *
 * Four directional pairs, eight directions. Every id is citable to design spec §6's directional
 * table, reproduced in `.planning/phases/03-registry-and-stores/03-CONTEXT.md`:
 *
 * | From -> To | typeId | Inverse | typeId |
 * |---|---|---|---|
 * | Contact -> Company | 279 | Company -> Contact | 280 |
 * | Contact -> Primary Company | 1 | Company -> Primary Contact | 2 |
 * | Deal -> Line Item | 19 | Line Item -> Deal | 20 |
 * | Note -> Contact | 202 | Contact -> Note | 201 |
 *
 * **Nothing else is seeded, and nothing else may be.** The full HubSpot-defined baseline is larger;
 * where this map is incomplete the miss path is the correct behaviour, and
 * `php artisan hubspot:associations:sync` (03-03) is how a portal fills the gap. An id nobody can cite
 * is worse than an absent id: an absent id throws where the reader can see it, and a wrong id is
 * accepted by HubSpot without complaint and associates records under a relationship nobody chose.
 * `tests/Unit/Registry/BaselineAssociationTypesTest.php` fails the build if the seeded id set stops
 * being exactly the cited one.
 *
 * A `USER_DEFINED` id is never seeded either, for a reason design spec §6.2 states outright: label
 * ids are per-portal, so "your `partner_agency` id is a different integer in another account". A
 * hardcoded one is correct only for its author's portal.
 *
 * ## Two columns that deliberately say "not known"
 *
 * **`is_default` is null on every row.** FOUND-03 measured which type an unlabelled write
 * materialises for `deals -> contacts` and for that pair only (`typeId 3`, `HUBSPOT_DEFINED`), and
 * none of the four pairs here is that pair. The probe document forbids extending its results "by
 * reasoning about cases that were not run", so the honest seeded value is the absent one. Nothing on
 * a write path reads the flag — an unlabelled association never touches the registry at all (design
 * spec §6.1 rule 3) — and 03-03's sync is where a portal's real answer arrives.
 *
 * ## The labels are this package's canonical names, and that is a derived decision
 *
 * HubSpot's API returns `label: null` for `HUBSPOT_DEFINED` types — measured twice in FOUND-03
 * (`typeId 3` and `typeId 4`, both `label null`). A row with a null label is unreachable through
 * `AssociationTypeResolver::resolve()`, whose label is non-nullable by design, so a baseline of
 * null-labelled rows could not satisfy the one thing this plan exists to deliver: a labelled write
 * that resolves offline.
 *
 * Each row therefore carries this package's own canonical NAME for the HubSpot-defined type,
 * transcribed from the cited table's row name (`Contact -> Company` becomes `Contact to company`).
 * Two consequences, both deliberate:
 *
 * - The two directions of one pair carry DIFFERENT names, because a paired label is asymmetric in its
 *   name as well as in its id — FOUND-03 run 2 measured `Deals` one way and `People` the other
 *   (03-CONTEXT.md rule 3).
 * - A portal could in principle define a user-defined label spelled the same way. A reconciled row
 *   always overrides a seeded one for the same `(direction, label)` key, so after
 *   `hubspot:associations:sync` the portal's own id wins. Before a sync the seeded HubSpot-defined id
 *   is what answers, which is the same id HubSpot itself would have used for that relationship.
 */
final class BaselineAssociationTypes
{
    /**
     * The cited table, as data. Each entry is `[from, to, typeId, label, inverseTypeId]`.
     *
     * @var list<array{string, string, int, string, int}>
     */
    private const CITED = [
        // design spec §6: Contact -> Company is 279, Company -> Contact is 280.
        ['contacts', 'companies', 279, 'Contact to company', 280],
        ['companies', 'contacts', 280, 'Company to contact', 279],
        // design spec §6: Contact -> Primary Company is 1, Company -> Primary Contact is 2.
        ['contacts', 'companies', 1, 'Contact to primary company', 2],
        ['companies', 'contacts', 2, 'Company to primary contact', 1],
        // design spec §6: Deal -> Line Item is 19, Line Item -> Deal is 20.
        ['deals', 'line_items', 19, 'Deal to line item', 20],
        ['line_items', 'deals', 20, 'Line item to deal', 19],
        // design spec §6: Note -> Contact is 202, Contact -> Note is 201 — the pair the design
        // documents name as the canonical mistake.
        ['notes', 'contacts', 202, 'Note to contact', 201],
        ['contacts', 'notes', 201, 'Contact to note', 202],
    ];

    /**
     * Every seeded row, in the order the cited table states them.
     *
     * @return list<AssociationTypeRow>
     */
    public static function rows(): array
    {
        $rows = [];

        foreach (self::CITED as [$from, $to, $typeId, $label, $inverseTypeId]) {
            $rows[] = new AssociationTypeRow(
                direction: AssociationDirection::of(from: $from, to: $to),
                type: new AssociationType(typeId: $typeId, category: AssociationCategory::HubspotDefined),
                label: $label,
                inverseTypeId: $inverseTypeId,
                // Never measured for these pairs. See the class docblock.
                isDefault: null,
            );
        }

        return $rows;
    }

    /**
     * The seeded row for exactly this direction under exactly this label, or nothing.
     *
     * **There is no second lookup here and there is nowhere for one to be added.** The key is built
     * from the direction as given; the reversed key is never computed anywhere in this class, which
     * is what makes "fall back to the inverse" unwritable rather than merely forbidden. A caller that
     * gets nothing has nothing to substitute — `AssociationTypeRegistry` turns that into a throw
     * naming the direction.
     */
    public static function resolve(AssociationDirection $direction, string $label): ?AssociationTypeRow
    {
        return self::keyed()[$direction->key($label)] ?? null;
    }

    /**
     * @return array<string, AssociationTypeRow>
     */
    private static function keyed(): array
    {
        $keyed = [];

        foreach (self::rows() as $row) {
            $keyed[$row->key()] = $row;
        }

        return $keyed;
    }
}
