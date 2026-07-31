---
phase: 04-model-sync
plan: 05
subsystem: sync
tags: [laravel, eloquent, observer, auto-sync, soft-deletes, mutation-testing]

requires:
  - phase: 04-model-sync (04-02)
    provides: "HubspotObserver with created wired, ModelBindings, SyncHubspotObjectJob, SyncTestCase"
  - phase: 04-model-sync (04-04)
    provides: "SyncsToHubspot's optional-property reader pattern (getHubspotUpdateMap)"
provides:
  - "Sync\\HubspotObserver::updated() plus the single syncOn() gate both handlers pass through"
  - "SyncsToHubspot::getHubspotAutoSync(): array|false|null -- the per-model override reader"
  - "config/hubspot.php auto_sync block: enabled, on, queue"
  - "D-17's restore guard, in updated() where updated is first wired"
affects: [04-06 (adds hard_delete/on_restore to the same auto_sync block, owns the whole restored
  response, and reuses SoftDeletingLead), 04-07 (SyncGate's suppression sits in front of this gate),
  04-09 (hubspot:doctor reports the resolved auto_sync values)]

tech-stack:
  added: []
  patterns:
    - "The gate is three separate early returns, never one boolean expression: each operand has to
      be separately observable, which is what the three matrix tests assert and what the mutation
      floor measures. It also keeps syncOn()'s cyclomatic complexity inside the phpcs ceiling."
    - "A per-model $hubspotAutoSync list REPLACES auto_sync.on rather than intersecting with it, so
      a model can sync on an event the application-wide list omits without editing config every
      other model shares."
    - "getHubspotAutoSync() answers 'what did the model say' in three shapes -- a list, false, or
      null for said-nothing -- and deliberately does NOT fold in the config default: a reader that
      did could no longer tell `false` from silence, which is the one distinction its caller needs."
    - "A config key that nothing reads is a defect, not a placeholder: auto_sync.queue shipped
      documented and unread, and Codex rejected it on PR #48 the same way it rejected the
      $hubspotUpdateMap deferral on PR #42. Either wire it in the plan that adds it, or do not add
      it."
    - "property_exists(), never $this->prop directly, for an optional model property: reading it
      directly goes through Model::__get() and reaches the ATTRIBUTE bag, so a column of that name
      would silently pose as a declaration. Same reason getHubspotUpdateMap() uses it."

key-files:
  created:
    - tests/Feature/Sync/AutoSyncBootTest.php
    - tests/Support/Sync/SoftDeletingLead.php
    - tests/Support/Sync/NarrowedAutoSyncLead.php
    - tests/Support/Sync/DisabledAutoSyncLead.php
    - tests/Support/Sync/ArchivedLead.php
  modified:
    - src/Sync/HubspotObserver.php
    - src/Sync/SyncsToHubspot.php
    - config/hubspot.php
    - tests/Unit/Sync/HubspotObserverTest.php
    - tests/Arch/SecretLoggingTest.php

key-decisions:
  - "`true` as a $hubspotAutoSync value collapses to null (declared nothing) rather than becoming a
    third shape. 'Sync on everything in auto_sync.on' is precisely what declaring nothing already
    means, so making it distinct would put an undocumented case in front of every caller for no
    behavioural gain. Anything else non-array lands there too, deferring to the application-wide
    setting rather than guessing."
  - "ServiceProvider::boot() needed NO change -- confirmed rather than assumed, as the plan asked.
    `observe()` takes a class string and registers every method the observer defines, so `updated`
    was wired the moment the method existed."
  - "Config is read through the Config FACADE, not a constructor dependency. Injection was the
    first implementation and was reverted: HubspotObserver is public API of a released package, and
    roave/backward-compatibility-check -- live since 0.4.0, with no advisory opt-out by design --
    counts a new REQUIRED constructor argument as a break. The facade resolves against the same
    container per call, so a per-test config()->set() is picked up exactly as an injected repository
    would be, and Illuminate\\Support\\Facades\\Config ships in illuminate/support, which R3 and the
    vendor-namespace gate already admit -- the argument SyncsToHubspot makes for the App facade.
    The Dispatcher stays injected: it was there before 0.4.0, so it costs nothing."

patterns-established:
  - "When a mutant survives, ask whether the code it mutates is a behaviour before writing a test to
    kill it. Two of this plan's three survivors were array_values() calls in front of in_array(),
    which ignores keys -- the mutants survived because deleting the calls changes no answer. The
    fix was deleting them, not testing them. The third WAS a behaviour and got a test."

requirements-completed: [SYNC-03b]

coverage:
  - id: D1
    description: "A bound model created in a Testbench app with an untouched AppServiceProvider
      dispatches the sync job -- the provider's boot is the whole registration"
    requirement: "SYNC-03b"
    verification:
      - kind: unit
        ref: "tests/Feature/Sync/AutoSyncBootTest.php#test_a_bound_model_dispatches_on_created_with_nothing_in_the_consumer_app"
        status: pass
    human_judgment: false
  - id: D2
    description: "auto_sync.enabled, auto_sync.on membership and the per-model $hubspotAutoSync each
      flip the outcome ALONE, with the other two left at values that would allow the dispatch"
    requirement: "SYNC-03b"
    verification:
      - kind: unit
        ref: "tests/Feature/Sync/AutoSyncBootTest.php#test_auto_sync_enabled_false_alone_stops_the_dispatch"
        status: pass
      - kind: unit
        ref: "tests/Feature/Sync/AutoSyncBootTest.php#test_an_event_absent_from_auto_sync_on_alone_stops_the_dispatch"
        status: pass
      - kind: unit
        ref: "tests/Feature/Sync/AutoSyncBootTest.php#test_a_per_model_array_override_alone_narrows_to_its_listed_events"
        status: pass
      - kind: unit
        ref: "tests/Feature/Sync/AutoSyncBootTest.php#test_a_per_model_false_override_disables_only_that_model"
        status: pass
    human_judgment: false
  - id: D3
    description: "A model event pushes a job and issues ZERO HTTP requests, both asserted in the
      same test (STANDARDS §11, T-04-19)"
    requirement: "SYNC-03b"
    verification:
      - kind: unit
        ref: "tests/Feature/Sync/AutoSyncBootTest.php#test_a_model_event_pushes_a_job_and_issues_no_http_request"
        status: pass
    human_judgment: false
  - id: D4
    description: "Restoring a soft-deleted bound model dispatches no property push (D-17), asserted
      against a fixture that declares no override so updated is genuinely enabled for it"
    requirement: "SYNC-03b"
    verification:
      - kind: unit
        ref: "tests/Feature/Sync/AutoSyncBootTest.php#test_restoring_a_soft_deleted_model_dispatches_no_property_push"
        status: pass
    human_judgment: false
  - id: D5
    description: "Full suite green, coverage 100%, scoped mutation 100%, PHPStan/phpcs/pint clean,
      architecture rules all fire"
    verification:
      - kind: other
        ref: "vendor/bin/pest (766 passed, 2844 assertions)"
        status: pass
      - kind: other
        ref: "vendor/bin/pest --coverage --min=100 (100.0%)"
        status: pass
      - kind: other
        ref: "vendor/bin/pest --mutate --parallel --min=80 --class=HubspotObserver,SyncsToHubspot (100.00%, 71/71)"
        status: pass
      - kind: other
        ref: "bash scripts/ci/verify-arch-rules-fire.sh (10/10 rules fired)"
        status: pass
    human_judgment: false

duration: ~1h
completed: 2026-07-31
status: complete
---

# 04-05 — Auto-sync event surface

## The gate's resolution order

`HubspotObserver::syncOn()`, in exactly this order, each step able to refuse on its own:

1. **`hubspot.auto_sync.enabled !== true`** → refuse. The kill switch for auto-sync as a whole.
2. **The event is not in the model's applicable list** → refuse. That list is:
   - the model's own `$hubspotAutoSync` when it is an array (REPLACING `auto_sync.on`, not
     intersecting with it),
   - the empty list when the model declared `false`,
   - `hubspot.auto_sync.on` when the model declared nothing.
