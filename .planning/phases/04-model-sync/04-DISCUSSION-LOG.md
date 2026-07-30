# Phase 4: Model Sync - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-07-30
**Phase:** 4-Model Sync
**Areas discussed:** Sync's framework boundary (R3), The queue seam inside seven requires, What the
queued job carries, Binding surface and id-column ownership

---

## Sync's framework boundary (R3)

### How R3 should move

| Option | Description | Selected |
|--------|-------------|----------|
| Widen to declared components only | Allow-list of Illuminate roots backed by a require; R3 becomes the gate catching an undeclared dependency | |
| Widen to all Illuminate, like R2 | Mirror the merged R2 amendment; one rule shape, no allow-list | ✓ |
| Keep R3 closed, invert with ports | Package-owned ports, adapters at the composition root, per 03-01 | |

**User's choice:** Widen to all Illuminate, like R2
**Notes:** Accepts by choice that the rule no longer catches an undeclared require. Prompted the
follow-up below.

### What guards the seven-require ceiling then

| Option | Description | Selected |
|--------|-------------|----------|
| A source-hygiene grep in CI | Derive allowed roots from composer.json, fail on others; extends an existing job | ✓ |
| Review discipline only | Rely on CLAUDE.md, STANDARDS §2 and Codex | |
| Declare the eighth require now | Accept illuminate/queue up front | |

**User's choice:** A source-hygiene grep in CI
**Notes:** **Later inverted** by the dependency-rule change in the next area — it now blocks
non-Illuminate vendors rather than undeclared Illuminate roots.

### Where SyncsToHubspot lives

| Option | Description | Selected |
|--------|-------------|----------|
| `Sync\SyncsToHubspot` | Beside its subsystem, inside the layer R3 governs | ✓ |
| Root `Hubspot\SyncsToHubspot` | Shortest import; matches Sanctum/Cashier single-trait packages | |
| ~~`Concerns\SyncsToHubspot`~~ | Withdrawn before the vote — see notes | — |

