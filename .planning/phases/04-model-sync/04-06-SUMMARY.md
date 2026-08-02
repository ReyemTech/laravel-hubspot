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
  - "Sync\\ArchiveHubspotObjectJob -- carries objectType + hubspotId as SCALARS, never the model;\n    treats a 404 as a completed archive"
  - "Sync\\HubspotObserver::trashed(), ::forceDeleted(), ::deleted(), ::restored()"
  - "Sync\\HubspotObserver::passesGate(), ::dispatchJob() and ::applyToLink(), factored out of syncOn()"
  - "SyncHubspotObjectJob's trashed-model early return"
  - "config/hubspot.php auto_sync.hard_delete ('guard') and auto_sync.on_restore ('flag');\n    on_restore accepts 'flag' ONLY -- 'recreate' was built during review and withdrawn"
  - "ConfigurationException::unknownHardDeletePolicy(), ::unknownRestorePolicy(), ::unknownDeleteEvent()"
  - "illuminate/log as a declared production require -- the package's first log calls"
  - "hubspot_object_links.archived_at -- durable evidence that THIS PACKAGE archived a link"
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
    - database/migrations/sync/0001_01_01_000001_add_archived_at_to_hubspot_object_links_table.php
    - tests/Unit/Sync/DeletePolicyTest.php
    - tests/Feature/Sync/DeletePolicyTest.php
    - tests/Feature/Sync/RestorePolicyTest.php
    - tests/Feature/Sync/CrossConnectionDeleteTest.php
    - tests/Support/Sync/DisabledSoftDeletingLead.php
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
  - "A PURGE archives on BOTH events, and the redundancy is deliberate. Deduplicating on trashed()
    was tried and reverted: it proves a soft delete happened, never that ITS archive passed the gate
    in force at the time, so it silently orphaned a live HubSpot record whenever deletes were
    enabled BETWEEN the two events (Codex, PR #49). ArchiveHubspotObjectJob treats a 404 as a
    completed archive so the redundant request cannot become a failed job."
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

## `on_restore => 'recreate'` was built and WITHDRAWN

**Read this before the finding log below.** `recreate` is not in this release. It was implemented
during review, drew eight of the seventeen findings on PR #49 on its own, and was withdrawn rather
than shipped. Everything the log says about `RecreateHubspotObjectJob`,
`NonRetryingObjectGatewayContract` and the split retry middlewares is a record of work that is **no
longer in the branch** — kept because the reasoning is what justifies the withdrawal, not because
the code survives.

What finally settled it: `archived_at` is stamped when an archive is DISPATCHED, so a restore can
race an archive that is still in flight. Creating the replacement before that archive confirms leaves
two active records with only one linked, and if the create is instead rejected for a uniqueness
conflict the restored model is left unlinked once the archive completes. Ordering the recreate after
*confirmed* completion needs a state machine on the link row and job chaining — a design 04-06 does
not own and should not invent under review pressure.

The value is **refused by name**, not approximated. `DeletePolicy` throws
`ConfigurationException::unknownRestorePolicy()` for it, naming why. Quietly treating it as `flag`
would be the worst of the three options: an operator would believe CRM history had been forked when
it had not.

`flag` — the default, and the only value this release accepts — has drawn no findings.

### What went with it

`Gateway\Contracts\NonRetryingObjectGatewayContract`, the `retryInternalErrors` split on
`HubspotClientFactory`, and their bindings and tests were all reverted. They existed solely so the
recreate's create could avoid the SDK's 5xx retry middleware; with no consumer they would be unused
public API on a released layer. **The underlying observation stands and is worth carrying into the
recreate plan:** `ObjectGateway::create()` and `createMany()` retry 5xx for every caller, and a
retried create is never safe — that is a Gateway-layer question that predates this phase.

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

A third P2 landed against `55f8306922` and was also real, fixed in `f1fd9f1`:

3. **A purge archived twice.** Soft-delete now, `forceDelete()` later: `trashed` dispatched the
   archive on the way down, then `forceDeleted` dispatched the same archive again. The first fix
   deduplicated on `trashed()`; **finding 4 below proved that unsound and it was reverted.** The
   redundancy is accepted, and the failed-job risk that motivated the finding is answered inside
   `ArchiveHubspotObjectJob` instead.

Each fix is pinned by a test that fails against the code as reviewed:
`test_a_successful_resync_clears_the_stale_flag_a_restore_set`,
`test_a_restore_under_recreate_syncs_a_model_that_never_linked`,
`test_purging_an_already_trashed_model_does_not_archive_a_second_time` and
`test_purging_while_deleted_is_not_opted_in_stays_silent`.

Two further P2s landed against `bfef9b5d99`, and the first of them **reverses** finding 3's fix
(`5c0bd5e`):

4. **The purge deduplication was unsound.** `trashed()` proves a soft delete happened; it never
   proves that soft delete's archive passed the gate in force at the time. A model soft-deleted
   while `deleted` was absent from `auto_sync.on` was never archived, so skipping its later purge
   left a live HubSpot record with no local row behind it — silently, and precisely for the operator
   who has since set `allow` to prevent that. The only sound version needs durable evidence that
   this package issued the first archive, and `hubspot_object_links` has nowhere to record it.

   So the purge archives on both events again, and the redundancy is now stated rather than
   optimised away. What made it worth avoiding was the risk of a failed job, and that is addressed
   where it actually occurs: `ArchiveHubspotObjectJob` treats a **404 as a completed archive** — the
   plan's own rule for a missing link row, applied to the record instead of the row — while every
   other status still throws, because a 429 or a 500 says nothing about whether the record is
   archived. Correct-and-redundant beats efficient-and-silently-wrong.
5. **`recreate` upserted instead of creating.** The ordinary sync job's no-link branch upserts on
   the model's unchanged `id_property` for D-11's reason, and that is the wrong verb here:
   converging onto whatever HubSpot's batch-upsert matches is the opposite of forking away from it.
   `recreate` now dispatches `RecreateHubspotObjectJob`, which calls `ObjectGatewayContract::
   create()`. **It does not guarantee a new object and no longer claims to** — HubSpot retains a
   unique property value on an archived record, so the create can be rejected for conflicting with
   the very object being forked away from, arriving as this package's own `ApiException` carrying
   HubSpot's reason. What it can no longer do is silently match the archived record and keep
   writing to it.

Pinned by `test_a_purge_archives_on_both_events_rather_than_risking_a_silent_orphan`,
`test_a_purge_still_archives_when_the_earlier_soft_delete_was_gated_off`,
`test_an_archive_of_an_already_gone_record_is_a_completed_archive` and
`test_an_archive_rejected_for_any_other_reason_still_throws`.

A fourth review round against `3fc2f1ade0` produced three more, and the last two forced the
design change this plan had been working around (`e535167`, `b315afe`):

6. **The recreate job had no trashed guard.** Queued is the default, so a model restored and then
   soft-deleted again before the worker ran arrived trashed — and this job CREATES, so it left an
   active HubSpot object behind a deleted local model with nothing to clean it up, the observer
   having dropped the old link before dispatching.
7. **A restore acted on a delete that was never mirrored** — `flag` marked a live link stale,
   `recreate` deleted a valid link and created a duplicate object.
8. **An edit to a soft-deleted model was discarded** as "the delete path owns this record" when,
   under the SHIPPED DEFAULT, `deleted` is not mirrored and the delete path had never touched it.
   The edit was lost outright: D-17 suppresses the restore's `updated` event and `restored` is
   gated off by the same absent option.

## `archived_at`: the fact three findings were missing

Findings 4, 7 and 8 are one defect wearing three hats. **Nothing recorded whether THIS PACKAGE
archived a link**, so every decision after a delete inferred it from `hubspot.auto_sync.on`'s
CURRENT value — and that value can change between the delete and whatever asks later. `trashed()`
proves a soft delete happened; it never proves that soft delete's archive passed the gate.

`archived_at` on `hubspot_object_links` is that fact. It is added by a **second migration**, because
the first shipped in 0.5.0 and editing a migration somebody has already run changes nothing in their
database while making the schema depend on when they installed. It is stamped when an archive is
DISPATCHED, which is the question being asked — has this package already issued an archive for this
record — rather than when one succeeds.

| decision | before | now |
|---|---|---|
| purge archives again? | skipped if `trashed()`, orphaning a live record when the soft delete was gated off | skipped only if `archived_at` is set |
| restore flags stale? | always | only if `archived_at` is set |
| restore recreates? | always, dropping a possibly-valid link | only if `archived_at` is set, or no link exists |
| property push skipped? | whenever the model was trashed | only when the link is archived; a trashed model with no link still creates nothing |

The stale flag consequently clears on the first successful write **after an operator relinks**,
which is the scenario finding 1 described. While `archived_at` stands there is no successful write
to be had, and that is the point: the flag cannot come off by accident.

**Consumer impact:** one additional migration. Additive, nullable, and null for every existing row
— which is the correct reading of a link written before the column existed, since this package has
no evidence it archived them.

A fifth round against `aece2f50f1` found two more, the first of them a consequence of the
`archived_at` guard itself (`0f2a1f8`):

9. **The restore response was still gated on the current event list.** Remove `deleted` from
   `auto_sync.on` between the delete and the restore and the record is STRANDED: the link stays
   archived and unflagged, so property pushes skip it (`archived_at` is set) while
   `pendingHubspotSync()` cannot report it (the stale flag was never set). Nothing local mentions it
   again. The list answers "does this application mirror deletes now"; a restore has to answer for
   an archive that already happened. `restored()` is now gated on the **kill switch alone** — that
   one is a statement about the package as a whole, and `recreate` reaches the API — and keys
   everything else on the link. `deleteOn()` serves the three delete events only, and its match
   collapses to the archive.
10. **A retried recreate duplicates the CRM object.** If the create lands and the worker dies before
    `updateOrCreate()`, or the acknowledgement is lost and the broker redelivers, a second attempt
    makes a second ACTIVE object with only the last one linked. Nothing durable distinguishes a lost
    response from a failed request, which is the distinction a retry has to make.
    `RecreateHubspotObjectJob` declares **`$tries = 1`**. `SyncHubspotObjectJob` has no such problem
    because D-11 chose upsert for exactly this reason; recreate is the path where converging is the
    wrong answer, so it gives up the retry instead. **When you cannot converge, do not repeat.**

    The cost is stated rather than hidden: a transiently-failing recreate is not retried either and
    lands in `failed_jobs` with HubSpot's own reason, which is the right destination for an operation
    that forks CRM history. An operator re-dispatching it knowingly is safe; a worker doing so
    silently is not.

A sixth round against `fdc4794508` found two more, both on the recreate job (`805b64e`, `2e0c1cb`):

11. **A recreate created a duplicate with no failure involved.** Under the default queued `created`
    sync, a model created, soft-deleted and restored before its initial `SyncHubspotObjectJob` runs
    leaves the restore seeing no link; the older sync then upserts and writes a live one, and the
    recreate creates a SECOND active object and overwrites the link with its id. The job now
    rechecks the link when it runs: the observer drops the old link before dispatching, so any link
    found there was written afterwards and the state the job was dispatched for no longer holds.
12. **`$tries = 1` did not actually prevent the duplicate it was added for.** It bounds Laravel job
    attempts; the production client still carried the SDK's internal-errors retry middleware, so a
    5xx or a timeout from `create()` was repeated INSIDE a single attempt — and after a write that
    already landed, that repeat is the duplicate.

## Two retries, only one of them safe

`HubspotClientFactory` pushed both of the SDK's retry middlewares together. They are not equally
safe, and they are now switchable separately:

| middleware | fires on | safe to repeat a create? |
|---|---|---|
| `rate_limit_retry` | 429 | **yes** — the request was refused, never processed |
| `internal_errors_retry` | 5xx | **no** — says nothing about whether the write landed |

`Gateway\Contracts\NonRetryingObjectGatewayContract` names a transport without the second one, and
`RecreateHubspotObjectJob` asks for it **by type rather than by argument**: the safety of repeating
a request is a property of the operation, and putting that decision at the call site makes it
forgettable. `ObjectGateway` implements both contracts — it is `final`, and a decorator
reimplementing eleven delegating methods to express one transport difference would be more surface
for less clarity — so the guarantee lives in the binding, and `ServiceProviderBindingsTest` asserts
it in both directions rather than assuming it.

**Nothing changes for existing callers.** This adds a second transport for one caller rather than
taking retries away from anybody, and `Hubspot::fake()` is unaffected by construction: it replaces
the factory singleton with a `forTransport()` one carrying no retry middleware at all, so the
binding finds nothing to rebuild and uses the mock unchanged.

A seventh round against `36d823baab` found two more (`bbf3c0e`):

13. **The recreate's entry guard is a check-before-act.** A soft delete landing between that check
    and the `create()` leaves an ACTIVE HubSpot object behind a deleted local model, with no
    `trashed` handler to clean it up because the observer had already dropped the link that handler
    looks for.
14. **The per-model opt-out was dropped when `restored()` was regated.** Finding 9's fix moved the
    restore off the event list and onto the kill switch, and took `$hubspotAutoSync = false` with
    it — so a model an operator opted out AFTER it was archived could still have a new CRM object
    created for it by a restore.

Finding 14 is a one-line rule: `false` means "never auto-sync this model", a statement about the
model rather than about which events an application mirrors, so it outranks the evidence an archived
link carries. The read is now factored into `declaredAutoSyncOf()`, which `eventsFor()` and
`restored()` consult for different parts of the same answer.

## Why finding 13 converges rather than locks

A lock was considered and rejected, and the reason is worth recording because the finding will look
unfixed to anyone who expects one. Mutual exclusion here would have to be taken by the **delete
path** as well as by the job — an exclusion one side observes is not exclusion — so every delete of
every bound model, on every consumer, would acquire a lock to protect one opt-in restore policy.

So the state is made to converge instead. After the create, the model is re-read **without scopes**
so a trashed row is actually found, and a row that came back deleted has the object archived
immediately and the link stamped — exactly what the `trashed` handler would have done had it been
able to see it. The window is not closed; it is made self-correcting, and a delete landing after
that read produces the same outcome on its own next pass.

`tests/Feature/Sync/DeletePolicyTest.php` outgrew the 500-line ceiling as a result, so the restore
half moved to `RestorePolicyTest.php`. The two sides gate differently and now say so in their own
headers.

## Ordering, not only gating (finding 18)

The last finding on the PR was an ordering one, and it produced the same silent stranding as finding
9 through a different door. `archived_at` was stamped AFTER the archive was dispatched — and with
`auto_sync.queue => false`, or on a synchronous queue driver, that dispatch performs the HubSpot
request inline. A restore landing in that window read a null marker, concluded nothing had been
archived, and left the link current; the archive then completed and stamped it, leaving a link that
is archived but never flagged. Pushes skip it (`archived_at` set), `pendingHubspotSync()` cannot
report it (not stale).

The marker is now written **before** the archive is published. Writing first cannot strand anything:
a dispatch that then fails leaves a link marked archived whose record is live, which is loud — a
synchronous failure propagates out of the model event, a queued one lands in `failed_jobs` — and
recoverable, because a restore flags it and the scope reports it. That is the same trade every
ordering decision in this file makes: a loud wrong state beats a silent one.

Its test asserts the ORDER rather than the outcome, reading the query log rather than a mocked
gateway — how many requests had gone out by the time the marker was written — and was verified to
fail against the previous ordering.

## Round nine: three findings, all born of earlier fixes (19-21)

19. **The marker outlived a failed archive.** Finding 18's fix wrote `archived_at` before
    dispatching, defended as "recoverable, because a restore flags it" — and that defence was wrong.
    A marker left by a failed dispatch makes a repeated delete SKIP (it says this package already
    archived) while ordinary pushes refuse the link for the same reason, so a still-live record was
    reachable only by editing the row. The marker is now **taken back** when publication fails, so
    it means what it says in both directions.
20. **A restore never repaired a skipped initial sync.** A model created, soft-deleted before its
    queued initial sync ran, and restored afterwards stayed unsynced forever: the job refuses to
    create a CRM record for a trashed model, D-17 suppresses the restore's `updated`, and there was
    no link to flag. `restored()` now dispatches that missing sync, **gated on `'created'`** being
    in `auto_sync.on` — a model whose application never syncs on create has no link for an innocent
    reason.
21. **The ordinary sync path has the same check-before-act race** the withdrawn recreate job had. A
    delete landing between the trashed guard and the link write finds no link, so `trashed` archives
    nothing; the job then links a deleted model and nothing revisits it.

Finding 21's answer is worth stating as a pattern: rather than reproduce an archive inside the job,
the event that could not act is **replayed** once the link it needed exists —
`HubspotObserver::trashed()` runs with the whole gate intact. Under the shipped default, where
deletes are not mirrored, that correctly decides to do nothing; a hand-rolled archive there would
have got it backwards. Both directions are tested.

## Finding 22: the convergence archived around `guard`

Finding 21's fix replayed `HubspotObserver::trashed()` for every raced delete, and that was the most
serious defect on the branch. **`trashed` resolves straight to `archive` without consulting
`hard_delete`** — correctly, because a soft delete is locally recoverable — so a raced FORCE delete
under the default `guard` archived irreversibly. The protection was defeated by the code written to
converge on it.

Which event is replayed now follows what actually happened:

| fresh row | model | replayed | consults `hard_delete`? |
|---|---|---|---|
| gone | soft-deleting | `forceDeleted()` | yes |
| gone | plain | `deleted()` | yes |
| present, trashed | soft-deleting | `trashed()` | no — archives, locally recoverable |
| present, live | either | nothing raced this write | — |

The plain-model row is new: that race was previously not converged at all, because the method
returned early for any model without `SoftDeletes`.

**The lesson this phase keeps re-learning**, and the reason the three-event split exists at all: the
three delete events are not interchangeable, and any code that picks one on the reader's behalf has
to justify which. Replaying "a delete happened" is not enough — *which* delete happened decides
whether `hard_delete` is consulted.

## Finding 23: the marker and the archive now share one transaction

The link table lives on the DEFAULT connection whatever connection a bound model is on — a shipped,
deliberate split. So a cross-connection model deleted inside its OWN transaction wrote `archived_at`
outside that transaction while the queued archive was deferred by `afterCommit()`. A rollback
discarded the job and kept the marker.

`archived_at` is what every read path downstream of a delete trusts — pushes skip a link carrying
it, a restore flags one carrying it, a later delete declines to archive twice on its strength — so a
marker describing an archive that never happened silently and permanently removes a live model from
every sync path there is.

It is now registered through `DB::afterCommit()`, which defers through the very same
`DatabaseTransactionsManager::addCallback()` the queued dispatch uses, so a rollback takes both or
neither. Outside a transaction the callback runs immediately, leaving finding 18's ordering intact.

`tests/Feature/Sync/CrossConnectionDeleteTest.php` pins it on the tenant-connection fixture where
the split is real, and was verified to fail against the eager marker.

## Findings 24-25: the archive is one deferred unit, not three steps

Deferring only the marker (finding 23) opened two more seams, both found immediately:

24. **An inline archive did not wait for the commit.** With `queue => false` inside a transaction,
    the marker deferred while `dispatchSync()` issued the irreversible archive at once — a rollback
    then left a live local model whose HubSpot record was gone, and no marker to show for it.
25. **The failure cleanup could not see a deferred publication.** Inside a transaction the queued
    dispatch only registers its own callback, so the surrounding `try` finished before the push ran.

Both close the same way: **marker, archive and cleanup are one `DB::afterCommit()` callback.**

| property | why |
|---|---|
| after commit | a rolled-back delete archives nothing; the archive is irreversible |
| together | the marker cannot outlive the archive, nor the archive the marker |
| marker first *within* | a restore racing the request still sees an archive was issued (finding 18) |
| catch inside | publication happens in the callback, not where it was registered |

`queue => false` is honoured, not overridden: it asks for the call to happen in the request, not for
it to happen before the delete is real.

## Findings 26-28: the restore beats the deferred archive two ways, and `updated` links too

Round fourteen, all three on `HubspotObserver`. Two are the cost of findings 23-25 — making the
archive one deferred unit put a whole transaction's worth of time between the decision to archive and
the archive itself, and a restore fits inside that. The third is finding 20's fix having been drawn
too narrowly.

26. **A restore inside the deleting transaction still archived.** `restored()` runs before the
    deferred callback and sees a link carrying no marker — there is none yet, by design — so it
    correctly declines to flag. The transaction then commits and the callback archives the HubSpot
    record of a model that is live again. Nothing repairs it afterwards: no further event fires, and
    `archived_at` then removes the model from every sync path there is.
27. **A failed archive left behind a stale flag its own marker caused.** With `queue => false` a
    restore racing the in-flight request reads the marker, concludes an archive was issued and flags
    the link stale. The request then fails, and a cleanup clearing only `archived_at` leaves a LIVE
    record reported by `pendingHubspotSync()` for ever, with no later write able to clear it.
28. **`updated` initiates a first link exactly as `created` does.** An application on
    `['updated', 'deleted']` links a model through the same upserting `SyncHubspotObjectJob` an
    ordinary edit dispatches, and that job CREATES the CRM record when no link exists. Delete before
    the job runs and it skips for being trashed; the restore then refused to replay it solely because
    `created` was absent from a list that had never been the question.

### 26: the recheck, and why it is asked of `trashed` alone

The callback now asks the DATABASE — not the in-memory model, which the delete ran on and which a
restore on another instance leaves untouched — whether a LIVE row with this key exists, and cancels
before stamping anything if one does. Phrased positively on purpose: a row that has since vanished
entirely answers false and the archive proceeds, because a purge between the delete and the commit
still leaves a HubSpot record that ought to go.

Only `trashed` is asked. A hard delete's row is gone for good, so the question is a wasted query
there — and on a model without `SoftDeletes` there is no delete column to ask it of at all, so an
ungated recheck raises `BadMethodCallException` from inside an event handler. That is what kills the
mutant on the event comparison rather than a test written for it.

### 27: put the flag BACK, do not blank it

The cleanup snapshots `is_stale`/`stale_at` immediately before the marker and restores exactly that
on failure. A blanket clear would have destroyed a flag set for some other reason — an earlier
archive that a restore already answered, or an operator's own hand — which had nothing to do with
this failure. The in-memory values are the row's true state at that moment: any link reaching the
marker carries none, so the restore path cannot have flagged it since it was read.

**Written through the query builder, not `$link->update()`,** and that is the difference between
putting the flag back and appearing to. Eloquent writes only DIRTY attributes: the racing restore's
flag lives in the row while this instance still believes what it read before the marker, so filling
`is_stale` with that same value leaves it clean and `save()` writes no column at all. `archived_at`
is dirty either way, which is exactly why the marker half of this cleanup never showed the problem.
The first draft of the fix used `$link->update()` and the test caught it.

### 28: two events can be the first one

The repair gate accepts `created` or `updated`, and the log context NAMES which one it stood in for —
the only place the two accepted answers are observably different, and what an operator needs to check
the repair against their own config. Neither configured is still a refusal: an absent link is then
absent for an innocent reason, and manufacturing one would create a CRM record nobody asked for.

### The second file split

Three more tests pushed `tests/Feature/Sync/DeletePolicyTest.php` through the 500-line code-shape
ceiling — CI caught it, not the local run that preceded the log assertion. Split rather than
compressed, on the seam the findings themselves drew: **`tests/Feature/Sync/ArchiveMarkerTest.php`**
now holds findings 18 and 23-27, everything about what `archived_at` promises once the decision to
archive is made, while `DeletePolicyTest` keeps the decision itself. A pure move — 824 tests and
2984 assertions before and after. This is the same split `RestorePolicyTest` came from.

`tests/Feature/Sync/RestorePolicyTest.php`'s old no-`created` case used `['updated', 'deleted']`,
which is now a case that SHOULD dispatch. It was replaced by `['deleted']` — the only shape where no
configured event would ever have linked the model.

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

Scoped run over the changed classes after findings 26-28: **88.13% MSI** (282 tested, 38 untested),
floor 80. The rise came from the log-context assertions the `created`/`updated` split needed anyway —
naming the initiating event made two arms observably different, and pinning the cancellation message
verbatim killed its `Concat` mutants. Survivors are unchanged in kind: `Concat*` and
`RemoveArrayItem` on log message strings and log context arrays.

The figure before those findings, for comparison: **85.20% MSI** (259 tested, 45 untested). Every
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
