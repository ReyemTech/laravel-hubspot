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
 *
 * Every `@phpstan-ignore` below suppresses the same, single known gap rather than
 * a baseline (D-04 forbids a baseline): Pest's arch()->expect()->toXxx() chain is a
 * "higher-order test" — the calls made on arch()'s return value are re-bound at
 * runtime to execute inside the test body (Pest\PendingCalls\TestCall has no
 * `expect()`/`toOnlyUse()`/`not`/`toUseStrictTypes()` methods of its own; pest bundles
 * no PHPStan stub for this dynamic dispatch). This is the documented, standard way to
 * write a Pest arch test; there is no non-suppressed alternative syntax for it.
 */

// @phpstan-ignore method.notFound, method.nonObject
arch('R1: Gateway is the only layer that may reference HubSpot\* SDK classes')->expect('HubSpot')->toOnlyBeUsedIn('ReyemTech\Hubspot\Gateway');

// @phpstan-ignore method.notFound, method.nonObject
arch('R2: Registry may depend only on Gateway')->expect('ReyemTech\Hubspot\Registry')->toOnlyUse('ReyemTech\Hubspot\Gateway');

// @phpstan-ignore method.notFound, method.nonObject
arch('R3: Sync may depend only on Registry and Gateway')->expect('ReyemTech\Hubspot\Sync')->toOnlyUse(['ReyemTech\Hubspot\Registry', 'ReyemTech\Hubspot\Gateway']);

// @phpstan-ignore method.notFound, method.nonObject
arch('R4: Webhooks may depend only on Registry and Gateway')->expect('ReyemTech\Hubspot\Webhooks')->toOnlyUse(['ReyemTech\Hubspot\Registry', 'ReyemTech\Hubspot\Gateway']);

// @phpstan-ignore method.notFound, method.nonObject
arch('R5: Signals may depend only on Registry and Gateway')->expect('ReyemTech\Hubspot\Signals')->toOnlyUse(['ReyemTech\Hubspot\Registry', 'ReyemTech\Hubspot\Gateway']);

// R6's positive allowlist names the intended public facade FQCN
// (ReyemTech\Hubspot\Facades\Hubspot), which does not exist until Phase 2 ships it
// (see 01-04-SUMMARY.md). This is deliberate: D-35 requires Frontend depend on "the
// public facade ONLY", and a placeholder that matches anything would weaken the
// rule to nothing. R8 below is the negative form and is fully expressible today;
// it carries the real weight until the facade lands.
// @phpstan-ignore method.notFound, method.nonObject
arch('R6: Frontend may depend only on the public facade')->expect('ReyemTech\Hubspot\Frontend')->toOnlyUse('ReyemTech\Hubspot\Facades\Hubspot');

// @phpstan-ignore method.notFound, property.nonObject, method.nonObject
arch('R7: Signals may not depend on Sync or Webhooks — it is a peer, not a consumer')->expect('ReyemTech\Hubspot\Signals')->not->toUse(['ReyemTech\Hubspot\Sync', 'ReyemTech\Hubspot\Webhooks']);

arch('R8: Frontend may not reference HubSpot\* or any internal layer')
    // @phpstan-ignore method.notFound
    ->expect('ReyemTech\Hubspot\Frontend')
    // @phpstan-ignore property.nonObject
    ->not
    // @phpstan-ignore method.nonObject
    ->toUse([
        'HubSpot',
        'ReyemTech\Hubspot\Gateway',
        'ReyemTech\Hubspot\Registry',
        'ReyemTech\Hubspot\Sync',
        'ReyemTech\Hubspot\Webhooks',
        'ReyemTech\Hubspot\Signals',
    ]);
