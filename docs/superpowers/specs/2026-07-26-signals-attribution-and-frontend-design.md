# reyemtech/laravel-hubspot — Intent signals, attribution and frontend

**Status:** Approved in brainstorming, ready for planning
**Date:** 2026-07-26
**Extends:** `2026-07-26-laravel-hubspot-design.md` (the core design)
**Supersedes:** `../../briefs/2026-07-26-meetings-embed-and-attribution.md` (candidates C1 and C2 are adopted here)
**Standards:** `../../../STANDARDS.md` — binding, and **amended by §14 of this document**

---

## 1. What this adds and why

The core design covers CRM objects, directional associations and inbound webhooks. This
document adds three things that came out of instrumenting a real funnel ahead of paid
acquisition spend:

1. **Intent signals** — recording behavioural signals against HubSpot records, the way a
   `dataLayer` push records them for GA4.
2. **Attribution properties** — paid click ids and first-touch landing data surviving a 3–10 week
   sales cycle, so ad spend can be traced to pipeline.
3. **A Meetings embed component** — a Blade component wrapping HubSpot's meetings iframe with a
   nonce-aware, origin-validating booking listener.

All three are currently hand-rolled in every Laravel app doing HubSpot-backed lead capture.

**The load-bearing insight:** GA4 and HubSpot are not the same kind of sink. GA4 accepts
unlimited fire-and-forget events from anonymous visitors and resolves identity later. HubSpot
has hard API rate limits, requires a contact to exist before anything can be written to it, and
models records as mutable property bags rather than event streams. A `dataLayer` push cannot map
1:1 to a HubSpot write. Everything below follows from that.

---

## 2. Decisions

| # | Decision | Rationale |
|---|---|---|
| D1 | All of this is v1 scope, not a later milestone | Owner's call, made against a stated 6→9 phase cost |
| D2 | The package ships frontend assets, in an isolated namespace | C1 needs them; isolation keeps the CRM core frontend-free |
| D3 | Signals land as **both** events and properties | Properties are what HubSpot workflows and lists can act on; events are what preserve history. Neither alone is sufficient |
| D4 | Event history sits behind a `SignalStore` driver contract | Portal tier and credential requirements differ per surface; mirrors the `cache`/`database` pattern of core §6.3 |
| D5 | Three drivers ship in v1: `local`, `custom_object`, `timeline` | Maximum portal coverage from the first release |
| D6 | The package owns anonymous buffering, and it **requires** the database store | The pre-identity window is where attribution value lives. A cache fallback would be silently lossy, and losing the buffer loses the attribution |
| D7 | `Signals` is a peer layer; `Frontend` is a leaf | Signals are event-shaped, `Sync` is model-shaped; merging them blurs the largest existing boundary |
| D8 | Roll-up is a declarative signal→property map | Consistent with `$hubspotMap` (core §5). A class per signal type would repeat the per-type-class flaw core §1.1 identifies as tapp's root problem |
| D9 | The consuming app supplies the visitor id; the package never reads cookies or session | Keeps `Signals` free of request-scoped state, so it stays a clean peer layer |
| D10 | A documentation site is adopted | See §14.4 — the standards' own trigger condition fired |

---

## 3. Architecture

Core §3's four layers become six:

```
Gateway    → hubspot/api-client          ONLY layer that may name HubSpot\*
Registry   → Gateway
Sync       → Registry, Gateway           model-driven outbound
Webhooks   → Registry, Gateway           inbound
Signals    → Registry, Gateway           event-driven outbound          [NEW]
Frontend   → public facade only          Blade + JS, leaf               [NEW]
```

Two new architecture-test rules:

- **`Signals` may not depend on `Sync` or `Webhooks`.** It is a peer, not a consumer.
- **`Frontend` may not name `HubSpot\*`, `Gateway`, `Registry`, `Sync`, `Webhooks` or `Signals`.**
  It talks to the same public facade a consumer would. This prevents the frontend becoming a back
  door around the boundary that makes the SDK swappable.

---

## 4. Components

Six units in `Signals`, each with one job and one reason to change:

