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
