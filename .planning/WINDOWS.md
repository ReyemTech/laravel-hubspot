---
schema_version: 1
open_count: 3
waived_count: 0
fixed_count: 0
total_count: 3
last_updated: 2026-07-26T20:36:32.666Z
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
  }
]
````
