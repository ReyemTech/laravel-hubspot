<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Probes;

use ReyemTech\Hubspot\Gateway\AssociationDefinitionsGateway;
use ReyemTech\Hubspot\Gateway\AssociationGateway;
use ReyemTech\Hubspot\Gateway\Contracts\AssociationTypeResolver;
use ReyemTech\Hubspot\Gateway\ExceptionTranslator;
use ReyemTech\Hubspot\Gateway\HubspotClientFactory;
use ReyemTech\Hubspot\Gateway\ObjectGateway;
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
    }

    /**
     * @return list<array{string, string}>
     */
    public function failures(): array
    {
        return $this->failures;
    }

    /**
     * Archives everything tracked, newest first, and reports anything it could not remove loudly
     * enough that a human will go and delete it by hand.
     */
    public function cleanUp(): void
    {
        if ($this->created === []) {
            echo "\nCleanup: nothing was created.\n";

            return;
        }

        echo "\nCleanup\n";

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