| Unit | Job | Depends on |
|---|---|---|
| `SignalRecorder` | Public entry. Validates the signal against the map, writes one buffer row. **Never calls the API** | `SignalBuffer` |
| `SignalBuffer` | Durable store of recorded signals, anonymous and identified, until flushed | database |
| `IdentityResolver` | Binds a visitor id to a bound model; backfills the subject on buffered rows | `SignalBuffer` |
| `RollUpCalculator` | Pure function `(signals, map) → property array`. No I/O, no HubSpot knowledge | nothing |
| `FlushSignalsJob` | Queued and batched. Computes roll-ups, writes properties, appends the trail, marks rows flushed | all of the above, `ObjectGateway` |
| `SignalStore` + drivers | Event-history half: `local`, `custom_object`, `timeline` behind one contract | `ObjectGateway` (two drivers) |

`RollUpCalculator` having no dependencies at all is deliberate. The merge semantics — including
first-touch-wins, the subtlest behaviour here — become provable with no HTTP, no database and no
fake.

---

## 5. Data flow

```
Hubspot::signal('pricing_page_viewed', $visitorId, ['source' => 'google_ads', 'gclid' => '…'])
    → validate against the map
    → one buffer row                                           [no API call]

Hubspot::identify($visitorId, $user)          ← when an email finally appears
    → backfill subject on every buffered row for that visitor

FlushSignalsJob  (queued; dispatched on identify, and on a schedule)
    → RollUpCalculator: buffered signals → absolute property values
    → ObjectGateway: ONE batch write of properties to contact/company/deal
    → SignalStore driver: append the trail
    → mark rows flushed
```

Three properties follow from this shape, each satisfying an existing standard rather than needing
a new one:

- **No API call in the request lifecycle**, and one batch write per flush rather than N.
  Standards §11, provable with `assertRequestCount()`.
- **Roll-ups are absolute values computed from the buffer**, never read back from HubSpot. A flush
  is therefore idempotent and a queue retry cannot double-count.
- **The read-then-write concurrency hazard disappears.** The buffer holds every signal, so the
  package never needs to ask HubSpot what the first touch was — it already knows.

### 5.1 The one caveat that survives

Contacts that existed *before* signals were enabled may hold attribution values the buffer never
saw. A `first_wins` roll-up computed purely from the buffer would overwrite them.

Resolved per property, explicitly, never silently:

```php
'first_touch_source' => 'first_wins:source',              // buffer is truth
'first_touch_source' => 'first_wins:source|reconcile',    // one read before first write
```

`reconcile` costs one additional request per subject on the first flush only, and is recorded on
the buffer row so it never repeats.

---

## 6. The signal map

> **Amended 2026-08-12 (D-08, plan 06-02).** Two changes from the original draft below, both
> load-bearing rather than cosmetic: (1) the closure escape hatch in `'intent_score'` is replaced
> with an invokable class-string -- `php artisan config:cache` serialises `config/hubspot.php` with
> `var_export()`, which throws *"Your configuration files are not serializable"* the moment a
> closure appears anywhere in it, a production-breaking regression invisible until someone deploys
> with cached config. That pattern is legal for `$hubspotMap` (core spec §5) only because those
> closures live on a MODEL CLASS, never in a config file. (2) each signal's per-property rules are
> nested under a `'properties'` key, alongside `'object'` -- matching what `Signals\SignalMap` and
> `Signals\FlushSignalsJob` (06-01) actually implement; the flat shape below (rules as SIBLINGS of
> `'object'`) was never built.

```php
'signals' => [
    'enabled'        => env('HUBSPOT_SIGNALS', false),
    'store'          => env('HUBSPOT_SIGNAL_STORE', 'local'),   // local | custom_object | timeline
    'retention_days' => 90,

    'map' => [
        'pricing_page_viewed' => [
            'object' => 'contacts',
            'properties' => [
                'last_pricing_view'  => 'last_wins:occurred_at',
                'pricing_view_count' => 'increment',
                'first_touch_source' => 'first_wins:source',
            ],
        ],

        'demo_requested' => [
            'object' => 'contacts',
            'properties' => [
                'intent_score' => App\Signals\IntentScore::class,   // __invoke(Collection $signals)
            ],
        ],
    ],
],
```

**Merge verbs — a closed vocabulary of four:**

