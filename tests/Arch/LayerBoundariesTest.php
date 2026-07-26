<?php

declare(strict_types=1);

/**
 * The six layer boundaries (D-09) plus the two rules added with Signals and
 * Frontend (D-35). Every rule below is proven to fail a build under its own
 * violation fixture by scripts/ci/verify-arch-rules-fire.sh — see
 * tests/Arch/Fixtures/ and tests/Arch/rules.json.
 *
 * NB: over the real, empty six-directory src/ tree these all report green by
 * construction (STANDARDS §6, CONTEXT.md) — that is expected, not a bug. Do not
 * "fix" a green result here; the firing harness is what proves these are real.
 */

arch('R1: Gateway is the only layer that may reference HubSpot\* SDK classes')
    ->expect('HubSpot')
    ->toOnlyBeUsedIn('ReyemTech\Hubspot\Gateway');

arch('R2: Registry may depend only on Gateway')
    ->expect('ReyemTech\Hubspot\Registry')
    ->toOnlyUse('ReyemTech\Hubspot\Gateway');

arch('R3: Sync may depend only on Registry and Gateway')
    ->expect('ReyemTech\Hubspot\Sync')
    ->toOnlyUse(['ReyemTech\Hubspot\Registry', 'ReyemTech\Hubspot\Gateway']);

arch('R4: Webhooks may depend only on Registry and Gateway')
    ->expect('ReyemTech\Hubspot\Webhooks')
    ->toOnlyUse(['ReyemTech\Hubspot\Registry', 'ReyemTech\Hubspot\Gateway']);

arch('R5: Signals may depend only on Registry and Gateway')
    ->expect('ReyemTech\Hubspot\Signals')
    ->toOnlyUse(['ReyemTech\Hubspot\Registry', 'ReyemTech\Hubspot\Gateway']);

// R6's positive allowlist names the intended public facade FQCN
// (ReyemTech\Hubspot\Facades\Hubspot), which does not exist until Phase 2 ships it
// (see 01-04-SUMMARY.md). This is deliberate: D-35 requires Frontend depend on "the
// public facade ONLY", and a placeholder that matches anything would weaken the
// rule to nothing. R8 below is the negative form and is fully expressible today;
// it carries the real weight until the facade lands.
arch('R6: Frontend may depend only on the public facade')
    ->expect('ReyemTech\Hubspot\Frontend')
    ->toOnlyUse('ReyemTech\Hubspot\Facades\Hubspot');

arch('R7: Signals may not depend on Sync or Webhooks — it is a peer, not a consumer')
    ->expect('ReyemTech\Hubspot\Signals')
    ->not->toUse(['ReyemTech\Hubspot\Sync', 'ReyemTech\Hubspot\Webhooks']);

arch('R8: Frontend may not reference HubSpot\* or any internal layer')
    ->expect('ReyemTech\Hubspot\Frontend')
    ->not->toUse([
        'HubSpot',
        'ReyemTech\Hubspot\Gateway',
        'ReyemTech\Hubspot\Registry',
        'ReyemTech\Hubspot\Sync',
        'ReyemTech\Hubspot\Webhooks',
        'ReyemTech\Hubspot\Signals',
    ]);
