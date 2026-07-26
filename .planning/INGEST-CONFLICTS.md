## Conflict Detection Report

Ingest mode: new (greenfield — no existing `.planning/` context to check against).
Sources: STANDARDS.md (ADR, precedence 0), docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md
(SPEC, precedence 1), BRIEF.md (DOC, precedence 2).

### BLOCKERS (0)

None. No LOCKED-vs-LOCKED contradiction exists — the ingest set contains a single ADR. No document
was classified UNKNOWN (all three are high confidence, manifest-typed). No cross-ref cycle produced
a synthesis loop (see the first INFO entry for the analysis).

### WARNINGS (0)

None outstanding. The one warning raised by synthesis was resolved by user sign-off before
routing; it is retained below, marked RESOLVED, so the decision and its rationale stay visible.

[RESOLVED — was WARNING] Unresolved tooling conflict: merge commits vs release-please
  Found: STANDARDS.md §12 states both "Merge commits, not squash" and "release-please owns versioning
    and CHANGELOG.md" as binding standards. STANDARDS.md §12a then records these two in tension and
    titles itself "Conflict to resolve" — with merge commits, release-please derives the changelog and
    version bump from individual commits on `main`, so every commit must be a valid Conventional Commit
    or it is silently dropped, a `feat:` buried in a branch bumps the minor version even if the PR was a
    fix, and semantic-pr-title alone becomes insufficient: commitlint on every commit becomes mandatory.
  Found: STANDARDS.md §12 says Conventional Commits are "enforced by commitlint in CI", but the
    required-checks list in STANDARDS.md §12b (tests, Pint, PHPStan, `pest --mutate`, architecture
    tests, `composer audit`, BC check) does not include commitlint.
  Impact: This is sign-off decision #0 and it is the only open decision that shapes Phase 0. Phase 0
    delivers repo scaffolding, the CI matrix and branch protection with required checks — so the merge
    strategy and whether commitlint is a required check must be settled before Phase 0's CI is
    configured. STANDARDS.md §12a states both paths work but require different tooling.
  → Sign off decision #0 (merge commits + mandatory commitlint, or squash + semantic-pr-title) before
    planning Phase 0, and reconcile the §12b required-checks list with the answer.
  Resolution (2026-07-26, user sign-off): merge commits stay; commitlint on every commit is
    mandatory and is now a required check. The deciding argument is §6a — preserving the RED→GREEN
    sequence so it survives into `main` only works with merge commits, and squashing would delete
    the history that standard exists to preserve. STANDARDS.md §12a retitled "Resolved", §12b's
    required-checks list now carries commitlint and `composer validate --strict`, and sign-off item
    #0 is marked signed. Five unsigned decisions remain (#1, #3, #4, #5, #6), none Phase-0-gating.

### INFO (11)

[INFO] Cross-ref cycle detected — resolved by explicit precedence, not blocked
  Note: Three-colour DFS over the `cross_refs` graph found 3 cycles among the ingested docs:
    STANDARDS → design-spec → STANDARDS; design-spec → BRIEF → design-spec; and
    STANDARDS → design-spec → BRIEF → STANDARDS. Max traversal depth 3, well inside the depth-50 cap.
    Each edge was inspected for mutual content-deferral, which is the loop-inducing kind: STANDARDS §7
    defers the store env var to the design spec and spec §6.3 answers it terminally; spec §14 defers the
    open sign-off items to STANDARDS and STANDARDS answers them terminally; spec §3 cites STANDARDS §6,
    which states the layer boundaries terminally; STANDARDS §2 overrides the spec's "no new dependency"
    phrasing rather than deferring to it; BRIEF's edges are citations from its document index. No item
    is deferred in both directions, so no synthesis loop is reachable, and the per-doc precedence
    integers (0 < 1 < 2) give a total order that resolves any contradiction deterministically.
    Synthesis therefore proceeded on the full set. These are three documents designed to cross-reference
    each other; treating mutual citation as a hard block would have gated the ingest on a false positive.
    Recorded here so the judgement is visible and reversible.

[INFO] STANDARDS.md status header contradicts its own body
  Note: The header says "Status: Draft for review" while the body opens "These are binding rules, not
    aspirations" and CLAUDE.md states "STANDARDS.md is binding. It is not advisory and it is not a
    starting point for negotiation." The classifier set `locked: false` on the Accepted-only rule.
    Resolved per ingest direction: §§1-13 are treated as LOCKED and extracted to decisions.md with
    `status: locked`; the six unsigned sign-off items are extracted with `status: proposed`.
    Updating the header to match the body would remove the ambiguity at source.

[INFO] Six unsigned decisions carried as proposed, with their stated values as defaults
  Note: Sign-off items #0 (merge commits vs release-please), #1 (PHP floor `^8.2`), #3
    (`declare(strict_types=1)`), #4 (coverage 95% / MSI 80%), #5 (`final` by default) and #6 (function
    hard limit 150 lines) each have a corresponding section in STANDARDS.md §§1-13 stating the value as
    binding. To avoid recording each subject as both locked and open, decisions.md splits them: the
    unsigned parameter is one entry with `status: proposed`, and the remainder of its section stays
    `status: locked`. Example: §1's Laravel 11/12 and SDK ^14.1 are locked, while the PHP floor is
    proposed. Decision #2 (Pest) is recorded by the source as settled and is locked. All three documents
    agree none of the six block Phase 0; BRIEF.md adds "Ask Mario rather than assuming."

