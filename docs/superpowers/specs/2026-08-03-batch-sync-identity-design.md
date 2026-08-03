# Batch Sync Identity Design

## Decision

`Model::syncManyToHubspot(iterable $models): void` remains the only public collection API. It accepts
models of the called class, as it does now, and makes no caller-visible mode selection.

The job partitions models by their persisted HubSpot identity:

- Models with a live `HubspotObjectLink` use one `ObjectGatewayContract::updateMany()` request keyed
  by their stored HubSpot IDs.
- Models without a link use one `ObjectGatewayContract::upsertMany()` request keyed by the configured
  `id_property`.

An all-linked or all-unlinked collection makes one HTTP request. A mixed collection makes two batch
requests at most. The former absolute one-request promise is replaced with this bounded identity-aware
batch contract.

## Lifecycle Rules

- An `archived_at` link is owned by the delete path and is excluded from property pushes.
- An unlinked, soft-deleted model is excluded so batch sync never creates a CRM record for it.
- A linked, unarchived soft-deleted model remains eligible, matching single-model update behavior when
  its delete was not mirrored.
- Every successful unlinked upsert replays the existing delete-race convergence before it is considered
  synchronized. Existing-link updates retain their stored HubSpot ID and clear stale state as the
  single-model path does.
- Duplicate values for the configured `id_property` in an unlinked group are rejected before any
  request. One response cannot unambiguously establish distinct local links for duplicate identifiers.

## Error Handling

- Partial batch results retain successful records and log rejected records, as before.
- No batch response may overwrite a stored HubSpot ID for a linked model.
- The public method continues to reject a model of another class, naming both classes.

## Verification

Regression coverage must prove:

- Changed identifiers update the stored HubSpot record rather than repointing a link.
- Mixed linked and unlinked collections make no more than two requests and use the correct identity
  strategy for each group.
- Duplicate unlinked identifiers fail before a request.
- Delete ownership and delete-race behavior match single-model sync.
- Partial results retain only confirmed link updates.

Run the existing full package gates, including 100% coverage and scoped mutation testing.
