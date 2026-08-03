# Batch Sync Identity Design

## Decision

`Model::syncManyToHubspot(iterable $models): void` remains the only public collection API. It accepts
models of the called class, as it does now, and makes no caller-visible mode selection.

The job partitions models by their persisted HubSpot identity, then chunks each non-empty group into
requests of at most 100 inputs:

- Models with a live `HubspotObjectLink` use `ObjectGatewayContract::updateMany()` requests keyed by
  their stored HubSpot IDs.
- Models without a link use `ObjectGatewayContract::upsertMany()` requests keyed by the configured
  `id_property`.

Any homogeneous collection of at most 100 records makes exactly one HTTP request; a larger homogeneous
collection makes `ceil(N / 100)` requests. A mixed collection sums the independently chunked update and
upsert groups: `ceil(update_count / 100) + ceil(upsert_count / 100)`. The public API remains singular
and dispatches one queued job; this is HubSpot API batching, not Laravel job batching.

HubSpot documents the 100-record batch-operation limit at
https://developers.hubspot.com/docs/api-reference/crm-contacts-v3/batch/post-crm-v3-objects-contacts-batch-upsert.

## Lifecycle Rules

- An `archived_at` link is owned by the delete path and is excluded from property pushes.
- An unlinked, soft-deleted model is excluded so batch sync never creates a CRM record for it.
- A linked, unarchived soft-deleted model remains eligible, matching single-model update behavior when
  its delete was not mirrored.
- The queued job stores model-class/key references, reloads them without global scopes, and skips a
  missing row while syncing every surviving row.
- An upsert response creates a link only when no link exists at response time. A concurrent worker's
  link and stored HubSpot ID win; delete-race reconciliation runs only when this job created the link.
  Existing-link updates retain their stored HubSpot ID and clear stale state as the single-model path
  does.
- Duplicate values for the configured `id_property` in an unlinked group are rejected before any
  request. One response cannot unambiguously establish distinct local links for duplicate identifiers.

## Error Handling

- Partial batch results retain successful records and log rejected records, as before.
- No batch response may overwrite a stored HubSpot ID for a linked model.
- The public method continues to reject a model of another class, naming both classes.

## Verification

Regression coverage must prove:

- Changed identifiers update the stored HubSpot record rather than repointing a link.
- No request contains more than 100 inputs; 101-record identity groups make two requests, and mixed
  collections sum their independently chunked update and upsert requests.
- Duplicate unlinked identifiers fail before a request.
- Delete ownership and delete-race behavior match single-model sync.
- Direct `DoctorCommand::handle(AssociationTypeStore $store)` invocation remains compatible; the command
  resolves `BoundModelReporter` from its container.
- Partial results retain only confirmed link updates.

Run the existing full package gates, including 100% coverage and scoped mutation testing.
