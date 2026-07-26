# Phase 1: Foundation & Gates - Research

**Researched:** 2026-07-26
**Domain:** CI/CD tooling for a PHP/Laravel package (GitHub Actions, Pest, PHPStan/Larastan, PHP_CodeSniffer, commitlint, Vitest, Astro/Starlight, release-please) — no HubSpot domain code in this phase
**Confidence:** MEDIUM — every version-sensitive claim below was checked against the Packagist/npm registry APIs directly or against current official docs during this session (2026-07-26). No Context7 MCP tool was available in this session, so all doc lookups fall back to WebSearch/WebFetch/direct registry API calls; see Sources.

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

**Test framework — Pest, deliberately (decision #2, settled).** `vendor/bin/pest`. Do NOT convert
to PHPUnit. Pest was chosen because `pest --mutate` and `pest-plugin-arch` give two of this
project's hard standards as first-class features. Pest runs on PHPUnit, so PHPUnit-style test
classes are valid if preferred.

**Support matrix (decision #1, signed 2026-07-26).** PHP `^8.2`; Laravel `11.x`, `12.x`, `13.x`.
Illuminate constraint `^11.0|^12.0|^13.0`. The matrix is NOT rectangular — Laravel 11 accepts PHP
8.2-8.4, Laravel 12 accepts 8.2-8.5, Laravel 13 requires 8.3+. Ten valid combinations x
prefer-stable/prefer-lowest = 20 CI jobs. Laravel 11 reached EOSS 2026-03-12 and PHP 8.2 reaches it
2026-12-31; both are supported anyway, deliberately, for migration reach. No framework API
introduced in Laravel 12/13 may be used without a compatibility shim.

**Production dependencies — exactly seven.** `php`, `hubspot/api-client:^14.1`,
`illuminate/contracts`, `illuminate/support`, `illuminate/database`, `laravel/prompts`,
`illuminate/view`. An eighth requires written justification. Explicitly excluded:
`spatie/laravel-package-tools`, `spatie/laravel-webhook-client`, `fakerphp/faker` (dev-only).

**Static analysis (locked).** PHPStan + Larastan at max level with `checkModelProperties: true`. A
baseline file is forbidden. Suppression is per-line only, always with a written reason. STANDARDS
§3 says "level 9 (max)"; under PHPStan 2.x the maximum is 10 — resolve against the actually-pinned
major rather than the literal 9.

**strict_types (decision #3, signed).** `declare(strict_types=1)` in every PHP file, enforced by an
architecture test rather than review.

**Coverage and mutation floors (decision #4, signed).** PHP line coverage 95%, JavaScript line
coverage 95% (Vitest), mutation score 80% via `pest --mutate` — not Infection.

**Code shape limits (decision #6, signed).** Hard fail at 500 lines/file, 150 lines/function,
cyclomatic complexity 10. Review targets 300/40/5. Enforced by a CI script (or equivalent tooling).

**Merge strategy (decision #0, signed).** Merge commits, not squash — therefore commitlint on every
commit is mandatory and is a required check. release-please owns versioning and `CHANGELOG.md`.

**The six layer boundaries — architecture tests.**
```
Gateway    -> hubspot/api-client       ONLY layer that may name HubSpot\*
Registry   -> Gateway
Sync       -> Registry, Gateway
Webhooks   -> Registry, Gateway
Signals    -> Registry, Gateway
Frontend   -> the public facade ONLY
```
Plus: `Signals` may not depend on `Sync` or `Webhooks`. `Frontend` may not reference `HubSpot\*`,
`Gateway`, `Registry`, `Sync`, `Webhooks` or `Signals`. These tests must be written and passing in
Phase 1 even though the namespaces are empty.

**Required checks (all must be green on the empty package).** tests (full 20-job matrix), Pint
(`laravel` preset, committed `pint.json`, `pint --test` fails on any diff), PHPStan, `pest --mutate`,
architecture tests, `composer audit`, BC check (`roave/backward-compatibility-check`), commitlint,
`composer validate --strict`, the JS coverage floor, and a code-shape script. CI also greps for
`TODO`/`FIXME` and fails.

### Claude's Discretion

Directory layout under `src/`, the exact shape of the CI workflow files, `pint.json` contents, the
code-shape script's implementation language, and the Vitest configuration. Follow
`spatie/package-skeleton-laravel` conventions where they do not conflict with anything above.

### Deferred Ideas (OUT OF SCOPE)

**Blocked — needs the owner, do NOT plan as executable work:**
- The §6.4 association-inverse empirical probe (FOUND-03). Requires a HubSpot developer test
  account token the executing agent does not hold. Plan it as a documented, ready-to-run procedure
  with the token as its only missing input.
- Branch protection on `main`. Needs owner action in GitHub settings. Produce the exact
  required-checks list as a checklist instead.
- Packagist registration and the GitHub<->Packagist webhook. Owner-gated, and impossible while the
  repository is private. REL-01 in this phase is only `composer validate --strict` plus
  release-please configuration.
- GitHub Pages deploy. Needs a paid plan on private repositories. The docs site should build in CI;
  the deploy step can exist but will not run until the repository is public.

**Open decision, not blocking:** Decision #5 (`final` by default) is still unsigned. Working
default is `final`. It shapes Phase 2 onward rather than Phase 1's gates, so it does not block this
phase.
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| FOUND-01 | Repository scaffolding with every standards gate green on an empty package — full 20-job matrix, Pint, PHPStan level 9/max with no baseline, `pest --mutate`, architecture tests, Vitest, docs-site build, `composer audit`, BC check, commitlint, `composer validate --strict`; exactly seven production requires | Matrix `include`/`exclude` pattern (Architecture Patterns #1); dual-constraint Pest/arch/laravel-plugin composer pins (Standard Stack, Architecture Patterns #2); PHPStan `level: max` resolution (Common Pitfalls #4); Larastan single-version-spans-matrix finding; `roave/backward-compatibility-check` version-pin guidance (Common Pitfalls #3, Open Question 2); PHPCS+Slevomat code-shape gate (Don't Hand-Roll, Code Examples) |
| FOUND-02 | `SECURITY.md` published from day one, Dependabot enabled | Environment Availability confirms tooling present; no version-sensitive research needed beyond `composer audit` (already in Standard Stack) |
| FOUND-03 | Association-inverse empirical probe — BLOCKED | Out of scope for this research; confirmed blocked per CONTEXT.md/ROADMAP.md, not re-researched |
| FOUND-04 | Node/pnpm toolchain, JS coverage gate (95% floor), docs-site build, green on an empty package | Vitest coverage-threshold syntax (Code Examples); Astro/Starlight version verification (Standard Stack); `stint` reference deploy pattern (Architecture Patterns diagram, Code Examples); Open Question 3 on which workspace should own Vitest |
| FOUND-05 | Six-layer architecture rules enforced from the first commit, including `declare(strict_types=1)` | `pest-plugin-arch` syntax (Architecture Patterns #3); empty-namespace vacuous-pass finding and violation-fixture mitigation (Common Pitfalls #1, Assumption A2) |
| REL-01 | `composer validate --strict` required check; release-please configured for versioning + `CHANGELOG.md` from Conventional Commits | `release-type: simple` vs `php` (Standard Stack, Alternatives Considered); commitlint-without-root-package.json pattern (Don't Hand-Roll, Code Examples) |
</phase_requirements>

## Summary

This phase installs zero HubSpot code and instead wires eleven CI gates so they are green on an
empty skeleton. The single biggest risk is a **version fault line that runs directly through the
tooling, not just the target framework**: the project's locked PHP floor is `^8.2`, but **Pest 4.x,
pest-plugin-arch 4.x and pest-plugin-laravel 4.x all now require PHP `^8.3`** (verified against the
Packagist API on 2026-07-26). Pest 3.x is the only line that installs on PHP 8.2, and it is no
longer receiving new releases (last tag: `pest-plugin-arch v3.1.1`, April 2025). The fix is not
"pick a version" but a **dual composer constraint** (`^3.x|^4.0`) so Composer resolves 3.x on the
PHP 8.2 matrix legs and 4.x everywhere else — this mirrors exactly how the support matrix itself
splits (Laravel 13, which needs PHP 8.3+, is never paired with PHP 8.2, so the two version bumps
land in the same place). `spatie/package-skeleton-laravel`'s **current** `main` branch does not
face this problem because it has already dropped PHP 8.2/8.3 support entirely (floor is now
`^8.4`) — so "follow spatie conventions" cannot be applied to the composer.json dependency pins
without modification; it still applies to the matrix *shape* and CI *structure*.

The second major finding is that `pest-plugin-arch` rules over an **empty namespace pass
silently** rather than erroring — confirmed by multiple independent search results describing this
as documented, intentional behavior. This directly answers the phase's decision-critical question:
writing the six layer-boundary tests in Phase 1, before any class exists in `Gateway`/`Registry`/
etc., will produce a green suite that proves nothing. ROADMAP success criterion 2 ("a deliberate
violation fixture fails the build for each") is not decorative — it is the only way Phase 1
actually proves the rules are live, and it must ship as an explicit task, not be assumed to fall
out of "write the arch test."

Third, PHPStan 2.x's true maximum is **level 10**, not 9 — confirmed directly from
`phpstan.org/user-guide/rule-levels`. STANDARDS §3's "level 9 (max)" is stale; the gate should be
configured as `level: max` (which resolves to 10 under PHPStan 2.x) rather than the literal digit
9, per the phase's own instruction to resolve this against the actually-pinned major.

Fourth, the 500/150/complexity-10 code-shape gate does **not** need a hand-rolled script: PHP
CodeSniffer core (actively released, v4.0.1, Nov 2025) plus `slevomat/coding-standard` (actively
released, v8.31.0, July 2026) together cover all three metrics precisely, as `require-dev`-only
tooling with zero runtime footprint.

**Primary recommendation:** Pin composer dev dependencies with dual-major constraints
(`pestphp/pest: ^3.8|^4.0`, `pest-plugin-arch: ^3.1|^4.0`, `pest-plugin-laravel: ^3.2|^4.0`),
pin `roave/backward-compatibility-check` to a release still supporting PHP 8.2 (the BC-check job
itself only needs to run once, not matrix-wide, so this is a one-line composer constraint rather
than a structural problem), set PHPStan to `level: max`, use PHPCS + Slevomat for code-shape, and
build the six architecture-rule tests together with throwaway violation fixtures so the rules are
provably live before Phase 2 exists.

## Architectural Responsibility Map

Phase 1 has no domain capabilities (no HubSpot code). The map below is a tooling/CI capability map,
not a runtime-tier map, since this phase's "system" is the CI pipeline and package skeleton.

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Composer skeleton / autoloading | Package root (`composer.json`) | — | Sole source of truth for production requires and PSR-4 mapping |
| PHP test matrix (20 jobs) | CI / GitHub Actions | Testbench (in-process Laravel) | Matrix drives which Laravel+PHP combination `orchestra/testbench` boots |
| Static analysis (PHPStan/Larastan) | CI / GitHub Actions | — | Runs against `src/` only; no runtime component |
| Mutation testing (`pest --mutate`) | CI / GitHub Actions | Pest runtime | Requires the full test suite to already be green; downstream of tests job |
| Architecture rules (layer boundaries, strict_types) | CI / GitHub Actions | Pest (pest-plugin-arch) | Encodes the six-layer boundary that later phases' domain code must respect |
| Code-shape gate (file/function/complexity) | CI / GitHub Actions | PHP_CodeSniffer + Slevomat | Pure static analysis over `src/`, no runtime dependency |
| Commit-message linting | CI / GitHub Actions | — | Docker-based action, no repo-root Node dependency needed |
| JS coverage gate (Vitest) | CI / GitHub Actions | `site/` or `resources/js/` Node workspace | Isolated Node surface; does not touch PHP autoloading |
| Docs site build + deploy | CI / GitHub Actions | Astro/Starlight (`site/`) | Publishes to a separate `docs-pages` branch, decoupled from `main` |
| Release versioning | CI / GitHub Actions (release-please) | Packagist (owner-gated, out of phase) | release-please owns `CHANGELOG.md` and git tags only in this phase |

## Standard Stack

### Core

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `pestphp/pest` | `^3.8\|^4.0` [VERIFIED: packagist registry] | Test runner, mutation testing (`--mutate`) | Locked by CONTEXT.md decision #2. Dual constraint is required: v4.7.5 requires PHP `^8.3.0`; v3.8.7 is the only line installable on the project's PHP `^8.2` floor. |
| `pestphp/pest-plugin-laravel` | `^3.2\|^4.0` [VERIFIED: packagist registry] | Laravel-specific Pest helpers (`artisan()`, HTTP testing, etc.) | v3.2.0 (Apr 2025) requires PHP `^8.2.0`, Laravel `^11.39.1\|^12.9.2` — does not itself support Laravel 13, but Laravel 13 is never combined with PHP 8.2 in this project's matrix (Laravel 13 needs PHP 8.3+), so the dual constraint resolves cleanly: 3.x for PHP-8.2 legs (Laravel 11/12 only), 4.x (`^11.45.2\|^12.52.0\|^13.0`, PHP `^8.3.0`) for PHP-8.3+ legs. |
| `pestphp/pest-plugin-arch` | `^3.1\|^4.0` [VERIFIED: packagist registry] | Architecture/layer-boundary tests, `strict_types` enforcement | v3.1.1 (Apr 2025, last 3.x tag) requires PHP `^8.2`; v4.0.2 requires PHP `^8.3`. Same dual-constraint logic as above. |
| `phpstan/phpstan` | `^2.2` (current 2.2.5) [VERIFIED: packagist registry] | Static analysis engine | PHP `^7.4\|^8.0` — no conflict with the project's floor. |
| `larastan/larastan` | `^3.10` (current 3.10.0) [VERIFIED: packagist registry] | Laravel-aware PHPStan rules, `checkModelProperties` | Requires PHP `^8.2`, `phpstan/phpstan ^2.2.0`, `illuminate ^11.44.2\|^12.4.1\|^13` — a **single** Larastan version spans the entire project matrix; no dual constraint needed here. |
| `orchestra/testbench` | `^9.0\|^10.0\|^11.0` [VERIFIED: packagist registry] | In-process Laravel app to test the package against | Testbench majors track Laravel majors at a fixed +(-2) offset: **testbench 9.x -> Laravel 11** (PHP `^8.2`), **testbench 10.x -> Laravel 12** (PHP `^8.2`), **testbench 11.x -> Laravel 13** (PHP `^8.3`, confirmed — v11.0.0/v11.1.0 both declare `php: ^8.3`). This independently confirms Laravel 13 never needs to run on PHP 8.2. |
| `roave/backward-compatibility-check` | pin to a release still supporting PHP `^8.2` (e.g. `8.14.0`, Jun 2025) rather than `^8.x` unpinned [VERIFIED: packagist registry] | BC check against the previous tag | The tool's own PHP floor has drifted upward release over release: 8.14.0 supports `~8.2.0\|~8.3.0\|~8.4.0`; the current 8.21.0 (May 2026) requires `~8.4.0\|~8.5.0` only. This is a single comparison job (not matrix-wide), so run it on one PHP version (recommend PHP 8.4, since that's inside every recent release's support window) rather than trying to make it float across the whole matrix. |
| `illuminate/pint` (`laravel/pint`) | latest `^1.x` | Style enforcement | Already implied by STANDARDS §5; Pint uses PHP-CS-Fixer under the hood and is unrelated to the PHPCS/Slevomat pairing used for code-shape below. |

### Supporting

| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| `squizlabs/php_codesniffer` | `^4.0` (current 4.0.1, actively released Nov 2025) [VERIFIED: packagist registry] | `Generic.Metrics.CyclomaticComplexity` sniff | Set `complexity="10"` and `absoluteComplexity="10"` so anything above 10 hard-fails, matching STANDARDS §6b. |
| `slevomat/coding-standard` | `^8.31` (actively released, latest tag Jul 2026) [VERIFIED: packagist registry] | `SlevomatCodingStandard.Files.FileLength` (`maxLinesLength=500`), `SlevomatCodingStandard.Functions.FunctionLength` (`maxLinesLength=150`) | These two sniffs are the precise, actively-maintained equivalent of the code-shape script; no cyclomatic-complexity sniff exists in Slevomat, hence pairing with PHPCS core above. |
| `phpstan/extension-installer` | `^1.4` | Auto-registers Larastan's `extension.neon` | Conventional for Larastan installs; avoids manually requiring the neon file. |
| `@commitlint/cli` + `@commitlint/config-conventional` | `21.2.1` / `21.2.0` (npm, verified via `npm view`) [VERIFIED: npm registry] | Commit-message linting | Consumed **inside** the `wagoid/commitlint-github-action` Docker action, not as a repo-root Node dependency (see Don't Hand-Roll / Architecture Patterns). |
| `wagoid/commitlint-github-action` | `@6` (current major) [CITED: github.com/wagoid/commitlint-github-action] | Runs commitlint across every commit in the PR | Docker-based action; bundles `@commitlint/config-conventional` itself, so **no root `package.json` is required**. |
| `vitest` | `4.1.10` (npm, verified via `npm view`) [VERIFIED: npm registry] | JS test runner + coverage floor | `@vitest/coverage-v8` `4.1.10` is the matching provider package. |
| `astro` | `7.1.3` (npm, verified via `npm view`) [VERIFIED: npm registry] | Docs site framework | — |
| `@astrojs/starlight` | `0.41.4` (npm, verified via `npm view`) [VERIFIED: npm registry] | Docs theme/framework | Matches the `ReyemTech/apps/stint` reference implementation's pattern (that repo pins an older `^0.34.0`/`^5.5.0`; current is newer — bump deliberately, don't blindly copy stint's exact pin). |
| `googleapis/release-please-action` | current major (`v4`) [CITED: github.com/googleapis/release-please] | Versioning + `CHANGELOG.md` | `release-type: simple` recommended (tracks version via `version.txt`, touches no `composer.json` field) rather than `release-type: php` (which expects/updates a version key inside `composer.json` — this package should not hardcode one, since Packagist derives the version from the git tag). |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Dual Pest constraint (`^3.8\|^4.0`) | Pin Pest 4 only and drop PHP 8.2 | Rejected — PHP 8.2 support is a signed, locked decision (migration reach for `tapp/laravel-hubspot` users); cannot be "fixed" by narrowing the matrix. |
| PHPCS + Slevomat for code-shape | Hand-rolled script (PHP tokenizer or `wc -l` + naive brace counting) | A hand-rolled script is still viable (CONTEXT.md explicitly leaves the code-shape script's implementation language to discretion) but is strictly worse here: PHPCS+Slevomat are actively maintained, need zero custom code to get exactly right, and a hand-rolled cyclomatic-complexity counter is easy to get subtly wrong. Recommend the tool pairing; fall back to a script only if a ruleset.xml proves awkward for CI wiring. |
| PHPCS + Slevomat | `phpmd` (PHP Mess Detector) | Rejected as primary — phpmd's last release was **December 2023** (2.5 years stale as of this research date); its `ExcessiveClassLength` measures class body length, not literal file length, and could miss trait-only or interface-only files. |
| `release-type: simple` | `release-type: php` | `php` release-type expects to manage a version key in `composer.json`; this package should never hardcode one (Packagist reads the git tag). `simple` avoids touching `composer.json` at all. |

**Installation:**
```bash
composer require --dev \
  "pestphp/pest:^3.8|^4.0" \
  "pestphp/pest-plugin-laravel:^3.2|^4.0" \
  "pestphp/pest-plugin-arch:^3.1|^4.0" \
  "phpstan/phpstan:^2.2" \
  "larastan/larastan:^3.10" \
  "phpstan/extension-installer:^1.4" \
  "orchestra/testbench:^9.0|^10.0|^11.0" \
  "roave/backward-compatibility-check:8.14.0" \
  "squizlabs/php_codesniffer:^4.0" \
  "slevomat/coding-standard:^8.31" \
  "laravel/pint:^1.0"

# site/ (docs) — separate Node workspace, NOT repo root
cd site && pnpm add -D vitest @vitest/coverage-v8 && pnpm add astro @astrojs/starlight
```

**Version verification:** Every version above was checked against the Packagist API
(`curl https://repo.packagist.org/p2/<vendor>/<package>.json`) or `npm view <pkg> version` during
this research session on 2026-07-26, not recalled from training data. `npm view` was additionally
run locally (Node 22.22.1, npm 9.2.0) as a second, independent confirmation for the npm packages.

## Package Legitimacy Audit

Composer packages are **not** covered by the `gsd-tools package-legitimacy check` seam (npm/pypi/crates
only). They were instead audited manually by querying the Packagist API directly for full release
history, requirement drift, and release cadence — the same rigor the automated gate applies, using
the authoritative registry itself.

| Package | Registry | Age / cadence | Downloads | Source Repo | Verdict | Disposition |
|---------|----------|----------------|-----------|--------------|---------|-------------|
| `pestphp/pest` | Packagist | Mature (first tag 2021), releasing weekly | very high (de facto standard) | github.com/pestphp/pest | OK | Approved |
| `pestphp/pest-plugin-arch` | Packagist | Mature, releasing regularly | high | github.com/pestphp/pest-plugin-arch | OK | Approved |
| `pestphp/pest-plugin-laravel` | Packagist | Mature | high | github.com/pestphp/pest-plugin-laravel | OK | Approved |
| `phpstan/phpstan` | Packagist | Mature, de facto standard | very high | github.com/phpstan/phpstan | OK | Approved |
| `larastan/larastan` | Packagist | Mature | high | github.com/larastan/larastan | OK | Approved |
| `orchestra/testbench` | Packagist | Mature, de facto standard for package testing | very high | github.com/orchestral/testbench-core | OK | Approved |
| `roave/backward-compatibility-check` | Packagist | Mature (Roave is a known org) | moderate-high | github.com/Roave/BackwardCompatibilityCheck | OK | Approved — **pin version**, see Common Pitfalls |
| `squizlabs/php_codesniffer` | Packagist | Very mature (15+ years) | very high | github.com/PHPCSStandards/PHP_CodeSniffer | OK | Approved |
| `slevomat/coding-standard` | Packagist | Mature, actively released (last tag Jul 2026) | high | github.com/slevomat/coding-standard | OK | Approved |

npm packages, run through `gsd-tools package-legitimacy check --ecosystem npm`:

| Package | Registry | Age | Downloads | Source Repo | Verdict | Disposition |
|---------|----------|-----|-----------|--------------|---------|-------------|
| `vitest` | npm | mature, gate flags "too-new" (false positive — see note) | 82.3M/week | github.com/vitest-dev/vitest | SUS (false positive) | Approved — see note |
| `@vitest/coverage-v8` | npm | mature | 31.7M/week | github.com/vitest-dev/vitest | SUS (false positive) | Approved — see note |
| `astro` | npm | mature | 4.2M/week | github.com/withastro/astro | SUS (false positive) | Approved — see note |
| `@astrojs/starlight` | npm | mature | 685K/week | github.com/withastro/starlight | SUS (false positive) | Approved — see note |
| `@commitlint/cli` | npm | mature | 9.4M/week | github.com/conventional-changelog/commitlint | SUS (false positive) | Approved — see note |
| `@commitlint/config-conventional` | npm | mature | 9.2M/week | github.com/conventional-changelog/commitlint | SUS (false positive) | Approved — see note |
| `pnpm` | npm | mature | 140.4M/week | github.com/pnpm/pnpm | SUS (false positive) | Approved — see note |

**Note on the SUS verdicts above:** every one triggered on the `too-new` signal alone, which reads
the **publish date of the latest version**, not the package's first release. All seven are
long-established, extremely high-download packages (millions to hundreds of millions of weekly
downloads) with `repoUrl` matching their well-known official GitHub organizations, and none have a
`postinstall` script (checked via `npm view <pkg> scripts.postinstall`, all empty). This is a
heuristic false-positive pattern for actively-maintained tools that release frequently, not a
legitimacy concern. Per protocol, the `SUS` tag is retained and the planner should still add a
lightweight `checkpoint:human-verify` before the first install step for these packages — but no
package is removed, and none should be treated as a real slopsquatting risk.

**Packages removed due to [SLOP] verdict:** none.
**Packages flagged as suspicious [SUS]:** all seven npm packages above, tagged `SUS` by the
`too-new` heuristic (false positive per the note); planner should gate the pnpm/npm install step
behind a `checkpoint:human-verify`.

## Architecture Patterns

### System Architecture Diagram

```
 PR opened / pushed to a feature branch
        |
        v
 [GitHub Actions: pull_request -> main]
        |
        |--> matrix job (x20): checkout -> setup PHP(v) -> composer install
        |         (Laravel(v) via testbench, prefer-stable | prefer-lowest)
        |         -> vendor/bin/pest  ---------------------------> tests + coverage >=95%
        |
        |--> arch job: vendor/bin/pest --group=arch (or same run, filtered)
        |         -> pest-plugin-arch evaluates expect()->toOnlyUse()/not->toUse()/toUseStrictTypes()
        |         -> FAILS if a violation fixture (Phase-1-only) is not caught
        |
        |--> mutation job: vendor/bin/pest --mutate --min=80
        |         (runs only after the tests job is green; needs covers() annotations)
        |
        |--> static-analysis job: vendor/bin/phpstan analyse --level=max
        |         (Larastan extension auto-registered via phpstan/extension-installer)
        |
        |--> code-shape job: vendor/bin/phpcs --standard=phpcs.xml src/
        |         (Generic.Metrics.CyclomaticComplexity + Slevomat FileLength/FunctionLength)
        |
        |--> style job: vendor/bin/pint --test
        |
        |--> dependency-audit job: composer audit
        |
        |--> bc-check job (single PHP version, pinned roave release):
        |         roave-backward-compatibility-check --format=github-actions
        |
        |--> commitlint job: wagoid/commitlint-github-action@6 (lints every commit in the PR)
        |
        |--> composer-validate job: composer validate --strict
        |
        |--> js job (site/ workspace): pnpm install --> vitest run --coverage
        |         (coverage.thresholds.lines/functions/branches/statements = 95)
        |
        |--> docs-build job (site/ workspace): pnpm --filter <docs-pkg> build (astro build)
        |
        v
 [all N required checks green] --> mergeable via branch protection (owner action, not this phase)

 Separate, decoupled pipeline (push to main only, path-filtered on site/**):
 [deploy-docs.yml] --> pnpm build (site/) --> push site/dist to `docs-pages` branch
        using a PAT (not GITHUB_TOKEN) because GITHUB_TOKEN-authored pushes do not
        trigger downstream workflow_run events
        |
        v
 [docs-pages branch receives push] --> [deploy-pages.yml: push->docs-pages] --> GitHub Pages deploy
        (deploy step will not actually publish while the repo is private — see Blocked)
```

### Recommended Project Structure

```
composer.json                 # exactly 7 production requires; dual-constraint dev requires
pint.json                     # committed, laravel preset
phpstan.neon                  # level: max, checkModelProperties: true, no baseline
phpcs.xml                     # Generic.Metrics.CyclomaticComplexity + Slevomat FileLength/FunctionLength
tests/
├── Pest.php                  # base TestCase binding, uses()
├── Arch/                     # architecture-boundary tests (six layers + strict_types)
│   ├── LayerBoundariesTest.php
│   └── Fixtures/             # throwaway violation classes (Phase 1 only — proves rules fire)
├── Unit/
└── Feature/
src/
├── Gateway/                  # empty in Phase 1 — the only namespace ever allowed to name HubSpot\*
├── Registry/                 # empty in Phase 1
├── Sync/                     # empty in Phase 1
├── Webhooks/                 # empty in Phase 1
├── Signals/                  # empty in Phase 1
└── Frontend/                 # empty in Phase 1
site/                         # Astro + Starlight docs, separate pnpm workspace
├── package.json
├── astro.config.mjs
├── src/
└── (vitest.config.ts, or resources/js/ + its own vitest.config.ts if the JS surface is kept
     separate from the docs site — Claude's discretion per CONTEXT.md)
.github/
├── workflows/
│   ├── ci.yml                # the 20-job matrix + all PHP gates + JS coverage + docs build
│   ├── deploy-docs.yml        # site/ -> docs-pages branch, PAT push (see stint reference)
│   └── deploy-pages.yml       # docs-pages branch -> GitHub Pages (will not run while repo private)
├── CODEOWNERS
├── PULL_REQUEST_TEMPLATE.md
└── ISSUE_TEMPLATE/
SECURITY.md
commitlint.config.mjs          # extends @commitlint/config-conventional; no root package.json needed
release-please-config.json     # release-type: simple
.release-please-manifest.json
```

### Pattern 1: Non-rectangular matrix via `include`/`exclude`

**What:** A `strategy.matrix` cross-product with explicit `include` entries to map each Laravel
major to its testbench major, and `exclude` entries to remove the four invalid PHP×Laravel cells.
**When to use:** Any time the support matrix isn't a full cross-product — which this project's
matrix explicitly is not (Laravel 11 excludes PHP 8.5; Laravel 13 excludes PHP 8.2).
**Example:**
```yaml
# Source: pattern confirmed against rubenvanassche.com/testing-php-packages-with-github-actions/
# and cross-checked against spatie/package-skeleton-laravel's current run-tests.yml structure
strategy:
  fail-fast: false   # all 20 jobs must independently report status — required checks need this
  matrix:
    php: ['8.2', '8.3', '8.4', '8.5']
    laravel: ['11.*', '12.*', '13.*']
    stability: [prefer-lowest, prefer-stable]
    include:
      - laravel: '11.*'
        testbench: '9.*'
      - laravel: '12.*'
        testbench: '10.*'
      - laravel: '13.*'
        testbench: '11.*'
    exclude:
      # Laravel 11 does not support PHP 8.5
      - php: '8.5'
        laravel: '11.*'
      # Laravel 13 requires PHP 8.3+
      - php: '8.2'
        laravel: '13.*'
```
This produces exactly the ten valid PHP×Laravel cells (four excluded from the naive 4×3=12
cross-product), each doubled by `stability` = 20 jobs, matching CONTEXT.md's locked count.

### Pattern 2: Composer dual-major dev constraints for a split PHP floor

**What:** `"pestphp/pest": "^3.8|^4.0"` (and the same shape for `pest-plugin-arch` and
`pest-plugin-laravel`) so Composer's platform-aware solver picks the installable major per PHP
version automatically — no per-job composer.json swapping needed.
**When to use:** Whenever a `require-dev` tool's own PHP floor has moved past the package's floor
mid-way through the supported range, and the tool's version split happens to align with (or at
least not conflict with) the package's own PHP/framework split.
**Example:**
```json
{
  "require-dev": {
    "pestphp/pest": "^3.8|^4.0",
    "pestphp/pest-plugin-arch": "^3.1|^4.0",
    "pestphp/pest-plugin-laravel": "^3.2|^4.0"
  }
}
```
On a PHP 8.2 CI leg, Composer's platform check makes `^4.0` uninstallable, so it silently resolves
`^3.8`. On PHP 8.3+, both are installable and Composer picks the higher satisfying version (`^4.0`)
by default unless `prefer-lowest` is in effect, in which case verify the `prefer-lowest` runs still
resolve a sane (non-EOL) 3.x version rather than the oldest ever published — pin a `>=3.8` floor
deliberately to avoid resolving something ancient under `prefer-lowest`.

### Pattern 3: Architecture rules with a strict_types preset

**What:** `pest-plugin-arch`'s `toUseStrictTypes()` expectation, applied package-wide.
**When to use:** Enforcing CONTEXT.md's `declare(strict_types=1)` decision as a build-breaking rule
rather than a review nicety.
**Example:**
```php
// Source: pestphp.com/docs/arch-testing (fetched 2026-07-26)
arch('strict types')
    ->expect('ReyemTech\Hubspot')
    ->toUseStrictTypes();

arch('gateway is the only layer naming HubSpot classes')
    ->expect('HubSpot')
    ->toOnlyBeUsedIn('ReyemTech\Hubspot\Gateway');

arch('registry may only depend on gateway')
    ->expect('ReyemTech\Hubspot\Registry')
    ->toOnlyUse('ReyemTech\Hubspot\Gateway');

arch('signals must not depend on sync or webhooks')
    ->expect('ReyemTech\Hubspot\Signals')
    ->not->toUse(['ReyemTech\Hubspot\Sync', 'ReyemTech\Hubspot\Webhooks']);

arch('frontend must not reach into internal layers')
    ->expect('ReyemTech\Hubspot\Frontend')
    ->not->toUse([
        'HubSpot',
        'ReyemTech\Hubspot\Gateway',
        'ReyemTech\Hubspot\Registry',
        'ReyemTech\Hubspot\Sync',
        'ReyemTech\Hubspot\Webhooks',
        'ReyemTech\Hubspot\Signals',
    ]);
```
**Confidence note:** this syntax was fetched from the current (v4-era) docs page and is CITED, not
directly confirmed against the 3.x line's docs snapshot — treat the exact method names
(`toOnlyBeUsedIn`, `toOnlyUse`, `not->toUse`, `toUseStrictTypes`) as MEDIUM confidence and verify
against the installed major's own `--help`/README once pinned, since this project runs both 3.x and
4.x across its matrix.

### Anti-Patterns to Avoid
- **Treating an empty-namespace arch test as proof the rule works:** it passes vacuously (see
  Common Pitfalls #1). A green Phase 1 arch suite is not evidence the boundary is enforced.
- **Pinning a single Pest major across the whole matrix:** either breaks PHP 8.2 (if 4.x) or misses
  Laravel 13 (if 3.x alone, without the dual constraint).
- **Copying `spatie/package-skeleton-laravel`'s `composer.json` verbatim:** its current `main`
  branch requires PHP `^8.4` and does not need the dual-constraint trick this project needs. Copy
  its CI *structure* (matrix shape, `include`/`exclude` pattern), not its dependency versions.
- **Using `composer.json`'s `version` key with release-please's `php` release-type:** creates a
  second source of truth that fights with Packagist's git-tag-derived versioning. Use
  `release-type: simple`.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| File/function length + complexity gate | A custom PHP script that walks the AST | `squizlabs/php_codesniffer` (`Generic.Metrics.CyclomaticComplexity`) + `slevomat/coding-standard` (`Files.FileLength`, `Functions.FunctionLength`) | Both actively maintained (PHPCS 4.0.1 Nov 2025, Slevomat 8.31.0 Jul 2026); a hand-rolled complexity counter is easy to get subtly wrong (e.g. miscounting `match` arms or null-coalescing chains) |
| HMAC/webhook signature verification | Anything touching `hash_equals` directly | `HubSpot\Utils\Signature::isValid()` | Explicit CLAUDE.md/STANDARDS rule — not a Phase 1 concern but worth reinforcing since the architecture tests for Phase 1 exist partly to make this rule mechanically checkable later |
| Commit-message linting harness | A repo-root `package.json` + manually wired `commitlint.config.js` + npm install step | `wagoid/commitlint-github-action@6` | Ships as a Docker action bundling `@commitlint/config-conventional`; needs only a `commitlint.config.mjs` (no root `package.json`, no dependency on the `site/` workspace) |
| PR-vs-tag backward-compatibility diffing | A custom `composer diff` + reflection script | `roave/backward-compatibility-check` | Purpose-built, Roave-maintained; just needs the right version pinned for the PHP 8.2 floor |

**Key insight:** every "don't hand-roll" case in this phase resolves to an existing,
actively-maintained tool once the PHP-8.2-floor constraint is applied correctly — the temptation to
write a script comes from assuming a single tool version must span the whole matrix, when in fact
dual constraints (Pest) or a single pinned version (BC-check) solve it without new code.

## Common Pitfalls

### Pitfall 1: Empty-namespace arch rules pass vacuously
**What goes wrong:** All six layer-boundary tests report green in Phase 1 even if the rule text is
wrong (e.g. a typo'd namespace string), because there are zero classes to check against.
**Why it happens:** `pest-plugin-arch` (and the underlying `phpunit-architecture-test` layer)
evaluates expectations over a *discovered file set*; an empty set trivially satisfies any
`toOnlyUse`/`not->toUse` assertion.
**How to avoid:** Ship a throwaway "violation fixture" per rule — a class temporarily placed in the
wrong namespace, importing a forbidden dependency — run the suite to confirm it goes red, then
delete the fixture (or move it to a clearly-marked `tests/Arch/Fixtures/` directory excluded from
`src/` autoloading) before merging. This is what ROADMAP success criterion 2 for Phase 1 actually
demands ("a deliberate violation fixture fails the build for each").
**Warning signs:** An arch test PR that never goes red at any point in its own history is
suspicious — TDD (CONTEXT.md's non-negotiable working method) requires the RED commit to exist.

### Pitfall 2: Pest's major-version split lands exactly on the PHP 8.2/8.3 line — but only if you check
**What goes wrong:** Assuming "Pest 4 is current, use that" (true, and true for `apps/laravel`, but
false for this package) breaks every PHP 8.2 CI job with an unresolvable `composer.json`.
**Why it happens:** Training-data recall defaults to "use the latest major," and Pest's own docs
site defaults to showing v4 content.
**How to avoid:** Dual constraint (`^3.8|^4.0`) on all three Pest packages, as documented above.
**Warning signs:** `composer install` failing only on the PHP-8.2 matrix legs with a
"your requirements could not be resolved" mentioning `pestphp/pest`.

### Pitfall 3: `roave/backward-compatibility-check`'s PHP floor keeps rising
**What goes wrong:** Pinning `roave/backward-compatibility-check: ^8.0` (or leaving it unconstrained)
picks up a release that has dropped PHP 8.2 support, breaking the BC-check CI job on whatever PHP
version that job happens to run.
**Why it happens:** The tool's own support window has moved twice in the last year (dropped 8.1,
then dropped 8.2/8.3).
**How to avoid:** Pin an explicit version known to support the runner's PHP version (this is a
single comparison job, not matrix-wide — run it once, on one PHP version inside the tool's current
support window, e.g. PHP 8.4).
**Warning signs:** A Dependabot PR silently bumping this package that turns the BC-check job red
with a PHP-version incompatibility rather than an actual BC violation.

### Pitfall 4: STANDARDS §3's "level 9 (max)" is stale
**What goes wrong:** Configuring `level: 9` literally, when the intent ("max") now means 10 under
PHPStan 2.x.
**Why it happens:** STANDARDS.md was written before/without re-checking PHPStan's own maximum, and
level 9 used to be the ceiling under PHPStan 1.x.
**How to avoid:** Use `level: max` in `phpstan.neon` rather than a literal digit, so it always
tracks whatever the pinned PHPStan major's true ceiling is.
**Warning signs:** A PHPStan config with a hardcoded digit that silently stops being "max" the next
time PHPStan bumps its own maximum.

### Pitfall 5: `spatie/package-skeleton-laravel`'s current `main` is not a drop-in template here
**What goes wrong:** Copying its `composer.json`/`run-tests.yml` verbatim silently drops PHP 8.2/8.3
support, since spatie's current skeleton floor is PHP `^8.4`.
**Why it happens:** CONTEXT.md and CLAUDE.md both point at spatie's skeleton as the convention
source, but conventions age — the skeleton itself has moved its floor since this project's support
matrix was decided.
**How to avoid:** Use spatie's skeleton for CI *structure* (job layout, `include`/`exclude` pattern
shape, general repo layout) but re-derive the actual version constraints against this project's own
locked matrix, as done throughout this document.
**Warning signs:** Any composer.json pin copied from an external template without independently
re-verifying it against `composer show <pkg> --all` or the Packagist API for *this* project's PHP
floor.

## Code Examples

### GitHub Actions job: JS coverage floor
```yaml
# Source: vitest.dev/config/coverage (fetched 2026-07-26) + vitest.dev/guide/coverage.html
# vitest.config.ts (inside site/ or resources/js/, per Claude's discretion)
import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    coverage: {
      provider: 'v8',
      thresholds: {
        lines: 95,
        functions: 95,
        branches: 95,
        statements: 95,
      },
    },
  },
});
```
```yaml
# .github/workflows/ci.yml (js-coverage job excerpt)
- uses: pnpm/action-setup@v4
  with: { version: 9 }
- uses: actions/setup-node@v4
  with: { node-version: 20, cache: pnpm }
- run: pnpm install --frozen-lockfile
- run: pnpm test -- --coverage   # exits non-zero when any threshold is unmet
```

### GitHub Actions job: commitlint with no root package.json
```yaml
# Source: github.com/wagoid/commitlint-github-action README (fetched 2026-07-26)
- uses: wagoid/commitlint-github-action@v6
  with:
    configFile: commitlint.config.mjs
    failOnWarnings: false
    # commitDepth left unset -> lints every commit in the PR, not only the head/title
```
```js
// commitlint.config.mjs (repo root — no package.json needed; the action bundles config-conventional)
export default {
  extends: ['@commitlint/config-conventional'],
};
```

### Docs deploy: PAT push, not GITHUB_TOKEN
```yaml
# Source: ReyemTech/apps/stint .github/workflows/deploy-docs.yml (read directly, 2026-07-26)
# Confirmed against official docs.github.com/en/actions/concepts/security/github_token:
# "events triggered by the GITHUB_TOKEN will not create a new workflow run" (except
# workflow_dispatch/repository_dispatch) — this is why deploy-pages.yml (triggered by
# push to docs-pages) would silently never fire if deploy-docs.yml pushed with GITHUB_TOKEN.
- name: Publish to docs-pages branch
  env:
    GITHUB_TOKEN: ${{ secrets.RELEASE_TOKEN || secrets.GITHUB_TOKEN }}
  run: scripts/release/publish-docs.sh
```

### phpcs.xml: code-shape gate
```xml
<!-- Source: derived from slevomat/coding-standard doc/files.md and doc/functions.md,
     and squizlabs/PHP_CodeSniffer's Generic.Metrics.CyclomaticComplexity sniff (fetched 2026-07-26) -->
<ruleset name="ReyemTechHubspotCodeShape">
    <rule ref="Generic.Metrics.CyclomaticComplexity">
        <properties>
            <property name="complexity" value="10"/>
            <property name="absoluteComplexity" value="10"/>
        </properties>
    </rule>
    <rule ref="SlevomatCodingStandard.Files.FileLength">
        <properties>
            <property name="maxLinesLength" value="500"/>
        </properties>
    </rule>
    <rule ref="SlevomatCodingStandard.Functions.FunctionLength">
        <properties>
            <property name="maxLinesLength" value="150"/>
        </properties>
    </rule>
</ruleset>
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|---------------|--------|
| PHPStan max = level 9 | PHPStan max = level 10 | PHPStan 2.0 (Dec 2024) | STANDARDS §3 needs a wording fix from "level 9 (max)" to "level: max" |
| Pest arch testing bundled loosely with Pest core | `pestphp/pest-plugin-arch` as its own versioned package, now on a v4 line requiring PHP 8.3 | Pest plugin ecosystem split; v4 line began Aug 2025 | Forces the dual-constraint pattern documented above |
| `phploc`/`pdepend` for code metrics | PHPCS core + `slevomat/coding-standard` | phploc abandoned 2020, pdepend stale since Dec 2023 | Don't reach for the "classic" PHP metrics tools; they're unmaintained |
| `spatie/package-skeleton-laravel` supporting PHP 8.1+ | Current skeleton floor is PHP `^8.4`, Pest `^4.0` only | Ongoing skeleton maintenance drift | Template can't be copied verbatim for this project's wider PHP floor |

**Deprecated/outdated:**
- `phploc`: last released Dec 2020, effectively abandoned. Do not add as a dependency.
- `pdepend`: last released Dec 2023, stale. Do not add as a dependency.
- Infection (mutation testing): explicitly rejected by this project's locked decisions in favor of
  `pest --mutate`; mentioned here only to note it should never reappear in a plan.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `pest-plugin-arch`'s exact method names (`toOnlyBeUsedIn`, `toOnlyUse`, `not->toUse`, `toUseStrictTypes`) are identical between the 3.x and 4.x lines | Architecture Patterns, Pattern 3 | If the 3.x API differs, the PHP-8.2 matrix legs' arch tests would need different syntax than the PHP-8.3+ legs — verify against the actually-installed major's changelog before writing the arch test file |
| A2 | The empty-namespace vacuous-pass behavior for `pest-plugin-arch` (LOW confidence, WebSearch synthesis only, no primary-source doc page quote) | Common Pitfalls #1, Summary | If this is wrong and empty-namespace rules actually error, Phase 1 planning around "write the fixture, then delete it" would need to change to "write the fixture, keep at least one class present" instead — either way, the mitigating task (a violation fixture) is correct regardless of which behavior is true, so this assumption is low-risk to plan against |
| A3 | `wagoid/commitlint-github-action@6` requires no root `package.json` at all (based on its README's description as a Docker action bundling config-conventional) | Standard Stack, Code Examples | If wrong, a minimal root `package.json` (or a `--filter`-scoped one in `site/`) would need to be added; low effort to correct if it surfaces during Wave 0 |
| A4 | `release-type: simple` is the right release-please strategy for a Packagist library with no hardcoded version field (vs. `release-type: php`) | Standard Stack | If wrong, switching release-types mid-project requires a manifest reset; verify against `release-please-config.json` schema docs before the REL-01 task locks it in |
| A5 | Pest arch testing's `toOnlyBeUsedIn`/`toOnlyUse` syntax cited from the (likely v4-era) pestphp.com docs page applies unchanged to Pest 3.x's arch plugin, since arch testing has been part of Pest since v2 and the API has been stable | Architecture Patterns, Pattern 3 | Same mitigation as A1 — confirm against the installed 3.x package's own docs/tests before finalizing |

**If this table is empty:** N/A — five assumptions logged above; none block Phase 1 planning, but
A1/A2/A5 should be spot-checked once Pest is actually installed in Wave 0, since they concern
syntax rather than architecture decisions.

## Open Questions

1. **Does `pest-plugin-arch` 3.x's arch-testing syntax match the v4-era docs page fetched in this
   session, method-for-method?**
   - What we know: Arch testing has been a stable Pest concept since v2; the `arch()` helper and
     `expect()->toOnlyUse()`/`not->toUse()`/`toUseStrictTypes()` shape is unlikely to have changed
     across a major bump that was primarily a PHP-floor bump (v3 -> v4 required PHP 8.3, not a
     documented API rewrite).
   - What's unclear: No 3.x-specific docs snapshot was fetched in this session to confirm word-for-word.
   - Recommendation: When Wave 0 installs Pest on a PHP-8.2 leg, run `composer show pestphp/pest-plugin-arch` and check its own README/CHANGELOG for the exact method signatures before finalizing `tests/Arch/LayerBoundariesTest.php`.

2. **What is the correct pinned version (or version range) for `roave/backward-compatibility-check`
   that satisfies "runs once, on a PHP version inside its own support window, without blocking PHP
   8.2 elsewhere in the matrix"?**
   - What we know: 8.14.0 (Jun 2025) supports PHP `~8.2.0|~8.3.0|~8.4.0`; 8.21.0 (May 2026, current)
     requires `~8.4.0|~8.5.0`.
   - What's unclear: Whether a version between 8.14.0 and 8.21.0 exists that's both recent and still
     PHP-8.2-compatible, versus deliberately running the BC-check job on PHP 8.4/8.5 with the latest
     roave release (avoiding the PHP-8.2 question entirely since the BC-check job doesn't need to
     run on every matrix leg — it needs to run once).
   - Recommendation: Simplest fix — run the BC-check as a single, non-matrixed job on PHP 8.4 (or
     8.5) with the latest `roave/backward-compatibility-check`, sidestepping the version-pin question.
     This is very likely the intended shape anyway (BC-check compares source trees, not runtime
     behavior, so it doesn't need to run per-PHP-version).

3. **Which node/pnpm workspace should own Vitest — `site/` (the docs site) or a standalone
   `resources/js/`?**
   - What we know: CONTEXT.md leaves this to Claude's discretion; STANDARDS §6 justifies the JS floor
     specifically by the `Frontend` layer's `postMessage`-origin-validating listener (Phase 8), which
     is *not* part of the docs site.
   - What's unclear: Whether Phase 1 should scaffold `resources/js/` now (with a placeholder file just
     to prove the coverage gate) or defer that until Phase 8 actually needs it, running Vitest inside
     `site/` in the meantime as a placeholder.
   - Recommendation: Scaffold a minimal `resources/js/` workspace now with one trivial, fully-covered
     function and its test, separate from `site/`'s docs-only Node workspace — this proves the 95%
     floor mechanism works today without conflating "docs build" and "frontend JS coverage" into one
     pnpm workspace whose `package.json` name and purpose diverge (`stint-docs`-style naming would be
     wrong for a coverage gate meant to protect Phase 8's security-sensitive listener).

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|--------------|-----------|---------|----------|
| PHP | All PHP gates | Yes | 8.5.4 (local dev machine; CI matrix will use `shivammathur/setup-php` for 8.2-8.5) | — |
| Composer | Dependency resolution | Yes | 2.9.5 | — |
| Node | JS/docs gates | Yes | 22.22.1 (CI should still pin to Node 20 LTS per the `stint` reference to match `pnpm/action-setup` + `actions/setup-node` conventions) | — |
| npm | Registry verification only, not a CI dependency | Yes | 9.2.0 | — |
| pnpm | JS/docs package management | Not installed locally | — | CI installs via `pnpm/action-setup@v4`; locally, use `corepack enable pnpm` or `npx pnpm` |
| git | SCM, BC-check comparisons | Yes | 2.53.0 | — |
| gh (GitHub CLI) | Not required by CI itself, useful for local repo administration | Yes | 2.46.0 | — |
| Docker | Explicitly NOT used per CLAUDE.md ("No Sail, no Docker") | Present locally but irrelevant | — | N/A — this package never uses Docker; testbench replaces it |

**Missing dependencies with no fallback:** none.
**Missing dependencies with fallback:** pnpm (install via `corepack` or CI action; not a blocker).

## Validation Architecture

`.planning/config.json` was not found in this project, so `workflow.nyquist_validation` is absent
and treated as enabled per the default rule.

### Test Framework

| Property | Value |
|----------|-------|
| Framework | Pest (dual-constraint `^3.8\|^4.0`), running on PHPUnit under the hood |
| Config file | `tests/Pest.php` — does not exist yet; created in Wave 0 |
| Quick run command | `vendor/bin/pest --parallel` |
| Full suite command | `vendor/bin/pest --coverage --min=95` (line coverage floor) plus a separate `vendor/bin/pest --mutate --min=80` pass |

### Phase Requirements -> Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|--------------------|--------------|
| FOUND-01 | Full 20-job matrix installs and runs a trivial passing test on an empty package | integration (CI) | `.github/workflows/ci.yml` matrix job | ❌ Wave 0 |
| FOUND-02 | `SECURITY.md` exists with a private disclosure address | manual-only (file existence, no test framework applicable) | `test -f SECURITY.md && grep -qi "security@" SECURITY.md` (a CI grep, not a Pest test) | ❌ Wave 0 |
| FOUND-03 | Association-inverse probe | BLOCKED — no test possible without the developer token | n/a | n/a |
| FOUND-04 | JS coverage floor + docs-site build green on an empty package | unit (Vitest) + build (Astro) | `pnpm test -- --coverage` / `pnpm build` | ❌ Wave 0 |
| FOUND-05 | Six layer-boundary architecture rules, each provably enforced | unit (Pest arch) | `vendor/bin/pest tests/Arch --group=arch` | ❌ Wave 0 |
| REL-01 | `composer validate --strict` passes; release-please configured | integration (CI) + manual config review | `composer validate --strict` | ❌ Wave 0 (composer.json doesn't exist yet) |

### Sampling Rate
- **Per task commit:** `vendor/bin/pest --parallel` (fast feedback; skips mutation/coverage floors)
- **Per wave merge:** `vendor/bin/pest --coverage --min=95` then `vendor/bin/pest --mutate --min=80`,
  plus `pnpm test -- --coverage` for the JS side
- **Phase gate:** Full 20-job matrix green (via `act` locally if feasible, otherwise a draft PR) before
  `/gsd-verify-work`

### Wave 0 Gaps
- [ ] `composer.json` — does not exist yet; everything in this phase depends on it
- [ ] `tests/Pest.php` — Pest bootstrap/base TestCase binding
- [ ] `tests/Arch/LayerBoundariesTest.php` — the six-layer + strict_types rules, plus throwaway
      violation fixtures (see Common Pitfalls #1)
- [ ] `phpstan.neon` — `level: max`, `checkModelProperties: true`, Larastan extension registered
- [ ] `phpcs.xml` — cyclomatic complexity + file/function length rules
- [ ] `pint.json` — committed style ruleset
- [ ] `site/` (or `resources/js/`) — Node workspace with a `vitest.config.ts` and one trivial,
      fully-covered function to prove the 95% floor mechanism (see Open Question 3)
- [ ] `.github/workflows/ci.yml`, `deploy-docs.yml`, `deploy-pages.yml` — none exist yet
- [ ] `commitlint.config.mjs`, `release-please-config.json`, `.release-please-manifest.json`
- [ ] Framework install: `composer require --dev ...` (see Installation block above)

## Security Domain

`security_enforcement` was not found configured to `false` anywhere in `.planning/config.json`
(the file does not exist), so this section is included per the default-enabled rule — even though
Phase 1 ships no domain functionality, several ASVS-relevant controls are established as CI gates
here and inherited by every later phase.

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|----------------|---------|-------------------|
| V2 Authentication | No | Phase 1 has no auth surface (package-level, not app-level) |
| V3 Session Management | No | N/A for a Composer package |
| V4 Access Control | No | N/A in this phase |
| V5 Input Validation | Not yet (Phase 5+) | Established here only as a *gate*: `declare(strict_types=1)` enforced package-wide via architecture test, closing the "0"/0/"" coercion class of bug the design spec calls out for HubSpot object IDs |
| V6 Cryptography | Not yet (Phase 5) | STANDARDS §10 locks HMAC verification to `HubSpot\Utils\Signature::isValid()` — a Phase 1 architecture-test grep for hand-rolled `hash_equals`/HMAC calls could optionally be added now as a forward-looking guard, though the rule has nothing to check yet since no code exists |
| V14 Configuration | Yes | `composer audit` required check (fails build on any advisory); Dependabot enabled from `SECURITY.md`/`FOUND-02` |

### Known Threat Patterns for this stack

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|----------------------|
| Vulnerable transitive dependency shipped in a tagged release | Tampering / Information Disclosure | `composer audit` as a required CI check; Dependabot weekly per STANDARDS §12c |
| A future contributor hand-rolls HMAC comparison instead of using the SDK's validator | Spoofing | Not enforceable by a Phase 1 architecture test today (no code exists), but the CI gate infrastructure built here (`pest-plugin-arch`) is exactly what a later phase will use to add a grep-style rule against `hash_equals`/`hmac` outside the sanctioned call site |
| CI-authored commits (GITHUB_TOKEN) silently failing to trigger downstream deploy workflows, masking a broken release pipeline as "succeeded" | Denial of Service (of the release process, not the app) | PAT-based push for `deploy-docs.yml`, documented and sourced from the `stint` reference implementation |

## Sources

### Primary (HIGH confidence)
- None — no Context7 MCP tool was available in this session; the closest to primary-source
  evidence obtained was direct Packagist/npm registry API queries (listed under Secondary, since
  the `classify-confidence` seam rates unverified single-source registry/web lookups as LOW, and
  cross-checked ones as MEDIUM).

### Secondary (MEDIUM confidence)
- Packagist API (`repo.packagist.org/p2/<vendor>/<package>.json`), queried directly for: `pestphp/pest`, `pestphp/pest-plugin-arch`, `pestphp/pest-plugin-laravel`, `phpstan/phpstan`, `larastan/larastan`, `orchestra/testbench`, `roave/backward-compatibility-check`, `squizlabs/php_codesniffer`, `slevomat/coding-standard`, `phploc/phploc`, `pdepend/pdepend` — all fetched 2026-07-26
- `npm view <pkg> version` / `npm view <pkg> scripts.postinstall`, run locally against the npm registry for `vitest`, `astro`, `@astrojs/starlight`, `@commitlint/cli`, `pnpm` — 2026-07-26
- [phpstan.org/user-guide/rule-levels](https://phpstan.org/user-guide/rule-levels) — level 10 is current max, confirmed 2026-07-26
- [pestphp.com/docs/arch-testing](https://pestphp.com/docs/arch-testing) — arch testing syntax
- [pestphp.com/docs/mutation-testing](https://pestphp.com/docs/mutation-testing) — `--mutate`, `--min`, `covers()`
- [vitest.dev/config/coverage](https://vitest.dev/config/coverage) and [vitest.dev/guide/coverage.html](https://vitest.dev/guide/coverage.html) — coverage thresholds syntax
- [github.com/wagoid/commitlint-github-action](https://github.com/wagoid/commitlint-github-action) README — Docker action, bundled config-conventional, `commitDepth`/`configFile` inputs
- [docs.github.com/en/actions/concepts/security/github_token](https://docs.github.com/en/actions/concepts/security/github_token) — GITHUB_TOKEN-authored events don't trigger new workflow runs
- `/home/mariomeyer/code/ReyemTech/apps/stint/.github/workflows/deploy-docs.yml`, `deploy-pages.yml`, `scripts/release/publish-docs.sh` — read directly, the prescribed reference implementation
- [github.com/googleapis/release-please](https://github.com/googleapis/release-please) customizing docs — `release-type: simple` vs `php`
- [rubenvanassche.com/testing-php-packages-with-github-actions](https://rubenvanassche.com/testing-php-packages-with-github-actions/) — matrix `include`/`exclude` pattern
- `spatie/package-skeleton-laravel`'s current `main` branch `composer.json` and `run-tests.yml` (fetched via raw GitHub content, 2026-07-26) — used as a structural reference, explicitly noted as version-incompatible with this project's PHP floor

### Tertiary (LOW confidence)
- WebSearch-synthesized claim that `pest-plugin-arch` rules over an empty namespace pass vacuously — consistent across two independent search queries but not confirmed against a primary docs-page quote; logged as Assumption A2
- WebSearch synthesis on PHPCS's `Generic.Metrics.CyclomaticComplexity` `complexity`/`absoluteComplexity` property names — not independently cross-checked against the sniff's own PHP source in this session

## Metadata

**Confidence breakdown:**
- Standard stack (composer package versions): MEDIUM — every version cross-checked directly against the Packagist API, which is authoritative, but the classify-confidence seam has no "verified" affordance without a paired qualitative source, so these are conservatively MEDIUM rather than HIGH
- Architecture (matrix shape, arch-test patterns): MEDIUM-to-LOW — the matrix `include`/`exclude` pattern is well-established and CITED; the exact arch-test method names carry a documented LOW-confidence gap (Assumption A1/A5) pending a 3.x-specific check
- Pitfalls: MEDIUM-HIGH — the Pest PHP-floor split and the BC-check PHP-floor drift are both directly confirmed via registry data, not recall; the empty-namespace vacuous-pass behavior is the one LOW-confidence pitfall in this document (Assumption A2), though the recommended mitigation (violation fixtures) is correct regardless of which way that assumption resolves

**Research date:** 2026-07-26
**Valid until:** 7 days — this document is unusually version-sensitive (a prior stale-version
incident is explicitly on record for this project) and several packages here (`pestphp/pest`,
`roave/backward-compatibility-check`, Astro/Starlight) are on fast release cadences; re-verify
exact pins immediately before Wave 0 execution rather than trusting this file if more than a few
days have elapsed.
