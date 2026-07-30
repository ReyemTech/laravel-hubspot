<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Probes;

use ReyemTech\Hubspot\Gateway\AssociationDefinitionsGateway;
use ReyemTech\Hubspot\Gateway\AssociationGateway;
use ReyemTech\Hubspot\Gateway\Contracts\AssociationTypeResolver;
use ReyemTech\Hubspot\Gateway\ExceptionTranslator;
use ReyemTech\Hubspot\Gateway\HubspotClientFactory;
use ReyemTech\Hubspot\Gateway\ObjectGateway;
use ReyemTech\Hubspot\Gateway\SearchQuery;
use ReyemTech\Hubspot\Gateway\UnresolvedAssociationTypeResolver;
use Throwable;

/**
 * The shared harness every phase probe is handed.
 *
 * It exists so a new phase costs one file rather than an edit to a growing one: the runner globs
 * `phase-*.php`, and each returns a closure taking this object. Nothing here knows what any phase
 * checks.
 *
 * ## Two responsibilities that are not about convenience
 *
 * **Every created record is tracked for deletion the moment it exists.** {@see track()} is called
 * immediately after each create, never at the end of a block, because the failure that strands a
 * record in somebody's CRM is the one that happens between the two. The runner archives in reverse
 * creation order inside a `finally`, so a probe that throws still cleans up after itself.
 *
 * **A failed step does not stop the run.** {@see fail()} records and continues, so one broken
 * expectation still yields a full report rather than hiding every later result behind it — and the
 * process still exits non-zero, because a probe whose failures are only legible to a human reading
 * prose is a probe nobody can put in CI later.
 */
final class Probe
{
    private int $step = 0;

    /** @var list<array{string, string}> */
    private array $failures = [];

    /** @var list<array{string, string}> */
    private array $created = [];

    public readonly ObjectGateway $objects;

    public readonly AssociationGateway $associations;

    public readonly AssociationDefinitionsGateway $definitions;

    public readonly string $stamp;

    public function __construct(
        private readonly HubspotClientFactory $clientFactory,
        private readonly ExceptionTranslator $translator,
        string $stamp,
    ) {
        $this->stamp = $stamp;
        $this->objects = new ObjectGateway($clientFactory, $translator);
        $this->definitions = new AssociationDefinitionsGateway($clientFactory, $translator);

        // The DEFAULT association gateway deliberately carries the throwing resolver, so a probe
        // that wants labelled writes has to ask for one explicitly via `associationsResolvedBy()`.
        // Phase 2's guarantee is that a labelled write is impossible without a registry, and a
        // harness that quietly bound a working one would make that guarantee unprovable here.
        $this->associations = new AssociationGateway(
            $clientFactory,
            $translator,
            new UnresolvedAssociationTypeResolver,
        );
    }

    /**
     * An association gateway backed by a real resolver — Phase 3 onwards.
     */
    public function associationsResolvedBy(AssociationTypeResolver $resolver): AssociationGateway
    {
        return new AssociationGateway($this->clientFactory, $this->translator, $resolver);
    }

    public function section(string $title): void
    {
        echo "\n".$title."\n";
    }

    public function ok(string $what, string $detail = ''): void
    {
        printf("  %d. %-46s %s\n", ++$this->step, $what, $detail);
    }

    /**
     * A step that did not do what the package promises. Recorded, printed, and survived.
     */
    public function fail(string $what, string $why): void
    {
        $this->failures[] = [$what, $why];

        printf("  %d. %-46s %s\n", ++$this->step, '!! FAILED: '.$what, $why);
    }

    /**
     * Indented prose under the last step, for the sentence that explains why a number matters.
     */
    public function note(string $text): void
    {
        echo '     '.str_replace("\n", "\n     ", wordwrap($text, 88))."\n";
    }

    /**
     * Register a record for deletion. Call it immediately after the create that made it.
     */
    public function track(string $objectType, string $id): void
    {
        $this->created[] = [$objectType, $id];
        $this->touchedTypes[$objectType] = true;
    }

    /**
     * Declares an object type the run is ABOUT to create in, so the sweep still covers it when the
     * create itself fails before `track()` is reached. Call it before the first `create()` of each
     * type -- that ordering is the whole point.
     */
    public function willCreate(string $objectType): void
    {
        $this->touchedTypes[$objectType] = true;
    }

    /**
     * @return list<array{string, string}>
     */
    public function failures(): array
    {
        return $this->failures;
    }

    /**
     * The object types a phase created, so the sweep knows where to look.
     *
     * @var array<string, true>
     */
    private array $touchedTypes = [];

