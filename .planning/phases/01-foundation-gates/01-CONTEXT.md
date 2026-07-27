# Phase 1: Foundation & Gates - Context

**Gathered:** 2026-07-26
**Status:** Ready for planning
**Mode:** Authored from approved design documents (discuss skipped — the specs are unusually complete and every grey area below was signed off in session on 2026-07-26)

<domain>
## Phase Boundary

Get **every binding gate green on an empty package**, before a single line of the package uses
them. `BRIEF.md`: *"Turning gates on later never happens."*

In scope: the composer skeleton, the full CI matrix, every required check, the six layer-boundary
architecture rules, the JavaScript toolchain and coverage gate, the documentation-site build,
`SECURITY.md`, `CODEOWNERS`, PR and issue templates, release-please configuration and
`composer validate --strict`.

Out of scope: any package functionality. Phase 1 ships a skeleton that passes every gate and does
nothing. `Gateway` starts in Phase 2.
</domain>

<decisions>
## Implementation Decisions

All of the following are LOCKED. Six of seven standards sign-off decisions were signed on
2026-07-26; do not reopen them, and do not "helpfully" relax a gate to make it pass.

### Test framework — Pest, deliberately (decision #2, settled)
`vendor/bin/pest`. **Do NOT convert to PHPUnit.** The sibling application's CLAUDE.md mandates
PHPUnit and instructs agents to convert Pest to PHPUnit — *that rule is app-scoped and explicitly
does not apply to this package*. Pest was chosen because `pest --mutate` and `pest-plugin-arch`
give two of this project's hard standards as first-class features. Pest runs on PHPUnit, so
PHPUnit-style test classes are valid if preferred.

### Support matrix (decision #1 — PHP floor RAISED to `^8.3` on 2026-07-26 during this phase's research; Laravel 11 DROPPED on 2026-07-27)
PHP `^8.3`; Laravel `12.x`, `13.x`. Illuminate constraint `^12.0|^13.0`.

**The floor was `^8.2` earlier the same day and was deliberately raised.** Pest 4,
`pest-plugin-arch` 4.x and `pest-plugin-laravel` 4.x all require PHP `^8.3`. Keeping an 8.2 leg
would force dual constraints (`^3.8|^4.0`), putting the unmaintained `pest-plugin-arch` 3.1.1
(April 2025, no further releases) on those jobs — so architecture tests and mutation scoring, two
of the three headline standards, would behave differently depending on which PHP version ran them.
**Use Pest 4 only. Do not add a dual constraint to recover PHP 8.2.**

**Laravel 11 was dropped outright on 2026-07-27**, reversing the "reach over tidiness" call made
the day before. Every published Laravel `11.x` release is blocked by live security advisories
(`PKSA-m5cs-t1y6-qpcs`, `PKSA-3r5d-mb8f-1qw9`, `PKSA-mdq4-51ck-6kdq`) and Laravel 11 reached end of
security support on 2026-03-12, so none of them will ever be patched. STANDARDS §12c fails the
build on any `composer audit` advisory with no escape hatch — keeping Laravel 11 for migration
reach put that rule in direct conflict with itself. The owner chose to drop Laravel 11 rather than
suppress the advisories or weaken the gate.

**The matrix is rectangular for the first time.** Every PHP version supports every remaining
Laravel major:

| | PHP 8.3 | PHP 8.4 | PHP 8.5 |
|---|---|---|---|
| Laravel 12 | yes | yes | yes |
| Laravel 13 | yes | yes | yes |

Six valid combinations × `prefer-stable` and `prefer-lowest` = **12 CI jobs**, no `exclude:` entries
needed.

**Consequence for code:** no framework API introduced in Laravel 13 may be used without a
compatibility shim.

### Production dependencies — exactly seven
`php`, `hubspot/api-client:^14.1`, `illuminate/contracts`, `illuminate/support`,
`illuminate/database`, `laravel/prompts`, `illuminate/view`. An eighth requires written
justification. The rule encoded is *no third-party runtime dependencies*; Illuminate packages are
first-party and in-policy.

Explicitly excluded: `spatie/laravel-package-tools` (hand-roll the service provider),
`spatie/laravel-webhook-client` (forces a migration on every consumer, contradicting
zero-migration install), `fakerphp/faker` (dev-only, every call site guarded by `class_exists()`).

### Static analysis (locked)
PHPStan + Larastan at max level with `checkModelProperties: true`. **A baseline file is
forbidden** — there is no legacy to grandfather on a greenfield package. Suppression is per-line
only and always carries a written reason. STANDARDS §3 says "level 9 (max)"; under PHPStan 2.x the
maximum is 10, so **resolve this against the PHPStan major actually pinned** and set the gate to
that version's true maximum rather than the literal 9.

### strict_types (decision #3, signed)
`declare(strict_types=1)` in every PHP file, enforced by an architecture test rather than review.
Rationale is specific: HubSpot object ids are strings that look like integers, and coercive typing
makes `"0"`, `0` and `""` silently equivalent — a wrong id writes to the wrong CRM record.

