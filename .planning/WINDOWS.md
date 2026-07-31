---
schema_version: 1
open_count: 3
waived_count: 0
fixed_count: 5
total_count: 8
last_updated: 2026-07-31T17:10:00.000Z
---

# Broken Windows Ledger

> Cross-phase defect register. `/gsd-ship` blocks while `open_count > 0`.
> Waive with `gsd-tools windows waive <id> "<reason>"` (reason required).
> Mark fixed with `gsd-tools windows fixed <id>`.

| id | phase | kind | file | line | description | status | reason | recorded_at | resolved_at |
|----|-------|------|------|------|-------------|--------|--------|-------------|-------------|
| 1 | 01 | unrun-verify | .github/workflows/ci.yml |  | vendor/bin/pest --coverage --min=95 fails on the empty src/ (PHPUnit runner warning, not a coverage-percentage evaluation); resolves automatically once Phase 2 adds the first src/ file. See 01-01-SUMMARY.md Decisions Made #3. | open |  | 2026-07-26T20:02:20.332Z |  |
| 2 | 01 | unrun-verify | .github/workflows/quality.yml |  | mutation job (pest --mutate --min=80) cannot compute a real MSI over the deliberately-empty src/; WARN+exit1 via failOnPhpunitWarning, resolves once Phase 2 adds source files (mirrors plan 01's coverage-floor finding) | open |  | 2026-07-26T20:36:32.352Z |  |
| 3 | 01 | deviation | tests/Arch/LayerBoundariesTest.php |  | Concurrent git staging in a shared working directory caused commit 022b9e6 (plan 05) to accidentally include three of plan 04's task-2 files; content correct, but plan 04 lacks its own dedicated GREEN commit for the ten rules in git history | open |  | 2026-07-26T20:36:32.666Z |  |
| 4 | 04 | deviation | src/Sync/SyncHubspotObjectJob.php |  | getHubspotUpdateMap() added to SyncsToHubspot and wired into SyncHubspotObjectJob, so $hubspotUpdateMap is honoured end to end. Codex raised the deferral as a P1 on PR #42 -- passing [] made mapForUpdate() fall back to the full create map, silently overwriting the properties a consumer declared the update map to protect | fixed | Closed in 04-03 rather than deferred to 04-04; shipping a documented option that does nothing is worse than the small scope extension. | 2026-07-31T02:25:33.228Z | 2026-07-31T04:10:00.000Z |
| 5 | 04 | deviation | src/Sync/SyncsToHubspot.php |  | The three SyncsToHubspot query scopes could not see hubspot_object_links when the bound model was on another connection: whereHas() compiles its existence subquery into the PARENT statement, so the parent's connection resolved an unqualified hubspot_object_links in its own database and raised a missing-table error -- while hubspotLink/hubspotId(), pinned to the link connection by PR #39, kept answering correctly. Codex raised it as a P2 on PR #44. Each scope now branches: shared connection keeps whereHas() unchanged, different connections resolve the same relation on the link table's own connection via Relation::noConstraints() and constrain the parent by key. | fixed | Fixed in 04-04 rather than deferred or documented as a limitation: PR #39 already committed the read surface to surviving the connection split this package itself creates, and leaving the scopes out would make that surface half-working by design. | 2026-07-31T14:30:00.000Z | 2026-07-31T14:30:00.000Z |
| 6 | 04 | deviation | scripts/ci/composer-retry.sh |  | Eight PR #44 checks failed at once in their Install dependencies step on a single packagist HTTP 502 for symfony/clock.json, and a plain re-run reproduced it. composer.lock is gitignored (correct for a library), so every job resolves from packagist with no offline path -- a packagist wobble takes out the whole board. scripts/ci/composer-retry.sh now fronts all ELEVEN dependency invocations (2 in ci.yml -- one install, one matrix update -- 5 in quality.yml, 2 in arch.yml, 2 in supply-chain.yml) with four attempts and doubling backoff, self-tested in the one job that installs no dependencies. Corrected from 'ten' after Codex flagged the miscount as a P3 on PR #44. | fixed | Fixed rather than waited out: STANDARDS.md Sec.12's merge rule is green or it does not merge, and 'not our code' does not make a branch mergeable. | 2026-07-31T15:05:00.000Z | 2026-07-31T15:05:00.000Z |
| 7 | 04 | deviation | src/Sync/SyncsToHubspot.php |  | The cross-connection scope branch read the link table when the scope was CALLED, not when the builder ran, so a builder that looked lazy had already queried on construction and a link row written between construction and execution was invisible -- pendingHubspotSync() kept reporting work already done. Codex raised it as a P2 on PR #44 against the fix for entry 5. All three scopes now register their constraint through Query\\Builder::beforeQuery(), whose callbacks run inside toSql() on every execution path. Two statements still leave an inherent window; what is removed is the unbounded, caller-controlled part. | fixed | Fixed rather than documented: a scope that queries on construction breaks the laziness every other Eloquent scope has, and the widened window is caller-controlled. | 2026-07-31T16:20:00.000Z | 2026-07-31T16:20:00.000Z |
| 8 | 04 | deviation | src/Sync/SyncsToHubspot.php |  | Deferring the cross-connection link resolution (entry 7) fixed WHEN it ran but moved WHERE the constraint landed: beforeQuery() appends, so syncedToHubspot()->orWhere('email', ...) became email = ? AND id IN (...) instead of (link) OR email = ?, silently dropping the unlinked row. The shared-connection branch kept the caller's meaning and the cross-connection one did not -- the two branches answering differently. Codex raised it as a P2 on PR #44. The constraint is now spliced in at the position recorded when the scope was called, clauses and bindings together, pinned by position tests on BOTH connections. | fixed | Fixed rather than kept as the documented caveat entry 7 left it: a silent wrong-results divergence between the two branches is not a nuance. | 2026-07-31T17:10:00.000Z | 2026-07-31T17:10:00.000Z |

````json
[
  {
    "id": 1,
    "kind": "unrun-verify",
    "phase": "01",
    "file": ".github/workflows/ci.yml",
    "line": null,
    "description": "vendor/bin/pest --coverage --min=95 fails on the empty src/ (PHPUnit runner warning, not a coverage-percentage evaluation); resolves automatically once Phase 2 adds the first src/ file. See 01-01-SUMMARY.md Decisions Made #3.",
    "status": "open",
    "reason": "",
    "recorded_at": "2026-07-26T20:02:20.332Z",
    "resolved_at": null
  },
  {
    "id": 2,
    "kind": "unrun-verify",
    "phase": "01",
    "file": ".github/workflows/quality.yml",
    "line": null,
    "description": "mutation job (pest --mutate --min=80) cannot compute a real MSI over the deliberately-empty src/; WARN+exit1 via failOnPhpunitWarning, resolves once Phase 2 adds source files (mirrors plan 01's coverage-floor finding)",
    "status": "open",
    "reason": "",
    "recorded_at": "2026-07-26T20:36:32.352Z",
    "resolved_at": null
  },
  {
    "id": 3,
    "kind": "deviation",
    "phase": "01",
    "file": "tests/Arch/LayerBoundariesTest.php",
    "line": null,
    "description": "Concurrent git staging in a shared working directory caused commit 022b9e6 (plan 05) to accidentally include three of plan 04's task-2 files; content correct, but plan 04 lacks its own dedicated GREEN commit for the ten rules in git history",
    "status": "open",
    "reason": "",
    "recorded_at": "2026-07-26T20:36:32.666Z",
    "resolved_at": null
  },
  {
    "id": 4,
    "kind": "deviation",
    "phase": "04",
    "file": "src/Sync/SyncHubspotObjectJob.php",
    "line": null,
    "description": "getHubspotUpdateMap() added to SyncsToHubspot and wired into SyncHubspotObjectJob, so $hubspotUpdateMap is honoured end to end. The deferral was raised as a P1 by Codex on PR #42: passing [] made mapForUpdate() fall back to the full create map, silently overwriting the properties the update map existed to protect.",
    "status": "fixed",
    "reason": "Closed in 04-03 rather than deferred to 04-04; shipping a documented option that does nothing is worse than the small scope extension.",
    "recorded_at": "2026-07-31T02:25:33.228Z",
    "resolved_at": "2026-07-31T04:10:00.000Z"
  },
  {
    "id": 5,
    "kind": "deviation",
    "phase": "04",
    "file": "src/Sync/SyncsToHubspot.php",
    "line": null,
    "description": "The three SyncsToHubspot query scopes could not see hubspot_object_links when the bound model was on another connection: whereHas() compiles its existence subquery into the PARENT statement, so the parent's connection resolved an unqualified hubspot_object_links in its own database and raised a missing-table error -- while hubspotLink/hubspotId(), pinned to the link connection by PR #39, kept answering correctly. Codex raised it as a P2 on PR #44. Each scope now branches: shared connection keeps whereHas() unchanged, different connections resolve the same relation on the link table's own connection via Relation::noConstraints() and constrain the parent by key.",
    "status": "fixed",
    "reason": "Fixed in 04-04 rather than deferred or documented as a limitation: PR #39 already committed the read surface to surviving the connection split this package itself creates, and leaving the scopes out would make that surface half-working by design.",
    "recorded_at": "2026-07-31T14:30:00.000Z",
    "resolved_at": "2026-07-31T14:30:00.000Z"
  },
  {
    "id": 6,
    "kind": "deviation",
    "phase": "04",
    "file": "scripts/ci/composer-retry.sh",
    "line": null,
    "description": "Eight PR #44 checks failed at once in their Install dependencies step on a single packagist HTTP 502 for symfony/clock.json, and a plain re-run reproduced it. composer.lock is gitignored (correct for a library), so every job resolves from packagist with no offline path -- a packagist wobble takes out the whole board. scripts/ci/composer-retry.sh now fronts all ELEVEN dependency invocations (2 in ci.yml -- one install, one matrix update -- 5 in quality.yml, 2 in arch.yml, 2 in supply-chain.yml) with four attempts and doubling backoff, self-tested in the one job that installs no dependencies. Corrected from 'ten' after Codex flagged the miscount as a P3 on PR #44.",
    "status": "fixed",
    "reason": "Fixed rather than waited out: STANDARDS.md Sec.12's merge rule is green or it does not merge, and 'not our code' does not make a branch mergeable.",
    "recorded_at": "2026-07-31T15:05:00.000Z",
    "resolved_at": "2026-07-31T15:05:00.000Z"
  },
  {
    "id": 7,
    "kind": "deviation",
    "phase": "04",
    "file": "src/Sync/SyncsToHubspot.php",
    "line": null,
    "description": "The cross-connection scope branch read the link table when the scope was CALLED, not when the builder ran, so a builder that looked lazy had already queried on construction and a link row written between construction and execution was invisible -- pendingHubspotSync() kept reporting work already done. Codex raised it as a P2 on PR #44 against the fix for entry 5. All three scopes now register their constraint through Query\\Builder::beforeQuery(), whose callbacks run inside toSql() on every execution path. Two statements still leave an inherent window; what is removed is the unbounded, caller-controlled part.",
    "status": "fixed",
    "reason": "Fixed rather than documented: a scope that queries on construction breaks the laziness every other Eloquent scope has, and the widened window is caller-controlled.",
    "recorded_at": "2026-07-31T16:20:00.000Z",
    "resolved_at": "2026-07-31T16:20:00.000Z"
  },
  {
    "id": 8,
    "kind": "deviation",
    "phase": "04",
    "file": "src/Sync/SyncsToHubspot.php",
    "line": null,
    "description": "Deferring the cross-connection link resolution (entry 7) fixed WHEN it ran but moved WHERE the constraint landed: beforeQuery() appends, so syncedToHubspot()->orWhere('email', ...) became email = ? AND id IN (...) instead of (link) OR email = ?, silently dropping the unlinked row. The shared-connection branch kept the caller's meaning and the cross-connection one did not -- the two branches answering differently. Codex raised it as a P2 on PR #44. The constraint is now spliced in at the position recorded when the scope was called, clauses and bindings together, pinned by position tests on BOTH connections.",
    "status": "fixed",
    "reason": "Fixed rather than kept as the documented caveat entry 7 left it: a silent wrong-results divergence between the two branches is not a nuance.",
    "recorded_at": "2026-07-31T17:10:00.000Z",
    "resolved_at": "2026-07-31T17:10:00.000Z"
  }
]
````