3. Resolve the binding by `get_class($model)` — throws `unboundSyncModel()` if somehow unbound.
4. Dispatch `SyncHubspotObjectJob` — via `dispatchSync()` when `auto_sync.queue` is `false`,
   otherwise `dispatch()` with `afterCommit()`. `afterCommit()` is deliberately absent from the
   synchronous branch: it defers a queue PUSH until commit, and there is no push to defer.

`updated()` additionally returns early when `getOriginal('deleted_at') !== null`, BEFORE reaching
the gate — a restore is not an update (D-17).

## The exact `auto_sync` default array

```php
'auto_sync' => [
    'enabled' => (bool) env('HUBSPOT_AUTO_SYNC', true),
    'on' => ['created', 'updated'],
    'queue' => true,
],
```

**04-06 adds `hard_delete` and `on_restore` to this same block.** No delete event appears in `on`,
deliberately: archiving a HubSpot record is not reversible through the API, so a local delete
removing CRM history has to be asked for rather than inherited from a default. 04-09's doctor
reports the resolved values of all of these.

## Review findings closed on PR #48

**Codex P2 — `auto_sync.queue` was dead configuration.** The key was documented in
`config/hubspot.php` and read by nothing, so a consumer setting `false` got no change and a config
file that said otherwise. Same defect shape as the `$hubspotUpdateMap` deferral Codex rejected on
PR #42. Now honoured: `false` dispatches through `Dispatcher::dispatchSync()`, and `afterCommit()`
is deliberately absent from that branch because there is no queue push to defer. This is the ONE
way an outbound call reaches a request lifecycle, which is why STANDARDS §11's contract is stated of
the DEFAULT rather than of every configuration. Both directions are tested, plus the absent-key
default -- an upgrade whose published config predates the key must keep queueing.

