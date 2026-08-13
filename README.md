# reyemtech/laravel-hubspot

A Laravel package for HubSpot CRM covering **every CRM object type**, with **directional
associations** as a first-class concept and **inbound webhooks** done properly.

> ### ⚠️ Early development — not released
>
> This package is **not on Packagist yet and is not usable**. The Gateway layer is built and the
> association-type registry resolves the HubSpot-defined baseline offline; model sync, webhooks,
> signals and the frontend do not exist. Do not depend on it.
>
> The repository is public because development happens in the open, not because there is
> something to install. Watch or star it if you want to know when there is.

---

## Why it exists

Laravel + HubSpot is a weak corner of the ecosystem. The leading package handles **contacts and
companies only**, and its design cannot extend past them without a rewrite — it uses the SDK's
per-type clients, so every additional object type costs a hand-written service. Inbound webhooks
are held by a package with double-digit installs.

The official SDK ships a *generic* objects API that removes that cost entirely. One set of model
classes, any object type — contacts, companies, deals, products, line items, tickets, quotes, and
custom `p_*` objects — which is what makes covering all of them tractable instead of ~500 lines
each.

## The thing that makes HubSpot integrations go wrong

**Association type ids are directional, and different in each direction.**

| Direction | typeId | | Direction | typeId |
|---|---|---|---|---|
| Contact → Company | 279 | | Company → Contact | 280 |
| Contact → Primary Company | 1 | | Company → Primary Contact | 2 |
| Deal → Line Item | 19 | | Line Item → Deal | 20 |
| Note → Contact | 202 | | Contact → Note | 201 |

Write the inverse id and you silently associate the wrong way. It is the single most common
HubSpot integration bug, and it is the reason this package is shaped the way it is:

- The primitive is a **directed pair**. No API here accepts two objects without an order — the
  mistake is meant to be *unrepresentable*, not merely discouraged.
- Unlabelled associations never resolve a typeId at all; HubSpot picks the default for that
  direction.
- A type that cannot be resolved for the requested direction **throws**, naming the direction. It
  never falls back to the inverse.

## Planned scope

- Every CRM object type through one generic core — no per-type service
- Directional associations, including labelled ones
- Inbound webhooks: signature verification, replay protection, batching, idempotency, typed events
- A real test double with directional assertions — `assertAssociated($deal, $contact, label:)`
  fails if the inverse typeId was written
- Zero-migration install: `composer require` plus a trait on a model, no publish step, no `migrate`.
  The association-type registry defaults to your application cache (`HUBSPOT_STORE=cache`) and
  resolves the HubSpot-defined baseline offline. Set `HUBSPOT_STORE=database` and the package loads
  its own migration — still no publish step — or run
  `php artisan vendor:publish --tag=hubspot-migrations` to own the file yourself. Publishing works
  whichever store is selected.
- Intent signals and paid-acquisition attribution that survives a long sales cycle
- A one-line migration path for existing `tapp/laravel-hubspot` users

## Artisan commands

```
php artisan hubspot:associations:sync
```

Reconciles your portal's own association labels into the registry. The seeded baseline covers
HubSpot-defined types only, and every portal's `USER_DEFINED` label has a portal-specific id, so
this is what makes your own labels resolvable. List the object-type pairs to reconcile under the
`associations.sync` key of `config/hubspot.php`; each pair is read in **both** directions, because a
paired HubSpot label carries a different name and a different id in each. It reports what it added,
updated, left unchanged and skipped, naming both ids whenever a portal id replaces a seeded one.

It deliberately leaves `inverse_type_id` empty: the two directional responses share no join key, so
pairing them up would be a guess, and a guessed inverse id is a real, valid, wrong association id.

```
php artisan hubspot:doctor
```

Reports what this installation currently believes — which store the registry uses, which resolver is
bound, whether and when the registry was reconciled, and how many rows across how many directions it
holds. Local state only: no network, no credentials. It also **names** the bound-model section as not
yet built, rather than omitting it, because "you have no bound models" and "this package cannot bind
models yet" are different facts.

```
php artisan hubspot:associations:doctor deals 10 contacts 20 --label="Deals" --inverse-label="People"
```

Probes one real association in both directions and reports, per direction, whether the type id the
registry would send is actually there. It **searches** every association type HubSpot reports for
that record rather than taking the first one — a read returns a list in no guaranteed order, and
taking the first would report success regardless of which id was really written. When both
directions are confirmed it records the observed pairing into the registry; when either is not, it
records nothing.

## Signals

Behavioural signals are recorded against a **visitor id the application supplies** — this package
never reads a cookie, the session or the request to invent one (D9). `Hubspot::signal()` buffers a
signal with zero HTTP; `Hubspot::identify()` binds that visitor id to a bound model, backfilling
every buffered signal for it and dispatching one batched HubSpot write.

One subject may be bound to **many** visitor ids — the same person on their phone and their
laptop — and roll-ups compute across the union, which is what lets a `first_wins` property capture
the genuinely earliest touch across a person's own devices. The reverse is refused: one visitor id
binding to a **second, different** subject throws `SignalException`.