### Coverage and mutation floors (decision #4, signed)
PHP line coverage **95%**, JavaScript line coverage **95%** (Vitest), mutation score **80%** via
`pest --mutate` — **not Infection**. STANDARDS §6 and §12b were corrected on 2026-07-26; any
remaining reference to Infection is stale.

### Code shape limits (decision #6, signed)
Hard fail at 500 lines/file, 150 lines/function, cyclomatic complexity 10. Review targets 300 / 40
/ 5. Enforced by a CI script.

### Merge strategy (decision #0, signed)
**Merge commits, not squash**, and therefore **commitlint on every commit is mandatory** and is a
required check. Rationale: STANDARDS §6a's RED→GREEN history only survives into `main` with merge
commits; squashing would delete the very history another standard exists to preserve.
release-please owns versioning and `CHANGELOG.md`.

### The six layer boundaries — architecture tests
```
Gateway    → hubspot/api-client       ONLY layer that may name HubSpot\*
Registry   → Gateway
Sync       → Registry, Gateway
Webhooks   → Registry, Gateway
Signals    → Registry, Gateway
Frontend   → the public facade ONLY
```
Plus: `Signals` may not depend on `Sync` or `Webhooks`. `Frontend` may not reference `HubSpot\*`,
`Gateway`, `Registry`, `Sync`, `Webhooks` or `Signals`.

These tests must be written and passing in Phase 1 even though the namespaces are empty — an
architecture test over an empty namespace is what makes the boundary real before there is code to
violate it.

### Required checks (all must be green on the empty package)
tests (full 12-job matrix), Pint (`laravel` preset, committed `pint.json`, `pint --test` fails on
any diff), PHPStan, `pest --mutate`, architecture tests, `composer audit`, BC check
(`roave/backward-compatibility-check`), **commitlint**, **`composer validate --strict`**, the JS
coverage floor, and a code-shape script. Also: CI greps for `TODO`/`FIXME` and fails — they never
reach `main`.

### Claude's discretion
Directory layout under `src/`, the exact shape of the CI workflow files, `pint.json` contents,
the code-shape script's implementation language, and the Vitest configuration. Follow
`spatie/package-skeleton-laravel` conventions where they do not conflict with anything above.
</decisions>

<code_context>
## Existing Code Insights

**There is no code.** The repository contains only documentation and `.planning/`. This is a
genuine greenfield: `git init` has been run, the repo exists as `ReyemTech/laravel-hubspot`
(private), `main` has four documentation commits, and `.gitignore` covers `/vendor/`,
`/build/`, `composer.lock` and PHPUnit caches.

Composer package name: `reyemtech/laravel-hubspot`. Vendor namespace: `ReyemTech\Hubspot`.

No Sail, no Docker — this is a standalone public package, not part of the sibling Laravel
application. Run `vendor/bin/pest`, `vendor/bin/pint`, `vendor/bin/phpstan` directly, and test the
matrix via `orchestra/testbench`.
</code_context>

<specifics>
## Specific Ideas

### TDD is the working method, not a preference
Every change starts as a **failing (RED) test**, committed *before* the implementation (GREEN)
commit. With merge commits that sequence survives into `main` and is visible in `git log` forever.
Review checks the sequence because CI cannot. **A plan whose tasks write code before tests is
wrong for this repository.**

For Phase 1 specifically this means the architecture tests, the code-shape script and the JS
coverage gate each land as a failing test first, then the configuration that makes them pass.

### Conventional Commits on every commit
Not merely the PR title. commitlint enforces it. Branch names: `feat/`, `fix/`, `chore/`, `docs/`
plus a short slug. Feature branches start from a freshly pulled `main`; **branching from a branch
is forbidden**.

### SECURITY.md ships in this phase, not later
STANDARDS §10 requires it "published from day one", which overrides the core design spec's §13
placement in the final phase. ADR precedence resolved this on ingest.

### No test performs real network I/O
The suite must run green with no HubSpot credentials and no internet. This is testable in Phase 1
by ensuring the suite passes in a clean environment with no `.env`.
</specifics>

<deferred>
## Deferred Ideas

### Blocked — needs the owner, do NOT plan as executable work
- **The §6.4 association-inverse empirical probe (FOUND-03).** Requires a HubSpot developer test
  account token the executing agent does not hold. Everything else in Phase 1 proceeds. Plan it as
  a documented, ready-to-run procedure with the token as its only missing input — do not fabricate
  a result, and do not guess the answer by reasoning. Design spec §6.4 defines the procedure.
- **Branch protection on `main`.** Needs owner action in GitHub settings. Produce the exact
  required-checks list as a checklist instead.
- **Packagist registration and the GitHub↔Packagist webhook.** Owner-gated, and impossible while
  the repository is private. REL-01 in this phase is only `composer validate --strict` plus
  release-please configuration.
- **GitHub Pages deploy.** Needs a paid plan on private repositories. The docs site should build in
  CI; the deploy step can exist but will not run until the repository is public.

### Open decision, not blocking
Decision #5 (`final` by default) is still unsigned. Working default is `final`. It shapes Phase 2
onward rather than Phase 1's gates, so it does not block this phase.
</deferred>
