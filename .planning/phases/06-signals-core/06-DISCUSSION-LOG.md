# Phase 6: Signals Core - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-08-11
**Phase:** 6-Signals Core
**Areas discussed:** Subject resolution, Flush triggering & schedule, Map validation at boot, Identity binding rules

---

## Subject resolution

### How should FlushSignalsJob resolve a bound model to the HubSpot record?

| Option | Description | Selected |
|--------|-------------|----------|
| Upsert on `id_property` | Read object type + `id_property` from the existing `hubspot.models` config key and upsert through `ObjectGateway`. Zero coupling to `Sync`, not even an inversion. Accepts that a flush can create a contact. | ✓ |
| Inversion contract | `Signals` declares the port, `HubspotManager` implements it by reading Sync's link table. Precedent exists twice, but a subject Sync never synced would have no record. | |
| Signals declares subjects | A signals-owned config block mapping subject model to object type and id property. Duplicates `hubspot.models`. | |

**User's choice:** Upsert on `id_property` (recommended)
**Notes:** Decisive argument was that a *config key* is not a `Sync` *class*, so the
namespace-based architecture tests stay green while D-35 is satisfied outright. The
create-a-contact side effect was accepted after establishing that anonymous rows never flush —
`subject_type`/`subject_id` stay null until `identify()` — so only explicitly identified people
reach HubSpot, which also defuses the HubSpot marketing-contact billing concern.

### What happens when the subject's `id_property` value is missing or blank?

| Option | Description | Selected |
|--------|-------------|----------|
| Throw at `identify()` | Fail fast in the caller's stack. `identify()` issues no HTTP so throwing is cheap. | ✓ |
| Skip at flush, leave unflushed | Self-heals if the email later appears, but the failure is invisible and rows accumulate. | |
| Skip and mark flushed | Keeps the buffer small by silently discarding attribution. | |

**User's choice:** Throw at `identify()` (recommended)
**Notes:** Framed against Phase 5's acknowledged-then-lost failure shape — an error surfacing hours
later in a worker log, detached from the call that caused it.

### What if a signal's map `object` differs from the subject's bound object type?

| Option | Description | Selected |
|--------|-------------|----------|
| Refuse the mismatch | `ConfigurationException` naming both sides; largely boot-checkable since every map `object` must be claimed by some bound model. | ✓ |
| Allow multi-object subjects | One batch write per object type. Breaks SIG-06's one-write proof and needs a record-resolution mechanism Phase 6 lacks. | |
| Subject's binding always wins | Ignore the map's `object` key, making a documented key decorative and letting a typo pass silently. | |

**User's choice:** Refuse the mismatch (recommended)
**Notes:** Multi-object subjects were captured as a deferred idea for Phase 7 rather than dropped.

---

## Flush triggering & schedule

### Who owns the recurring flush?

| Option | Description | Selected |
|--------|-------------|----------|
| Ship a command, app schedules it | `hubspot:signals:flush` plus one documented scheduler line; the package registers nothing. | ✓ |
| Package registers the schedule | Works with no setup, but a library silently adds recurring work and takes overlap/queue/env decisions. | |
| Identify-only, no schedule | Simplest, but signals recorded after identification may never flush. | |

**User's choice:** Ship a command, app schedules it (recommended)
**Notes:** Matches the existing `hubspot:webhooks:prune` precedent — verified that the package
registers no schedule anywhere today — and preserves the inert-by-default posture.

### What is the unit of work when a flush finds many pending subjects?

| Option | Description | Selected |
|--------|-------------|----------|
| Batch across subjects, chunk at 100 | Reuses Phase 4's chunked transport and Phase 2's 207-as-partial-success handling. | ✓ |
| One job per subject | Clean isolation, but N requests — the shape SIG-06's `assertRequestCount` forbids. | |
| One request per flush, hard cap | Strictest reading, but a backlog can outrun the schedule and never drain. | |

**User's choice:** Batch across subjects, chunk at 100 (recommended)
**Notes:** SIG-06's "one batch property write (not N)" is genuinely ambiguous; this reading keeps
"not N" true as volume grows rather than only at low volume.

### What stops an overlapping or retried flush double-appending the trail?

| Option | Description | Selected |
|--------|-------------|----------|
| Idempotent by buffer row identity | Trail entry keyed on the `hubspot_signals` row id; `reconcile` gated on `reconciled_at`; roll-ups already absolute. No lease. | ✓ |
| Claim/lease like the webhook store | Proven in-repo, but adds a tunable timeout, and the store's own docblock notes a lease buys exclusion, not a lock. | |
| Rely on `withoutOverlapping()` | Zero code, but an `identify()`-triggered flush still races the scheduled one. | |

