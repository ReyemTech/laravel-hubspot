<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Registry;

use Illuminate\Console\OutputStyle;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Application;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use ReyemTech\Hubspot\Gateway\AssociationCategory;
use ReyemTech\Hubspot\Gateway\AssociationType;
use ReyemTech\Hubspot\Gateway\Contracts\AssociationTypeResolver;
use ReyemTech\Hubspot\Gateway\UnresolvedAssociationTypeResolver;
use ReyemTech\Hubspot\Registry\AssociationDirection;
use ReyemTech\Hubspot\Registry\AssociationTypeRegistry;
use ReyemTech\Hubspot\Registry\AssociationTypeRow;
use ReyemTech\Hubspot\Registry\Console\DoctorCommand;
use ReyemTech\Hubspot\Registry\Contracts\AssociationTypeStore;
use ReyemTech\Hubspot\Registry\Stores\ArrayAssociationTypeStore;
use ReyemTech\Hubspot\Tests\Support\CommandOutput;
use ReyemTech\Hubspot\Tests\Support\Sync\SoftDeletingLead;
use ReyemTech\Hubspot\Tests\Support\Sync\SyncedContact;
use ReyemTech\Hubspot\Tests\Support\Sync\SyncedIntake;
use ReyemTech\Hubspot\Tests\Support\Sync\SyncedLead;
use ReyemTech\Hubspot\Tests\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * **`php artisan hubspot:doctor` — what the package currently believes, without reading source.**
 *
 * REG-04 asks it to report "every bound model, whether it soft-deletes and what its delete policy
 * resolves to". Model binding shipped in Phase 4 (SYNC-01), so this command reports the configured
 * bindings alongside each model's actual deletion behaviour, as well as the pre-existing registry
 * state: which store each concern uses, whether and when the registry was reconciled, how many
 * directions it holds, and which resolver is bound.
 *
 * **The bound-model section is real, not omitted.** With no configured bindings it says so and names
 * the config key to add; that is now a true statement, unlike the old not-built wording.
 *
 * REG-04a — the registry report plus `hubspot:associations:doctor` — completed in Phase 3. REG-04b,
 * this real bound-model section, completes in 04-09; together they close REG-04. The split remains
 * recorded in `.planning/REQUIREMENTS.md` and `.planning/ROADMAP.md`.
 */
mutates(DoctorCommand::class);

final class DoctorCommandTest extends TestCase
{
    private const FROZEN_NOW = '2026-07-29T09:15:00.000Z';

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var ConfigRepository $config */
        $config = $app->make('config');

