# The real-portal smoke probe

**This is the only thing in the repository that tests reality.**

Everything else — 641 tests, 100% line coverage, MSI 99%+ — runs against `Testing\HubspotFake`. The
fake's shapes are asserted against the SDK's own models, which makes it a good simulation and still
a simulation. Real auth, real HTTP, real HubSpot validation and real response bodies are outside its
reach, and every defect listed under *What this has already caught* below was invisible to it.

Opt-in, never part of the default suite, never required to merge (`CLAUDE.md`).

---

## ⚠️ It writes to and deletes from the portal the token points at

**Use a developer test account or a sandbox. Never a production portal.**

Every record is registered for deletion the instant it exists and archived in a `finally`, so a
mid-run failure still cleans up — that path is exercised, not theoretical. A process killed outright
(`SIGKILL`, closed laptop) cannot clean up, and then somebody deletes rows by hand.

If a record cannot be archived, the probe says so per record and exits non-zero.

## Running it

```bash
HUBSPOT_TOKEN=pat-... php scripts/probes/smoke.php
```

Better, keeping the token out of your shell history **and** out of this repository:

```bash
printf 'HUBSPOT_TOKEN=pat-...\n' > ~/.hubspot-uat.env
chmod 600 ~/.hubspot-uat.env
set -a; . ~/.hubspot-uat.env; set +a; php scripts/probes/smoke.php
```

`.env` is gitignored here, but this package ships no application and loads no dotenv of its own, so
a `.env` in the repository root is read by **nothing**. Putting the token there does not work and
only creates a file to leak.

**Exit status is 0 only if every step passed.** Assert on that, never on the output's wording.

## Scopes

Grant these on the private app. Measured against a real portal, not copied from documentation:

```
crm.objects.contacts.read       crm.objects.contacts.write
crm.objects.companies.read      crm.objects.companies.write
crm.objects.deals.read          crm.objects.deals.write
crm.schemas.contacts.read       crm.schemas.companies.read
```

That list is exactly what the phases below touch, and nothing more. A probe token is a real
credential on a real portal, so a scope granted "while you are in there" is standing access
nothing exercises — the opposite of what a diagnostic should cost.

The two `crm.schemas.*` entries are **required by the current probe**, not aspirational: phase 3
calls `listFor('contacts', 'companies')`, which reads the association-definitions schema for that
pair. Granting only the `crm.objects.*` block above leaves phase 3 failing on a 403 whose body does
not say which scope is missing — see below.

Worth granting at the same time, for phases not yet probed:

```
crm.objects.line_items.read     crm.objects.line_items.write
crm.schemas.deals.read          crm.schemas.line_items.read
crm.objects.custom.read         crm.objects.custom.write
crm.schemas.custom.read
```

`line_items` is here rather than above because **no phase touches it yet**. The baseline seeds
`deals ↔ line_items` (typeIds 19 and 20) and nothing has ever verified that pair against a portal,
so a future phase will want these — but granting them today buys access no code uses.

The remaining `crm.schemas.*` scopes are for property definitions, which model sync needs for
mapping. The `custom` ones matter because `Registry\HubspotObjectType` supports `p_*` custom objects
and deliberately has no allow-list — behaviour never yet exercised against a real portal.

**Not needed:** `tickets` and `products`. The package touches neither, and a STANDARD portal reports
the tickets scope "isn't available for public use" anyway.

### Two things that will waste your afternoon

**1. Newly granted scopes propagate unevenly, and it looks like a bug in your code.**

Immediately after saving scopes, consecutive identical calls disagree with each other. Measured
here, roughly a minute apart, same token, same portal:

| Attempt | `companies` | `line_items` |
|---|---|---|
| 1 | 403 | 403 |
| 2 | **READ ok** | 403 |
| 3 | 403 | **READ ok** |
| 4 | READ ok | READ ok |

Nothing changed between those runs. HubSpot's grant reaches their edge nodes at different times, so
a 403 straight after a scope change means *wait and retry*, not *the scope is wrong*. Give it a
minute or two before you start editing anything.

