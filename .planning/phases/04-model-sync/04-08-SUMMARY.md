# 04-08 — One HubSpot batch request per model collection

_Completed 2026-08-03._

## What shipped

`Model::syncManyToHubspot(iterable $models): void` materialises one same-class iterable, rejects a
foreign model with both class names, and dispatches one `SyncHubspotObjectsBatchJob`. The job maps fresh
worker models, filters soft-deleted models before building the payload, and calls
`ObjectGatewayContract::upsertMany()` exactly once.

This is HubSpot API batching, never Laravel job batching. `Batchable` remains on the job only so a
consumer may wrap its own jobs; this package never calls `Bus::batch()` and creates no `job_batches`

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
