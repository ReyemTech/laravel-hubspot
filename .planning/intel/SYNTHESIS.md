# Synthesis

Entry point for `gsd-roadmapper`. Greenfield ingest (`MODE: new`) — no pre-existing `.planning/`
context. Generated 2026-07-26.

---

## Documents synthesized (3)

- **ADR** — `STANDARDS.md` (precedence 0, manifest-typed, high confidence)
- **SPEC** — `docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md` (precedence 1, manifest-typed, high confidence)
- **DOC** — `BRIEF.md` (precedence 2, manifest-typed, high confidence)

Cross-ref cycle detection: 3 cycles found, all mutual-citation rather than mutual content-deferral,
resolved deterministically by the per-doc precedence integers. Synthesis proceeded on the full set.
Rationale in `INGEST-CONFLICTS.md`.

## Decisions (38) → `decisions.md`

- **32 locked** — `STANDARDS.md` §§1-13, treated as binding per the ingest direction, plus sign-off
  decision #2 (Pest as the test framework), which the source records as settled.
- **6 proposed** — the unsigned sign-off items #0, #1, #3, #4, #5, #6. Each carries the value stated
  in the STANDARDS body as its default. All three source documents agree none block Phase 0; #0 is
  the only one that shapes Phase 0 (see the single WARNING).
- Note: several §§1-13 sections are split across both buckets — the unsigned parameter is `proposed`
  while the rest of its section is `locked`. Detail in `INGEST-CONFLICTS.md`.

Locked decisions carrying the highest downstream weight: DEC-architecture-layer-boundaries,
DEC-runtime-dependencies (exactly six), DEC-phpstan-level-9 (baseline forbidden), DEC-no-network-io,
DEC-tdd, DEC-zero-migration-install, DEC-no-hand-rolled-hmac, DEC-webhook-fail-closed,
DEC-exception-hierarchy, DEC-branch-protection, DEC-security-md-day-one.

## Requirements (21) → `requirements.md`

No PRD was present. Requirements were derived from design spec §2 (goals stated in requirement form)
and the body deliverables per phase, with acceptance criteria verbatim where stated and marked absent
where the source is silent (REQ-object-type-registry, REQ-webhook-subscription-sync).

REQ-generic-object-core, REQ-directional-associations, REQ-association-inverse-probe,
REQ-association-registry, REQ-object-type-registry, REQ-model-binding, REQ-property-mapping,
REQ-model-sync-trait, REQ-delete-policy, REQ-sync-escape-hatches, REQ-inbound-webhooks,
REQ-webhook-subscription-sync, REQ-webhook-audit-trail, REQ-test-double, REQ-error-hierarchy,
REQ-zero-migration-install, REQ-installer, REQ-diagnostics-commands, REQ-tapp-migration-path,
REQ-repo-scaffolding, REQ-documentation.

## Constraints (18) → `constraints.md`

By type: 7 protocol, 6 api-contract, 3 schema, 2 nfr.

**Six hard constraints, highest project risk** — silent and expensive to violate:

1. **CON-association-direction** — association type ids are directional and differ per direction
   (279 vs 280, 19 vs 20, 201 vs 202). The primitive is a directed pair; a registry miss **throws**
   and **never falls back to the inverse id**.
2. **CON-webhook-signature** — reconstruct the raw request URI, **never** `$request->fullUrl()`
   (Symfony sorts query params; HubSpot signs byte-for-byte); HMAC delegated to
   `HubSpot\Utils\Signature::isValid()`; fails closed by default.
3. **CON-layer-boundaries** — `Gateway` is the only layer permitted to name `HubSpot\*`.
4. **CON-zero-migration-install** — `composer require` plus a trait works with no publish step and
   no `migrate`; `loadMigrationsFrom()` only when a database store is active.
5. **CON-no-network-io** — no test performs real network I/O in the default suite; it runs green with
   no credentials and no internet.
6. **CON-tdd-sequence** — the RED test commit precedes the GREEN implementation commit.

Remaining: CON-generic-objects-api, CON-association-inverse-unverified, CON-association-registry-schema,
CON-store-selection, CON-model-binding, CON-property-mapping, CON-auto-sync-and-delete-policy,
CON-webhook-delivery, CON-test-double, CON-installer, CON-tapp-compat, CON-phase-ordering.

## Context topics (10) → `context.md`

What this package is; repository status; ecosystem gap; the tapp audit; scope boundaries and
non-goals; things that will bite you if you skip the spec; how to start; open sign-off items;
the Pest-conversion agent hazard; when the spec is silent.

## Phasing constraint (preserve this ordering)

Design spec §13 proposes six phases and **Phase 0 is not optional and comes first** — it carries the
association-inverse probe plus getting every standards gate green on an empty package. BRIEF.md:
"Turning gates on later never happens." Each phase ships green against the full matrix with the
coverage and MSI floors met; no phase merges with a gate disabled "temporarily".

Phase 0 — inverse probe (§6.4); repo scaffolding, CI matrix, all standards gates green (plus
`SECURITY.md`, moved here from Phase 5 by ADR precedence).
Phase 1 — `Gateway`: `ObjectGateway`, `AssociationGateway`, error hierarchy, `Hubspot::fake()`.
Phase 2 — `Registry`: object types, association registry, cache + database stores, `associations:sync`, `doctor`.
Phase 3 — `Sync`: `PropertyMapper`, `SyncsToHubspot`, observer, job, delete policy, `withoutSyncing()`.
Phase 4 — `Webhooks`: signature middleware (incl. the `fullUrl()` fix), dispatcher, idempotency, typed events, `webhooks:sync`.
Phase 5 — `hubspot:install`, tapp compat shim, README quickstart, `UPGRADE.md`, `CONTRIBUTING.md`.

## Conflicts

- **0 blockers**
- **1 competing variant / warning** — sign-off decision #0, merge commits vs release-please. It is the
  only open decision that shapes Phase 0, because Phase 0 configures the CI required checks and the
  merge strategy determines whether commitlint is mandatory. Needs user resolution before Phase 0 is
  planned.
- **10 auto-resolved / informational** — including three ADR-over-SPEC precedence resolutions
  (`laravel/prompts` as a declared dependency, `SECURITY.md` from day one, `CONTRIBUTING.md`), the
  cross-ref cycle analysis, and the STANDARDS draft-vs-binding header ambiguity.

Full detail: `.planning/INGEST-CONFLICTS.md`

## Per-type intel files

- `.planning/intel/decisions.md` — ADR-owned decisions (locked and proposed)
- `.planning/intel/requirements.md` — SPEC-derived requirements
- `.planning/intel/constraints.md` — SPEC-owned technical contracts, hard constraints indexed first
- `.planning/intel/context.md` — orientation, rationale, and DOC-unique facts

## Open items the roadmapper should carry forward

1. Sign-off decision #0 (merge commits vs release-please) — gates Phase 0 CI configuration.
2. Sign-off decisions #1, #3, #4, #5, #6 — stated defaults are usable; confirm before or during
   Phase 0, none block it.
3. The §6.4 association-inverse probe — a Phase 0 deliverable with a prescribed empirical procedure,
   not a question to answer by reasoning. A developer test account is confirmed available.
