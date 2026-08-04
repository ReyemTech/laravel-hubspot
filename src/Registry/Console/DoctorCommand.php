<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Registry\Console;

use DateTimeInterface;
use Illuminate\Console\Command;
use ReyemTech\Hubspot\Gateway\Contracts\AssociationTypeResolver;
use ReyemTech\Hubspot\Registry\AssociationTypeRow;
use ReyemTech\Hubspot\Registry\Contracts\AssociationTypeStore;
use ReyemTech\Hubspot\Registry\Contracts\BoundModelReporter;

/**
 * **What this installation of the package currently believes, without reading its source.**
 *
 * Which store each concern uses, whether and when the registry was reconciled with a portal, how many
 * rows and directions it holds, and which resolver is bound. It reads only local state — no network,
 * no credentials — so it answers in an environment where nothing else works yet, which is where a
 * developer most needs it.
 *
 * ## REG-04 is complete
 *
 * REG-04 asks this command to report "every bound model, whether it soft-deletes and what its delete
 * policy resolves to". REG-04a — the registry report plus `hubspot:associations:doctor` — shipped in
 * Phase 3. REG-04b is the bound-model section below, now that Phase 4's binding and deletion policy
 * exist; together they close the requirement.
 *
 * `BoundModelReporter` is a Registry-owned contract implemented by Sync. The report contains only
 * resolved primitive facts, so this command neither depends on Sync nor recreates its delete policy.
 *
 * ## Reporting is not failing
 *
 * It always exits successfully. Every fact it reports is a legitimate state of an installation — a
 * registry nobody has synced yet is the state of every fresh install — so a non-zero exit would make
 * the command useless in the health check an operator would most want to script it into.
 */
final class DoctorCommand extends Command
{
    protected $signature = 'hubspot:doctor';

    protected $description = 'Report what this package currently believes: stores, registry state and bindings';

    public function handle(AssociationTypeStore $store): int
    {
        /** @var mixed $configuredStore */
        $configuredStore = $this->laravel->make('config')->get('hubspot.store');

        // Both the configured selector and the class actually bound. They disagreeing is a real
        // failure mode — a consumer rebinding the store contract directly, or a config cached from a
        // different environment — and an operator cannot see it from either value alone.
        $this->line(sprintf(
            'Association type registry store: %s (%s)',
            is_string($configuredStore) ? $configuredStore : get_debug_type($configuredStore),
            $store::class,
        ));

        // What is bound NOW, not what ships by default: rebinding this one key is the package's whole
        // extension seam, so the shipped default is the least interesting possible answer.
        $this->line(sprintf(
            'Association type resolver bound: %s',
            $this->laravel->make(AssociationTypeResolver::class)::class,
        ));

        $reconciledAt = $store->reconciledAt();

        $this->line($reconciledAt === null
            ? 'Last reconciled with a portal: never. Run `php artisan hubspot:associations:sync`.'
            : sprintf('Last reconciled with a portal: %s.', $reconciledAt->format(DateTimeInterface::ATOM)));

        $rows = $store->all();

        // Rows and directions are different counts and both are reported, because neither implies
        // the other: the eight seeded baseline rows span SIX directions, since `contacts -> companies`
        // carries two labels and so does `companies -> contacts`. A single number could not say that,
        // and "how many directions do I hold" is the question an operator debugging a wrong-direction
        // write is actually asking.
        $this->line(sprintf(
            'Holds %d rows across %d directions.',
            count($rows),
            count(array_unique(array_map(
                static fn (AssociationTypeRow $row): string => $row->direction->describe(),
                $rows,
            ))),
        ));

        /** @var BoundModelReporter $boundModels */
        $boundModels = $this->laravel->make(BoundModelReporter::class);
        $this->reportBoundModels($boundModels);

        return self::SUCCESS;
    }

    private function reportBoundModels(BoundModelReporter $boundModels): void
    {
        $reports = $boundModels->boundModelReports();

        if ($reports === []) {
            $this->line('Bound models: none configured. Add bindings to `hubspot.models`.');

            return;
        }

        foreach ($reports as $report) {
            $this->line(sprintf(
                'Bound model: %s; object: %s; id_property: %s; SoftDeletes: %s; delete policy: %s.',
                $report['modelClass'],
                $report['objectType'],
                $report['idProperty'],
                $report['usesSoftDeletes'] ? 'yes' : 'no',
                $report['deletePolicy'],
            ));
        }
    }
}