| Verb | Meaning |
|---|---|
| `first_wins:<field>` | The earliest signal's value of `<field>`. Never overwritten once set |
| `last_wins:<field>` | The most recent signal's value of `<field>` |
| `increment` | Count of matching signals |
| `sum:<field>` | Sum of a numeric field across matching signals |

Plus an invokable class-string implementing `Signals\Contracts\SignalCalculator`
(`__invoke(Collection $signals): mixed`), for the rare case the vocabulary does not cover. This is
deliberately the same three-tier flexibility core §5 gives `$hubspotMap` — literals, field
references, an invokable escape hatch — which the core spec keeps from tapp on purpose. The
class-string form, not a closure, is what keeps `config/hubspot.php` a plain, `config:cache`-safe
array; see the amendment note above.

**Ambiguity resolved:** an earlier draft listed `overwrite` and `last_wins` as separate verbs.
They are the same operation. Only `last_wins` exists.

An unknown signal name or an unknown merge verb throws `ConfigurationException` naming the fix.
The map is validated at boot, not at flush time, so a typo fails fast rather than silently
dropping data.

---

## 7. Identity and buffering

**The app supplies the visitor id** (D9). Any stable string — a GA4 client id, a first-party
cookie value, a session id. The package never sets a cookie, reads the session, or inspects the
request. This keeps `Signals` free of request-scoped state.

**Buffering requires the database store** (D6). Standards §6.3 already establishes that migrations
exist only when a database store is active, so this does **not** violate the zero-migration
install: signals are off by default, and `composer require` plus a trait still works with no
publish step and no `migrate`. Enabling signals is what requires the migration.

A cache-backed buffer was explicitly rejected. Cache is evictable by definition; the `li_fat_id`
case needs a 90-day window, and losing the buffer loses the attribution the feature exists to
protect. Better to be explicitly off than silently lossy.

`hubspot_signals` schema:

| Column | Notes |
|---|---|
| `visitor_id` | caller-supplied, indexed |
| `subject_type`, `subject_id` | null until identity resolves |
| `signal_name` | validated against the map |
| `properties` | json payload |
| `occurred_at` | caller-supplied or now; frozen in tests |
| `flushed_at` | null until written to HubSpot |
| `reconciled_at` | set when a `reconcile` read has happened for this subject |

**Garbage collection is not optional.** This table is fed by page-view-grain traffic and anonymous
visitors who never identify accumulate forever. `php artisan hubspot:signals:prune` deletes
flushed rows and unidentified rows older than `retention_days`, defaulting to the 90 days the
`li_fat_id` case requires.

---

## 8. Storage drivers

All three write the same roll-up properties. They differ only in where the event trail goes.

| Driver | HubSpot visibility | Cost |
|---|---|---|
| `local` | none — roll-up properties only | none. No new credential, no tier gate, no portal schema |
| `custom_object` | associated records on the contact | tier-gated; consumer creates the object schema |
| `timeline` | native on the contact timeline | third credential class; per-consumer developer app |

`local` is the default because it is the only one that works on any portal with no additional
setup.

**`custom_object`** reuses the generic objects API of core §1.2 and the directional associations of
core §6 — it is nearly free given the architecture already built for it, and needs no credential
beyond the existing PAT.

**`timeline`** requires an app id and a developer API key. This is a **third** credential class:
core §8 already warns that the webhook secret is the app's client secret and not the PAT, and this
adds a third distinct thing. Timeline event types are defined per HubSpot app, so each consuming
application needs its own developer app.

### 8.1 Requires verification before implementation

Per `CLAUDE.md`'s rule that an empirical question is probed rather than guessed, these must be
confirmed against live HubSpot documentation during Phase 7 and **not** from recall:

1. Which HubSpot tiers permit custom objects.
2. Which tiers permit custom behavioural events (relevant only if `timeline` proves insufficient).
3. Current API rate limits per tier, both per-interval and daily.
4. The exact credential and scope requirements of the Timeline Events API.

If custom objects turn out to be gated above the tiers this package targets, the `custom_object`
driver ships with that requirement documented rather than being quietly dropped.

---

## 9. Attribution

Attribution is **not** a separate subsystem. A paid click id is a signal whose roll-up uses
`first_wins`:

