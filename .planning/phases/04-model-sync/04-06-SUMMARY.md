---
phase: 04-model-sync
plan: 06
subsystem: sync
tags: [laravel, eloquent, observer, soft-deletes, delete-policy, archive, logging]

requires:
  - phase: 04-model-sync (04-02)
    provides: "HubspotObserver, ModelBindings, SyncHubspotObjectJob, HubspotObjectLink, SyncTestCase"
  - phase: 04-model-sync (04-05)
    provides: "the auto_sync gate, D-17's restore guard on updated(), SoftDeletingLead"
provides:
  - "Sync\\DeletePolicy -- design spec §7's table as a pure static function over four primitives"
  - "Sync\\ArchiveHubspotObjectJob -- carries objectType + hubspotId as SCALARS, never the model"
  - "Sync\\HubspotObserver::trashed(), ::forceDeleted(), ::deleted(), ::restored()"
  - "Sync\\HubspotObserver::passesGate(), ::dispatchJob() and ::applyToLink(), factored out of syncOn()"
  - "SyncHubspotObjectJob's trashed-model early return"
  - "config/hubspot.php auto_sync.hard_delete ('guard') and auto_sync.on_restore ('flag')"
  - "ConfigurationException::unknownHardDeletePolicy(), ::unknownRestorePolicy(), ::unknownDeleteEvent()"
  - "illuminate/log as a declared production require -- the package's first log calls"
affects: [04-07 (SyncGate's suppression sits in front of passesGate; withoutSyncing() must suppress
  the delete path too), 04-09 (hubspot:doctor reports the resolved hard_delete and on_restore
  values -- defaults are 'guard' and 'flag')]

tech-stack:
  added:
    - "illuminate/log ^12.0|^13.0 (production require)"
  patterns:
    - "Three DISTINCT Eloquent events drive the delete table, never one with a branch inside it.
      `deleted` fires identically for a soft delete and for a forceDelete(), and forceDelete()
      calls delete() internally, so a `deleted`-plus-trashed() implementation archives twice and
      misclassifies the hard delete. `trashed`, `forceDeleted`, and `deleted` gated on the ABSENCE
      of SoftDeletes are the three that actually distinguish the rows."
    - "A queued job whose subject may no longer exist carries SCALARS, not the model.
      SerializesModels re-fetches by key on the worker and CallQueuedHandler::handleModelNotFound()
      DELETES the message before handle() runs -- so a model-carrying archive job is silently
      discarded on exactly the hard deletes it exists to mirror."
    - "Lazy validation of policy values: only the value the event consults is checked. Eager
      validation of both would make a typo in one throw on events the other governs, reporting the
      wrong problem at the wrong moment from inside an unrelated Eloquent event."
    - "A resolver's answer for a row nothing asks about is still correct, not dead code. DeletePolicy
      answers `skip-quietly` for `deleted` on a SoftDeletes model; the observer returns before ever
      asking, because routing it through the resolver would log a skipped archive at info on every
      soft delete that had just archived successfully."
    - "Where a config value's whole content is a LOG LEVEL, the suite has to be able to see one.
      D-21 makes `guard` and `warn` take the same action, so request-count tests cannot tell them
      apart -- Log::spy() and shouldHaveReceived('info'|'warning') are what make the decision real."

key-files:
  created:
    - src/Sync/DeletePolicy.php
    - src/Sync/ArchiveHubspotObjectJob.php
    - tests/Unit/Sync/DeletePolicyTest.php
    - tests/Feature/Sync/DeletePolicyTest.php
  modified:
    - src/Sync/HubspotObserver.php
    - src/Sync/SyncHubspotObjectJob.php
    - src/Exceptions/ConfigurationException.php
    - config/hubspot.php
    - composer.json
    - docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md
    - .planning/REQUIREMENTS.md
    - .planning/intel/requirements.md
    - .planning/intel/constraints.md