[INFO] Auto-resolved: ADR > SPEC on `laravel/prompts`
  Note: docs/.../design.md §11 describes `laravel/prompts` as "Laravel core — no new dependency";
    STANDARDS.md §2 lists it as one of exactly six production `require` entries and explicitly reconciles
    the two — "that is true of the vendor tree and not true of this package's composer.json". ADR wins on
    precedence and the source has already stated the reconciliation. Synthesised intel records the
    six-package production `require` including `laravel/prompts`. No action needed.

[INFO] Auto-resolved: ADR > SPEC on when `SECURITY.md` ships
  Note: STANDARDS.md §10 requires "SECURITY.md with a private disclosure address, published from day
    one"; docs/.../design.md §13 schedules `SECURITY.md` in Phase 5 alongside the README quickstart and
    `UPGRADE.md`. ADR wins on precedence, which moves `SECURITY.md` into Phase 0 repo scaffolding.
    Recorded on DEC-security-md-day-one, CON-phase-ordering and REQ-repo-scaffolding so the roadmapper
    places it in Phase 0 rather than Phase 5.

[INFO] Auto-resolved: ADR > SPEC on `CONTRIBUTING.md`
  Note: STANDARDS.md §13 requires `CONTRIBUTING.md` stating the standards and that CI enforces them, "so
    nobody discovers the mutation-score floor from a red build". The spec's §13 phase table lists README,
    `UPGRADE.md` and `SECURITY.md` in Phase 5 but omits `CONTRIBUTING.md` entirely. Omission rather than
    contradiction; ADR requirement carried into REQ-documentation.

[INFO] No PRD in the ingest set — requirements derived from the SPEC
  Note: The ingest set contains one ADR, one SPEC and one DOC. requirements.md is normally fed by PRDs.
    Requirements were therefore extracted from design spec §2 (which states goals in requirement form)
    and the body deliverables per phase, with acceptance criteria taken verbatim where the source states
    one. Where the source states a capability but no acceptance criteria — REQ-object-type-registry and
    REQ-webhook-subscription-sync — the acceptance field is marked absent rather than invented. 21
    requirements extracted.

[INFO] BRIEF.md restatements deduplicated against their owning sources
  Note: BRIEF.md (precedence 2) restates constraints owned by STANDARDS.md and the design spec — its
    "Things that will bite you if you skip the spec" and "Non-negotiables" sections. No contradiction was
    found in any restatement; all five bite-items and all five non-negotiables agree with their owners.
    They are recorded in context.md with a pointer to the owning entry, and the normative text lives once
    under its owner in decisions.md or constraints.md. BRIEF.md's unique content — repository status (not
    yet a git repository, not yet on Packagist) — is preserved in context.md.

[INFO] Open empirical question §6.4 captured as a Phase 0 requirement, not resolved by reasoning
  Note: design spec §6.4 records that HubSpot's docs do not state whether an A→B association is readable
    B→A, and prescribes a probe procedure against a developer test account. This is not a conflict and not
    a decision to settle by reasoning, so it is not in a conflict bucket — it is captured as
    REQ-association-inverse-probe with the full procedure and as CON-association-inverse-unverified. A
    developer test account is confirmed available, so it is actionable in Phase 0. The probe sets the
    default for the `bidirectional:` parameter; per the source, the API surface is identical either way,
    so it does not block the design.

[INFO] Coverage gap found outside the ingest set: Packagist publishing
  Note: No ingested document covers how a release reaches Packagist. STANDARDS.md §12 assigns
    versioning and `CHANGELOG.md` to release-please, but release-please cuts tags and GitHub
    releases only — it does not publish. Packagist requires the GitHub App or webhook integration,
    absent from all three sources; BRIEF.md mentions Packagist once, as a status note ("not yet on
    Packagist"), and no phase in spec §13 owns publishing. Raised by the user during ingest rather
    than found in a document, so it is not a conflict between sources — it is a gap in all of them.
    Captured as REQ-release-publishing, split Phase 0 (claim the name, wire the integration, add
    `composer validate --strict` as a required check) and Phase 5 (first tagged release verified
    end to end). STANDARDS.md §12 Releases and §12b were amended to match.

[INFO] Five pre-existing contradictions were fixed before this run and are excluded
  Note: Prior to synthesis, five cross-document contradictions were found and corrected in STANDARDS.md:
    the mutation tool (now `pest --mutate` throughout, previously inconsistent with Infection), the
    coverage floor (now 95% everywhere, previously 90% in one place), the production dependency list (now
    six packages including `illuminate/support`, `illuminate/database` and `laravel/prompts`), the
    sign-off count in the section header, and the coverage figure in Definition of Done item 3. Verified
    consistent in the current sources and deliberately not re-reported. Listed so their absence from this
    report is not read as a miss.