**Codex P2 — D-17's guard hardcoded `deleted_at`.** `SoftDeletes::getDeletedAtColumn()` returns
`static::DELETED_AT` when the model defines the constant, so a model soft-deleting on `archived_at`
is ordinary supported configuration. Against one, the guard read null, fell through, and pushed
properties on every restore — the exact failure D-17 exists to prevent, made invisible by testing
only against the default column. The column is now resolved through `getDeletedAtColumn()`, narrowed
to a string first (`getOriginal(null)` returns the whole original attribute array, which is never
null, so an unnarrowed value would make the guard swallow every update on every model). `ArchivedLead`
is the fixture; `SoftDeletingLead` stays on the default column, because the guard has to hold for
both and one fixture can only be one of them.

**Codex P2 — `method_exists()` is a name check, not a contract.** Having resolved the delete column
through `getDeletedAtColumn()`, the guard still decided WHETHER to ask by whether a method of that
name existed. Two misfires on a model that never used `SoftDeletes`: one defining that method for
unrelated reasons had its ordinary updates silently suppressed whenever the named attribute had a
non-null original, and a NON-PUBLIC method of that name raised `BadMethodCallException` from inside
an event handler. Codex predicted "Call to protected method"; the actual mechanism is `Model::__call`
intercepting (Eloquent defines it, so PHP routes there rather than raising a visibility error) and
forwarding to the query builder — verified by probe, and the conclusion holds either way.

The guard now asks `hasGlobalScope(SoftDeletingScope::class)`, which `SoftDeletes::bootSoftDeletes()`
registers and nothing else does. Chosen over `class_uses_recursive()` because it needs no widening of
R3's allow-list — `SoftDeletingScope` is a namespaced `Illuminate` class already admitted, while the
helper is a bare global function that would need its own entry the way `data_get` did — and it holds
for a model inheriting the trait from a parent, since booting registers the scope.

**Codex P2 — the restore guard suppressed any save on a trashed row.** D-17 suppresses a RESTORE,
not "any update while soft-deleted", and both have a non-null ORIGINAL delete column. Editing a
trashed record therefore dropped its configured `updated` sync silently. What separates them is the
TRANSITION: a restore nulls the current column while its original holds the delete timestamp. The
guard now requires both.

**Codex P2 — `method_exists()` again, on the other contract.** The `getDeletedAtColumn()` fix left
the identical name-only check on `getHubspotAutoSync()`. Both now go through one
`modelUses($model, $trait)` helper over `class_uses_recursive()`, which asks the actual question.
That required adding the bare function to R3's allow-list, on the same footing as `data_get` in
04-03. An earlier revision used `hasGlobalScope(SoftDeletingScope::class)` for the SoftDeletes half
specifically to AVOID that widening; once the second finding forced it anyway, one mechanism
answering one question for both traits beats two answering it differently — and `class_uses()` alone
was not an option, since it ignores parent classes and a model inheriting either trait from an
abstract base is ordinary.

