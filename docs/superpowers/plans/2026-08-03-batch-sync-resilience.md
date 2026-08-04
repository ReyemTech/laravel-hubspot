# Batch Sync Resilience Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make batch sync safely process arbitrary-size collections without losing surviving work or
overwriting concurrently created HubSpot links.

**Architecture:** The batch job stores local model references instead of serialized Eloquent instances,
then reloads each reference independently when its worker runs. Surviving models retain identity-aware
partitioning and each update/upsert group is chunked to HubSpot's documented 100-input limit. Link
creation becomes create-only, and `DoctorCommand` resolves its new collaborator internally to preserve
its public handler signature.

**Tech Stack:** PHP 8.3+, Laravel 12/13, Eloquent, Orchestra Testbench, Pest, PHPStan.

## Global Constraints

- Keep `Model::syncManyToHubspot(iterable $models): void` as the sole public collection API.
- Gateway remains the only layer allowed to name `HubSpot\*` SDK classes.
- Never call `Bus::batch()` or introduce a `job_batches` migration.
- Each HubSpot batch request contains at most 100 records; request count is the sum of 100-record
  update and upsert chunks.
- A homogeneous collection of at most 100 records emits one request.
- A persisted `hubspot_id` is never replaced by a batch response.
- Write failing Pest tests and commit RED before each production change.
- Preserve 100% line coverage and run scoped mutation with a minimum MSI of 80.

---

### Task 1: Lock Resilience Contracts

**Files:**
- Modify: `tests/Feature/Sync/BatchSyncTest.php`
- Modify: `tests/Feature/Registry/DoctorCommandTest.php`

**Interfaces:**
- Consumes: `SyncHubspotObjectsBatchJob`, `HubspotObjectLink`, `Hubspot::fake()` request recording,
  `DoctorCommand::handle(AssociationTypeStore): int`.
- Produces: red contracts for bounded transport, independently missing models, concurrent link
  preservation, and the command handler's original public shape.

- [ ] **Step 1: Add failing batch size contracts**

Create 101 linked models and assert two `/batch/update` requests with 100 and 1 inputs. Create 101
unlinked models and assert two `/batch/upsert` requests with 100 and 1 inputs. Use the recorded
request bodies, not just request count, so an oversized first request fails.

```php
Hubspot::assertRequestCount(2);
self::assertSame([100, 1], array_map(
    static fn (array $entry): int => count(json_decode((string) $entry['request']->getBody(), true)['inputs']),
    $fake->recordedRequests(),
));
```

- [ ] **Step 2: Add failing missing-model and concurrent-link contracts**

Dispatch a batch containing two models, hard-delete one after dispatch, run the queued job, and assert
the surviving model still sends one request and receives a link. Configure the fake response callback
to create a link for an originally unlinked model after request classification but before response
storage; assert the pre-created `hubspot_id` remains unchanged and the response's ID does not replace it.

- [ ] **Step 3: Add failing direct handler contract**

Instantiate `DoctorCommand`, provide its Laravel container, and invoke `handle($store)` with only the
existing `AssociationTypeStore` argument. Assert it returns `Command::SUCCESS` and prints the bound
model section, proving Laravel still resolves `BoundModelReporter` internally.

- [ ] **Step 4: Verify RED**

Run:

```bash
TMPDIR="$PWD/.tmp" vendor/bin/pest tests/Feature/Sync/BatchSyncTest.php tests/Feature/Registry/DoctorCommandTest.php
```

Expected: the oversized assertions fail because one endpoint receives 101 inputs; the missing-member
case loses its sibling; the concurrent link is overwritten; and direct `DoctorCommand::handle($store)`
raises an argument-count error.

- [ ] **Step 5: Commit RED**

```bash
git add tests/Feature/Sync/BatchSyncTest.php tests/Feature/Registry/DoctorCommandTest.php
git commit -m "test(04-08): cover resilient batch sync"
```

### Task 2: Reload, Chunk, and Preserve Links

**Files:**
- Modify: `src/Sync/SyncHubspotObjectsBatchJob.php`
- Modify: `src/Registry/Console/DoctorCommand.php`
- Test: `tests/Feature/Sync/BatchSyncTest.php`
- Test: `tests/Feature/Registry/DoctorCommandTest.php`

**Interfaces:**
- Consumes: `Model::newQueryWithoutScopes()`, `ModelBinding`, `ObjectGatewayContract::updateMany()` and
  `::upsertMany()`, `BoundModelReporter` resolved from the command container.
- Produces: a job that accepts the existing constructor input but persists model class/key references,
  sends only legal-size requests, and preserves links created after classification.

- [ ] **Step 1: Snapshot references in the constructor and reload each one in `handle()`**

Keep the constructor's `array $models` parameter, but convert each input model to its class and key
when constructing the job. Before binding lookup, reload every reference with its class's
`newQueryWithoutScopes()->find($key)`; discard a missing row and continue with the survivors. Return
when no survivor remains. Never leave an Eloquent model instance in the serialized job payload.

