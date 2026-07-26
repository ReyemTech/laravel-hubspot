---
schema_version: 1
open_count: 1
waived_count: 0
fixed_count: 0
total_count: 1
last_updated: 2026-07-26T20:02:20.332Z
---

# Broken Windows Ledger

> Cross-phase defect register. `/gsd-ship` blocks while `open_count > 0`.
> Waive with `gsd-tools windows waive <id> "<reason>"` (reason required).
> Mark fixed with `gsd-tools windows fixed <id>`.

| id | phase | kind | file | line | description | status | reason | recorded_at | resolved_at |
|----|-------|------|------|------|-------------|--------|--------|-------------|-------------|
| 1 | 01 | unrun-verify | .github/workflows/ci.yml |  | vendor/bin/pest --coverage --min=95 fails on the empty src/ (PHPUnit runner warning, not a coverage-percentage evaluation); resolves automatically once Phase 2 adds the first src/ file. See 01-01-SUMMARY.md Decisions Made #3. | open |  | 2026-07-26T20:02:20.332Z |  |

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
  }
]
````
