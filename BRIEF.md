# reyemtech/laravel-hubspot — Brief

**Read this first. It is the entry point for whoever picks this project up.**

---

## What this is

A Laravel package for HubSpot CRM that covers **every CRM object type** — contacts, companies,
deals, products, line items, tickets, quotes, custom objects — with **directional associations**
as a first-class concept, plus **inbound webhooks**, which no maintained package in this
ecosystem does properly.

Nothing has been built yet. This directory currently holds three documents and no code.

## Why it exists

The leading Laravel HubSpot package (`tapp/laravel-hubspot`, 6,203 installs) handles contacts and
companies only, and its design cannot extend past them without a rewrite: it uses the SDK's
per-type clients, so every object type costs a hand-written service. The official SDK ships a
*generic* objects API that removes that cost entirely. Inbound webhooks are held by a package
with 105 installs and 0 stars.

Full reasoning, with the audit numbers, is in the design spec.

## The three documents

| File | What it is |
|---|---|
| `docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md` | **The design.** Architecture, every decision and its rationale, suggested phasing |
| `STANDARDS.md` | **Binding engineering standards.** CI-enforced. Read before writing code |
| `CLAUDE.md` | Package-specific agent rules — including ones that override the workspace defaults |

## How to start

```
/gsd-ingest-docs
```

That bootstraps `.planning/` (PROJECT.md + a phased ROADMAP.md) from the design spec rather than
re-deriving requirements from scratch. Then work phase by phase with `/gsd-plan-phase` and
`/gsd-execute-phase`.

The spec proposes six phases in §13. **Phase 0 is not optional and must come first** — it
contains an empirical probe whose answer changes an API default, plus getting every standards
gate green on an empty package. Turning gates on later never happens.

## Things that will bite you if you skip the spec

1. **Association type ids are directional and different in each direction.** Contact→Company is
   279; Company→Contact is 280. Note→Contact is 202; Contact→Note is 201. Writing the inverse id
   silently associates the wrong way. This is the single most common HubSpot integration bug and
   the reason this package's shape is what it is.
2. **There is no unarchive endpoint.** The SDK's delete is `archive()`. The package can never
   programmatically undo one, which is why delete propagation is guarded by default.
3. **`$request->fullUrl()` breaks webhook signatures.** Symfony's `getQueryString()` sorts query
   parameters; HubSpot signs the URI byte-for-byte. Reconstruct the raw URI.
4. **The webhook secret is the app's *client secret*, not the PAT.** Two different credentials.
5. **Bindings are many-to-one.** Several local models can map to one HubSpot object type — the
   originating app has three models mapping to `contacts`. Config keyed by object type cannot
   express that.

## Non-negotiables

- **TDD.** Every change starts as a failing test. The RED commit precedes the GREEN one.
- **Zero-migration install.** `composer require` + a trait on a model must work with no publish
  step and no `migrate`.
- **`Gateway` is the only layer allowed to name `HubSpot\*` classes.** Architecture tests enforce
  it.
- **No test performs real network I/O.** The suite runs green with no credentials and no
  internet.
- **Six decisions in `STANDARDS.md` are unsigned** (see *Decisions needing sign-off*). None block
  Phase 0. Ask Mario rather than assuming.

## Repository status

Not yet a git repository, and not yet on Packagist. Phase 0 covers `git init`, the composer
skeleton, the CI matrix, and branch protection on `main`.
