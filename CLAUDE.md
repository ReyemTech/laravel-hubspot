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
- **Never add a runtime dependency** without justification in the PR description. Production
  `require` is `php`, `hubspot/api-client`, `illuminate/contracts` — and stays that way.
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

## When the spec is silent

Ask rather than invent. Six decisions in `STANDARDS.md` are explicitly unsigned, and the
association-inverse question in design spec §6.4 is an empirical probe with a defined procedure —
run the probe, do not guess the answer.

If you find a genuine flaw in the design, say so and stop. Do not route around it silently.
