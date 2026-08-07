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
 * R5 has not needed `Illuminate` yet — `Signals` has not needed it, and that is now the whole of
 * what this sentence says. R4 gained it 2026-08-06 — see the note directly above its `arch()` call
 * below. (R3 gained it 2026-07-30, below.)
 */

// @phpstan-ignore method.notFound, method.nonObject
arch('R2: Registry may depend only on Gateway, the package exceptions and the framework')->expect('ReyemTech\Hubspot\Registry')->toOnlyUse(['ReyemTech\Hubspot\Gateway', 'ReyemTech\Hubspot\Exceptions', 'Illuminate']);

/*
 * R3 additionally allows `Illuminate`, added 2026-07-30 (D-01, Phase 4).
 *
 * **R3 is a rule about this package's INTERNAL layering, never about keeping the framework out.**
 * `Sync` must not reach into `Webhooks`, `Signals` or `Gateway` internals, because those
 * dependencies are what turn six layers into one — exactly the argument R2's own 2026-07-29
 * amendment made, applied here for the same reason and mirrored in the same shape: one array-append,
 * one rule-description rename, no allow-list to maintain per layer.
 *
 * The concrete cost of NOT widening it: `Sync` is where the queue job (`Illuminate\Contracts\Bus\
 * Dispatcher`, `Illuminate\Queue\InteractsWithQueue`, `Illuminate\Queue\SerializesModels`), the
 * observer (`Illuminate\Database\Eloquent\Model::observe()`) and the trait's query scopes
 * (`Illuminate\Database\Eloquent\Builder`) all live — a package-owned port over the queue, the
 * observer API and the Eloquent contracts, invented solely to satisfy a lint rule, in a package
 * whose entire purpose is Laravel integration. `illuminate/queue`, `illuminate/bus`,
 * `illuminate/collections` and `illuminate/console` are all declared production `require`s as of
 * this same phase (D-02, D-07, D-16, D-19), alongside `illuminate/contracts` and
 * `illuminate/database` already shipped in Phase 1 — so naming any of them here installs nothing
 * and admits nothing that was not already shipped.
 *
 * This widens no LAYER boundary. R3's own committed violation fixture,
 * `tests/Arch/Fixtures/R3/SyncDependsOnWebhooks.php`, is reused unchanged and still makes the rule
 * go red under `scripts/ci/verify-arch-rules-fire.sh`, because the boundary it violates — `Sync`
 * reaching into `Webhooks` — is the boundary R3 is actually for, and is untouched.
 *
 * R3 additionally allows the bare functions `data_get` (2026-07-31, 04-03) and
 * `class_uses_recursive` (2026-07-31, 04-05). Both are unnamespaced Illuminate helpers, so
 * pest-plugin-arch records each call as a bare string the `'Illuminate'` entry does not cover.
 *
 * `class_uses_recursive()` is how `HubspotObserver` asks whether a model actually applies
 * `SoftDeletes` or `SyncsToHubspot`, rather than trusting that a method of the right NAME exists --
 * a name check silently changed behaviour for models defining such a method for unrelated reasons,
 * and threw from inside an event handler for a non-public one (Codex, PR #48). PHP's own
 * `class_uses()` does not look at parent classes, so a model inheriting either trait from an
 * abstract base would read as not using it; hand-rolling the recursion would be reimplementing a
 * framework helper this project's own research says not to hand-roll.
 *
 * Like `data_get`, this widens no LAYER boundary: it is pure class introspection over a value the
 * caller already holds, and installs no dependency on another layer.
 *
 * `data_get()` is `PropertyMapper`'s dot-path walker (04-RESEARCH.md's "Don't Hand-Roll" verdict:
 * a custom relation-path walker is exactly the kind of code this package's whole design argument
 * is against) and lives in `illuminate/collections`, already a declared production `require`
 * (D-16). But it is declared UNNAMESPACED in `Illuminate\Collections\helpers.php` — `function
 * data_get(...)`, no `namespace` statement above it — so `pest-plugin-arch`'s dependency scan
 * records the call as the bare string `'data_get'`, which the `'Illuminate'` entry above does not
 * match: `expectToOnlyUse()` allow-lists concrete FQCNs and functions it can resolve BY NAME, and
 * `'Illuminate'` only expands to classes under that namespace prefix, never to unnamespaced global
 * helpers the framework happens to ship. `Pest\Arch\Repositories\ObjectsRepository::allByNamespace()`
 * has a dedicated branch for exactly this shape — `function_exists($namespace) &&
 * (new ReflectionFunction($namespace))->getName() === $namespace` — which is what makes a bare
 * function name a valid, first-class entry in a `toOnlyUse()` array rather than a workaround.
 *
 * This still widens no LAYER boundary: `data_get()` is a pure array/object accessor, not a
 * dependency on any of `Webhooks`, `Signals` or `Gateway` internals, and R3's own violation
 * fixture is untouched and still fires.
 */

