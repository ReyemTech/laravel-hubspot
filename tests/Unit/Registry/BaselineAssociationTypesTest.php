<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Unit\Registry;

use PHPUnit\Framework\Attributes\DataProvider;
use ReyemTech\Hubspot\Gateway\AssociationCategory;
use ReyemTech\Hubspot\Registry\AssociationDirection;
use ReyemTech\Hubspot\Registry\AssociationTypeRow;
use ReyemTech\Hubspot\Registry\BaselineAssociationTypes;
use ReyemTech\Hubspot\Tests\TestCase;

/**
 * **The seeded map, and the promise that nothing in it was invented.**
 *
 * Every id below is citable to design spec §6's directional table, reproduced in
 * `.planning/phases/03-registry-and-stores/03-CONTEXT.md`. Four pairs, eight directions. The full
 * HubSpot-defined baseline is larger; what is not here throws, and `hubspot:associations:sync` is how
 * a portal fills the gap.
 *
 * **An id nobody can cite is worse than an absent id.** An absent id throws where the reader can see
 * it; a wrong id is accepted by HubSpot without complaint and associates records under a relationship
 * nobody chose. The test asserting the seeded id set is EXACTLY the cited set is therefore not a
 * tautology restating the implementation — it is the gate that fails the build when a later hand adds
 * a plausible-looking id from memory.
 *
 * Two things every row states as "not known", deliberately:
 *
 * - `is_default` is null on every seeded row. FOUND-03 measured which type an unlabelled write
 *   materialises for `deals -> contacts` only (`typeId 3`), and none of the four pairs seeded here is
 *   that pair. The probe document forbids extending its results "by reasoning about cases that were
 *   not run", so the honest seeded value is "unmeasured". Nothing reads it on a write path.
 * - The label is the package's own canonical NAME for the HubSpot-defined type, not a portal label:
 *   HubSpot's API returns `label: null` for `HUBSPOT_DEFINED` types (FOUND-03 runs 1 and 2). It is
 *   what makes the type addressable through the one seam a labelled write has, and a portal row
 *   reconciled onto the same direction and label always overrides it.
 */
mutates(BaselineAssociationTypes::class);

final class BaselineAssociationTypesTest extends TestCase
{
    /**
     * The cited table, transcribed from design spec §6 / 03-CONTEXT.md. Read by several tests below,
     * so the citation lives in exactly one place in this file too.
     *
     * @return array<string, array{string, string, string, int, int}>
     */
    public static function citedDirectionProvider(): array
    {
        return [
            // fromType, toType, label, this direction's id, the inverse direction's id
            'Contact -> Company is 279' => ['contacts', 'companies', 'Contact to company', 279, 280],
            'Company -> Contact is 280' => ['companies', 'contacts', 'Company to contact', 280, 279],
            'Contact -> Primary Company is 1' => ['contacts', 'companies', 'Contact to primary company', 1, 2],
            'Company -> Primary Contact is 2' => ['companies', 'contacts', 'Company to primary contact', 2, 1],
            'Deal -> Line Item is 19' => ['deals', 'line_items', 'Deal to line item', 19, 20],
            'Line Item -> Deal is 20' => ['line_items', 'deals', 'Line item to deal', 20, 19],
            'Note -> Contact is 202' => ['notes', 'contacts', 'Note to contact', 202, 201],
            'Contact -> Note is 201' => ['contacts', 'notes', 'Contact to note', 201, 202],
        ];
    }

    #[DataProvider('citedDirectionProvider')]
    public function test_every_cited_direction_resolves_offline_to_its_own_type_id(
        string $fromType,
        string $toType,
        string $label,
        int $typeId,
        int $inverseTypeId,
    ): void {
        $row = BaselineAssociationTypes::resolve(
            AssociationDirection::of(from: $fromType, to: $toType),
            $label,
        );

        self::assertInstanceOf(AssociationTypeRow::class, $row);
        self::assertSame($typeId, $row->type->typeId);
        self::assertSame($inverseTypeId, $row->inverseTypeId);
        self::assertSame(AssociationCategory::HubspotDefined, $row->type->category);
        self::assertSame($label, $row->label);
        self::assertNull($row->isDefault, 'No source measures which of these types a bare association resolves to.');
    }

    /**
     * **The never-the-inverse guarantee at the seeded-data level.** Asking the baseline for the
     * direction it does not hold under a label must answer nothing — not the row it does hold for
     * the opposite direction, whose id is sitting one array lookup away and is correctly typed.
     */
    #[DataProvider('citedDirectionProvider')]
    public function test_the_baseline_answers_nothing_for_the_opposite_direction_under_the_same_label(
        string $fromType,
        string $toType,
        string $label,
        int $typeId,
        int $inverseTypeId,
    ): void {
        self::assertNull(BaselineAssociationTypes::resolve(
            AssociationDirection::of(from: $toType, to: $fromType),
            $label,
        ));

        // And the id it refused to hand back is genuinely there, under the opposite direction's own
        // name. Without this half the test above would also pass against a baseline that simply held
        // nothing for the inverse direction at all, which is a much weaker statement.
        $inverseRow = BaselineAssociationTypes::resolve(
            AssociationDirection::of(from: $toType, to: $fromType),
            self::labelFor($toType, $fromType, $inverseTypeId),
        );

        self::assertInstanceOf(AssociationTypeRow::class, $inverseRow);
        self::assertSame($inverseTypeId, $inverseRow->type->typeId);
        self::assertSame($typeId, $inverseRow->inverseTypeId);
    }