**Accepted consequence:** a visitor id reused across two different people — a shared device, a
shared browser profile — merges their attribution onto one subject. Visitor-id issuance is the
application's own responsibility (D9), so the fix lives there: issue a fresh visitor id per
person, not per device. An operator can recognise a merged subject directly, without this package
doing anything special to surface it: its `hubspot_signals` rows carry more than one distinct
`visitor_id` for the same `subject_type`/`subject_id` pair.

### Flushing

`Hubspot::identify()` dispatches one flush per call, covering exactly the subject just identified.
For everything still buffered when no `identify()` call happens to touch it, schedule the
package's own command:

```
php artisan hubspot:signals:flush
```

First sweeps every buffered signal recorded **after** an `identify()` call for its own visitor id —
`Hubspot::signal()` always buffers anonymous, even for an already-identified visitor, because
buffering stays a single write with no lookup (SIG-02); this sweep is what keeps that signal from
being stranded until the application happens to call `identify()` again. Then selects every
identified subject (`subject_type` set, straggler sweep included) carrying at least one unflushed
row, batches them at 100 subjects per dispatch, and queues one `FlushSignalsJob` per batch. The
package registers **no** schedule of its own (D-04) — add one line in your own
`routes/console.php` or `bootstrap/app.php`'s `withSchedule()`:

```php
Schedule::command('hubspot:signals:flush')->everyFiveMinutes()->withoutOverlapping();
```

Frequency, queue and `withoutOverlapping()` are your own operational choices, sized for your own
traffic and worker capacity. **`withoutOverlapping()` is convenience, not correctness.** It stops
two scheduled runs from stacking, but the scheduler's own lock covers only the scheduled command
— SIG-06 also lets `identify()` dispatch a flush for the very subject a scheduled run is
mid-flight on, and no scheduler lock can see that second, independently-triggered dispatch. What
actually makes two overlapping flushes safe is the per-subject claim `FlushSignalsJob` takes
internally (D-06): the loser of that race skips the subject outright, leaving its rows for the
next flush to pick up. `withoutOverlapping()` is still worth setting — it just is not the thing
that makes concurrent flushes correct.

### Testing

`Hubspot::fake()` carries three assertions for the signals path, alongside the ones it already
carries for outbound writes (`assertSynced()`) and inbound webhooks (`assertWebhookHandled()`):

```php
Hubspot::fake();

Hubspot::signal('pricing_page_viewed', 'visitor-1', ['source' => 'google_ads']);
$lead = Lead::create(['email' => 'ada@example.com']);
Hubspot::identify('visitor-1', $lead);

Hubspot::assertSignalRecorded('visitor-1', 'pricing_page_viewed', ['source' => 'google_ads']);
Hubspot::assertPropertyRolledUp($lead, 'pricing_page_views', '1');
Hubspot::assertRequestCount(1);
```

`assertSignalRecorded()` reads the INBOUND buffer receipt, never the outbound Guzzle history — a
buffered signal never leaves the process (SIG-02), so it never satisfies `assertRequestCount()` or
`assertSynced()`, and the reverse holds too. `assertSignalFlushed()` and `assertPropertyRolledUp()`
take a bound model (as above) or a `'SubjectType#subjectId'` string for a caller with no model
instance in hand. `assertPropertyRolledUp()` requires that ONE flushed record carried the property
with the expected value, mirroring `assertSynced()`'s one-record rule — never a value assembled by
checking the property's presence and the value's presence as two independent facts.

`assertRequestCount()` is the EXISTING mechanism, reused rather than duplicated: a flush issues
`sum(ceil(groupSize / 100))` requests, one per `(objectType, idProperty)` chunk of at most 100
subjects (D-05) — the worked example's `1` is that arithmetic for one subject in one group, not a
promise that every flush is a single request. Two subjects bound to different object types, or
more than 100 subjects sharing one, issue more than one.

The whole signals suite runs with **no credentials and no internet** — every assertion above
passes with `HUBSPOT_TOKEN` unset, exactly like the rest of this package's test surface (D-12).

## Requirements

- PHP `^8.3`
- Laravel `12.x` or `13.x`
- `hubspot/api-client:^14.1`

Every supported combination is tested on both `prefer-stable` and `prefer-lowest`. A version not
in the matrix is a version not supported.

## Engineering standards

This package is held to a higher bar than an application, because other people have to integrate
with it. `STANDARDS.md` is binding and CI-enforced — 95% line coverage, an 80% mutation score,
PHPStan at maximum level with **no baseline**, architecture tests that fail the build if a layer
reaches across a boundary, and TDD with the failing-test commit preserved in history.

- [`STANDARDS.md`](STANDARDS.md) — the binding engineering standards
- [`BRIEF.md`](BRIEF.md) — what this is and why
- [`docs/superpowers/specs/`](docs/superpowers/specs/) — the design specs
- [`SECURITY.md`](SECURITY.md) — private disclosure

## License

MIT. See [`LICENSE`](LICENSE).