```php
'paid_landing' => [
    'object'                => 'contacts',
    'hs_first_touch_gclid'  => 'first_wins:gclid',
    'hs_first_touch_source' => 'first_wins:utm_source',
    'hs_first_touch_at'     => 'first_wins:occurred_at',
    'hs_first_landing_page' => 'first_wins:landing_page',
],
```

What the package owns:

- **A documented property-name convention**, so two applications do not invent `first_gclid` and
  `gclid_first` for the same field.
- **The `first_wins` semantics**, computed from the buffer and therefore correct under concurrency.

What the app owns: capture and persistence of the click ids, and the visitor id. The brief notes
`li_fat_id`'s own cookie is 30 days — shorter than the sales cycle — so app-side persistence is
what makes it survive at all. That is app-side by design, not an omission.

---

## 10. Frontend

### 10.1 This crosses a stated non-goal

Core §2 lists as a non-goal: *"Marketing/CMS/Conversations APIs. CRM only, until someone asks."*
A Meetings embed is a frontend widget rather than a CRM API call, so it sits outside that line on
the most natural reading. The candidate brief flagged this as an honest scope tension and offered
two defensible readings.

**Adopted deliberately**, on the reading that the package's differentiator is *inbound* signal —
and a booking confirmation is inbound signal whose trust problem (validating that a message really
came from HubSpot) is the same class as webhook signature verification. The isolation of §10 is
what keeps this from eroding the CRM core.

Core §2 must be amended to record the exception rather than left to contradict this document.
Listed in §14.5.

### 10.2 What ships

Isolated namespace. Nothing in `Gateway`, `Registry`, `Sync`, `Webhooks` or `Signals` may depend
on it, and it may not depend on them.

```blade
<x-hubspot::meetings :url="$meetingEmbedUrl" :topic="$topic" />
```

Ships:

- The embed markup and HubSpot's `MeetingsEmbedCode.js` loader.
- A booking listener that **validates `event.origin` against `https://meetings.hubspot.com`
  before trusting any payload.** Omitting this is a real vulnerability — any page can
  `postMessage`.
- CSP nonce support via `nonce="{{ app('csp-nonce') }}"`.
- A `HubspotMeetingBooked` browser event carrying the topic.
- A documented CSP `frame-src` allowlist snippet, because every team rediscovers that the hard way.

**`meetingBookSucceeded` is community-documented, not a versioned HubSpot API.** It is treated as
an enhancement, never as the source of truth. A booking is confirmed server-side via the webhook
path; the postMessage exists to make the UI responsive, and the two are deduplicated.

`illuminate/view` becomes the seventh production dependency. Standards §2's rule is *no
third-party runtime dependencies*; `illuminate/view` is first-party Laravel, present in every
consumer via `laravel/framework`, and used directly by a component the package ships and renders
itself. The list extends; the rule is unchanged.

---

## 11. Errors

Extends core §9 by exactly one member, rooted at the same `HubspotException` interface:

```
HubspotException (interface)
├── ConfigurationException      — unknown signal name, unknown merge verb, missing table
├── AssociationTypeException
├── ObjectTypeException
├── ApiException
└── SignalException             — visitor id already bound to a different subject   [NEW]
```

Messages name the fix, not the fault:

> `HUBSPOT_SIGNALS=true but table 'hubspot_signals' does not exist — run 'php artisan migrate'.`

A raw SDK exception never reaches userland. Flush failures surface as `ApiException` and are
retried by the queue.

---

## 12. Testing

- **`RollUpCalculator` is pure.** Every merge verb, including first-touch-wins, is unit-testable
  with no HTTP, no database and no fake. This is also dense enough logic that `pest --mutate`
  meaningfully exercises the 80% MSI floor rather than rubber-stamping it.
- **New fake assertions**, alongside core §10's: `assertSignalRecorded()`, `assertSignalFlushed()`,
  `assertPropertyRolledUp()`, and `assertRequestCount()` proving one batched write per flush.
- **Determinism**, per Standards §6: `occurred_at` from a frozen `Carbon`, visitor ids from a
  counter, no Faker in default fakes.
- **No real network I/O.** The suite runs green with no credentials and no internet. The tier and
  rate-limit verification of §8.1 is manual research plus an opt-in integration test, never part of
  the default suite.
