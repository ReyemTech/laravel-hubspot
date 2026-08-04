# Batch Sync Identity Remediation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make batch sync preserve persisted HubSpot identity and match the single-model delete lifecycle.

**Architecture:** `syncManyToHubspot()` remains the only public collection API. Its job separates linked
models from unlinked models: linked models use Gateway `updateMany()` by stored HubSpot ID and unlinked
models use `upsertMany()` by configured identifier. A small shared reconciler replays the existing
observer delete policy only after a newly linked model races a delete.

**Tech Stack:** PHP 8.3+, Laravel 12/13, Eloquent, Orchestra Testbench, Pest, PHPStan.

## Global Constraints

- Keep `Model::syncManyToHubspot(iterable $models): void` as the sole public collection API.
- Gateway remains the only layer allowed to name `HubSpot\*` SDK classes.
- Never call `Bus::batch()` or introduce a `job_batches` migration.
- A homogeneous collection emits one HubSpot batch request; a mixed linked/unlinked collection emits at most two.
- Write failing Pest tests and commit RED before each production change.
- Preserve 100% line coverage and run scoped mutation with a minimum MSI of 80.

---

### Task 1: Lock Identity-Aware Batch Contracts

**Files:**
- Modify: `tests/Feature/Sync/BatchSyncTest.php`
- Test: `tests/Feature/Sync/BatchSyncTest.php`

**Interfaces:**
- Consumes: `SyncHubspotObjectsBatchJob`, `HubspotObjectLink`, `ObjectGatewayContract` request recording.
- Produces: regression contracts for linked updates, unlinked upserts, duplicate rejection, and delete ownership.

- [ ] **Step 1: Write failing tests for persisted identity and duplicate validation**

Add tests that seed a link with `hubspot_id: "existing-id"`, change the model email, then assert the
recorded request is the batch update route with `id: "existing-id"`, the link remains `existing-id`,
and the request count is one. Add a mixed linked/unlinked case asserting two requests and one link per
model. Add duplicate unlinked emails asserting `ConfigurationException` and zero requests.

- [ ] **Step 2: Write failing tests for lifecycle parity**

Add one linked soft-deleted model with `archived_at: null` and assert it remains in the update batch.
Add one linked model with `archived_at` set and assert it is excluded. Retain the unlinked trashed
assertion. Add an unlinked upsert race by soft-deleting the database row while the in-memory model stays
live, then assert the replayed delete policy marks the new link archived.

- [ ] **Step 3: Run the focused tests and verify RED**

Run: `TMPDIR="$PWD/.tmp" vendor/bin/pest tests/Feature/Sync/BatchSyncTest.php`

Expected: failures because the current job upserts every model, overwrites links, accepts duplicate
identifiers, and treats every trashed model as delete-owned.

- [ ] **Step 4: Commit RED**

Run:
```bash
git add tests/Feature/Sync/BatchSyncTest.php
```

### Task 2: Partition Batch Writes by Persisted Identity

**Files:**
- Modify: `src/Sync/SyncHubspotObjectsBatchJob.php`
- Test: `tests/Feature/Sync/BatchSyncTest.php`

**Interfaces:**
- Consumes: `ModelBindings::for()`, `PropertyMapper::map()` and `mapForUpdate()`,
  `ObjectGatewayContract::updateMany()` and `upsertMany()`.
- Produces: at most one `updateMany()` and one `upsertMany()` request, with stored links never repointed.

- [ ] **Step 1: Classify each model before any request**

Resolve each model's current `hubspotLink()`. Skip only links with non-null `archived_at`, plus unlinked
soft-deleted models. Map linked models through `mapForUpdate()` and build `['id' => $link->hubspot_id,
'properties' => $properties]`; map unlinked models through `map()` and build an upsert record from the
configured identifier.

- [ ] **Step 2: Reject ambiguous unlinked identifiers before remote work**

Build the unlinked model map as `array<string, Model>`. Before assigning each item, throw
`ConfigurationException::idPropertyNotMapped()` only for missing/empty values and add a dedicated package
configuration exception for duplicate identifiers naming the model class, id property, and duplicate
value. Perform this validation before either Gateway call.

- [ ] **Step 3: Send the two bounded batches and persist results correctly**

Call `updateMany()` only when linked records exist. Match returned records by HubSpot ID and update only
`synced_at`, `is_stale`, and `stale_at` on the known link; never replace `hubspot_id`. Call `upsertMany()`
only when unlinked records exist, use `recordsDespitePartialFailure()`, and create links only for matching
submitted identifiers. Log each result's errors.

