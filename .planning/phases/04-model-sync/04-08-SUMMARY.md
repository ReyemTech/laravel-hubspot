# 04-08 — Identity-aware HubSpot batch sync per model collection

_Completed 2026-08-03._

## What shipped

`Model::syncManyToHubspot(iterable $models): void` materialises one same-class iterable, rejects a
foreign model with both class names, and dispatches one `SyncHubspotObjectsBatchJob`. The job maps fresh
worker models and partitions eligible records by persisted HubSpot identity: linked models use one
`ObjectGatewayContract::updateMany()` request keyed by their stored IDs; unlinked models use one
`ObjectGatewayContract::upsertMany()` request keyed by the configured `id_property`. A homogeneous
linked or unlinked collection makes one request; a mixed collection makes at most two.

This is HubSpot API batching, never Laravel job batching. `Batchable` remains on the job only so a
consumer may wrap its own jobs; this package never calls `Bus::batch()` and creates no `job_batches`

An archived link is owned by the delete path and receives no property push. An unlinked soft-deleted
model is likewise excluded, while a linked unarchived soft-deleted model remains eligible for its
stored-ID update. Duplicate configured identifiers among unlinked models throw before a request, since
one upsert response cannot establish separate local link ownership.

HTTP 207 responses deliberately use `BatchResult::recordsDespitePartialFailure()`: confirmed records
write their link rows, rejected records are logged, and the job does not throw away survivors.

`Hubspot::assertSynced()` and the fake now accept either an object-type string or a bound Eloquent
model. A model resolves through `ModelBindings`; existing string callers are unchanged.

## Decision record

- **D-16 confirmed:** the public collection entry point is
  `Model::syncManyToHubspot(iterable $models): void`. Mixed model classes throw rather than inferring
  or grouping object types.
- **Partial responses:** `recordsDespitePartialFailure()` is mandatory here because `records()` refuses
  a 207, and treating survivors as absent would turn a partial success into a total local loss.
- **Architecture:** the package root may reference `Sync\ModelBindings`; existing architecture rules
  permit it, so no rule widening or container-contract accommodation was needed.
- **Identity-aware correction, 2026-08-03:** the original all-upsert, exactly-one-request collection
  promise was superseded. The public API stays singular; transport is one request for homogeneous
  identity state and at most two for mixed linked/unlinked state. Linked responses never replace their
  stored HubSpot IDs.
- **Delete-race convergence:** `DeleteRaceReconciler::reconcile(Model $model): void` is shared by the
  single and batch jobs after confirmed unlinked upserts. It replays the matching observer event rather
  than archiving directly, preserving delete policy, gates and archive evidence. Linked updates do not
  replay it because their link existed when the delete observer ran.

## Verification

- RED: `vendor/bin/pest tests/Feature/Sync/BatchSyncTest.php` failed because
  `syncManyToHubspot()` did not exist.
- GREEN focused: `BatchSyncTest` passed 5 tests / 21 assertions.
- Sync plus fake regression suites passed 147 tests / 378 assertions.
- `vendor/bin/phpstan analyse`, `vendor/bin/pint`, and `vendor/bin/phpcs --standard=phpcs.xml -q`
  passed.
- `vendor/bin/pest tests/Arch` passed 26 tests / 145 assertions.
- Full `vendor/bin/pest` reached 852 passing tests / 3,069 assertions, then failed 9 fixture-copy/write
  cases with `errno=122 Disk quota exceeded` under `/tmp`; no test assertion failed.
- `bash scripts/ci/verify-arch-rules-fire.sh` was blocked by the same `/tmp` disk quota while creating
  its scratch copy after the ordinary architecture suite passed.
- Post-remediation TDD: `test(04-08): cover identity-aware batch sync` preceded
  `fix(04-08): preserve identity in batch sync`; the shared reconciler followed in
  `fix(sync): share delete-race reconciliation`. The focused lifecycle suite passed 40 tests / 83
  assertions. `codex review --base HEAD~1` found no actionable correctness, architecture, security or
  performance issues in the shared-reconciler extraction; its sandbox subprocesses could not create
  their Testbench and PHPStan caches, so the focused command was run separately with writable `TMPDIR`.
- Reconciliation gates, run with `TMPDIR="$PWD/.tmp"`: Pint, PHPStan, PHPCS, all 877 Pest tests / 3,136
  assertions at 100% coverage, scoped mutation testing (89.34%: 578 tested / 69 untested), the
  architecture, source-hygiene, vendor-namespace and quality-gate firing harnesses, and
  `composer validate --strict` all passed.
