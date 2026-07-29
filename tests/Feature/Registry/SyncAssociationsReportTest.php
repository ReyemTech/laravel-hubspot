<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Registry;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use ReyemTech\Hubspot\Facades\Hubspot;
use ReyemTech\Hubspot\Registry\AssociationTypeRow;
use ReyemTech\Hubspot\Registry\Console\SyncAssociationsCommand;
use ReyemTech\Hubspot\Registry\Contracts\AssociationTypeStore;
use ReyemTech\Hubspot\Registry\Stores\ArrayAssociationTypeStore;
use ReyemTech\Hubspot\Tests\Support\CommandOutput;
use ReyemTech\Hubspot\Tests\TestCase;

/**
 * **What `hubspot:associations:sync` SAYS it did.**
 *
 * Split out of `SyncAssociationsCommandTest`, which owns what the command WRITES: the two concerns
 * are genuinely different (one is about registry rows, the other about an operator's ability to
 * notice a surprise), and one file carrying both would not have held the 500-line gate — the fifth
 * such extraction this repository's code-shape gate has forced.
 *
 * A reconciliation command that printed "done" gives nobody a way to notice it wrote something
 * surprising, so every outcome is reported per direction — added, updated, unchanged, skipped — and
 * an update names **both ids and both categories**, which is what makes "a portal id replaced a
 * seeded HubSpot-defined one" visible rather than silent.
 *
 * Every assertion here is on a WHOLE LINE, never a substring: substring assertions leaked 31
 * `ConcatSwitchSides`/`ConcatRemoveRight` mutation survivors on an earlier plan, and two commands'
 * worth of output formatting is a large new surface for exactly that family. See
 * {@see CommandOutput}.
 */
mutates(SyncAssociationsCommand::class);