    /**
     * Archives everything tracked, newest first, then sweeps for records that were created but
     * never tracked, and reports anything it could not remove loudly enough that a human will go
     * and delete it by hand.
     */
    public function cleanUp(): void
    {
        // Sweep FIRST. The tracked records still exist at this point, which is what makes their
        // count a usable lower bound on what the search index should return -- archive them first
        // and the index correctly stops returning them, turning every run into a false "the index
        // is behind" alarm. Found that by testing the sweep against a deliberately untracked
        // record rather than by reasoning about it.
        echo "\nCleanup\n";

        $this->sweepUntracked();
        $this->archiveTracked();
    }

    /**
     * **The gap `track()` cannot close.**
     *
     * `track()` runs on the line after `create()` returns, so a create HubSpot COMMITTED but whose
     * response was lost -- a timeout, a dropped connection, a retried 5xx after the write landed --
     * throws before the id is ever known. A retry can commit a second record while only the last
     * response's id comes back. Those records exist, are untracked, and the `finally` cannot see
     * them (Codex P2, PR #34).
     *
     * Every record this probe creates carries the run's unique stamp in a searchable property, so
     * the sweep finds them by that and archives whatever the tracked pass missed.
     *
     * **Best effort, and it says so.** HubSpot's search index is eventually consistent, so a record
     * committed seconds ago may not be findable yet; the sweep is therefore a second chance rather
     * than a guarantee, and it reports what it removed instead of staying silent.
     */
    private function sweepUntracked(): void
    {
        $trackedIds = array_map(static fn (array $r): string => $r[1], $this->created);
        $swept = 0;

        foreach (array_keys($this->touchedTypes) as $type) {
            // How many of this type we KNOW exist. That is the lever: HubSpot's search index is
            // eventually consistent, so an empty result is ambiguous between "nothing untracked"
            // and "the index has not caught up" -- and the first run of this sweep was fooled by
            // exactly that, missing a deliberately untracked contact and leaving it in the portal.
            // Finding fewer than we tracked proves the index is behind, so it is worth waiting.
            $expected = count(array_filter($this->created, static fn (array $r): bool => $r[0] === $type));
            $property = match ($type) {
                'contacts' => 'email',
                'companies' => 'name',
                default => 'dealname',
            };

            $found = [];

            for ($attempt = 1; $attempt <= 6; $attempt++) {
                if ($attempt > 1) {
                    sleep(3);
                }

                try {
                    $page = $this->objects->search(
                        $type,
                        SearchQuery::make()->where($property, 'CONTAINS_TOKEN', $this->stamp),
                    );
                } catch (Throwable $e) {
                    echo "  !! could not sweep {$type} for stamp {$this->stamp}: ".$e->getMessage()."\n";

                    continue 2;
                }

                $found = $page->results;

                if (count($found) >= $expected) {
                    break;
                }
            }

            if (count($found) < $expected) {
                echo "  !! sweep of {$type} saw only ".count($found)." of {$expected} known record(s); "
                    ."the search index is behind and an untracked record could be invisible.\n";
                $this->failures[] = [
                    "sweep of {$type} was inconclusive",
                    'the search index returned fewer records than are known to exist, so this sweep '
                    .'cannot prove nothing was stranded.',
                ];
            }

            foreach ($found as $record) {
                if (in_array($record->id, $trackedIds, true)) {
                    continue;
                }

                try {
                    $this->objects->archive($type, $record->id);
                    echo "  swept UNTRACKED {$type}/{$record->id} (created but never tracked)\n";
                    $swept++;
                } catch (Throwable $e) {
                    echo "  !! could not archive swept {$type}/{$record->id}: ".$e->getMessage()."\n";
                    $this->failures[] = ["sweep of {$type}/{$record->id}", $e->getMessage()];
                }
            }
        }

        if ($swept > 0) {
            $this->failures[] = [
                'untracked records existed',
                $swept.' record(s) were created without being tracked -- a create most likely '
                .'committed and lost its response. They were archived, but investigate.',
            ];
        }
    }

    private function archiveTracked(): void
    {
        if ($this->created === []) {
            echo "  nothing tracked was created.\n";

            return;
        }

        foreach (array_reverse($this->created) as [$type, $id]) {
            try {
                $this->objects->archive($type, $id);
                echo "  archived {$type}/{$id}\n";
            } catch (Throwable $e) {
                echo "  !! could not archive {$type}/{$id}: ".$e->getMessage()."\n";
                echo "     DELETE IT BY HAND in the portal.\n";

                $this->failures[] = ["cleanup of {$type}/{$id}", $e->getMessage()];
            }
        }
    }
}