- [ ] **Step 4: Run the focused tests and verify GREEN**

Run: `TMPDIR="$PWD/.tmp" vendor/bin/pest tests/Feature/Sync/BatchSyncTest.php`

Expected: all identity, duplicate, partial-result, and delete-ownership contracts pass.

### Task 3: Reuse Delete-Race Convergence

**Files:**
- Create: `src/Sync/DeleteRaceReconciler.php`
- Modify: `src/Sync/SyncHubspotObjectJob.php`
- Modify: `src/Sync/SyncHubspotObjectsBatchJob.php`
- Test: `tests/Feature/Sync/DeletePolicyTest.php`
- Test: `tests/Feature/Sync/BatchSyncTest.php`

**Interfaces:**
- Consumes: a `Model`, `HubspotObserver`, `SoftDeletes`, and the newly persisted link.
- Produces: `DeleteRaceReconciler::reconcile(Model $model): void`, called after unlinked single and batch upserts.

- [ ] **Step 1: Extract the single-job delete-race behavior without changing it**

Move `SyncHubspotObjectJob::archiveIfTheModelWasDeletedMeanwhile()` and its race log into an injected
`DeleteRaceReconciler`. Preserve its current event choice: missing soft-deleting row calls
`HubspotObserver::forceDeleted()`, missing plain row calls `deleted()`, and a present trashed row calls
`trashed()`.

- [ ] **Step 2: Invoke the same reconciler for each confirmed unlinked batch survivor**

After each matching upsert response creates its link, call `DeleteRaceReconciler::reconcile($model)`.
Do not replay delete convergence for linked updates: the delete observer already had a link on which to
act, matching the existing single-job update path.

- [ ] **Step 3: Run lifecycle regressions and verify GREEN**

Run:
```bash
TMPDIR="$PWD/.tmp" vendor/bin/pest tests/Feature/Sync/DeletePolicyTest.php tests/Feature/Sync/BatchSyncTest.php
```

Expected: existing single-job delete tests and the new batch race test pass with the same archive policy.

- [ ] **Step 4: Commit GREEN**

Run:
```bash
git add src/Sync tests/Feature/Sync
```

### Task 4: Reconcile GSD Records and Run Release Gates

**Files:**
- Modify: `.planning/phases/04-model-sync/04-08-SUMMARY.md`
- Modify: `.planning/phases/04-model-sync/04-08-PLAN.md`
- Modify: `.planning/REQUIREMENTS.md`
- Modify: `.planning/ROADMAP.md`
- Modify: `.planning/STATE.md`

**Interfaces:**
- Consumes: approved design `docs/superpowers/specs/2026-08-03-batch-sync-identity-design.md`.
- Produces: planning records that state the revised at-most-two-request contract and its verification.

- [ ] **Step 1: Correct the Phase 4 promise and summary**

Record that the public API remains singular but transport is identity-aware: one request for homogeneous
state and at most two for a mixed collection. Remove claims that every collection has exactly one request.

- [ ] **Step 2: Run all required gates**

Run:
```bash
TMPDIR="$PWD/.tmp" vendor/bin/pint
TMPDIR="$PWD/.tmp" vendor/bin/phpstan analyse
TMPDIR="$PWD/.tmp" vendor/bin/phpcs --standard=phpcs.xml -q
TMPDIR="$PWD/.tmp" vendor/bin/pest --coverage --min=100
TMPDIR="$PWD/.tmp" vendor/bin/pest --mutate --parallel --min=80 --class="$(bash scripts/ci/mutation-scope.sh origin/main)"
TMPDIR="$PWD/.tmp" bash scripts/ci/verify-arch-rules-fire.sh
TMPDIR="$PWD/.tmp" bash scripts/ci/check-source-hygiene.sh --self-test
TMPDIR="$PWD/.tmp" bash scripts/ci/check-vendor-namespaces.sh --self-test
TMPDIR="$PWD/.tmp" bash scripts/ci/verify-quality-gates-fire.sh
composer validate --strict
```

- [ ] **Step 3: Commit planning reconciliation**

Run:
```bash
git add .planning
```

## Self-Review

- Spec coverage: Tasks 1-3 cover persisted identity, bounded batching, duplicate rejection, delete
  ownership, and delete-race convergence. Task 4 corrects the prior planning promise and reruns gates.
- Placeholder scan: no placeholders or deferred implementation steps remain.
- Type consistency: the only new interface is `DeleteRaceReconciler::reconcile(Model $model): void`,
  consumed by both sync jobs.