final class SyncAssociationsReportTest extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var ConfigRepository $config */
        $config = $app->make('config');

        $config->set('hubspot.store', 'array');
        $config->set('hubspot.associations.sync', [
            ['from' => 'deals', 'to' => 'contacts'],
        ]);
    }

    /**
     * @param  list<array{category: string, typeId: int, label: string|null}>  $results
     * @return array<string, mixed>
     */
    private static function body(array $results): array
    {
        return ['results' => $results];
    }

    /**
     * @param  list<array{category: string, typeId: int, label: string|null}>  $forward
     * @param  list<array{category: string, typeId: int, label: string|null}>  $reverse
     */
    private static function fakePortal(array $forward, array $reverse = []): void
    {
        Hubspot::fake([
            'definitions:deals>contacts' => Hubspot::response(self::body($forward), 200),
            'definitions:contacts>deals' => Hubspot::response(self::body($reverse), 200),
        ]);
    }

    /**
     * @return list<array{category: string, typeId: int, label: string|null}>
     */
    private static function twoLabels(): array
    {
        return [
            ['category' => 'USER_DEFINED', 'typeId' => 1, 'label' => 'Deals'],
            ['category' => 'USER_DEFINED', 'typeId' => 5, 'label' => 'Sponsor'],
        ];
    }

    /**
     * @return list<string>
     */
    private static function runSync(): array
    {
        Artisan::call('hubspot:associations:sync');

        return CommandOutput::linesOf(Artisan::output());
    }

    public function test_a_first_run_reports_every_row_it_added(): void
    {
        self::fakePortal(self::twoLabels(), [
            ['category' => 'USER_DEFINED', 'typeId' => 2, 'label' => 'People'],
        ]);

        $lines = self::runSync();

        self::assertContains('deals -> contacts: added "Deals" #1 (USER_DEFINED)', $lines);
        self::assertContains('deals -> contacts: added "Sponsor" #5 (USER_DEFINED)', $lines);
        self::assertContains('contacts -> deals: added "People" #2 (USER_DEFINED)', $lines);
        self::assertContains('Reconciled 2 directions: 3 added, 0 updated, 0 unchanged, 0 skipped.', $lines);
    }

    public function test_re_running_against_unchanged_portal_state_reports_every_row_as_unchanged(): void
    {
        self::fakePortal(self::twoLabels());
        Artisan::call('hubspot:associations:sync');

        self::fakePortal(self::twoLabels());
        $lines = self::runSync();

        self::assertContains('deals -> contacts: unchanged "Deals" #1 (USER_DEFINED)', $lines);
        self::assertContains('deals -> contacts: unchanged "Sponsor" #5 (USER_DEFINED)', $lines);
        self::assertContains('Reconciled 2 directions: 0 added, 0 updated, 2 unchanged, 0 skipped.', $lines);
    }

    /**
     * A reconciled row overrides a seeded baseline one on the same `(direction, label)` key, which is
     * correct — the portal's own id is the one HubSpot will honour. It must never happen silently:
     * the line names both ids and both categories, so an operator can see a HubSpot-defined default
     * being replaced by a portal label spelled the same way.
     */
    public function test_overwriting_a_seeded_baseline_id_says_so_in_the_output(): void
    {
        config(['hubspot.associations.sync' => [['from' => 'contacts', 'to' => 'companies']]]);

        Hubspot::fake([
            'definitions:contacts>companies' => Hubspot::response(self::body([
                ['category' => 'USER_DEFINED', 'typeId' => 42, 'label' => 'Contact to company'],
            ]), 200),
            'definitions:companies>contacts' => Hubspot::response(self::body([]), 200),
        ]);

        $lines = self::runSync();

        self::assertContains(
            'contacts -> companies: updated "Contact to company" #279 (HUBSPOT_DEFINED) -> #42 (USER_DEFINED)',
            $lines,
        );
        self::assertContains('Reconciled 2 directions: 0 added, 1 updated, 0 unchanged, 0 skipped.', $lines);
    }

    /**
     * **A change of CATEGORY alone is a change**, and the id staying put is exactly when that is
     * easiest to miss. A portal converting a HubSpot-defined label to a user-defined one keeps the
     * type id and changes what the write must send, so "unchanged" would be a false report — the
     * comparison is `typeId AND category`, never `typeId` with the category along for the ride.
     */
    public function test_a_category_change_at_the_same_type_id_is_reported_as_an_update(): void
    {
        self::fakePortal([['category' => 'USER_DEFINED', 'typeId' => 1, 'label' => 'Deals']]);
        Artisan::call('hubspot:associations:sync');

        self::fakePortal([['category' => 'INTEGRATOR_DEFINED', 'typeId' => 1, 'label' => 'Deals']]);
        $lines = self::runSync();

        self::assertContains(
            'deals -> contacts: updated "Deals" #1 (USER_DEFINED) -> #1 (INTEGRATOR_DEFINED)',
            $lines,
        );
        self::assertContains('Reconciled 2 directions: 0 added, 1 updated, 0 unchanged, 0 skipped.', $lines);
    }

    /**
     * HubSpot returns `label: null` for its own `HUBSPOT_DEFINED` types — measured twice in FOUND-03.
     * Those are skipped rather than written, and the skip is reported rather than silent.
     *
     * **Skipping is the correct answer, not a shortcut.** The registry's only read takes a
     * NON-NULLABLE label (`AssociationTypeResolver::resolve()`), and the unlabelled write path
     * consults the registry not at all (design spec §6.1 rule 3) — so a null-label row is unreachable
     * by every consumer the package has. Worse, a direction with two HubSpot-defined types would give
     * both the same `default:` storage key, so the second would silently overwrite the first. Rows
     * nobody can read that overwrite each other are worse than no rows.
     */
    public function test_definitions_with_no_label_are_skipped_and_the_skip_is_reported(): void
    {
        self::fakePortal([
            ['category' => 'HUBSPOT_DEFINED', 'typeId' => 3, 'label' => null],
            ['category' => 'HUBSPOT_DEFINED', 'typeId' => 4, 'label' => null],
            ['category' => 'USER_DEFINED', 'typeId' => 1, 'label' => 'Deals'],
        ]);

        $lines = self::runSync();

        self::assertCount(1, self::rowsWrittenBySync());
        self::assertContains(
            'deals -> contacts: skipped 2 definitions HubSpot returned with no label of their own',
            $lines,
        );
        self::assertContains('Reconciled 2 directions: 1 added, 0 updated, 0 unchanged, 2 skipped.', $lines);
    }

    /**
     * One skipped definition is "1 definition", not "1 definitions". Pinned because a plural-only
     * message is the kind of thing nobody notices until it is in front of a customer — and because a
     * count comparison that only ever sees two is a comparison no test has actually exercised.
     */
    public function test_a_single_unlabelled_definition_is_reported_in_the_singular(): void
    {
        self::fakePortal([['category' => 'HUBSPOT_DEFINED', 'typeId' => 3, 'label' => null]]);

        $lines = self::runSync();

        self::assertContains(
            'deals -> contacts: skipped 1 definition HubSpot returned with no label of their own',
            $lines,
        );
        self::assertContains('Reconciled 2 directions: 0 added, 0 updated, 0 unchanged, 1 skipped.', $lines);
    }

    /**
     * And nothing skipped reports NOTHING — no "skipped 0 definitions" line for either direction.
     * The absence is asserted explicitly, because a report that mentions every direction whether or
     * not anything happened to it is a report an operator stops reading.
     */
    public function test_a_direction_with_nothing_to_skip_produces_no_skip_line_at_all(): void
    {
        self::fakePortal([['category' => 'USER_DEFINED', 'typeId' => 1, 'label' => 'Deals']]);

        foreach (self::runSync() as $line) {
            self::assertStringNotContainsString('skipped 0', $line);
            self::assertDoesNotMatchRegularExpression('/: skipped /', $line);
        }
    }

    /**
     * Rows the sync actually wrote — the store's own rows, with the seeded baseline filtered out, so
     * a count assertion cannot pass on rows this command never touched.
     *
     * @return list<AssociationTypeRow>
     */
    private static function rowsWrittenBySync(): array
    {
        $store = app(AssociationTypeStore::class);

        self::assertInstanceOf(ArrayAssociationTypeStore::class, $store);

        /** @var list<array<string, mixed>> $rows */
        $rows = $store->toArray()['rows'];

        return array_map(AssociationTypeRow::fromArray(...), $rows);
    }
}