**User's choice:** `Sync\SyncsToHubspot`
**Notes:** The user challenged the initial ranking, observing that `Concerns\` looked like the
standard route. Checking `laravel/framework` showed the opposite: `Eloquent\Concerns\*` holds
traits the framework composes into `Model` itself (`HasAttributes`, `HasEvents`,
`HasRelationships`), while every trait a *user* applies — `SoftDeletes`, `Prunable`,
`MassPrunable`, `BroadcastsEvents` — sits one level up beside the subsystem. `Concerns\` was
withdrawn as an option rather than re-ranked, and the original recommendation was re-justified on
that evidence rather than on internal namespace consistency.

---

## The queue seam inside seven requires

### The rule for illuminate/* components

| Option | Description | Selected |
|--------|-------------|----------|
| Declare them, amend the gate | Any illuminate/* may be required; CI gate becomes a vendor allow-list | ✓ |
| Use them, don't declare them | Rely on laravel/framework; no composer.json change | |
| Keep seven, add only when forced | No rule change; contracts-only where possible | |

**User's choice:** Declare them, amend the gate
**Notes:** User's framing — *"all of standard laravel packages should be allowed .. we just don't
want to install new stuff unless absolutely needed."* This is a **project-wide rule change**
superseding `CLAUDE.md`'s seven-requires paragraph, raised as such before the vote. The third
option was flagged as genuinely viable: Phase 4 *is* buildable inside the seven, since `ShouldQueue`,
`Bus\Dispatcher` and `Queue\Factory` all live in `illuminate/contracts`.

### How much of the queue stack to declare

| Option | Description | Selected |
|--------|-------------|----------|
| Declare queue + bus, idiomatic job | Queueable, InteractsWithQueue, SerializesModels, Batchable | ✓ |
| Declare illuminate/queue only | Re-fetch and retry, no Queueable sugar, no Bus batching | |
| Declare nothing, contracts-only | Plain final class implementing ShouldQueue | |

**User's choice:** Declare queue + bus, idiomatic job
**Notes:** Verified against `laravel/framework`'s `replace` list that `illuminate/queue` and
`illuminate/bus` are split packages while `illuminate/foundation` is not — so `Dispatchable` is
permanently unavailable regardless of the rule change.

### Retry after a lost response

| Option | Description | Selected |
|--------|-------------|----------|
| Upsert on a declared idempotency property | Per-binding `id_property`; retry converges | ✓ |
| Search-before-create on retry only | Mirrors the smoke probe's sweep | |
| Create, and reconcile out of band | Accept duplicates, sweep afterwards | |

**User's choice:** Upsert on a declared idempotency property
**Notes:** The failure mode was demonstrated live on a real portal during PR #34 the same day.
`Gateway::upsert()` already ships.

### A binding with no id_property

| Option | Description | Selected |
|--------|-------------|----------|
| Throw at boot, not at runtime | ConfigurationException when the provider reads `models` | ✓ |
| Retry safe failures, stop on ambiguous ones | No config; fail loudly on ambiguity | |
| Retry anyway, sweep afterwards | Accept duplicates, ship a sweep command | |

**User's choice:** Throw at boot, not at runtime
**Notes:** Options were framed on the distinction that 429s and pre-send errors definitely did not
commit and are always safe to retry; only timeouts and 5xx are ambiguous.

---

## What the queued job carries

*Scope reduced mid-discussion: `SerializesModels` had already answered the original question, so the
user elected a short pass on the two parts that remained genuinely open.*

### When $hubspotMap closures run

| Option | Description | Selected |
|--------|-------------|----------|
| At handle, on the worker | Job carries the reference; PropertyMapper runs after re-fetch | ✓ |
| At dispatch, in the request | Job carries a resolved property array | |

**User's choice:** At handle, on the worker
**Notes:** Accepted cost — a closure reading request state sees nothing, and the property sent may
differ from the one at save time.

### The row is gone at handle time

| Option | Description | Selected |
|--------|-------------|----------|
| `deleteWhenMissingModels = true` | Worker discards the job silently | ✓ |
| Let it fail into failed_jobs | Every dropped sync recorded | |
| Delete quietly, but log it | Both, at the cost of a listener or middleware | |

**User's choice:** `deleteWhenMissingModels = true`
**Notes:** Framing was corrected by reading the framework first: `restoreModel()` calls
`firstOrFail()` and `newQueryForRestoration()` uses `newQueryWithoutScopes()`, so soft-deleted
models *are* restored and only hard-deleted rows throw — during deserialization, where `handle()`
cannot observe it.

---

## Binding surface and id-column ownership

*Two questions were withdrawn and reframed before a vote — see notes.*

### Where the local↔HubSpot id mapping lives

| Option | Description | Selected |
|--------|-------------|----------|
| Package-owned links table, gated | `hubspot_object_links`; no consumer schema touched | ✓ |
| Pull a slice of the installer into Phase 4 | Build part of SHIP-01 now | |
| Column now, doctor guides, installer later | Spec §4 as written; doctor prints the migration line | |

**User's choice:** Package-owned links table
**Notes:** The user twice challenged the framing, and both challenges were correct.

First: *"why is it a hard contract?? what would be bad about having migrations?"* — the
zero-migration constraint had been overstated. `PROJECT.md` D-14 says the package works with **no
publish step and no `migrate`**, and D-38 states outright that a gated, off-by-default migration
*"does not violate zero-migration install."* The rejected anti-example,
`spatie/laravel-webhook-client`, was rejected for *forcing* its migration on every consumer. The
real constraint was a different one that had been folded in: altering a table the package does not
own.

Second: *"on artisan hubspot:install we can determine which entities we want to be sync'd …
programatically generate the migrations and run them."* — this is SHIP-01 as already specified in
spec §11, and it is **Phase 9's**. Since spec §11 also requires `composer require` + the trait to
work with zero setup, Phase 4 must stand up without it. That is what reframed the question into
the one actually voted on.

### How consumers query by HubSpot id

| Option | Description | Selected |
|--------|-------------|----------|
| Relation + scopes on the trait | `hubspotLink`, `hubspotId()`, `whereHubspotId()` | ✓ |
| Links table canonical, optional column mirror | Denormalised copy when `id_column` is declared | |
| Links table only, no query helpers | Minimal surface | |

**User's choice:** Relation + scopes on the trait

### Which binding modes land in Phase 4

| Option | Description | Selected |
|--------|-------------|----------|
| Attached + API-only; Generated to Phase 9 | Generation is an installer function | ✓ |
| All three in Phase 4 | SYNC-01 ticks whole; Phase 9 wires prompts to it | |

**User's choice:** Attached + API-only; Generated to Phase 9
**Notes:** Means SYNC-01 needs an a/b split, following REG-01a/b and REG-04a/b.

---

## Claude's Discretion

- Column set and index strategy for `hubspot_object_links`
- Whether the R3 amendment reuses `Fixtures/R3/SyncDependsOnWebhooks.php` unchanged
- Naming of the new source-hygiene script and its violation fixture

## Deferred Ideas

- "Generated" binding mode → Phase 9 with SHIP-01
- Registry store pruning (inherited from 03-03) — sixth store operation plus a baseline
  read-through decision
- An update job arriving with `trashed() === true` after a soft delete — flagged for the planner,
  governed by SYNC-04
- `composer.lock` staleness and search sort direction — still owed their own PRs
- `BaselineAssociationTypes` typeId 1 / `Primary` — deliberately unfixed, filed in Phase 3

## Offered and not taken

The user was offered a choice on whether the five contradicting documents (`CLAUDE.md`, the manifest
CI gate, spec §4, REG-01b, SYNC-01) get amended in a housekeeping PR before planning or folded into
Phase 4's plans, and elected to proceed to context first. **That question is still open** and is
recorded in CONTEXT.md's `<amendments>` section.
