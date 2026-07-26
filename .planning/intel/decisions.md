# Decisions

Source type: ADR. Extracted from `STANDARDS.md` (precedence 0, highest).

`STANDARDS.md` carries `Status: Draft for review` in its header while its body ("These are
binding rules, not aspirations") and `CLAUDE.md` ("STANDARDS.md is binding") both declare it
binding. Per ingest direction, §§1-13 are treated as LOCKED. The six items in *Decisions needing
sign-off* that remain unsigned (#0, #1, #3, #4, #5, #6) are treated as `proposed` — the value is
stated in the body and is usable as a default, but the parameter itself awaits sign-off.
Decision #2 (Pest) is recorded as settled by the source and is LOCKED.

---

## DEC-support-matrix: Laravel 11/12 and HubSpot SDK ^14.1, full CI matrix
- source: STANDARDS.md §1
- status: locked
- decision: Support Laravel 11.x and 12.x (Laravel 10 is past EOL) and `hubspot/api-client:^14.1`. Every supported combination runs in the CI matrix against both `prefer-stable` and `prefer-lowest`. A version not tested is not supported, and the README says so.
- scope: runtime support matrix, CI matrix, README support statement

## DEC-php-floor: PHP `^8.2`, not `^8.3`
- source: STANDARDS.md §1, sign-off item #1
- status: proposed
- decision: PHP floor `^8.2` (Laravel 11's own floor), diverging from `apps/laravel`'s `^8.3`, because a library excluding 8.2 excludes a large share of installs for no nameable benefit. Awaits sign-off as decision #1.
- scope: composer `require` php constraint, CI matrix rows

## DEC-runtime-dependencies: production `require` is exactly six packages
- source: STANDARDS.md §2
- status: locked
- decision: Production `require` is exactly `php`, `hubspot/api-client`, `illuminate/contracts`, `illuminate/support`, `illuminate/database`, `laravel/prompts`. The last three are Illuminate code the package calls directly and are declared rather than assumed transitively. A seventh dependency requires written justification in the PR description and the reviewer's default answer is no. The rule being encoded is no third-party runtime dependencies.
- scope: composer.json require block, PR review gate

## DEC-excluded-dependencies: three named packages are excluded deliberately
- source: STANDARDS.md §2
- status: locked
- decision: Exclude `spatie/laravel-package-tools` (a dependency to save ~80 lines of service provider; hand-roll instead), `spatie/laravel-webhook-client` (forces its `webhook_calls` migration on every consumer, contradicting zero-migration install), and `fakerphp/faker` from production (`require-dev` only, every call site guarded by `class_exists()`).
- scope: dependency selection, service provider, webhook implementation, test support

## DEC-phpstan-level-9: level max, baseline forbidden
- source: STANDARDS.md §3
- status: locked
- decision: PHPStan + Larastan at level 9 (max) with `checkModelProperties: true`. A baseline file is forbidden — on a greenfield package there is no legacy to grandfather. Suppression is per-line, never per-file, and always carries a written reason. CI fails on any new error; there is no "fix it later" mode.
- scope: static analysis configuration, CI gate

## DEC-strict-types: `declare(strict_types=1)` in every PHP file
- source: STANDARDS.md §4, sign-off item #3
- status: proposed
- decision: `declare(strict_types=1)` in every PHP file, enforced by an architecture test rather than review. Justification is specific: the package passes HubSpot object ids around as strings that look like integers, and coercive typing makes `"0"`, `0` and `""` silent equivalents — a wrong object id writes to the wrong CRM record. Awaits sign-off as decision #3.
- scope: every PHP source file, architecture test

## DEC-style-pint: Pint `laravel` preset with committed `pint.json`
- source: STANDARDS.md §5
- status: locked
- decision: Pint with the `laravel` preset and a committed `pint.json` so the ruleset is explicit rather than implied. CI runs `pint --test` and fails on any diff.
- scope: code style, CI gate

## DEC-test-framework-pest: Pest, deliberately
- source: STANDARDS.md sign-off item #2 (recorded as settled), CLAUDE.md
- status: locked
- decision: Pest is the test framework. The `apps/laravel` rule mandating PHPUnit and converting Pest to PHPUnit is app-scoped and does not carry here. Reason is tooling, not taste: mutation testing (`pest --mutate`) and architecture tests (`pest-plugin-arch`) are first-class Pest features but need Infection plus deptrac/phpat under PHPUnit — four tools for what Pest does in one runner. Pest runs on PHPUnit, so PHPUnit-style test classes work unmodified. Known hazard: an agent in this workspace will read `apps/laravel`'s CLAUDE.md and try to convert the suite.
- scope: test runner, mutation testing, architecture tests

## DEC-coverage-and-mutation-floors: 95% line coverage, 80% MSI
- source: STANDARDS.md §6, sign-off item #4
- status: proposed
- decision: Line coverage floor 95% (Pest + Xdebug/PCOV) and mutation score floor 80% (`pest --mutate`), both enforced in CI. Coverage alone measures which lines ran, not whether an assertion would notice them breaking. These are real floors that will occasionally block a merge; lower them now rather than under deadline. Awaits sign-off as decision #4.
- scope: CI gates, Definition of Done item 3

## DEC-architecture-layer-boundaries: Gateway is the only layer naming `HubSpot\*`
- source: STANDARDS.md §6, design spec §3 (concurring)
- status: locked
- decision: Architecture tests enforce four layer boundaries — `Gateway` may depend on `hubspot/api-client`; `Registry` on `Gateway`; `Sync` on `Registry` and `Gateway`; `Webhooks` on `Registry` and `Gateway`. Anything reaching upward fails the build. `Gateway` is the only layer permitted to reference `HubSpot\*` classes, which is what makes the SDK swappable and the rest of the package fast to test.
- scope: package architecture, architecture tests, CI gate

## DEC-deterministic-tests: determinism is a correctness property
- source: STANDARDS.md §6
- status: locked
- decision: Tests are deterministic or they are broken. Time is frozen with `Carbon::setTestNow()`, never `sleep()`; randomness is seeded; ordering is never assumed. A test that passes in isolation and fails in the parallel suite is a failing test, not an environment quirk.
- scope: test suite

## DEC-no-skipped-tests: `failOnSkipped`, `failOnIncomplete`, `failOnRisky`
- source: STANDARDS.md §6
- status: locked
- decision: No skipped, incomplete or risky tests on `main`; PHPUnit runs with `failOnSkipped`, `failOnIncomplete` and `failOnRisky` enabled. A test worth skipping is worth deleting or fixing. Flaky tests are quarantined within 24 hours — reverted or marked and issued, never left to rot in CI.
- scope: test configuration, CI gate, flake policy

## DEC-no-network-io: no test performs real network I/O in the default suite
- source: STANDARDS.md §6, BRIEF.md (concurring)
- status: locked
- decision: No test may perform real network I/O. The suite runs green with no HubSpot credentials and no internet. Integration tests against a live developer portal live in a separate, opt-in suite gated on a secret and are never required to merge.
- scope: default test suite, integration test suite, CI

## DEC-tdd: RED test commit precedes GREEN implementation commit
- source: STANDARDS.md §6a, BRIEF.md (concurring)
- status: locked
- decision: Every change starts as a failing (RED) test and is implemented until green — the working method, not a preference. The test commit precedes the implementation commit; with merge-commit SDLC that history survives into `main`, so the RED→GREEN sequence is visible in `git log` forever. Every bug fix opens with a test reproducing the bug, and the PR names the commit where it was red. Review checks the sequence because CI cannot; a PR whose tests were written after the code is sent back. Stated caveat: this is the one standard tooling cannot fully enforce.
- scope: every commit, PR review, git history

## DEC-code-shape-limits: file 500 lines hard, complexity 10 hard
- source: STANDARDS.md §6b
- status: locked
- decision: File length hard fail at 500 lines (review target 300) and cyclomatic complexity hard fail at 10 (review target 5), enforced by a CI script. Anything over the review target needs a sentence in the PR saying why. Logic used more than once is extracted and reused — extract behaviour, not shape: two functions that resemble each other but answer different questions stay separate; two that answer the same question become one immediately, not on the third occurrence.
- scope: CI script, PR review

## DEC-function-length-limit: function hard limit 150 lines
- source: STANDARDS.md §6b, sign-off item #6
- status: proposed
- decision: Function length hard fail at 150 lines with a review target of 40. Kept as specified, but the source notes that with everything else in the document a 150-line function should never survive review — the review target of 40 is the number that will actually operate. Awaits sign-off as decision #6.
- scope: CI script, PR review

## DEC-zero-migration-install: no migrations on install
- source: STANDARDS.md §7, design spec §2 goal 5 and §6.3 (concurring)
- status: locked
- decision: The package works after `composer require` with no publish step and no `migrate`. Database-backed stores are opt-in via one env var.
- scope: service provider, migrations, install experience

## DEC-install-optional: `hubspot:install` is optional, never required
- source: STANDARDS.md §7, design spec §11 (concurring)
- status: locked
- decision: `hubspot:install` is optional and never required. A package that breaks without an install step gets abandoned at the README.
- scope: installer command, install experience

## DEC-config-surface: inline-documented config, namespaced env vars
- source: STANDARDS.md §7
- status: locked
- decision: Every key in `config/hubspot.php` carries a comment stating what it does and what breaks if it is wrong. Env vars are namespaced `HUBSPOT_*` and listed in the README with their defaults.
- scope: config/hubspot.php, README

## DEC-semver-and-bc: strict semver with automated BC checking
- source: STANDARDS.md §8
- status: locked
- decision: Semantic versioning strictly; the public API is everything not marked `@internal`. `roave/backward-compatibility-check` runs on every PR to `main` and a detected break fails CI unless the PR is labelled `breaking` and targets the next major. Deprecations live for two minor versions minimum, emit `E_USER_DEPRECATED`, and name their replacement in the message. `UPGRADE.md` is updated in the same PR as any breaking change, not at release time.
- scope: versioning, public API, CI gate, UPGRADE.md

## DEC-final-by-default: every class `final` unless extension is documented
- source: STANDARDS.md §8, sign-off item #5
- status: proposed
- decision: Every class is `final` unless extension is an explicit, documented feature — unsealing later is a patch, sealing later is a breaking change. Reduces consumer flexibility and prevents accidental BC commitments; the escape hatch is interfaces, which the layer design already provides. Awaits sign-off as decision #5.
- scope: every class in the package

## DEC-exception-hierarchy: typed hierarchy, no raw SDK exception to userland
- source: STANDARDS.md §9, design spec §9 (identical hierarchy)
- status: locked
- decision: A typed hierarchy rooted at a package-owned `HubspotException` interface with `ConfigurationException`, `AssociationTypeException`, `ObjectTypeException` and `ApiException`. A raw `HubSpot\Client\...\ApiException` must never reach userland — consumers catch package types, which is what allows changing SDKs without breaking their `catch` blocks. Every exception message names the fix, not just the fault.
- scope: error handling, public API, SDK swappability

## DEC-no-secret-logging: tokens and client secrets are never logged
- source: STANDARDS.md §10
- status: locked
- decision: Tokens and client secrets are never logged, never in exception messages, never in `dd()`-able state. An architecture test greps for the config keys in log calls.
- scope: logging, exception messages, architecture test

## DEC-webhook-fail-closed: signature verification fails closed by default
- source: STANDARDS.md §10, design spec §8 (concurring)
- status: locked
- decision: Webhook signature verification fails closed by default. `enforce => false` exists for transitions and logs loudly on every request.
- scope: webhook middleware, config default

## DEC-no-hand-rolled-hmac: delegate signature comparison to the SDK
- source: STANDARDS.md §10, design spec §8, CLAUDE.md (concurring)
- status: locked
- decision: Signature comparison uses `hash_equals`, delegated to the SDK's validator (`HubSpot\Utils\Signature::isValid()`). The package does not hand-roll HMAC.
- scope: webhook signature verification

## DEC-security-md-day-one: `SECURITY.md` published from day one
- source: STANDARDS.md §10
- status: locked
- decision: `SECURITY.md` with a private disclosure address, published from day one. Dependabot enabled; security advisories are patch releases within 48 hours.
- scope: repository files, release policy
- note: design spec §13 schedules `SECURITY.md` in Phase 5; ADR precedence places it in Phase 0. See INGEST-CONFLICTS.md INFO entry.

## DEC-performance-batching: batch endpoints, no N+1, queued by default
- source: STANDARDS.md §11, design spec §10 (concurring)
- status: locked
- decision: Batch endpoints are used wherever HubSpot offers one — syncing a collection issues one batch request, not N. N+1 API calls are a test failure, not a code smell: `Hubspot::fake()` counts requests and the sync tests assert exact call counts. No API call in a request lifecycle by default — sync is queued unless explicitly told otherwise.
- scope: gateway batching, sync jobs, performance tests

## DEC-branching: fresh `main`, never branch from a branch
- source: STANDARDS.md §12, CLAUDE.md (concurring)
- status: locked
- decision: Every feature branch starts from a freshly pulled `main`, no exceptions. Branching from a branch is strictly forbidden — if work depends on unmerged work, the dependency merges first. A stale branch is updated by rebasing onto a freshly pulled `main`, which preserves the RED→GREEN sequence intact. Rebase means force-pushing, so always `--force-with-lease`, never `--force`; this is safe precisely because branching from a branch is forbidden, making every branch single-author by construction. Rebase before requesting review, not in the middle of one. Branch names: `feat/`, `fix/`, `chore/`, `docs/` plus a short slug.
- scope: git workflow

## DEC-merge-commits-vs-release-please: RESOLVED — merge commits, commitlint mandatory
- source: STANDARDS.md §12, §12a, sign-off item #0
- status: locked
- decision: **Signed off 2026-07-26.** Merge commits stay (not squash), and commitlint on every commit is therefore mandatory — it is a required check in §12b. The deciding argument is §6a: preserving the RED→GREEN sequence so it "survives into `main`… visible in `git log` forever" only works with merge commits; squashing would delete exactly the history a standard set elsewhere in the document exists to preserve. Accepted costs: contributor friction from commitlint, and a stray `feat:` inside a branch bumping the minor version, which review must catch.
- scope: merge strategy, release automation, commitlint, Phase 0 CI setup

## DEC-conventional-commits: on every commit, not merely the PR title
- source: STANDARDS.md §12, CLAUDE.md (concurring)
- status: locked
- decision: Conventional Commits on every commit, not merely the PR title, enforced by commitlint in CI. (The source cross-references the §12a conflict for the tooling consequence.)
- scope: every commit, CI gate

## DEC-pr-standards: verification statements, reviewable size, never merge red
- source: STANDARDS.md §12
- status: locked
- decision: Every PR states what was verified and how — "tests pass" is not a verification statement; naming the command and its result is. PRs are reviewable in one sitting; over ~400 changed lines the description must say why it could not be split. No PR merges red — not "it's unrelated", not "it's flaky".
- scope: pull request process

## DEC-no-todo-on-main: `TODO`/`FIXME` never reach `main`
- source: STANDARDS.md §12
- status: locked
- decision: No `TODO`/`FIXME` reaches `main`. CI greps for them; they become issues instead. A TODO is a decision deferred where nobody will find it.
- scope: CI gate, source files

## DEC-releases: release-please owns versioning, `main` always releasable
- source: STANDARDS.md §12
- status: locked
- decision: release-please owns versioning and `CHANGELOG.md`; nobody edits the changelog by hand. `main` is always releasable. **Packagist is wired, not manual:** release-please cuts the tag and the GitHub release but does not publish — the GitHub↔Packagist integration (App or webhook) is what turns a tag into an installable version. Without it, tags land and Packagist never notices, so the package looks abandoned while `main` is green. The Packagist name is claimed in Phase 0, before the vendor namespace, README and docs are written against it; the accepted trade-off is a public `dev-main` with no functionality until the first tag.
- scope: release process, CHANGELOG.md, Packagist publishing
- note: the §12a tooling conflict is resolved — see DEC-merge-commits-vs-release-please.

## DEC-branch-protection: `main` protected with required checks
- source: STANDARDS.md §12b, BRIEF.md (concurring, scheduled in Phase 0)
- status: locked
- decision: `main` is protected — PR required, CI required, no direct pushes, no force-push. A public package cannot start without it. Required checks: tests (full matrix), Pint, PHPStan, `pest --mutate`, architecture tests, `composer audit`, BC check, **commitlint** (mandatory per the resolved §12a) and **`composer validate --strict`** (an invalid `composer.json` fails at Packagist submission time). Plus `CODEOWNERS` and PR and issue templates, with the PR template carrying the Definition of Done.
- scope: repository settings, CI required checks, repository files

## DEC-definition-of-done: seven boxes ticked before review is requested
- source: STANDARDS.md §12b
- status: locked
- decision: Every box ticked before review is requested — (1) started as a RED test, (2) full matrix green, (3) coverage ≥95% and MSI ≥80%, (4) Pint and PHPStan clean with no new baseline, (5) docs and `UPGRADE.md` updated in this PR, (6) no new runtime dependency or justified in the description, (7) public API changes are semver-assessed.
- scope: PR template, review gate

## DEC-dependency-audits: `composer audit` fails the build
- source: STANDARDS.md §12c
- status: locked
- decision: `composer audit` runs in CI and fails the build on any advisory. Dependabot weekly, with patch and minor dev-dependency bumps auto-merging on green. Dependencies are updated at the start of a work cycle, never mixed into a feature PR. The CI matrix is what makes aggressive updating safe: every supported PHP × Laravel combination is exercised on `prefer-lowest` and `prefer-stable`, so a bump breaking an older supported version fails before release rather than in someone's application.
- scope: CI gate, dependency update cadence

## DEC-documentation: 60-second quickstart and per-method examples
- source: STANDARDS.md §13
- status: locked
- decision: The README opens with a 60-second quickstart — install, one model, one sync. Every public method has a usage example; signature-only reference is not documentation. The association direction table (279 vs 280, 19 vs 20, 201 vs 202) is documented prominently as the single most common source of HubSpot integration bugs. `CONTRIBUTING.md` states these standards and the fact that CI enforces them, so nobody discovers the mutation-score floor from a red build.
- scope: README, per-method docs, CONTRIBUTING.md

## DEC-explicit-rejections: four practices rejected deliberately
- source: STANDARDS.md "Not standards, deliberately"
- status: locked
- decision: Rejected so nobody re-proposes them in month three — (1) commit signing (real security value, real onboarding friction for outside contributors; revisit if the package gains maintainers beyond ReyemTech), (2) 100% coverage (the last 5% is `__toString()` and unreachable defensive branches; 95% plus an 80% mutation score is a genuinely higher bar than 100% coverage with weak assertions), (3) Rector in CI (excellent for one-off upgrades, noisy as a gate; run deliberately at version bumps), (4) a `docs/` site (README plus inline examples until there is enough surface to justify one).
- scope: project non-goals, tooling selection
