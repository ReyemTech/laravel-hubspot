<?php

declare(strict_types=1);

/**
 * **The real-portal smoke probe: the only thing in this repository that tests reality.**
 *
 * Everything else — 641 tests, 100% coverage, MSI 99%+ — runs against `Testing\HubspotFake`. The
 * fake's shapes are asserted against the SDK's own models, which makes it a good simulation and
 * still a simulation: real auth, real HTTP, real HubSpot validation and real response bodies are
 * outside its reach. This script is where those get exercised.
 *
 * It is opt-in, never part of the default suite, and never required to merge (`CLAUDE.md`).
 *
 * ## WRITES TO AND DELETES FROM THE PORTAL THE TOKEN POINTS AT
 *
 * Use a developer test account or a sandbox. Never a production portal. Every record it creates is
 * registered for deletion the instant it exists and archived in a `finally`, so a mid-run failure
 * still cleans up — but a process killed outright cannot, and then somebody deletes rows by hand.
 *
 *   HUBSPOT_TOKEN=pat-... php scripts/probes/smoke.php
 *
 * Or, keeping the token out of your shell history and out of this repository:
 *
 *   set -a; . ~/.hubspot-uat.env; set +a; php scripts/probes/smoke.php
 *
 * Scopes: `crm.objects.contacts.read/write`, `crm.objects.companies.read/write`,
 * `crm.objects.deals.read/write`, `crm.schemas.contacts.read`, `crm.schemas.companies.read`.
 * Both schema scopes are needed by phase 3's `listFor('contacts', 'companies')` -- see
 * `smoke/README.md`, which this list must stay in step with.
 *
 * ## Adding a phase
 *
 * Drop a `phase-NN-name.php` into `smoke/` returning `function (Probe $p): void`. The runner globs
 * and sorts them, so a new phase is a new file — not an edit to a file that grows until nobody
 * reads it. See `smoke/README.md`.
 *
 * ## No Laravel, on purpose
 *
 * The gateways and the registry are wired by hand. What passes here is the package, not a container
 * arrangement — so a green run cannot be an artefact of service-provider wiring. The cost is that
 * artisan commands (`hubspot:doctor`, `hubspot:associations:sync`) are out of scope; they need an
 * application, and verifying them wants a scratch app rather than this script.
 *
 * Exit status is 0 only if every step passed. Assert on that, never on this output's wording.
 */

use ReyemTech\Hubspot\Gateway\ExceptionTranslator;
use ReyemTech\Hubspot\Gateway\HubspotClientFactory;
use ReyemTech\Hubspot\Probes\Probe;

require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/smoke/Probe.php';

$token = getenv('HUBSPOT_TOKEN') ?: null;

if ($token === null || $token === '') {
    fwrite(STDERR, <<<'TXT'
    HUBSPOT_TOKEN is not set, so there is nothing to talk to.

    This probe writes to and deletes from the portal the token points at. Use a developer test
    account or a sandbox, never a production portal.

        HUBSPOT_TOKEN=pat-... php scripts/probes/smoke.php

    TXT);

    exit(1);
}

$probe = new Probe(
    HubspotClientFactory::fromConfig($token),
    new ExceptionTranslator,
    // Random, not `time()`. The stamp is what the cleanup sweep searches by, so two runs starting
    // within the same second against one portal would share it -- and each would discover the
    // other's records as "untracked" and archive them mid-test, breaking both runs and deleting
    // live records out from under whichever was still using them (Codex P2, PR #34).
    bin2hex(random_bytes(6)),
);

$phases = glob(__DIR__.'/smoke/phase-*.php');
sort($phases);

if ($phases === [] || $phases === false) {
    fwrite(STDERR, "No phase probes found in smoke/.\n");

    exit(1);
}

try {
    foreach ($phases as $phase) {
        $name = basename($phase, '.php');

        try {
            /** @var callable(Probe): void $run */
            $run = require $phase;
            $run($probe);
        } catch (Throwable $e) {
            // Caught PER PHASE, not around the whole loop. A phase that dies takes itself out and
            // nothing else: an unrelated later phase still runs and still reports. Letting the
            // throwable escape would print a stack trace and hide every check after it, which is
            // the least useful thing a diagnostic can do -- and it is how the first run of this
            // script reported a missing scope as an uncaught fatal.
            $probe->fail($name, $e->getMessage());
            $probe->note('Phase aborted here. Later phases still ran; earlier results above stand.');
        }
    }
} finally {
    // Runs even if the loop itself dies. Cleanup is the one thing that must not be conditional on
    // everything before it having worked -- that is precisely when records get stranded.
    $probe->cleanUp();
}

$failures = $probe->failures();

if ($failures !== []) {
    echo "\n".count($failures)." step(s) FAILED:\n";

    foreach ($failures as [$what, $why]) {
        echo "  - {$what}: {$why}\n";
    }

    exit(1);
}

echo "\nDone. Everything above was real HTTP against a real portal.\n";

exit(0);