**User's choice:** Idempotent by buffer row identity (recommended)
**Notes:** Explicitly the lesson this codebase paid for in Phase 5 the same day — deduplication
keyed on a delivery identity rather than on timing. Correctness by construction over coordination.

---

## Map validation at boot

### Where does validation actually run?

| Option | Description | Selected |
|--------|-------------|----------|
| At boot, guarded by `signals.enabled` | Honours SIG-03 literally; zero cost when signals are off. | ✓ |
| At the entry points only | Closer to Phase 5's `HandlerMap` precedent, but a typo in an unused signal name stays hidden. | |
| Boot validation plus an artisan check | Redundant once boot throws; extra surface. | |

**User's choice:** At boot, only when signals are enabled (recommended)
**Notes:** Deliberately diverges from Phase 5's `HandlerMap::validate()`-at-job-time decision, on
the grounds that a bad handler map costs one webhook claim whereas a bad signal map silently drops
buffered attribution. The divergence is recorded so it does not read as an inconsistency later.

### The spec's closure-in-config breaks `config:cache` — what replaces it?

| Option | Description | Selected |
|--------|-------------|----------|
| Invokable class-string | `'intent_score' => IntentScore::class`; config stays serializable and the rule is unit-testable without booting config. | ✓ |
| Keep closures, document the limit | Faithful to the spec, but trades a standard deploy step for syntax sugar. | |
| Map moves out of config entirely | Preserves the spec's syntax but gives signals a bespoke configuration story. | |

**User's choice:** Invokable class-string (recommended)
**Notes:** Raised as a genuine flaw in the spec rather than routed around. Laravel cannot serialize
a closure in a config file. The pattern is legal for `$hubspotMap` because those closures live on a
model class; moving it into `config/hubspot.php` is what breaks it. Recorded as requiring a spec
amendment to §6, SIG-03 and SIG-04.

---

## Identity binding rules

### Can one subject be bound to several visitor ids?

| Option | Description | Selected |
|--------|-------------|----------|
| Yes, many visitor ids per subject | Roll-ups compute across the union, so `first_wins` picks the genuinely earliest touch across devices. | ✓ |
| One visitor id per subject | Symmetric and strict, but throws on ordinary cross-device behaviour. | |
| Yes, but last binding wins | No exceptions, but discards the earlier device — usually the first touch. | |

**User's choice:** Yes, many visitor ids per subject (recommended)
**Notes:** The visitor-side rule stays asymmetric and unchanged: one visitor id binding to a
*different* subject still throws (SIG-05). Shared-device merging is accepted as the app's
visitor-id problem, consistent with D9 putting visitor-id issuance on the app.

### What set of rows does `RollUpCalculator` compute over?

| Option | Description | Selected |
|--------|-------------|----------|
| All rows for the subject, flushed included | The only reading consistent with D-40's absolute values; `flushed_at` never an input to the maths. | ✓ |
| Only unflushed rows | Bounded query, but `increment`/`sum` restart at zero and overwrite HubSpot with a partial value. | |
| Materialise a running roll-up | Solves prune-safety and gives an O(1) read, but adds an unscoped second table. | |

**User's choice:** All rows for the subject, flushed included (recommended)
**Notes:** Surfaced a cross-phase constraint: because roll-ups read flushed rows, Phase 7's
`hubspot:signals:prune` must not delete identified rows, or must materialise the roll-up first.
`retention_days` applies cleanly to unidentified rows, which is where unbounded growth comes from.

---

## Claude's Discretion

- Unbound subject passed to `identify()` throws, mirroring `ModelBindings::for()`'s
  `unboundSyncModel()` precedent.
- `ConfigurationException` / `SignalException` message wording, per STANDARDS §9.
- Queue retry and backoff, following existing queued-job conventions.
- The `local` `SignalStore` driver's table shape beyond the unique key on the source row id.
- `first_wins` tie-break when two signals share an `occurred_at` — not discussed; the planner picks
  a deterministic rule and states it.

## Deferred Ideas

- Multi-object subjects (one subject, several object types) — Phase 7, alongside company-level
  attribution.
- `hubspot:signals:check` artisan command for CI — redundant once boot validation throws.
- Materialised per-subject roll-up table — the natural answer if Phase 7 finds the prune constraint
  too restrictive.
- Boot-time validation against the portal's property schema — would need a portal read, which boot
  must never do.
