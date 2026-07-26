# Phase numbering: source documents vs this roadmap

**The source documents number the phases 0-8. `ROADMAP.md` numbers them 1-9.** The structure,
contents and ordering are identical — only the label shifts.

## Why

GSD's roadmap parser silently drops a `### Phase 0:` header. Verified 2026-07-26: a two-phase
fixture using Phase 0 and Phase 1 returned `phase_count: 1` from `roadmap.analyze`, with the zeroth
phase absent from the phase list entirely. No error, no warning. Had the roadmap been written with
a zeroth phase, `/gsd-plan-phase 0` and every downstream lookup would have silently skipped the one
phase the design spec calls non-optional.

## Why this lives in its own file

This mapping was originally a table inside `ROADMAP.md`. It had to move: `gsd-tools query
init.milestone-op` counts phase references with a looser pattern than `roadmap.analyze` uses, so the
literal zeroth-phase labels in the mapping table were counted as a real phase. `ROADMAP.md` reported
`phase_count: 10` against nine actual phases, while `roadmap.analyze` reported 9.

That mattered beyond tidiness. The autonomous workflow uses `phase_count` to decide
`all_phases_complete`, so a phantom tenth phase would have meant the milestone never registered as
finished and the audit → complete → cleanup lifecycle never fired.

Keep the zeroth-phase label out of `ROADMAP.md`. It is safe here.

## The mapping

| Core spec §13 / intel | Signals spec §15 | ROADMAP.md | Name |
|---|---|---|---|
| 0 | 1 | **Phase 1** | Foundation & Gates |
| 1 | 2 | **Phase 2** | Gateway Layer |
| 2 | 3 | **Phase 3** | Registry & Stores |
| 3 | 4 | **Phase 4** | Model Sync |
| 4 | 5 | **Phase 5** | Inbound Webhooks |
| — | 6 | **Phase 6** | Signals Core |
| — | 7 | **Phase 7** | Signal Stores & Attribution |
| — | 8 | **Phase 8** | Frontend & Meetings Embed |
| 5 | 9 | **Phase 9** | Adoption & Release |

Anywhere the core design spec, the signals spec, `BRIEF.md`, `STANDARDS.md` or `.planning/intel/`
refers to the zeroth phase, read **Phase 1**.

The signals spec §15 table already uses 1-9, so it agrees with `ROADMAP.md` everywhere except the
label on the first phase, which its prose calls the zeroth. Both mean **Phase 1**.

Standard GSD numbering otherwise applies: integer phases are planned milestone work; decimal phases
(e.g. 2.1) are urgent insertions and execute between their surrounding integers.
