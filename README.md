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
- Zero-migration install: `composer require` plus a trait on a model, no publish step, no `migrate`
- Intent signals and paid-acquisition attribution that survives a long sales cycle
- A one-line migration path for existing `tapp/laravel-hubspot` users

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