- **JavaScript is tested.** The origin-validating listener is the most security-sensitive new code
  in the package and PHP line coverage cannot see it. Vitest covers it with its own floor. This is
  affordable specifically because the documentation site (§13) brings Node and pnpm into CI
  anyway; on its own it would have been hard to justify for ~30 lines.

---

## 13. Documentation site

Astro + Starlight in `site/`, following the pattern already proven in `ReyemTech/apps/stint`:
build on push to `main`, publish to a `docs-pages` branch, which triggers the Pages deploy.

One inherited detail worth carrying over deliberately: stint pushes with a PAT rather than
`GITHUB_TOKEN`, because Actions suppresses workflow triggers for commits authored by
`GITHUB_TOKEN`. Without that, the Pages deploy silently never fires.

The site is where Standards §13's requirements actually become practical: a usage example for
every public method, and the association direction table documented prominently.

---

## 14. Standards amendments required

`STANDARDS.md` is binding, so these are changes to it, each needing sign-off:

1. **§2** — production `require` extends to seven with `illuminate/view`. The encoded rule (no
   third-party runtime dependencies) is unchanged.
2. **§6** — a JavaScript coverage floor is added for `Frontend`, since the PHP floors cannot see it.
3. **§6 and core §3** — the layer graph goes from four layers to six, with the two new
   architecture rules of §3 above.
4. **"Not standards, deliberately"** — the docs-site rejection is removed. That rejection was
   explicitly conditional: *"README plus inline examples until there is enough surface to justify
   one."* Adopting signals, attribution, a frontend surface and a public `identify()` API is the
   trigger condition firing, not a reversal of the reasoning. Recorded so it does not read as
   drift.
5. **Core design spec §2 non-goals** — the "CRM only" non-goal is amended to record the Meetings
   embed as a deliberate, isolated exception. See §10.1. Without this amendment the two design
   documents contradict each other, which is worse than either answer.

---

## 15. Phasing

The core spec's six phases become nine.

| Phase | Contents |
|---|---|
| 1 | Foundation & gates — **plus** Node/pnpm toolchain, Vitest gate, docs-site build, the two new architecture rules, `illuminate/view` |
| 2 | Gateway layer — unchanged |
| 3 | Registry & stores — unchanged |
| 4 | Model sync — unchanged |
| 5 | Inbound webhooks — unchanged |
| 6 | **Signals core** — buffer + migration, recorder, `identify()`, roll-up calculator, merge vocabulary, flush job, `local` driver |
| 7 | **Signal stores & attribution** — `custom_object` and `timeline` drivers, the §8.1 verification, prune command, attribution conventions |
| 8 | **Frontend & meetings embed** — Blade component, nonce-aware listener, origin validation + Vitest, CSP recipe |
| 9 | Adoption & release — installer, tapp compat, docs, **plus** the Astro/Starlight site and `docs-pages` workflow |

The Phase 1 additions land early on purpose. `BRIEF.md` states that Phase 0 exists to get every
gate green on an empty package because *"turning gates on later never happens."* The JS coverage
gate and the docs build therefore ship in Phase 1, before anything uses them.

### 15.1 Publishing is explicitly user-gated

**Decided 2026-07-26.** The repository is `ReyemTech/laravel-hubspot`, created **private**. Packagist
registration, the GitHub↔Packagist integration and the first public release are deferred until the
owner has reviewed the package — publishing is not an autonomous step and will not be performed
without explicit approval.

Phase 1 therefore keeps only the parts of `REQ-release-publishing` that are local: `composer
validate --strict` as a required check, and release-please configuration. Claiming the Packagist
name and wiring the webhook move to a later owner-gated step, and cannot happen at all while the
repository is private, since Packagist requires a public repository.

Two further consequences of the repository being private: GitHub Pages needs a paid plan on private
repositories, so the documentation site may build in CI but not deploy until the repository is made
public; and branch protection rules may be limited by plan.

**Recorded for the record:** shipping v1 at phase 5 — the originally specced scope, the part that
displaces `tapp/laravel-hubspot` — and treating phases 6–9 as v1.1 was offered and declined. The
owner chose all nine in v1 with the 6→9 phase cost stated. Noted here so the trade-off is visible
to whoever picks this up, not to reopen it.