        $config->set('hubspot.store', 'array');
        $config->set('hubspot.models', [
            SyncedLead::class => ['object' => 'contacts', 'id_property' => 'email'],
            SyncedContact::class => ['object' => 'companies', 'id_property' => 'domain'],
            SyncedIntake::class => ['object' => 'deals', 'id_property' => 'external_id'],
        ]);
    }

    /**
     * @return list<string>
     */
    private static function runDoctor(): array
    {
        Artisan::call('hubspot:doctor');

        return CommandOutput::linesOf(Artisan::output());
    }

    /**
     * The store per concern. One concern exists today — the association-type registry — and it is
     * reported with BOTH the configured selector and the class actually bound, because those two
     * disagreeing is a real failure mode an operator cannot otherwise see.
     */
    public function test_it_reports_the_store_each_concern_uses(): void
    {
        self::assertContains(
            'Association type registry store: array ('.ArrayAssociationTypeStore::class.')',
            self::runDoctor(),
        );
    }

    public function test_it_reports_which_resolver_is_bound(): void
    {
        self::assertContains(
            'Association type resolver bound: '.AssociationTypeRegistry::class,
            self::runDoctor(),
        );
    }

    /**
     * A consumer may bind their own resolver — that rebinding is the package's whole extension seam —
     * so the doctor reports what is actually bound rather than what ships by default.
     */
    public function test_it_reports_a_rebound_resolver_rather_than_the_shipped_default(): void
    {
        app()->instance(AssociationTypeResolver::class, new UnresolvedAssociationTypeResolver);

        self::assertContains(
            'Association type resolver bound: '.UnresolvedAssociationTypeResolver::class,
            self::runDoctor(),
        );
    }

    /**
     * "Never reconciled" is a first-class answer, not a missing value, and the line names the command
     * that changes it (STANDARDS §9: every message names the fix).
     */
    public function test_it_reports_a_never_reconciled_registry_and_names_the_command_that_fixes_it(): void
    {
        self::assertContains(
            'Last reconciled with a portal: never. Run `php artisan hubspot:associations:sync`.',
            self::runDoctor(),
        );
    }

    public function test_it_reports_when_the_registry_was_last_reconciled(): void
    {
        Carbon::setTestNow(Carbon::parse(self::FROZEN_NOW));

        app(AssociationTypeStore::class)->markReconciled(Carbon::now()->toDateTimeImmutable());

        self::assertContains(
            'Last reconciled with a portal: 2026-07-29T09:15:00+00:00.',
            self::runDoctor(),
        );

        Carbon::setTestNow();
    }

    /**
     * Rows and DIRECTIONS are different counts, and both are reported because neither implies the
     * other. The eight seeded baseline rows span SIX directions, not eight: `contacts -> companies`
     * carries two labels (`Contact to company` and `Contact to primary company`) and so does
     * `companies -> contacts`. A single number could not say that, and "how many directions do I
     * hold" is what an operator debugging a wrong-direction write is actually asking.
     */
    public function test_it_reports_how_many_rows_and_how_many_directions_the_registry_holds(): void
    {
        self::assertContains('Holds 8 rows across 6 directions.', self::runDoctor());
    }

    public function test_a_reconciled_row_on_a_new_direction_moves_both_counts(): void
    {
        app(AssociationTypeStore::class)->upsert(new AssociationTypeRow(
            direction: AssociationDirection::of(from: 'deals', to: 'contacts'),
            type: new AssociationType(typeId: 1, category: AssociationCategory::UserDefined),
            label: 'Deals',
            inverseTypeId: null,
            isDefault: null,
        ));

        self::assertContains('Holds 9 rows across 7 directions.', self::runDoctor());
    }

    public function test_it_reports_each_bound_model_with_its_own_object_type_and_id_property(): void
    {
        $lines = self::runDoctor();

        $lead = 'Bound model: '.SyncedLead::class
            .'; object: contacts; id_property: email; SoftDeletes: no; delete policy: skip-quietly.';
        $contact = 'Bound model: '.SyncedContact::class
            .'; object: companies; id_property: domain; SoftDeletes: no; delete policy: skip-quietly.';
        $intake = 'Bound model: '.SyncedIntake::class
            .'; object: deals; id_property: external_id; SoftDeletes: no; delete policy: skip-quietly.';

        self::assertSame(1, count(array_keys($lines, $lead)));
        self::assertSame(1, count(array_keys($lines, $contact)));
        self::assertSame(1, count(array_keys($lines, $intake)));
    }

    public function test_it_resolves_different_delete_policies_for_soft_deleting_and_non_soft_deleting_models(): void
    {
        config()->set('hubspot.models', [
            SoftDeletingLead::class => ['object' => 'contacts', 'id_property' => 'email'],
            SyncedLead::class => ['object' => 'companies', 'id_property' => 'domain'],
        ]);

        $lines = self::runDoctor();

        self::assertContains(
            'Bound model: '.SoftDeletingLead::class
            .'; object: contacts; id_property: email; SoftDeletes: yes; delete policy: archive.',
            $lines,
        );
        self::assertContains(
            'Bound model: '.SyncedLead::class
            .'; object: companies; id_property: domain; SoftDeletes: no; delete policy: skip-quietly.',
            $lines,
        );
    }

    public function test_it_reports_when_no_bound_models_are_configured(): void
    {
        config()->set('hubspot.models', []);

        self::assertContains('Bound models: none configured. Add bindings to `hubspot.models`.', self::runDoctor());
    }

    /**
     * A diagnostic reports; it does not fail. Everything above is a fact about the installation, and
     * none of them is an error condition — an operator scripting `hubspot:doctor` in a health check
     * would otherwise see a red exit for a registry nobody had synced yet, which is a normal state.
     */
    public function test_reporting_is_not_a_failure(): void
    {
        self::assertSame(Command::SUCCESS, Artisan::call('hubspot:doctor'));
    }

    public function test_handle_resolves_the_bound_model_reporter_when_called_with_only_the_store(): void
    {
        $output = new BufferedOutput;
        $command = new DoctorCommand;
        $command->setLaravel(app());
        $command->setOutput(new OutputStyle(new ArrayInput([]), $output));

        self::assertSame(Command::SUCCESS, $command->handle(app(AssociationTypeStore::class)));
        self::assertContains(
            'Bound model: '.SyncedLead::class
            .'; object: contacts; id_property: email; SoftDeletes: no; delete policy: skip-quietly.',
            CommandOutput::linesOf($output->fetch()),
        );
    }
}
