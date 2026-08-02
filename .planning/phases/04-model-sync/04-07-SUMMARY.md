# 04-07 — Two escape hatches, and Octane

_Completed 2026-08-02. PR #56._

## What shipped

`Sync\SyncGate` answers one question — may a sync reach HubSpot right now — with three separately
observable operands:

1. `hubspot.disabled`, the environment-level kill switch.
2. An open `withoutSyncing()` block, in-process.
3. The testing environment with no fake bound.

It is consulted at **dispatch** (in `HubspotObserver`) and again on the **worker** (in both jobs).

`HubspotManager::withoutSyncing()` saves and RESTORES the previous value in a `finally`. Both halves
are load-bearing and have their own named test: clearing rather than restoring un-suppresses at an
inner call's exit while an outer block is still running, and a throwing callback must restore state
on its way out with its exception unchanged.

Suppression stops the **dispatch**, not the request. Refusing at the far end would still queue every
job and leave a backlog that fires the moment the worker drains — the failure a seeder is protected
from, not a tidier version of it.

## R3 forbade what the plan asked for

`SyncGate` needs `HubspotManager`, which is the root namespace; R3 permits `Sync` to depend on
`Registry`, `Gateway`, the package exceptions and `Illuminate`, and nothing else.

**The arrow was inverted rather than the rule relaxed.** `Sync` declares `SyncStateContract`,
`HubspotManager` implements it, `ServiceProvider` binds the two. Widening R3 would have been the easy
fix and the wrong one — the boundary exists so `Sync` cannot quietly grow a dependency on the
package's entry point, and a rule relaxed once to unblock a plan stops meaning anything.

## The plan's must-have overstated the kill switch

> "checked at dispatch AND again on the worker, so a job queued before the switch was flipped does
> not fire either"

That is true only once the worker's config reflects the flip. `Config::get()` re-reads the running
PROCESS's repository, so a `queue:work` daemon keeps what it booted with; editing `.env` alone
reaches nothing already running, and with cached config it reaches nothing until rebuilt.

Flipping the switch means **both** steps:

```
HUBSPOT_DISABLED=true        # + php artisan config:cache if config is cached
php artisan queue:restart
```

The smaller promise is the true one and is now documented in `SyncGate`, `config/hubspot.php` and the
tests. What the worker re-check genuinely buys is unchanged: it stops jobs **already sitting on the
queue** as restarted workers drain them, and covers `queue:listen`, the `sync` driver and
`queue:work --once`.

## Octane is supported, by owner decision

Octane is first-party Laravel tooling, so "we do not support it" is not a position this package may
take by omission — which is what it was doing. Issue #55, closed here.

The entire exposure is **state on container singletons**. On PHP-FPM a process handles one request
and dies; Octane keeps the worker alive, so anything mutable on a singleton becomes the next
request's starting point. `HubspotManager` is the only singleton holding any.

- `flushState()` returns it to a freshly-booted state.
- `ServiceProvider` listens on `RequestTerminated`, `TaskTerminated`, `TickTerminated` — **by
  class-string**, since `laravel/octane` is not a dependency and D-03's allow-list would not admit
  one. Laravel's dispatcher keys listeners on the event class name, so a string registration fires
  for the real object and costs nothing when Octane is absent.
- **Termination, never reception.** Resetting on `*Received` destroys state prepared FOR the incoming
  work — a boot-installed fake above all — and in the testing environment the consequence is silent
  and total.

`STANDARDS.md` §1 carries the commitment and the rule it implies: **no container singleton this
package binds may hold mutable state unless it resets that state at Octane's boundaries.** It also
states what is not closed: parallel coroutines inside one request would still share it.

## Review: six rounds, one root cause

Eight findings. Setting aside the facade one, **every remaining finding was the same defect**: a
lifecycle implemented in two places, where one was updated and the other drifted.

| round | finding | the two places |
|---|---|---|
| 1 | suppressed archive stranded `archived_at` | `archive()` withdrew on failure; the job did not withdraw at all |
| 2 | suppression stopped `flagStale()` too | the gate belonged at the dispatch, not the whole handler |
| 3 | suppressed archive left `is_stale` set | `archive()` restored the snapshot; the job restored only the marker |
| 3 | `flushState()` discarded a custom transport | `HubspotFake` installs it; `flushState()` removed it blindly |
| 5 | reset ran on `*Received` | cleanup placed before the work instead of after |
| 6 | `STANDARDS.md` and `SyncGate`'s table | code fixed, prose not swept |

Each fix was correct in isolation; none prevented the next. **Issue #57** exists to give the archive
marker and the fake transport one owner each, and carries the round-5 redelivery case that cannot be
fixed without deciding what `archived_at` means.

Two findings were deliberately NOT fixed here, both recorded with reasoning: the redelivery window
(#57) and coroutine-local state (STANDARDS §1).

## Fallout

The testing-environment default did not exist while 04-02 … 04-06 were written. It silenced **14
tests across 3 files** that asserted dispatch through `Bus::fake()` without binding a HubSpot fake:
`AutoSyncBootTest`, `Unit/Sync/HubspotObserverTest`, `TracerCorrectnessTest`. Every one was fixed by
binding a fake — never by loosening the gate.

`illuminate/container` became a declared production require, admitted by D-02 and demanded by
`ComposerManifestTest`, because `resolved()` lives on the concrete container. `App::resolved()` is
the FACADE's own static method for registration callbacks, not a proxy — that mistake failed 349
tests before the signature was read.

## Numbers

**853 tests, 3056 assertions, 100.0% coverage.** CI-scoped MSI 89.30%; 100.00% over `SyncGate` and
`HubspotManager` alone. Every new test was verified RED against the unfixed code before being
trusted.

## Next

**04-08** — `Model::syncManyToHubspot()`: one job, one `upsertMany()`, one request. **04-09** —
`hubspot:doctor`'s bound-model section and the phase close-out. **#57** is worth taking before
either, since it removes the defect shape that produced six of this plan's eight findings.
