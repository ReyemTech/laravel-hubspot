# FOUND-03: The Association-Inverse Probe

**Status: not yet run.** This is a ready-to-run procedure whose only missing input is a HubSpot
developer test account access token. **Do not fill in the results table by guessing, reasoning, or
recalling something similar from another API.** The whole point of this probe is that the answer
is not knowable except by running it.

## The question

Design spec §6.4 states it plainly:

> HubSpot's docs do not state whether creating an association from A to B makes it readable from
> B to A. Checked directly; the only related wording is a warning to use the typeId for the
> correct direction, which is about choosing an id, not about how many records are written.

Concretely: if `scripts/probes/association-inverse-probe.sh` creates a labelled
`deals → contacts` association, does `GET /crm/v4/objects/contacts/{contactId}/associations/deals`
(the inverse direction, `contacts → deals`) return that association automatically? Or does nothing
appear on that side unless the write is made bidirectional explicitly?

This is not an academic question. §6.2's registry stores `inverse_type_id` "for traversal and
verification only — never used for writes" specifically because the design spec assumes the two
directions can disagree. If the inverse *is* automatic, that column stays read-only exactly as
documented. If it is *not* automatic, `inverse_type_id` becomes **write-critical** the moment
`associate(..., bidirectional: true)` is implemented in Phase 3 — and a wrong default in that
implementation would write associations in the wrong direction where nobody notices for months
(the exact failure mode note 202/201 and 279/280 exist to prevent).

## Why this can't be answered from documentation alone

HubSpot's public API reference documents the *shape* of an association write and the *shape* of
an association read. It does not document the *side effect* of a write on the opposite direction's
read. The only adjacent guidance found during design-spec research was a warning about picking the
correct `typeId` for the direction you intend — which is a warning about choosing the right id, not
a statement about how many association records a single write produces. Absent that documentation,
the only way to know is to write one association and read both directions back.

## Safety

**Run this against a HubSpot developer test account only. Never a production portal.** The
procedure creates a real association record. A test/developer account (free, created via
[developers.hubspot.com](https://developers.hubspot.com)) is the correct place to run this — not
any account with real customer data.

## Procedure

1. **Obtain a HubSpot developer test account private-app access token.** This is the only missing
   input. Create a developer test account (or use an existing one), create a private app scoped to
   `crm.objects.deals.read`, `crm.objects.deals.write`, `crm.objects.contacts.read`,
   `crm.objects.contacts.write`, and `crm.schemas.deals.read` (for looking up the association
   `typeId`), and copy its access token.
2. **Export the token** into the environment variable the script reads (see
   `scripts/probes/association-inverse-probe.sh` — `HUBSPOT_PROBE_TOKEN`). Never commit it, never
   paste it into this file, never paste it into a chat log or CI output.
3. **Create a deal and a contact** to associate (the script creates disposable test records for
   this — it does not touch any existing data, since this is a fresh developer test account).
4. **Create a labelled `deals → contacts` association** between them (a non-default association
   type, so the direction is unambiguous — the design spec's own example, §6, uses this exact
   pair).
5. **Read the association back from both directions**:
   - `GET /crm/v4/objects/deals/{dealId}/associations/contacts` (the direction that was written)
   - `GET /crm/v4/objects/contacts/{contactId}/associations/deals` (the inverse direction — this is
     the actual question)
6. **Record what `scripts/probes/association-inverse-probe.sh` prints** in the results table
   below, by hand, from the script's own output. Do not infer or interpolate a result that wasn't
   printed.
7. **Clean up** the test deal and contact created in step 3 (the script does this automatically at
   the end of a successful run).

## What the two possible outcomes mean for the design

| If the inverse IS automatic | If it is NOT |
|---|---|
| `associate()` = one API write | `associate()` = one write; `bidirectional: true` = two writes |
| `inverse_type_id` used for reads/assertions only | `inverse_type_id` becomes write-critical |

The public API surface `associate($from, $to, label:, bidirectional:)` is identical either way —
this probe sets a *default*, it does not block the design (§6.4). Regardless of the answer, two
verification mechanisms ship with the package (not built in Phase 1):

- `associate(..., verify: true)` — an opt-in read-back that throws if the association isn't
  visible from the direction it should be, per the observed answer. Off by default (costs a
  request).
- `php artisan hubspot:associations:doctor` — probes a real portal and writes the observed answer
  into the registry, the hedge against per-portal behaviour or a future HubSpot platform change.

## Results

**Do not fill this in without running the script.** Leave every cell blank until an actual run has
produced actual output to transcribe.

| Field | Value |
|---|---|
| Date run | *(blank — fill in after running)* |
| Run by | *(blank)* |
| HubSpot portal type | *(blank — must read "developer test account")* |
| Association written | `deals → contacts`, label: *(blank)* |
| `typeId` used for the write | *(blank)* |
| `GET .../deals/{id}/associations/contacts` returned | *(blank)* |
| `GET .../contacts/{id}/associations/deals` returned | *(blank)* |
| Inverse automatic? | *(blank — YES / NO, from the above, not assumed)* |
| `inverse_type_id` observed (if returned) | *(blank)* |
| Raw script output | *(attach or link — do not summarize away the actual response bodies)* |

## Unblocking this

The only thing standing between this document and a filled-in results table is a HubSpot
developer test account access token, exported as `HUBSPOT_PROBE_TOKEN`, and running:

```bash
HUBSPOT_PROBE_TOKEN=your-test-account-token bash scripts/probes/association-inverse-probe.sh
```
