<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Registry;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Application;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use ReyemTech\Hubspot\Facades\Hubspot;
use ReyemTech\Hubspot\Registry\AssociationDirection;
use ReyemTech\Hubspot\Registry\AssociationTypeRow;
use ReyemTech\Hubspot\Registry\Console\SyncAssociationsCommand;
use ReyemTech\Hubspot\Registry\Contracts\AssociationTypeStore;
use ReyemTech\Hubspot\Registry\Stores\ArrayAssociationTypeStore;
use ReyemTech\Hubspot\Tests\Support\CommandOutput;
use ReyemTech\Hubspot\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * **`php artisan hubspot:associations:sync` — reconciling a portal's own labels into the registry.**
 *
 * The seeded baseline covers HubSpot-defined types only. Every portal's `USER_DEFINED` label has a
 * portal-specific id (design spec §6.2: "your `partner_agency` id is a different integer in another
 * account"), so without this command the registry is permanently incomplete for the labels teams
 * actually use.
 *
 * ## The two rules this file exists to hold
 *
 * 1. **One row per DIRECTION, from that direction's own read.** `getPage($from, $to)` answers for one
 *    direction, and a paired label carries a different NAME in each — FOUND-03 run 2 measured `Deals`
 *    forward and `People` inverse. A sync that read once and wrote both directions would be the
 *    wrong-direction defect this package exists to prevent, wearing a different hat.
 * 2. **`inverse_type_id` is left null, because it is not derivable from two directional reads**
 *    (Codex P1 on PR #22). Each returned item carries only `category`, `label` and `type_id` — and
 *    since the two directions' labels have different names, the forward list `[1: "Deals", 5:
 *    "Sponsor"]` and the reverse list `[2: "People", 6: "Sponsored by"]` contain **no join key at
 *    all**. `test_reordering_a_multi_label_response_changes_nothing_about_what_is_written` is the
 *    test that matters here: an implementation pairing by array position passes every single-label
 *    test and is wrong.
 *
 * **No richer endpoint exposes the pairing, and that was checked rather than assumed.** In the pinned
 * 14.1.0, `Schema\Model\PublicAssociationDefinitionCreateRequest` carries an `inverseLabel` — so
 * HubSpot knows the pairing when a definition is CREATED — but no read model returns it:
 * `AssociationSpecWithLabel` (what `DefinitionsApi::getPage()` returns) and
 * `PublicAssociationDefinitionUserConfiguration` (what `DefinitionConfigurationsApi` returns) both
 * carry `category`, `label` and `typeId` and nothing else. Null is therefore the honest value, and
 * `hubspot:associations:doctor` is what fills it in by observation.
 */
mutates(SyncAssociationsCommand::class);

final class SyncAssociationsCommandTest extends TestCase
{
    private const FROZEN_NOW = '2026-07-29T09:15:00.000Z';

    /**
     * The array store, so every assertion below reads rows this process wrote and nothing a previous
     * one left behind. `HUBSPOT_STORE` is read while the application is being created, so it has to
     * be set here rather than in a test body.
     *
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
     * FOUND-03 run 2's own measurement, with a second user-defined pair added so that positional
     * pairing has something to get wrong. The forward direction names `Deals` and `Sponsor`; the
     * reverse names `People` and `Sponsored by`. Nothing in either response says which pairs with
     * which.
     *
     * @param  list<array{category: string, typeId: int, label: string|null}>  $results
     * @return array<string, mixed>
     */
    private static function body(array $results): array
    {
        return ['results' => $results];
    }

    /**
     * @return array<string, mixed>
     */
    private static function forwardBody(): array
    {
        return self::body([
            ['category' => 'USER_DEFINED', 'typeId' => 1, 'label' => 'Deals'],
            ['category' => 'USER_DEFINED', 'typeId' => 5, 'label' => 'Sponsor'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function reverseBody(): array
    {
        return self::body([
            ['category' => 'USER_DEFINED', 'typeId' => 2, 'label' => 'People'],
            ['category' => 'USER_DEFINED', 'typeId' => 6, 'label' => 'Sponsored by'],
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $forward
     * @param  array<string, mixed>|null  $reverse
     */
    private static function fakePortal(?array $forward = null, ?array $reverse = null): void
    {
        Hubspot::fake([
            'definitions:deals>contacts' => Hubspot::response($forward ?? self::forwardBody(), 200),
            'definitions:contacts>deals' => Hubspot::response($reverse ?? self::reverseBody(), 200),
        ]);
    }

    /**
     * @return list<string>
     */
    private static function runSync(): array
    {
        Artisan::call('hubspot:associations:sync');

        return CommandOutput::linesOf(Artisan::output());
    }

    private static function store(): AssociationTypeStore
    {
        return app(AssociationTypeStore::class);
    }

    /**
     * Reconciling a pair is TWO reads, one per direction. Asserted on the recorded URIs rather than
     * on the call site, because a single read whose answer was written to both directions would look
     * identical in source and be wrong on the wire.
     */
    public function test_reconciling_a_pair_reads_each_direction_separately(): void
    {
        $fake = Hubspot::fake();

        Artisan::call('hubspot:associations:sync');

        $paths = array_map(
            static fn (array $entry): string => $entry['request']->getUri()->getPath(),
            $fake->recordedRequests(),
        );

        self::assertSame(
            [
                '/crm/associations/v4/deals/contacts/labels',
                '/crm/associations/v4/contacts/deals/labels',
            ],
            $paths,
        );
    }

    /**
     * Each direction's row carries that direction's own label and that direction's own id. The
     * forward label must NOT resolve on the reverse direction — that is the whole asymmetry.
     */
    public function test_each_direction_is_written_as_its_own_row_under_its_own_label(): void
    {
        self::fakePortal();

        self::assertSame(Command::SUCCESS, Artisan::call('hubspot:associations:sync'));

        $forward = AssociationDirection::of(from: 'deals', to: 'contacts');
        $reverse = AssociationDirection::of(from: 'contacts', to: 'deals');

        self::assertSame(1, self::store()->resolve($forward, 'Deals')?->type->typeId);
        self::assertSame(5, self::store()->resolve($forward, 'Sponsor')?->type->typeId);
        self::assertSame(2, self::store()->resolve($reverse, 'People')?->type->typeId);
        self::assertSame(6, self::store()->resolve($reverse, 'Sponsored by')?->type->typeId);

        self::assertNull(
            self::store()->resolve($reverse, 'Deals'),
            'The forward direction\'s label must not answer on the reverse direction.',
        );
        self::assertNull(
            self::store()->resolve($forward, 'People'),
            'The reverse direction\'s label must not answer on the forward direction.',
        );
    }

    /**
     * The rule the plan was corrected for. Every row this command writes leaves `inverse_type_id`
     * null: the two directional responses share no join key, so any value here would be a guess, and
     * a guessed inverse id is a real, valid, wrong association HubSpot accepts without complaint.
     */
    public function test_the_sync_leaves_inverse_type_id_null_on_every_row_it_writes(): void
    {
        self::fakePortal();

        Artisan::call('hubspot:associations:sync');

        $written = self::rowsWrittenBySync();

        self::assertCount(4, $written, 'Four labels across two directions.');

        foreach ($written as $row) {
            self::assertNull(
                $row->inverseTypeId,
                sprintf(
                    'The sync recorded an inverse id (%s) for %s under "%s". Two directional reads share no join key.',
                    var_export($row->inverseTypeId, true),
                    $row->direction->describe(),
                    (string) $row->label,
                ),
            );
        }
    }

    /**
     * **The test that separates a correct implementation from a plausible one.**
     *
     * The reverse direction's two labels are returned in the opposite order the second time. A
     * positional implementation would pair `Deals`↔`People` and `Sponsor`↔`Sponsored by` in one run
     * and `Deals`↔`Sponsored by` and `Sponsor`↔`People` in the other — and a single-label test would
     * never notice. Byte-identical rows across the two runs is the property that says the pairing was
     * not guessed.
     */
    public function test_reordering_a_multi_label_response_changes_nothing_about_what_is_written(): void
    {
        self::fakePortal();
        Artisan::call('hubspot:associations:sync');
        $first = self::rowDataWrittenBySync();

        // A fresh store, so the second run reconciles from nothing exactly as the first did.
        app()->instance(AssociationTypeStore::class, new ArrayAssociationTypeStore);

        self::fakePortal(reverse: self::body([
            ['category' => 'USER_DEFINED', 'typeId' => 6, 'label' => 'Sponsored by'],
            ['category' => 'USER_DEFINED', 'typeId' => 2, 'label' => 'People'],
        ]));
        Artisan::call('hubspot:associations:sync');
        $second = self::rowDataWrittenBySync();

        self::assertNotSame([], $first);
        self::assertSame($first, $second);
    }

    /**
     * A label a portal defines in one direction only is one row. Writing a second row for the other
     * direction would mean inventing that direction's id and its name, neither of which was read.
     */
    public function test_a_label_present_in_one_direction_only_produces_one_row_not_two(): void
    {
        self::fakePortal(
            forward: self::body([['category' => 'USER_DEFINED', 'typeId' => 1, 'label' => 'Deals']]),
            reverse: self::body([]),
        );

        Artisan::call('hubspot:associations:sync');

        $written = self::rowsWrittenBySync();

        self::assertCount(1, $written);
        self::assertSame('deals -> contacts', $written[0]->direction->describe());
        self::assertSame('Deals', $written[0]->label);
    }

    /**
     * Re-running against unchanged portal state writes the same rows and adds no duplicates, and says
     * so rather than reporting a second round of additions.
     */
    public function test_re_running_against_unchanged_portal_state_is_idempotent(): void
    {
        self::fakePortal();
        Artisan::call('hubspot:associations:sync');
        $afterFirstRun = self::rowDataWrittenBySync();

        self::fakePortal();
        $lines = self::runSync();

        self::assertSame($afterFirstRun, self::rowDataWrittenBySync());
        self::assertContains('deals -> contacts: unchanged "Deals" #1 (USER_DEFINED)', $lines);
        self::assertContains('Reconciled 2 directions: 0 added, 0 updated, 4 unchanged, 0 skipped.', $lines);
    }

    public function test_a_first_run_reports_every_row_it_added(): void
    {
        self::fakePortal();

        $lines = self::runSync();

        self::assertContains('deals -> contacts: added "Deals" #1 (USER_DEFINED)', $lines);
        self::assertContains('deals -> contacts: added "Sponsor" #5 (USER_DEFINED)', $lines);
        self::assertContains('contacts -> deals: added "People" #2 (USER_DEFINED)', $lines);
        self::assertContains('contacts -> deals: added "Sponsored by" #6 (USER_DEFINED)', $lines);
        self::assertContains('Reconciled 2 directions: 4 added, 0 updated, 0 unchanged, 0 skipped.', $lines);
    }

    /**
     * A reconciled row overrides a seeded baseline one on the same `(direction, label)` key, which is
     * correct — the portal's own id is the one HubSpot will honour. It must never happen silently:
     * the line names both ids and both categories, so an operator can see a HubSpot-defined default
     * being replaced by a portal label that happens to be spelled the same way.
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
    public function test_definitions_hubspot_returned_with_no_label_are_skipped_and_the_skip_is_reported(): void
    {
        self::fakePortal(
            forward: self::body([
                ['category' => 'HUBSPOT_DEFINED', 'typeId' => 3, 'label' => null],
                ['category' => 'HUBSPOT_DEFINED', 'typeId' => 4, 'label' => null],
                ['category' => 'USER_DEFINED', 'typeId' => 1, 'label' => 'Deals'],
            ]),
            reverse: self::body([]),
        );

        $lines = self::runSync();

        self::assertCount(1, self::rowsWrittenBySync());
        self::assertContains(
            'deals -> contacts: skipped 2 definitions HubSpot returned with no label of their own',
            $lines,
        );
        self::assertContains('Reconciled 2 directions: 1 added, 0 updated, 0 unchanged, 2 skipped.', $lines);
    }

    /**
     * An empty enabled list is a configuration mistake, never a successful no-op. A command that
     * printed "done" would tell an operator their portal had nothing to reconcile.
     */
    public function test_an_empty_enabled_pair_list_fails_with_a_directed_message(): void
    {
        config(['hubspot.associations.sync' => []]);

        Hubspot::fake();

        $exitCode = Artisan::call('hubspot:associations:sync');
        $lines = CommandOutput::linesOf(Artisan::output());

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertContains(
            'No association pairs are enabled for reconciliation. Add at least one to the '
            .'`associations.sync` key of config/hubspot.php, for example '
            .'["from" => "deals", "to" => "contacts"], then run this command again.',
            $lines,
        );
        Hubspot::assertRequestCount(0);
    }

    /**
     * With no token the package's own `ConfigurationException` names the fix. It must reach the
     * operator as a reported failure rather than as a raw SDK or Guzzle stack trace (STANDARDS §9).
     */
    public function test_with_no_credentials_the_command_fails_with_the_packages_configuration_error(): void
    {
        config(['hubspot.token' => null]);

        self::assertSame(Command::FAILURE, Artisan::call('hubspot:associations:sync'));

        self::assertContains(
            'HUBSPOT_TOKEN is not set. Create a HubSpot Service Key (HubSpot account settings '
            .'-> Integrations -> Service Keys) and set HUBSPOT_TOKEN in your .env file before '
            .'making any Gateway call. A legacy Private App token also works.',
            CommandOutput::linesOf(Artisan::output()),
        );
    }

    /**
     * Reconciliation state is what `hubspot:doctor` reports as "last synced". The clock belongs to
     * the caller, so the command reads it once and hands it to the store — a store reading `now()`
     * itself could not be asserted deterministically (STANDARDS §6).
     */
    public function test_a_successful_sync_records_when_it_reconciled(): void
    {
        Carbon::setTestNow(Carbon::parse(self::FROZEN_NOW));

        self::assertNull(self::store()->reconciledAt(), 'Nothing has been reconciled yet.');

        self::fakePortal();
        Artisan::call('hubspot:associations:sync');

        self::assertSame(
            Carbon::parse(self::FROZEN_NOW)->getTimestamp(),
            self::store()->reconciledAt()?->getTimestamp(),
        );

        Carbon::setTestNow();
    }

    /**
     * A failed run must not claim a reconciliation happened: `hubspot:doctor` would then report a
     * registry as freshly synced when nothing was read.
     */
    public function test_a_failed_run_records_no_reconciliation(): void
    {
        config(['hubspot.token' => null]);

        Artisan::call('hubspot:associations:sync');

        self::assertNull(self::store()->reconciledAt());
    }

    /**
     * Rows the sync actually wrote — the store's own rows, with the seeded baseline filtered out, so
     * a count assertion cannot pass on rows this command never touched.
     *
     * @return list<AssociationTypeRow>
     */
    private static function rowsWrittenBySync(): array
    {
        $store = self::store();

        self::assertInstanceOf(ArrayAssociationTypeStore::class, $store);

        /** @var list<array<string, mixed>> $rows */
        $rows = $store->toArray()['rows'];

        return array_map(AssociationTypeRow::fromArray(...), $rows);
    }

    /**
     * The same rows as plain, order-independent data, for comparing two runs against each other.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function rowDataWrittenBySync(): array
    {
        $data = [];

        foreach (self::rowsWrittenBySync() as $row) {
            $data[$row->key()] = $row->toArray();
        }

        ksort($data);

        return $data;
    }
}
