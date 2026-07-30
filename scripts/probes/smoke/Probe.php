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
    /**
     * The searchable property carrying the run stamp, per object type. The cleanup sweep needs one
     * for every type a phase creates in; a type missing here cannot be swept and says so.
     */
    private const STAMP_PROPERTIES = [
        'contacts' => 'email',
        'companies' => 'name',
        'deals' => 'dealname',
        'line_items' => 'name',
    ];

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
        // Keyed by TYPE AND id. HubSpot record ids are unique only within an object type, so an
        // untracked contact can legitimately carry the same numeric id as a tracked deal -- and an
        // id-only comparison would read that contact as already tracked, skip it, and leave it
        // stranded while `archiveTracked()` removed only the deal (Codex P2, PR #34).
        $tracked = [];

        foreach ($this->created as [$trackedType, $trackedId]) {
            $tracked[$trackedType.':'.$trackedId] = true;
        }

        $swept = 0;

        foreach (array_keys($this->touchedTypes) as $type) {
            // How many of this type we KNOW exist. That is the lever: HubSpot's search index is
            // eventually consistent, so an empty result is ambiguous between "nothing untracked"
            // and "the index has not caught up" -- and the first run of this sweep was fooled by
            // exactly that, missing a deliberately untracked contact and leaving it in the portal.
            // Finding fewer than we tracked proves the index is behind, so it is worth waiting.
            $expected = count(array_filter($this->created, static fn (array $r): bool => $r[0] === $type));
            // Explicit per type, with NO default. `willCreate()` accepts any object type and the
            // README advertises future line_items and custom-object phases, so falling back to
            // `dealname` would issue a deal-specific search against those types -- silently
            // returning nothing, and reporting a clean sweep it never performed (Codex P2,
            // PR #34). An unmapped type is a failure, not a guess.
            $property = self::STAMP_PROPERTIES[$type] ?? null;

            if ($property === null) {
                echo "  !! no stamp property is mapped for {$type}, so it cannot be swept\n";
                $this->failures[] = [
                    "sweep of {$type} is not possible",
                    'add it to Probe::STAMP_PROPERTIES with the searchable property that carries '
                    .'the run stamp, or the sweep cannot recover a lost create for this type.',
                ];

                continue;
            }

            /** @var array<string, object> $found */
            /** @var array<string, object> $found */
            $found = [];
            $failure = null;
            $lastAttemptCount = 0;

            // A MINIMUM number of passes regardless of `$expected`, then more while the index is
            // demonstrably behind. Stopping as soon as `count($found) >= $expected` looked right and
            // was worst exactly where it matters: a create that commits and throws before `track()`
            // leaves `$expected` at zero for that type, so the first empty result satisfied the
            // condition immediately and the record the sweep exists to catch was never waited for
            // (Codex P2, PR #34). Reaching the tracked count is not proof that nothing else exists.
            for ($attempt = 1; $attempt <= 6; $attempt++) {
                if ($attempt > 1) {
                    sleep(3);
                }

                try {
                    // Every page, not the first. Retry middleware can produce more stamped records
                    // than fit one page, and a sweep that reads only page one archives some of them
                    // and reports nothing about the rest (Codex P2, PR #34).
                    $thisAttempt = 0;
                    $after = null;

                    do {
                        $query = SearchQuery::make()->where($property, 'CONTAINS_TOKEN', $this->stamp);
                        $page = $this->objects->search($type, $after === null ? $query : $query->after($after));
                        // Merged as each page arrives, not after the cursor loop. A later page
                        // request throwing would otherwise jump past the merge and discard records
                        // from pages already fetched -- ids the sweep had genuinely observed
                        // (Codex P2, PR #34).
                        //
                        // UNION by id, never replace: the index is eventually consistent in both
                        // directions, so a later attempt returning fewer records must not drop an
                        // untracked id already seen. Anything ever observed stays observed.
                        foreach ($page->results as $record) {
                            $found[$record->id] = $record;
                            $thisAttempt++;
                        }

                        $after = $page->after;
                    } while ($after !== null && $after !== '');
                } catch (Throwable $e) {
                    // `$found` deliberately keeps whatever an earlier attempt discovered. Resetting
                    // it meant a final attempt throwing would abandon a record already identified,
                    // leaving it in the portal while the run reported only the search failure
                    // (Codex P2, PR #34). A record whose id is known must be archived even if the
                    // sweep afterwards became inconclusive.
                    $failure = $e->getMessage();

                    continue;
                }

                $failure = null;

                // THIS attempt's count, not the union's. The union retains ids from earlier
                // attempts, so a current attempt returning fewer than `$expected` -- the very
                // signal that the index is behind -- would still satisfy a union-based check and
                // stop polling early (Codex P2, PR #34).
                $lastAttemptCount = $thisAttempt;

                if ($attempt >= 3 && $thisAttempt >= $expected) {
                    break;
                }
            }

            if ($failure !== null) {
                // Printed AND recorded. A sweep that could not run is inconclusive, and an
                // inconclusive cleanup reported as success is the failure this whole pass is about:
                // retry middleware may have left a duplicate that was never inspected.
                echo "  !! sweep of {$type} ended on a failure: {$failure}\n";
                $this->failures[] = ["sweep of {$type} was inconclusive", $failure];

                // NOT `continue`. Anything an earlier attempt already found still gets archived
                // below -- an inconclusive sweep is a reason to report, not a reason to abandon
                // records whose ids are already known.
            }

            if ($lastAttemptCount < $expected) {
                // `$lastAttemptCount`, not the union: the union can exceed `$expected` after a
                // fluctuating search, so printing it produces "saw only 2 of 1" and hides how far
                // behind the final search actually was (Codex P3, PR #34).
                echo "  !! sweep of {$type} saw only {$lastAttemptCount} of {$expected} known record(s) on its last attempt; "
                    ."the search index is behind and an untracked record could be invisible.\n";
                $this->failures[] = [
                    "sweep of {$type} was inconclusive",
                    'the search index returned fewer records than are known to exist, so this sweep '
                    .'cannot prove nothing was stranded.',
                ];
            }

            foreach ($found as $record) {
                if (isset($tracked[$type.':'.$record->id])) {
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
