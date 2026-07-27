# FOUND-03: The Association-Inverse Probe

**Status: RUN on 2026-07-27 against a developer test account. Answered.**

**The inverse IS automatic, and it carries its own distinct `typeId`.** Writing one
`deals → contacts` association made the `contacts → deals` direction readable immediately, with no
second write — for HubSpot's unlabelled default type *and* for a user-defined paired label. See
[Results](#results) for the raw output of both runs.

`inverse_type_id` therefore stays exactly what design spec §6.2 says it is: recorded for traversal
and verification, **never used for writes**. That is now an observed fact rather than an assumption.

The results below were transcribed from actual script output. **Do not edit them from memory, and
do not extend them by reasoning about cases that were not run** — the whole point of this probe is
that the answer is not knowable except by running it.

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
procedure creates a real contact, a real deal and a real association record (and archives all of
them on the way out). A test/developer account (free, created via
[developers.hubspot.com](https://developers.hubspot.com)) is the correct place to run this — not
any account with real customer data.

Keep the key out of your shell history, your commits, and any chat or CI log. The pattern the
2026-07-27 run used:

```bash
printf 'export HUBSPOT_PROBE_TOKEN=REPLACE_ME\n' > ~/.hubspot-probe-env && chmod 600 ~/.hubspot-probe-env
# paste the real key into that file in an editor, then:
. ~/.hubspot-probe-env && bash scripts/probes/association-inverse-probe.sh
```

The script never prints the key, so its output is safe to paste into a review or an issue. Revoke
the key once the probe is done — it exists to answer one question.

## Procedure

1. **Obtain a HubSpot developer test account access token.** Create a developer test account (or
   use an existing one), then create a **Service Key** in it — Settings → Integrations → Service
   Keys — scoped to `crm.objects.deals.read`, `crm.objects.deals.write`,
   `crm.objects.contacts.read`, `crm.objects.contacts.write`, and `crm.schemas.deals.read` (for
   looking up the association `typeId`), and copy its key.

   HubSpot now classifies private apps as **legacy** ("won't receive new API scopes or features")
   and steers single-account API access to Service Keys. A Service Key is a drop-in replacement
   here: it is sent as a plain `Authorization: Bearer <key>` header, which is what this script and
   `HubSpot\Factory::createWithAccessToken()` both already expect, so nothing in the package or the
   script changes. **Verified on 2026-07-27** — the key authenticated on first use.

1. **Create a user-defined `deals → contacts` association label** in the test account: Settings →
   Data Management → Objects → Deals → Associations → create a label. A **paired** label (each
   direction gets its own name, e.g. `Deals` one way and `People` the other) is what the run below
   used, and is the most informative shape because it makes an unmirrored inverse obvious.

   This is a prerequisite, not an optional nicety. The script deliberately refuses to run against
   HubSpot's unlabelled default type alone: unlabelled associations take a separate path in this
   package — `createDefault()`, which never resolves a `typeId` at all — so a default-only result
   would answer a different question than the one asked here, while looking like an answer.
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

| ✅ **OBSERVED** — the inverse IS automatic | ❌ not what happened |
|---|---|
| `associate()` = one API write | `associate()` = one write; `bidirectional: true` = two writes |
| `inverse_type_id` used for reads/assertions only | `inverse_type_id` becomes write-critical |

The left column is the one that applies. It was the cheaper branch for the design, but it was not
assumed — it was run.

The public API surface `associate($from, $to, label:, bidirectional:)` is identical either way —
this probe sets a *default*, it does not block the design (§6.4). Regardless of the answer, two
verification mechanisms ship with the package (not built in Phase 1):

- `associate(..., verify: true)` — an opt-in read-back that throws if the association isn't
  visible from the direction it should be, per the observed answer. Off by default (costs a
  request).
- `php artisan hubspot:associations:doctor` — probes a real portal and writes the observed answer
  into the registry, the hedge against per-portal behaviour or a future HubSpot platform change.

## Results

Transcribed from actual script output on 2026-07-27. Two runs: the first landed on HubSpot's
unlabelled default type (before the script was tightened to require a user-defined label), the
second on a paired user-defined label. Both are recorded — the default-type run is not noise, it
answers the `createDefault()` path this package uses for unlabelled associations.

| Field | Run 1 — unlabelled default | Run 2 — user-defined paired label |
|---|---|---|
| Date run | 2026-07-27 | 2026-07-27 |
| Run by | Claude Code, on Mario Meyer's machine, key supplied by the owner | same |
| HubSpot portal type | developer test account (`na2`) | same |
| Association written | `deals → contacts` | `deals → contacts`, label `Deals` (paired with `People`) |
| `typeId` / category used for the write | `3` / `HUBSPOT_DEFINED` | `1` / `USER_DEFINED` |
| `GET .../deals/{id}/associations/contacts` returned | 1 result: `typeId 3`, `HUBSPOT_DEFINED`, label `null` | 1 result carrying **two** types: `typeId 3` `HUBSPOT_DEFINED` label `null`, **and** `typeId 1` `USER_DEFINED` label `Deals` |
| `GET .../contacts/{id}/associations/deals` returned | 1 result: `typeId 4`, `HUBSPOT_DEFINED`, label `null` | 1 result carrying **two** types: `typeId 2` `USER_DEFINED` label `People`, **and** `typeId 4` `HUBSPOT_DEFINED` label `null` |
| **Inverse automatic?** | **YES** | **YES** |
| `inverse_type_id` observed | `4` (write used `3`) | `2` (write used `1`) |

Raw output of run 2, step 4 (the write) — note `labels`, which confirms the label was applied and
not silently dropped:

```json
{
  "fromObjectTypeId": "0-3", "fromObjectId": 338960291537,
  "toObjectTypeId": "0-1",  "toObjectId": 527152015051,
  "labels": ["Deals"]
}
```

Raw output of run 2, step 6 — the inverse direction, which is the actual question:

```json
{
  "results": [
    {
      "toObjectId": 338960291537,
      "associationTypes": [
        { "category": "USER_DEFINED",    "typeId": 2, "label": "People" },
        { "category": "HUBSPOT_DEFINED", "typeId": 4, "label": null }
      ]
    }
  ]
}
```

### What was observed, beyond the question asked

Three findings the probe produced that the design did not ask about, all load-bearing later:

1. **The inverse `typeId` differs from the written one in every case** — `3 → 4` unlabelled,
   `1 → 2` labelled. This is the empirical confirmation of the premise the whole package rests on
   (design spec §6: Contact→Company 279 vs Company→Contact 280). The pairing is real, and reading
   an association back does **not** hand you the id you wrote.

2. **A labelled write also materialises the unlabelled default association.** Run 2 wrote only
   `typeId 1`, yet the forward read returned *both* `typeId 1` (the label) and `typeId 3` (the
   default). Labelling is additive on top of the default association, not a replacement for it.

3. **An association READ returns a LIST of `associationTypes` per related record, not one type** —
   and after a labelled write that list contains both the label and the default, in an order that
   is not guaranteed (run 2's inverse read put `USER_DEFINED` first; the forward read put
   `HUBSPOT_DEFINED` first).

   This constrains the components that parse a **read response**: `associate(..., verify: true)`
   and `php artisan hubspot:associations:doctor`. Both must search the list for the expected
   directional id, and neither may take "the first" or "the only" type — on this output that would
   succeed regardless of which id was written, i.e. for the wrong reason.

   It does **not** constrain `Hubspot::assertAssociated()`, despite the surface similarity. Per
   `02-06-PLAN.md` Task 2 that assertion parses the recorded *outgoing request* — the path segments
   for direction, and the decoded request body for the type id — so it never sees a read response
   at all. The write body is its own list (`[{associationCategory, associationTypeId}]`) and needs
   its own search, but for a different reason and against a different payload. Conflating the two
   would send the fake's implementation looking for a field that is not in the payload it reads.

### What this settles, and what it does not

- **Settles:** `associate()` is one API write. `inverse_type_id` stays read/verification-only
  (design spec §6.2), unchanged. A future `bidirectional:` parameter defaults to *not* issuing a
  second write, since HubSpot already maintains the other direction.
- **Does not settle:** whether this holds for every object-type pair, for `INTEGRATOR_DEFINED`
  associations, or permanently. It was observed on one pair (`deals ↔ contacts`) in one portal on
  one date. That is exactly why both hedges named below still ship.

## Re-running it

This is answered, so re-running is a verification exercise rather than an unblocking one. It is
worth doing when HubSpot changes its association platform, when a user reports an association that
does not appear from the direction they expect, or when extending the answer to an object-type pair
other than `deals ↔ contacts`.

Prerequisites are the same as the first run: a developer test account, a Service Key with the five
scopes in the [Procedure](#procedure), and a user-defined `deals → contacts` label in that account.
Then:

```bash
. ~/.hubspot-probe-env && bash scripts/probes/association-inverse-probe.sh
```

If a re-run ever contradicts the table above, **do not edit the table to match** — add a second
dated row and say so loudly. A behaviour that changed is a far more important finding than a
behaviour that held, and overwriting the original would destroy the evidence that it changed.