**The backward-compatibility gate, live for the first time.** `HubspotObserver` originally took the
config repository as a fourth constructor argument, and `roave/backward-compatibility-check` counts
a new REQUIRED constructor argument as a break. That gate deliberately has no advisory opt-out
(supply-chain.yml: "no unconditional success-on-failure step"), and 0.4.0 is the first release it
had a base to compare against -- so this plan is the first change it has ever measured.

Config is therefore read through the `Config` FACADE and the constructor is unchanged. An optional
fourth argument with a container fallback would also have satisfied roave, and was rejected: the
fallback branch is only reachable by constructing the observer by hand, so it would have been
untestable defensive code -- the exact thing this plan already deleted two of.

## Deviations

**1. [Plan] Three support models rather than the one named**
- **Named:** `tests/Support/Sync/SoftDeletingLead.php`.
- **Also created:** `NarrowedAutoSyncLead.php`, `DisabledAutoSyncLead.php`.
- **Why:** `$hubspotAutoSync` is a class property. Its two documented forms — a narrowing array and
  `false` — cannot be exercised through config, and cannot share a class with a model that must
  behave normally elsewhere. `SoftDeletingLead` in particular must declare NO override, or the
  restore-guard test would pass while proving nothing. The alternative was declaring extra classes
  inside the test file, which this repository does not do anywhere in `tests/Support/`.

**2. [Rule 1 — Bug] R10's config walker threw on the first list-valued config key**
- **Found during:** task 2, running the full suite after adding the `auto_sync` block.
- **Issue:** `tests/Arch/SecretLoggingTest.php` recursed into every nested config array and threw
  `hubspot.auto_sync.on contains a non-string key`, on the strength of a docblock asserting that
  "a config file's own arrays are always string-keyed by construction". That was true until this
  plan, and `auto_sync.on => ['created', 'updated']` is the shape the design spec §7 specifies.
- **Fix:** a list is treated as a LEAF. The walker exists to enumerate config KEY paths so R10 can
  check them against secret-looking names, and a list has no keys to check — recursing would
  produce `hubspot.auto_sync.on.0`, which is not a key anybody can set. The path itself is still
  recorded, so the name `on` is checked like any other. `ensure_string_keyed_array()` still throws
  for a non-string key in a MAP, which remains a real defect.

**3. [Rule 1] `tests/Unit/Sync/HubspotObserverTest.php` updated for the new constructor**
- Adding the config repository to `HubspotObserver` broke two direct constructions. Updated, plus
  three new tests covering branches the feature tests cannot reach (below).

## Mutation note

The first scoped run came back **95.08%** with three survivors, and they split two ways:

- **Two were dead code.** Both `array_values()` calls in `eventsFor()` sat in front of an
  `in_array()`, which ignores keys — deleting them changes no answer the method can produce. They
  were removed rather than tested. A mutant that cannot be killed by any assertion is often telling
  you the code is not a behaviour.
- **One was a real behaviour.** The `property_exists()` guard in `getHubspotAutoSync()`: reading
  `$this->hubspotAutoSync` directly goes through `Model::__get()` and reaches the attribute bag, so
  a column or filled attribute of that name would silently pose as an opt-out and stop a model
  syncing with nothing saying why. Killed by a test that gives a model exactly that attribute and
  asserts the declaration still reads as absent.

Back to **100.00% (59/59)**.

## Next Phase Readiness

- 04-06 owns `restored` entirely, and D-17's guard is already in place so its restore path is the
  only thing that responds to one. It also adds `hard_delete` and `on_restore` to the `auto_sync`
  block and reuses `SoftDeletingLead` — do not duplicate that fixture.
- D-21 stands: `hard_delete => 'warn'` SKIPS like `guard` and logs at warning level.
- 04-07's `SyncGate` sits in FRONT of this gate; nothing here needs to move for it.

---
*Phase: 04-model-sync*
*Completed: 2026-07-31*
