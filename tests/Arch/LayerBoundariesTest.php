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

/*
 * R2 through R5 additionally allow `ReyemTech\Hubspot\Exceptions`, added 2026-07-27.
 *
 * **The package exception hierarchy is a cross-cutting namespace, not a layer.** STANDARDS §9 requires
 * one typed hierarchy rooted at a package-owned interface, which consumers catch, and forbids a raw SDK
 * exception ever reaching userland — so every layer has to be able to throw it. Without `Exceptions` in
 * these allow-lists those two rules are mutually impossible, and the first place that bites is the
 * resolver seam plan 02-05 shipped: a `Registry` implementation of
 * `Gateway\Contracts\AssociationTypeResolver` MUST throw `Exceptions\AssociationTypeException` on a
 * miss (the contract's return type is non-nullable, and 02-CONTEXT.md rule 3 forbids answering a miss
 * with anything else), and R2 rejected it for exactly that. `Sync`, `Webhooks` and `Signals` hit the
 * same wall the first time they throw.
 *
 * This widens no LAYER boundary: nothing here lets `Registry` see `Sync`, or `Frontend` see the SDK,
 * and R6 and R8 are untouched — `Frontend` still talks to the public facade only, which is where its
 * exceptions arrive from anyway. `Exceptions` depends on no layer in return (it names two `Gateway`
 * classes via `::class`, which the compiler resolves to plain strings and never autoloads), so this
 * adds no cycle.
 *
 * `tests/Arch/ResolverSeamTest.php` proves each of these four rules PASSES against a committed fixture
 * of the code the boundary exists to permit, and `scripts/ci/verify-arch-rules-fire.sh` still proves
 * each one FAILS under its own violation fixture — those fixtures violate by depending on
 * `Sync`/`Webhooks`/`Frontend`, never on `Exceptions`, so every one of them fires unchanged.
 */

/*
 * R2 additionally allows `Illuminate`, added 2026-07-29.
 *
 * **The framework namespace was never what R2 existed to keep out.** R2 is a rule about this
 * package's INTERNAL layering: `Registry` must not reach into `Sync`, `Webhooks` or `Gateway`
 * internals, because those dependencies are the ones that turn six layers into one. `Illuminate`
 * tripping it was incidental — `pest-plugin-arch`'s dependency scan keeps user-defined vendor classes
 * and filters only PHP internals, so an `Illuminate\*` import read as a layer violation.
 *
 * Plan 03-01 worked around that for a two-method cache by inverting the dependency
 * (`Registry\Contracts\RegistryCache` as the port, `ReyemTech\Hubspot\IlluminateRegistryCache` at the
 * composition root as the adapter), which was cheap at that size. It is not cheap for 03-02's
 * database store: a package-owned port over query-builder and schema access is a large, leaky
 * abstraction invented solely to satisfy a lint rule, in a package whose entire purpose is Laravel
 * integration. `illuminate/database` is already a production `require` in `composer.json`, so naming
 * `Illuminate\Database\ConnectionInterface` or `Illuminate\Database\QueryException` installs nothing
 * and admits nothing that was not already shipped.
 *
 * This widens no LAYER boundary. R2's own committed violation fixture,
 * `tests/Arch/Fixtures/R2/RegistryDependsOnSync.php`, still makes the rule go red under
 * `scripts/ci/verify-arch-rules-fire.sh`, because the boundary it violates — `Registry` reaching into
 * `Sync` — is the boundary R2 is actually for, and is untouched. 03-01's `RegistryCache` port stays
 * exactly where it is: it is merged, tested and harmless, and rewriting it to match the widened rule
 * would be churn.
 *
 * R3 through R5 deliberately do NOT gain `Illuminate`. `Sync`, `Webhooks` and `Signals` have not
 * needed it yet, and a rule widened before something needs it is a rule nobody can argue against
 * later.
 */

// @phpstan-ignore method.notFound, method.nonObject
arch('R2: Registry may depend only on Gateway, the package exceptions and the framework')->expect('ReyemTech\Hubspot\Registry')->toOnlyUse(['ReyemTech\Hubspot\Gateway', 'ReyemTech\Hubspot\Exceptions', 'Illuminate']);

// @phpstan-ignore method.notFound, method.nonObject
arch('R3: Sync may depend only on Registry, Gateway and the package exceptions')->expect('ReyemTech\Hubspot\Sync')->toOnlyUse(['ReyemTech\Hubspot\Registry', 'ReyemTech\Hubspot\Gateway', 'ReyemTech\Hubspot\Exceptions']);

// @phpstan-ignore method.notFound, method.nonObject
arch('R4: Webhooks may depend only on Registry, Gateway and the package exceptions')->expect('ReyemTech\Hubspot\Webhooks')->toOnlyUse(['ReyemTech\Hubspot\Registry', 'ReyemTech\Hubspot\Gateway', 'ReyemTech\Hubspot\Exceptions']);

// @phpstan-ignore method.notFound, method.nonObject
arch('R5: Signals may depend only on Registry, Gateway and the package exceptions')->expect('ReyemTech\Hubspot\Signals')->toOnlyUse(['ReyemTech\Hubspot\Registry', 'ReyemTech\Hubspot\Gateway', 'ReyemTech\Hubspot\Exceptions']);

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