    /**
     * The cited label for a direction and id, read back out of the provider so the citation is not
     * written down a second time in this file.
     */
    private static function labelFor(string $fromType, string $toType, int $typeId): string
    {
        foreach (self::citedDirectionProvider() as [$from, $to, $label, $id]) {
            if ($from === $fromType && $to === $toType && $id === $typeId) {
                return $label;
            }
        }

        self::fail("No cited label for {$fromType} -> {$toType} with type id {$typeId}.");
    }

    /**
     * The label half of the same guarantee: FOUND-03 run 2 measured a paired label named `Deals` one
     * way and `People` the other, so a direction asked for under a name that is not its own must miss
     * even when the direction itself is seeded.
     */
    public function test_a_seeded_direction_asked_for_under_another_directions_name_misses(): void
    {
        self::assertNull(BaselineAssociationTypes::resolve(
            AssociationDirection::of(from: 'contacts', to: 'companies'),
            'Company to contact',
        ));
    }

    public function test_an_unseeded_direction_answers_nothing_rather_than_guessing(): void
    {
        self::assertNull(BaselineAssociationTypes::resolve(
            AssociationDirection::of(from: 'tickets', to: 'contacts'),
            'Reporter',
        ));
    }

    /**
     * **The no-invented-ids gate.** If a later hand seeds an id from memory, this fails.
     */
    public function test_the_seeded_id_set_is_exactly_the_cited_one(): void
    {
        $seeded = array_map(
            static fn (AssociationTypeRow $row): int => $row->type->typeId,
            BaselineAssociationTypes::rows(),
        );

        sort($seeded);

        $cited = array_map(
            static fn (array $case): int => $case[3],
            array_values(self::citedDirectionProvider()),
        );

        sort($cited);

        self::assertSame(
            $cited,
            $seeded,
            'The baseline holds an id that no cited source states, or is missing one that it does. An '
            .'absent id throws, which is safe; a wrong id writes silently, which is the failure this '
            .'package exists to prevent.',
        );
    }

    public function test_the_baseline_seeds_one_row_per_cited_direction_and_no_others(): void
    {
        self::assertCount(count(self::citedDirectionProvider()), BaselineAssociationTypes::rows());
    }

    /**
     * Every seeded row is HubSpot-defined. A `USER_DEFINED` id is per-portal — "your `partner_agency`
     * id is a different integer in another account" (design spec §6.2) — so one seeded here would be
     * correct only for the account it was copied from.
     */
    public function test_every_seeded_row_is_hubspot_defined(): void
    {
        foreach (BaselineAssociationTypes::rows() as $row) {
            self::assertSame(AssociationCategory::HubspotDefined, $row->type->category);
        }
    }

    /**
     * No row claims its own id as its inverse. `279`/`280`, `1`/`2`, `19`/`20` and `202`/`201` are
     * pairs of DIFFERENT ids — a row whose inverse equalled its own id would be the assumption this
     * whole package denies, written down as data.
     */
    public function test_no_row_records_its_own_id_as_its_inverse(): void
    {
        foreach (BaselineAssociationTypes::rows() as $row) {
            self::assertNotSame($row->type->typeId, $row->inverseTypeId);
        }
    }

    /**
     * Each direction's recorded inverse id is the id the OTHER direction's row actually holds, rather
     * than a number that merely looks like one. This is what makes `inverse_type_id` usable for
     * traversal and verification (design spec §6.2) instead of decorative.
     */
    #[DataProvider('citedDirectionProvider')]
    public function test_a_recorded_inverse_id_is_the_id_the_opposite_direction_really_holds(
        string $fromType,
        string $toType,
        string $label,
        int $typeId,
        int $inverseTypeId,
    ): void {
        $row = BaselineAssociationTypes::resolve(
            AssociationDirection::of(from: $fromType, to: $toType),
            $label,
        );

        self::assertInstanceOf(AssociationTypeRow::class, $row);
        self::assertSame($inverseTypeId, $row->inverseTypeId);

        $inverseRows = array_values(array_filter(
            BaselineAssociationTypes::rows(),
            static fn (AssociationTypeRow $candidate): bool => $candidate->direction->from->value === $toType
                && $candidate->direction->to->value === $fromType
                && $candidate->type->typeId === $row->inverseTypeId,
        ));

        self::assertCount(1, $inverseRows);
        self::assertSame($typeId, $inverseRows[0]->inverseTypeId);
    }

    /**
     * A paired label is asymmetric in its NAME, not only in its type id (03-CONTEXT.md rule 3). Two
     * rows sharing one name across both directions would be that assumption reintroduced.
     */
    public function test_no_two_seeded_rows_share_a_label(): void
    {
        $labels = array_map(
            static fn (AssociationTypeRow $row): ?string => $row->label,
            BaselineAssociationTypes::rows(),
        );

        self::assertSame($labels, array_unique($labels));
    }

    /**
     * The offline promise, stated as the thing a consumer actually cares about: no network, no
     * credentials, no database, no container. This test resolves through the static seed alone.
     */
    public function test_resolution_needs_no_credentials_no_network_and_no_container(): void
    {
        config(['hubspot.token' => null]);

        $row = BaselineAssociationTypes::resolve(
            AssociationDirection::of(from: 'contacts', to: 'companies'),
            'Contact to company',
        );

        self::assertInstanceOf(AssociationTypeRow::class, $row);
        self::assertSame(279, $row->type->typeId);
    }
}