// @phpstan-ignore method.notFound, method.nonObject
arch('R3: Sync may depend only on Registry, Gateway, the package exceptions and the framework')->expect('ReyemTech\Hubspot\Sync')->toOnlyUse(['ReyemTech\Hubspot\Registry', 'ReyemTech\Hubspot\Gateway', 'ReyemTech\Hubspot\Exceptions', 'Illuminate', 'data_get', 'class_uses_recursive']);

/*
 * R4 additionally allows `Illuminate`, added 2026-08-06 (05-01, HOOK-01).
 *
 * **R4 is a rule about this package's INTERNAL layering, never about keeping the framework out.**
 * `Webhooks` must not reach into `Sync`, `Signals` or `Gateway` internals, because those
 * dependencies are what turn six layers into one — exactly the argument R2's 2026-07-29 and R3's
 * 2026-07-30 amendments already made, applied here for the same reason and mirrored in the same
 * shape: one array-append, one rule-description rename, no allow-list to maintain per layer.
 *
 * The concrete cost of NOT widening it: `Illuminate\Http\Request` in the controller,
 * `Illuminate\Support\Facades\Route` in the route registrar, `Illuminate\Bus\Queueable` and
 * `Illuminate\Contracts\Queue\ShouldQueue` in the queued job, and, from 05-02 on,
 * `Illuminate\Database\Connection` and `Illuminate\Console\Command` — a package-owned port over
 * routing, the queue and the console, invented solely to satisfy a lint rule, in a package whose
 * entire purpose is Laravel integration. `illuminate/support`, `illuminate/bus`,
 * `illuminate/queue`, `illuminate/contracts`, `illuminate/console` and `illuminate/database` are
 * already declared production `require`s, and `illuminate/http` is declared by this same task —
 * so naming any of them here installs nothing and admits nothing that was not already shipped.
 *
 * This widens no LAYER boundary. R4's own committed violation fixture,
 * `tests/Arch/Fixtures/R4/WebhooksDependsOnSync.php`, is reused unchanged and still makes the
 * rule go red under `scripts/ci/verify-arch-rules-fire.sh`, because the boundary it violates —
 * `Webhooks` reaching into `Sync` — is the boundary R4 is actually for, and is untouched.
 *
 * The widening's other half — that R4 still rejects `HubSpot\*` from `Webhooks`, so this is
 * narrower than "allow anything outside the package" — is proven separately by
 * `SeamFixtures/Webhooks/WebhooksUsingTheSdkDirectly.php` and its guard test in
 * `tests/Arch/ResolverSeamTest.php`.
 */

// @phpstan-ignore method.notFound, method.nonObject
arch('R4: Webhooks may depend only on Registry, Gateway, the package exceptions and the framework')->expect('ReyemTech\Hubspot\Webhooks')->toOnlyUse(['ReyemTech\Hubspot\Registry', 'ReyemTech\Hubspot\Gateway', 'ReyemTech\Hubspot\Exceptions', 'Illuminate']);

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
