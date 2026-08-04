# Batch Sync Resilience Design

**Date:** 2026-08-03
**Status:** Approved

## Context

Identity-aware batch sync separates linked models, updated by their stored HubSpot IDs, from unlinked
models, upserted by their configured identifiers. Review found four gaps:

- HubSpot limits CRM batch operations to 100 inputs, while `syncManyToHubspot()` accepts an arbitrary
  iterable.
- Laravel can discard a serialized job when one queued Eloquent model is hard-deleted, silently
  skipping every remaining model in that job.
- A link created after batch classification can be overwritten by an upsert response.
- `DoctorCommand::handle()` gained a required public parameter, breaking direct callers.

HubSpot documents that CRM batch operations are limited to 100 records per request:
https://developers.hubspot.com/docs/api-reference/crm-contacts-v3/batch/post-crm-v3-objects-contacts-batch-upsert

## Decision

`Model::syncManyToHubspot(iterable $models): void` remains the sole public collection API and still
queues one job. The job snapshots each input as a model-class/key reference rather than serializing
the model instance. At handling time it reloads each reference without global scopes; a missing row
is skipped while all surviving rows proceed.

The job keeps its identity-aware partitioning, then chunks each non-empty group into inputs of at
most 100:

- linked records use `updateMany()` by stored HubSpot ID;
- unlinked records use `upsertMany()` by configured identifier;
- the request count is `ceil(update_count / 100) + ceil(upsert_count / 100)`;
- any homogeneous group of at most 100 records still emits exactly one request.

After an upsert response, the job creates a link only if one still does not exist. If another worker
created a link after classification, its HubSpot ID is retained and delete-race reconciliation is not
replayed. `DeleteRaceReconciler` runs only after this job created the link itself.

`DoctorCommand::handle()` keeps its former public signature. It resolves `BoundModelReporter` from
the command container instead of accepting a new required parameter.

## Tests

RED contracts must prove:

- 101 linked and 101 unlinked records split into two requests per identity group, with no request
  containing more than 100 inputs;
- a hard-deleted queued member is skipped while surviving members still sync;
- a link created while an unlinked upsert is in flight is not repointed by the response;
- direct invocation of `DoctorCommand::handle(AssociationTypeStore $store)` remains valid;
- the batch-job docblock and planning records describe chunked, identity-aware transport.

All existing identity, delete-lifecycle, coverage, mutation, architecture, static-analysis and
Composer gates remain required.

## Non-goals

- No public collection API, new configuration, queue-batch API, or `job_batches` migration.
- No SDK classes outside `Gateway`.
- No recovery for a model that is absent when the worker begins; it is intentionally skipped because
  no local row remains to map or link.