**2. HubSpot's 403 does not name the missing scope.**

The body says only *"This app hasn't been granted all required scopes"* plus a link. So work out the
gap by elimination — read one object of each type and see which 403s. `scripts/probes/smoke.php`
does not do this for you; it is a two-minute throwaway script when you need it.

## What each phase covers

| Phase | Verifies |
|---|---|
| `phase-02-gateway.php` | One generic class over many object types; create/find/update/search/archive; directional associate/read/dissociate; **that the two directions carry different type ids**; that a labelled write is impossible with no registry bound |
| `phase-03-registry.php` | A seeded baseline id resolving offline with no network, reaching the wire, and being **found by searching** the returned types; a registry miss throwing without substituting the inverse; the `Schema`-namespaced `DefinitionsApi`; that HubSpot really returns `label: null` for HubSpot-defined types |

### Two measurements worth keeping

Both confirmed live rather than assumed:

- **`deals → contacts` is typeId 3; `contacts → deals` is typeId 4.** Different ids for the same two
  records. This asymmetry is the reason this package exists, and a library that assumed symmetry
  would write the wrong one, HubSpot would accept it, and nobody would notice for months.
- **`contacts → companies` is 279 and its inverse is 280**, exactly as the seeded baseline claims. A
  wrong baseline id is accepted silently by HubSpot, so no test against a fake could catch it.

## What this has already caught

- A **400 on an invalid email**: `.test` is reserved by RFC 2606, and HubSpot's validator rejects it
  regardless. The fake accepted it happily.
- A **403 for a missing scope**, which is how the scope table above got written.
- That `ApiException`'s message **discards HubSpot's own explanation** for 4xx responses. HubSpot
  said *"This app hasn't been granted all required scopes"*; the package said *"failed with status
  403. Quote correlation id … to HubSpot support."* Support cannot grant your app a scope — you can.
  The detail is retained on `$e->body()`, so nothing is lost, but the message a developer reads
  first sends them somewhere useless. Only a live portal surfaces this: canned fixtures do not carry
  HubSpot's validation prose.

## Adding a phase

Drop a `phase-NN-name.php` into this directory returning `function (Probe $p): void`. The runner
globs and sorts, so **a new phase is a new file** — not an edit to a file that grows until nobody
reads it.

```php
return function (Probe $p): void {
    $p->section('Phase 4 — model sync (SYNC-01)');

    $contact = $p->objects->create('contacts', ['email' => "p4-{$p->stamp}@example.com"]);
    $p->track('contacts', $contact->id);   // ALWAYS immediately after the create
    $p->ok('created a contact', "id {$contact->id}");
};
```

The harness gives you `$p->objects`, `$p->associations`, `$p->definitions`,
`$p->associationsResolvedBy($resolver)`, `$p->stamp`, and `section()` / `ok()` / `fail()` / `note()`
/ `track()`.

Three rules the harness depends on:

1. **`track()` immediately after each create.** Not at the end of a block. The failure that strands
   a record is the one that happens in between — that is not hypothetical, it is what happened when
   the 403 landed between creating a contact and creating a company.
2. **Use `fail()` rather than throwing** for an expectation that did not hold. It records, prints,
   continues, and makes the process exit non-zero. A throw aborts the phase — the runner catches it
   per phase so later phases still run, but everything after it in *that* phase is lost.
3. **Assert, do not just print.** A step that prints a number nobody compares is decoration. Every
   `ok()` above has a real condition behind it.

## Not covered here, on purpose

The gateways and registry are wired **by hand** — no Laravel. What passes is the package, not a
container arrangement, so a green run cannot be an artefact of service-provider wiring.

The cost is that the artisan commands are out of reach: `hubspot:doctor`,
`hubspot:associations:doctor` and `hubspot:associations:sync` need an application. Verifying those
wants a scratch Laravel app, which is its own piece of work.

`hubspot:associations:doctor` is the biggest untested surface in the package: `inverse_type_id` is
null on every row the sync writes, because the two directional responses share no join key, and
**direct observation is the only thing that ever fills it in**. Nobody has watched that work.
