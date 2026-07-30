# CLAUDE.md — reyemtech/laravel-hubspot

Start with `BRIEF.md`, then the design spec at
`docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md`, then `STANDARDS.md`.

`STANDARDS.md` is binding. It is not advisory and it is not a starting point for negotiation.

---

## Overrides of the workspace defaults

This is a **standalone public package**, not part of the ReyemTech Laravel application. Rules
that apply to `../../apps/laravel` do not automatically apply here.

### Tests: Pest, deliberately

`apps/laravel/CLAUDE.md` says *"If you see a test using Pest, convert it to PHPUnit."*
**That rule does not apply to this package. Do not convert anything.**

Pest was chosen for tooling reasons, not taste: `pest --mutate` and `pest-plugin-arch` provide
two of this project's hard standards — the mutation-score floor and the layer-boundary
architecture tests — as first-class features. Under PHPUnit those need Infection plus
deptrac/phpat, i.e. four tools for what Pest does in one runner. `spatie/package-skeleton-laravel`
and `tapp/laravel-hubspot` both ship Pest, so contributors expect it.

Pest runs on PHPUnit, so PHPUnit-style test classes are valid here if you prefer them.

### No Sail, no Docker

`apps/laravel` runs everything through Sail/`docker compose`. This package does not. Run
`vendor/bin/pest`, `vendor/bin/pint`, `vendor/bin/phpstan` directly, and test against the CI
matrix via `orchestra/testbench`.

### PHP and Laravel versions

Target the support matrix in `STANDARDS.md` §1, not this workspace's versions. The application is
PHP `^8.3`; this package targets `^8.2` for reach.

---

## Rules specific to this codebase

- **`Gateway` is the only layer that may name `HubSpot\*` classes.** If you find yourself
  importing an SDK class in `Sync`, `Registry` or `Webhooks`, the design is being violated —
  add the capability to `Gateway` instead. Architecture tests will fail the build anyway.
- **Never write an association without an explicit direction.** `(from, to)` is the primitive.
  If a function signature lets a caller pass two objects without an order, that signature is
  wrong.
- **Never fall back to the inverse typeId on a registry miss.** Throw. A silent fallback writes
  the wrong direction and nobody notices for months.
- **Never hand-roll HMAC.** Signature verification delegates to `HubSpot\Utils\Signature::isValid()`.
- **Never use `$request->fullUrl()` for signature verification.** Symfony sorts query params;
  HubSpot signs the raw URI.
- **Never add a THIRD-PARTY runtime dependency** without justification in the PR description, and
  the reviewer's default answer is still no. **Superseded 2026-07-30 (D-02, Phase 4):** any
  `illuminate/*` component may be declared as a production require — being first-party Laravel does
  make a component free, provided it is declared rather than relied on transitively. This document
  previously claimed the opposite ("being first-party Laravel does not make a component free") and
  fixed the production `require` count at seven forever; both claims are wrong now. Production
  `require` currently stands at **eleven** entries, but that count is not the rule and will drift —
  the vendor-allow-list CI gate (`manifest shape (vendor allow-list)`,
  `tests/Ci/ComposerManifestTest.php`) is authoritative on what is admitted: `php`,
  `hubspot/api-client`, any `illuminate/*` package, and `laravel/prompts` via its own enumerated
  exception. A non-`illuminate/*` third-party package still needs `STANDARDS.md` §2 justification in
  the PR description before it is added.
- **Never add a PHPStan baseline.** Fix it or suppress it per-line with a written reason.
- **Never let a raw SDK exception reach userland.** Wrap it in the package's hierarchy.
- **Never run tests against a real HubSpot portal** in the default suite. Integration tests are a
  separate, opt-in, secret-gated suite and are never required to merge.

## Working method

- **TDD is the method, not a preference.** Write the failing test, commit it, then implement.
  The RED commit precedes the GREEN commit in history.
- Feature branch from a freshly pulled `main`. **Never branch from a branch.** Update a stale
  branch by rebasing onto `main` with `--force-with-lease`.
- Conventional Commits on **every** commit, not just the PR title.
- Before finalising: `vendor/bin/pint`, `vendor/bin/phpstan analyse`, `vendor/bin/pest`, and the
  architecture tests. All green, no exceptions, no disabled gates.
- **Never resolve a review thread you have not read.** See `STANDARDS.md` §12, *Automated review is
  review*. Branch protection requires conversation resolution, so resolving threads is the last
  thing standing between you and a green merge button — which is exactly why it is the easiest
  discipline in this repository to quietly drop. Read every Codex comment in full, then either fix
  it or reply saying with evidence why it is wrong. Closing a thread in silence is not allowed, and
  "it's only a bot" is not a reason.
- **Every resolved thread gets a written reply, including the ones you fixed.** A fix is not a
  reply — otherwise the thread records only that somebody clicked resolve. Say which of the four
  dispositions applies and carry what it needs: **fixed** → the commit SHA; **mitigated** → the SHA
  plus what remains unfixed; **judged wrong** → evidence, what you checked and what it showed;
  **out of scope** → where the work went. Do not demand a SHA for the last two — there isn't one.
- **Merge only when a completed review names the commit you are merging.** Codex reviews trigger on
  pull request open, on draft-ready, and on an `@codex review` comment — **never on a push**. So the
  commits that fix a finding are by default never reviewed by the thing that found it, and "no new
  comments appeared" means nobody looked; never report that as a clean result. Requesting is not
  enough either: `@codex review` is asynchronous, so posting it and merging immediately, or pushing
  again afterwards, leaves the same hole. Check it — Codex prints `**Reviewed commit:** <sha>`;
  compare with `gh pr view <n> --json headRefOid`. Every Phase 3 PR merged with a mismatch; see
  `STANDARDS.md` §12.
- **Local green is not evidence.** Phase 1 shipped four gate failures that passed on the machine
  and failed in CI — no `composer.lock` on a matrix build, a missing workflow permission, a Node
  pin below what pnpm requires, and a missing coverage driver. None were reachable without pushing.
  Push, watch the real checks, and report what GitHub says rather than what your terminal said.

## When the spec is silent

Ask rather than invent. Six decisions in `STANDARDS.md` are explicitly unsigned, and the
association-inverse question in design spec §6.4 is an empirical probe with a defined procedure —
run the probe, do not guess the answer.

If you find a genuine flaw in the design, say so and stop. Do not route around it silently.