key-decisions:
  - "**D-21 implemented as selected: `hard_delete => 'warn'` SKIPS**, exactly as `guard` does, and
    differs only in log level -- warning rather than info. Only the value literally named `allow`
    archives. The owner selected this before execution; this plan did not re-open it."
  - "The four delete/restore handlers are gated on the event name `'deleted'`, not on their own
    names. `auto_sync.on` is the consumer's statement about whether local deletes mirror at all;
    four separately-opt-in-able names would let somebody enable `trashed` and forget `forceDeleted`,
    which is the exact failure the three-event split makes possible. `restored` rides the same
    switch because a restore is only interesting when a delete archived something."
  - "ArchiveHubspotObjectJob takes (string $objectType, string $hubspotId) and NOT the model, and
    declares no deleteWhenMissingModels. The observer reads the link row while the event is still
    running -- on a hard delete that is the last moment it is reachable. This is the one place the
    two Sync jobs must differ."
  - "The `deleted` gate uses class_uses_recursive(), NOT method_exists($model, 'forceDelete') as
    04-RESEARCH.md Pitfall 2 suggested. Codex rejected method_exists-as-contract twice on PR #48: a
    lookalike method silently reclassifies deletes, and a non-public one is reached through
    Model::__call() and raises BadMethodCallException from inside an event handler."
  - "SyncHubspotObjectJob::handle() returns early when its model arrives trashed -- closing
    04-CONTEXT.md's deferred 'update job dispatched before a soft delete' item. SerializesModels
    DOES restore soft-deleted models (newQueryForRestoration() uses newQueryWithoutScopes()), so the
    job finds its model rather than being discarded, and the delete path already owns that record's
    archived state."
  - "syncOn() was split into passesGate() + dispatchJob() rather than duplicated. dispatchJob()
    takes a union of the two concrete job classes rather than ShouldQueue, because afterCommit() is
    declared by Illuminate\\Bus\\Queueable and not by that interface."
  - "illuminate/log is now a declared production require. The Log facade ships in illuminate/support
    but resolves illuminate/log's LogManager at runtime, and D-19 is precisely what an undeclared
    runtime dependency costs. CLAUDE.md's post-D-02 rule admits any illuminate/* on declaration."
  - "The stale flag is CLEARED by SyncHubspotObjectJob's existing-link branch, and by nothing else.
    04-04 wrote the stale leg into scopePendingHubspotSync() on the stated assumption that a
    successful re-sync would clear it; 04-06 is the plan that made the flag reachable, so it owes
    the clear. Without it a link goes stale once, on the first restore, and is re-reported as
    pending forever (Codex, PR #49)."
  - "A PURGE -- forceDelete() on a row already soft-deleted -- archives ONCE in total. `trashed`
    owns the archive on the way down, so `forceDeleted` skips rather than addressing a record
    HubSpot has already archived. `trashed()` is a sound discriminator at that one point:
    performDeleteOnModel() skips runSoftDelete() while forceDeleting is true, so a DIRECT force
    delete reads false and a purge reads true (Codex, PR #49; see the research correction below)."
  - "`recreate` is routed PAST the missing-link guard; `archive` and `flag-stale` keep it. Its
    instruction is 'sync this model afresh', which is exactly what a restored model that never
    linked needs, and skipping was silent -- D-17 suppresses the restore's own `updated` event, so
    nothing else would ever have dispatched for it (Codex, PR #49)."
  - "A third factory, ConfigurationException::unknownDeleteEvent(), was added beyond the plan's two.
    DeletePolicy is public API of a released package and PHPStan (level max) requires the match to
    be total; a written `default => throw` with a unit test covering it is honest and covered, where
    a silent fallback would answer an unmodelled event with either an irreversible archive or a
    dropped mirror."

patterns-established:
  - "Assert the log CONTEXT, not only that a log happened. Mutation testing showed the whole context
    array could be emptied and every key removed without a test noticing -- a log line saying an
    archive was skipped without saying which record is not actionable."
  - "Mockery spy assertions must be written as $log = Log::spy() then $log->shouldHaveReceived(...),
    never Log::shouldHaveReceived(...): the facade's static proxy is invisible to PHPStan at level
    max, and shouldHaveReceived() returns LegacyMockInterface, so ->once() does not type-check.
    'Never received' is shouldNotHaveReceived(), not shouldHaveReceived()->never() -- the latter
    verifies receipt first and fails."

requirements-completed: [SYNC-04]

coverage:
  - id: D1
    description: "A SoftDeletes model soft-deleted with 'deleted' opted in archives in HubSpot
      exactly once"
    requirement: "SYNC-04"
    verification:
      - kind: unit
        ref: "tests/Feature/Sync/DeletePolicyTest.php#test_a_soft_delete_archives_exactly_once"
        status: pass
    human_judgment: false
  - id: D2
    description: "A force delete on a model NEVER soft-deleted first follows hard_delete and archives
      exactly once, not twice -- the case a `deleted`-plus-trashed() implementation gets wrong"
    requirement: "SYNC-04"
    verification:
      - kind: unit
        ref: "tests/Feature/Sync/DeletePolicyTest.php#test_a_force_delete_follows_hard_delete_and_archives_exactly_once_under_allow"
        status: pass
      - kind: unit
        ref: "tests/Feature/Sync/DeletePolicyTest.php#test_a_force_delete_under_the_default_guard_issues_no_request"
        status: pass
    human_judgment: false
  - id: D3
    description: "D-21: `warn` takes the same action as `guard` and differs only in log level"
    requirement: "SYNC-04"
    verification:
      - kind: unit
        ref: "tests/Feature/Sync/DeletePolicyTest.php#test_warn_skips_exactly_as_guard_does"
        status: pass
      - kind: unit
        ref: "tests/Feature/Sync/DeletePolicyTest.php#test_the_default_guard_logs_the_skipped_archive_at_info"
        status: pass
      - kind: unit
        ref: "tests/Feature/Sync/DeletePolicyTest.php#test_warn_logs_the_same_skipped_archive_at_warning"
        status: pass
    human_judgment: false
  - id: D4
    description: "A model with no SoftDeletes follows hard_delete exactly once"
    requirement: "SYNC-04"
    verification:
      - kind: unit
        ref: "tests/Feature/Sync/DeletePolicyTest.php#test_a_model_without_soft_deletes_follows_hard_delete_exactly_once"
        status: pass
    human_judgment: false
  - id: D5
    description: "With 'deleted' absent from auto_sync.on -- the shipped default -- none of the three
      delete events reaches HubSpot, whatever hard_delete says (ROADMAP SC5 clause 1)"
    requirement: "SYNC-04"
    verification:
      - kind: unit
        ref: "tests/Feature/Sync/DeletePolicyTest.php#test_no_delete_event_reaches_hubspot_while_deleted_is_not_opted_in"
        status: pass
    human_judgment: false
  - id: D6
    description: "A restore flags the link row stale, stamps stale_at, issues no property push, and
      leaves hubspot_id byte-identical -- asserted separately, so nulling-then-flagging fails"
    requirement: "SYNC-04"
    verification:
      - kind: unit
        ref: "tests/Feature/Sync/DeletePolicyTest.php#test_a_restore_flags_the_link_stale_without_touching_the_stored_id"
        status: pass
    human_judgment: false
  - id: D7
    description: "on_restore => 'recreate' drops the link row and syncs afresh, proven by the link
      row's own primary key moving rather than by the request count alone -- and syncs a model that
      never linked at all, rather than skipping it (Codex, PR #49)"
    requirement: "SYNC-04"
    verification:
      - kind: unit
        ref: "tests/Feature/Sync/DeletePolicyTest.php#test_a_restore_under_recreate_drops_the_link_and_syncs_afresh"
        status: pass
      - kind: unit
        ref: "tests/Feature/Sync/DeletePolicyTest.php#test_a_restore_under_recreate_syncs_a_model_that_never_linked"
        status: pass
    human_judgment: false
  - id: D11
    description: "A successful re-sync CLEARS the stale flag a restore set, so
      scopePendingHubspotSync() stops reporting the model as outstanding (Codex, PR #49)"
    requirement: "SYNC-04"
    verification:
      - kind: unit
        ref: "tests/Feature/Sync/DeletePolicyTest.php#test_a_successful_resync_clears_the_stale_flag_a_restore_set"
        status: pass
    human_judgment: false
  - id: D8
    description: "A property-push job arriving with its model trashed issues no request and logs"
    requirement: "SYNC-04"
    verification:
      - kind: unit
        ref: "tests/Feature/Sync/DeletePolicyTest.php#test_a_property_push_job_arriving_with_the_model_trashed_issues_no_request"
        status: pass
    human_judgment: false
  - id: D9
    description: "Every cell of the delete-policy table, as a pure function over primitives -- 13
      rows, plus lazy validation and three unrecognised-value cases"
    requirement: "SYNC-04"
    verification:
      - kind: unit
        ref: "tests/Unit/Sync/DeletePolicyTest.php#test_it_resolves_every_cell_of_the_delete_policy_table"
        status: pass
      - kind: unit
        ref: "tests/Unit/Sync/DeletePolicyTest.php#test_an_unconsulted_policy_value_is_not_validated"
        status: pass
      - kind: unit
        ref: "tests/Unit/Sync/DeletePolicyTest.php#test_an_unrecognised_hard_delete_value_throws_naming_the_supported_ones"
        status: pass
      - kind: unit
        ref: "tests/Unit/Sync/DeletePolicyTest.php#test_an_unrecognised_on_restore_value_throws_naming_the_supported_ones"
        status: pass
      - kind: unit
        ref: "tests/Unit/Sync/DeletePolicyTest.php#test_an_unrecognised_event_throws_naming_the_events_it_models"
        status: pass
    human_judgment: false
  - id: D10
    description: "A delete on a model that never synced logs and stops rather than throwing, and a
      non-string policy value fails as this package's own exception naming the key"
    requirement: "SYNC-04"
    verification:
      - kind: unit
        ref: "tests/Feature/Sync/DeletePolicyTest.php#test_a_delete_with_no_link_row_issues_no_request_and_does_not_throw"
        status: pass
      - kind: unit
        ref: "tests/Feature/Sync/DeletePolicyTest.php#test_a_non_string_hard_delete_value_fails_as_a_configuration_exception"
        status: pass
    human_judgment: false
---

# 04-06: the delete policy, on the three events that distinguish it

## The resolved action set

`DeletePolicy::resolve(bool $usesSoftDeletes, string $event, string $hardDelete, string $onRestore)`
returns one of five action names:

| action | what the observer does |
|---|---|
| `archive` | dispatches `ArchiveHubspotObjectJob` with the link row's object type and HubSpot id |
| `skip-quietly` | logs at **info**; issues nothing |
| `skip-loudly` | logs at **warning**; issues nothing |
| `flag-stale` | sets `is_stale` and `stale_at` on the link row; never touches `hubspot_id` |
| `recreate` | logs at warning, deletes the link row, dispatches `SyncHubspotObjectJob` |

The table it resolves, in full:

| uses SoftDeletes | event | consults | action |
|---|---|---|---|
| any | `trashed` | — | `archive` |
| any | `forceDeleted` | `hard_delete` | `guard`→`skip-quietly`, `warn`→`skip-loudly`, `allow`→`archive` |
| no | `deleted` | `hard_delete` | same mapping |
| yes | `deleted` | — | `skip-quietly` (`trashed`/`forceDeleted` own both outcomes) |
| any | `restored` | `on_restore` | `flag`→`flag-stale`, `recreate`→`recreate` |

## The exact config defaults 04-09's doctor will report

```php
'auto_sync' => [
    'enabled' => (bool) env('HUBSPOT_AUTO_SYNC', true),
    'on' => ['created', 'updated'],
    'queue' => true,
    'hard_delete' => 'guard',
    'on_restore' => 'flag',
],
```

Both new keys are plain scalars with no `env()` call. Neither has a fallback on an unrecognised
value: `ConfigurationException::unknownHardDeletePolicy()` and `::unknownRestorePolicy()` throw
naming the supported values, because both available fallbacks are silent and wrong in opposite
directions — defaulting a typo to `allow` issues irreversible archives nobody asked for, and
defaulting it to `guard` stops mirroring deletes an operator believed were mirrored (T-04-26).

## D-21, and why `warn` skips

`guard` and `warn` take the **same action**. `warn` is `guard` said loudly, not "archive it, but
tell me". A config value whose plain-English reading is the opposite of its behaviour is a trap,
and because HubSpot's delete is an archive with **no unarchive endpoint**, that trap stays silent
until somebody reads the CRM. Only `allow` archives.

The whole of the difference is therefore a log level, which is why two of this plan's tests spy on
the logger. Without them the suite would pass identically against an implementation where `guard`
and `warn` were literally the same branch — that is, against a decision never made.

## The trashed-job decision, closing `04-CONTEXT.md`'s deferred item

A property-push job queued before a soft delete arrives with the model trashed, and **it does
arrive** — `SerializesModels::newQueryForRestoration()` uses `newQueryWithoutScopes()`, so the
trashed model is found and handed to `handle()` exactly as a live one would be. It must not push:
the delete path has already archived that record, so the write is at best wasted and at worst a
write to archived CRM state nothing local points at any more.

`SyncHubspotObjectJob::handle()` therefore returns early and logs. The delete path owns the
archived state.

## Deviations from `04-06-PLAN.md`

1. **Actions are strings, not a backed enum.** The plan asked for an enum "for the reason the
   association category is one". The RED contract committed in `702e947` asserts them as strings
   (`self::assertSame('archive', DeletePolicy::resolve(...))`), so an enum could only arrive by
   editing that contract — which the same plan forbids — or by shipping a second type whose only
   job is to be unwrapped back to a string at the boundary. The set is closed inside `DeletePolicy`,
   every member is pinned by a test row, and `resolve()` carries a narrowed `@return` union so
   PHPStan gets the exhaustiveness the enum was wanted for.
2. **`ArchiveHubspotObjectJob` carries scalars, and declares no `deleteWhenMissingModels`.** The
   plan asked for "same primitives as the sync job". A model-carrying archive job is silently
   discarded on every hard delete, which is most of what this job exists for.
3. **One test line changed, and nothing it asserts.**
   `(new SyncHubspotObjectJob($lead))->handle()` raised `ArgumentCountError` before any assertion in
   it could run — `handle()`'s collaborators are method parameters resolved per call. It now goes
   through `app()->call()`, which is how the queue itself invokes it.
4. **A third `ConfigurationException` factory,** `unknownDeleteEvent()`. PHPStan at level max
   requires `resolve()`'s match to be total, and the alternative to a covered `default => throw` was
   a silent fallback answering an unmodelled event with an archive or a dropped mirror.
5. **Tests were ADDED, never edited** beyond point 3: the recreate path, the no-link path, the
   non-string config value, the two log-level assertions and the unrecognised-event case. Four of
   them exist because the branches they cover would otherwise have been uncovered lines; the rest
   because mutation testing showed the assertions were free.

## Review findings closed on PR #49

Codex raised two P2s against `215e110df4`, both real, both fixed in `ecbeaed`:

1. **The stale flag was never cleared.** `flagStale()` set `is_stale`/`stale_at` and
   `SyncHubspotObjectJob`'s existing-link branch updated only `synced_at`. Since
   `SyncsToHubspot::scopePendingHubspotSync()` returns every model carrying the flag, a link went
   stale once — on the first restore — and every later successful sync still re-reported the model
   as having work outstanding, forever. 04-04's own docblock on that scope already said "nothing
   else ever clears the flag but a successful re-sync"; this plan is the one that made the flag
   reachable, so it owed the clear. Only the update branch needed it — the upsert branch is reached
   only when the relation found no row, and a new row's `is_stale` is false.
2. **`on_restore => 'recreate'` skipped a model that had never linked.** It hit the missing-link
   guard and returned, so a model deleted before its initial create sync ran stayed permanently
   unsynced under the one setting whose purpose is to resync it — and silently, because D-17
   suppresses the restore's own `updated` event. `recreate` now runs past that guard with a
   nullable link; `archive` and `flag-stale` keep it, factored into `applyToLink()`.

A third P2 landed against `55f8306922` and was also real, fixed in `05a6d7f`:

3. **A purge archived twice.** Soft-delete now, `forceDelete()` later: `trashed` dispatched the
   archive on the way down, then `forceDeleted` dispatched the same archive again against a record
   HubSpot had already archived. `deleteOn()` now skips a `forceDeleted` whose model is already
   trashed — after the gate, so a purge under a disabled auto-sync stays silent, and keyed on the
   event rather than applied generally, since `trashed()` is true during `restored` handling too
   and means the opposite there.

Each fix is pinned by a test that fails against the code as reviewed:
`test_a_successful_resync_clears_the_stale_flag_a_restore_set`,
`test_a_restore_under_recreate_syncs_a_model_that_never_linked`,
`test_purging_an_already_trashed_model_does_not_archive_a_second_time` and
`test_purging_while_deleted_is_not_opted_in_stays_silent`.

## A correction to `04-RESEARCH.md` Common Pitfall 2

Fixing the purge meant relying on `trashed()` inside `forceDeleted()`, which the research says is
unreliable. It is not — and the research's stated MECHANISM is wrong, though its conclusion is
right. Pitfall 2 says the in-memory delete column is already set by the time `deleted` runs during
a force delete. `SoftDeletes::performDeleteOnModel()` skips `runSoftDelete()` entirely while
`forceDeleting` is true, so nothing sets it. Measured against the framework rather than recalled:

| scenario | `deleted` fires | `trashed()` inside it |
|---|---|---|
| soft delete | once | true |
| direct `forceDelete()` | once | **false** |
| purge (soft delete, then `forceDelete()`) | **twice** | true |

So a `deleted`-plus-`trashed()` implementation reads a direct force delete CORRECTLY and
misclassifies a **purge** as a soft delete — archiving it even under `hard_delete => 'guard'`, the
setting that exists to prevent exactly that, and twice, because `deleted` fires twice there. The
three-event split stands; only the reason for it needed correcting. The docblocks in
`HubspotObserver`, `DeletePolicy` and `tests/Feature/Sync/DeletePolicyTest.php` that repeated the
wrong mechanism now carry the measured one.

## Mutation note

Scoped run over the five changed classes: **86.28% MSI** (195 tested, 31 untested), floor 80. Every
remaining survivor is a `Concat*` mutator on a multi-line log MESSAGE string, plus one pre-existing
`RemoveStringCast` on `SyncHubspotObjectJob`'s `(string) getKey()` from 04-02. Log wording is not a
behaviour worth pinning by string equality; the log **level** and the log **context** are, and both
are asserted.

The first run scored 82.04%. Four of those survivors were real and were killed: removing `stale_at`
from the flag write, and deleting the `Log` call outright from the flag, recreate and no-link
branches. That is the `04-05` lesson applied in the other direction — ask whether the mutated code
is a behaviour, and when it is, test it.

## Next Phase Readiness

- **04-07** adds `SyncGate` (SYNC-05). Its suppression must sit in front of `passesGate()`, and
  `withoutSyncing()` has to suppress the delete path too — there are now four more handlers than
  when 04-07 was planned, and an archive that escapes a suppression block cannot be undone.
- **04-09**'s `hubspot:doctor` reports the resolved `auto_sync` values; the two new keys default to
  `'guard'` and `'flag'`.
- **Deferred, not scheduled:** a hard delete leaves an orphan `hubspot_object_links` row behind. The
  archive is issued, but nothing prunes the row, and no test asserts either behaviour. Pruning it
  was out of scope here and is a pre-1.0 decision alongside the cross-connection link-table question
  `04-05` logged.