```php
/** @var class-string<Model> $class */
$model = (new $class())->newQueryWithoutScopes()->find($key);

if ($model instanceof Model) {
    $models[] = $model;
}
```

- [ ] **Step 2: Chunk each identity group at 100 records**

After `recordsFor()` validates all identifiers, iterate `array_chunk($updates, 100)` and
`array_chunk($upserts, 100)`. For each update chunk, call `updateMany()`, mark returned known links,
and log chunk errors. For each upsert chunk, call `upsertMany()`, persist returned confirmed records,
and log chunk errors. Do not chunk before duplicate validation, and do not introduce `Bus::batch()`.

```php
foreach (array_chunk($updates, 100) as $chunk) {
    $result = $gateway->updateMany($binding->objectType, $chunk);
    $this->markUpdatedRecords($result->recordsDespitePartialFailure(), $linksByHubspotId);
    $this->logErrors($result->errors(), $binding->objectType);
}
```

- [ ] **Step 3: Create links without repointing a concurrent winner**

Replace upsert-response `updateOrCreate()` with create-only persistence keyed by
`lookup_hash`, `model_id`, and `object_type`. If a query race raises a unique-constraint exception,
load the winner and retain its ID. Invoke `DeleteRaceReconciler` only when this batch actually created
the link; an existing winner has already owned its own lifecycle.

```php
$link = HubspotObjectLink::query()->firstOrCreate($identity, $attributes);

if ($link->wasRecentlyCreated) {
    App::make(DeleteRaceReconciler::class)->reconcile($model);
}
```

Laravel's `firstOrCreate()` retains the concurrent winner on a unique-constraint race and continues to
surface other database failures.

- [ ] **Step 4: Preserve `DoctorCommand::handle()` compatibility**

Restore `handle(AssociationTypeStore $store): int`. Resolve `BoundModelReporter` with
`$this->laravel->make(BoundModelReporter::class)` immediately before `reportBoundModels()`. This keeps
Laravel DI behavior and lets direct callers retain their former call shape.

- [ ] **Step 5: Correct the batch job docblock**

State that HubSpot batching is not Laravel job batching; homogeneous collections of at most 100 use one
endpoint request, and mixed or oversized collections use bounded update/upsert chunks. Remove the stale
claim that every job makes one `upsertMany()` request.

- [ ] **Step 6: Verify GREEN**

Run:

```bash
TMPDIR="$PWD/.tmp" vendor/bin/pest tests/Feature/Sync/BatchSyncTest.php tests/Feature/Registry/DoctorCommandTest.php
TMPDIR="$PWD/.tmp" vendor/bin/phpstan analyse
```

Expected: all new and existing batch and doctor contracts pass with no static-analysis errors.

- [ ] **Step 7: Commit GREEN**

```bash
git add src/Sync/SyncHubspotObjectsBatchJob.php src/Registry/Console/DoctorCommand.php tests
git commit -m "fix(04-08): harden batch sync transport"
```

### Task 3: Reconcile Contracts and Run Release Gates

**Files:**
- Modify: `docs/superpowers/specs/2026-08-03-batch-sync-identity-design.md`
- Modify: `.planning/phases/04-model-sync/04-08-SUMMARY.md`
- Modify: `.planning/phases/04-model-sync/04-08-PLAN.md`
- Modify: `.planning/REQUIREMENTS.md`
- Modify: `.planning/ROADMAP.md`
- Modify: `.planning/STATE.md`

**Interfaces:**
- Consumes: the approved resilience design and verified transport behavior.
- Produces: records that describe 100-record chunks rather than an invalid at-most-two-request limit.

- [ ] **Step 1: Replace the superseded bounded-request wording**

Update the identity design and Phase 4 records: a homogeneous collection up to 100 uses one request;
larger collections use `ceil(N / 100)` requests; mixed collections sum their separately chunked update
and upsert groups. Retain the singular public API and one queued job claims.

- [ ] **Step 2: Record review dispositions**

Record the verified HubSpot 100-input limit, missing serialized-model resilience, concurrent-link
protection, retained `DoctorCommand::handle()` compatibility, and corrected batch-job documentation.

- [ ] **Step 3: Run all release gates**

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

- [ ] **Step 4: Commit documentation reconciliation**

```bash
git add docs/superpowers/specs .planning
git commit -m "docs(04-08): record resilient batch transport"
```

## Self-Review

- Spec coverage: Task 1 proves the 100-record limit, survivor handling, concurrent links, and public
  command shape; Task 2 implements each behavior; Task 3 records the replacement contract and reruns
  every release gate.
- Placeholder scan: no deferred implementation steps or unspecified API shapes remain.
- Type consistency: the batch constructor keeps `array $models`; the worker receives reloaded
  `list<Model>` before calling `recordsFor()`. `DoctorCommand::handle()` retains exactly
  `AssociationTypeStore $store`.
