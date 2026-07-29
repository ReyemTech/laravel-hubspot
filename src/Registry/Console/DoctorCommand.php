<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Registry\Console;

use DateTimeInterface;
use Illuminate\Console\Command;
use ReyemTech\Hubspot\Gateway\Contracts\AssociationTypeResolver;
use ReyemTech\Hubspot\Registry\AssociationTypeRow;
use ReyemTech\Hubspot\Registry\Contracts\AssociationTypeStore;

/**
 * **What this installation of the package currently believes, without reading its source.**
 *
 * Which store each concern uses, whether and when the registry was reconciled with a portal, how many
 * rows and directions it holds, and which resolver is bound. It reads only local state — no network,
 * no credentials — so it answers in an environment where nothing else works yet, which is where a
 * developer most needs it.
 *
 * ## It is deliberately partial, and it says so
 *
 * REG-04 asks this command to report "every bound model, whether it soft-deletes and what its delete
 * policy resolves to". **Model binding does not exist in this release** — it is SYNC-01, a later phase
 * — so building that section now would mean building it against a guess.
 *
 * The section is therefore **named as absent rather than omitted**, which is the whole of
 * 03-CONTEXT.md §3's decision. Printing nothing would assert "you have no bound models"; the true
 * statement is "this package cannot bind models yet", and those are different facts. A developer
 * reading a silent report would reasonably conclude their own configuration was wrong.
 *
 * **This command does not close REG-04.** REG-04a — everything it does report, plus
 * `hubspot:associations:doctor` — completes here; REG-04b, the bound-model section, is owned by the
 * phase that builds model binding. Printing "not available yet" is not an implementation of a
 * requirement, and marking one delivered because a command with that name exists is how acceptance
 * criteria quietly go unbuilt.
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

        $this->reportTheSectionThatDoesNotExistYet();

        return self::SUCCESS;
    }

    /**
     * The absence, in words.
     *
     * Three lines rather than one because each says something different: that the section is not
     * built, that its emptiness is not a statement about the reader's models, and what it will report
     * once it is built. Dropping the middle line is the specific way this report would become
     * misleading.
     */
    private function reportTheSectionThatDoesNotExistYet(): void
    {
        $this->line('Bound models: NOT BUILT YET.');
        $this->line(
            'This section is empty because model binding does not exist in this release, NOT '
            .'because you have no bound models.'
        );
        $this->line(
            'When it ships it will report every bound model, whether it soft-deletes, and what its '
            .'delete policy resolves to.'
        );
    }
}
