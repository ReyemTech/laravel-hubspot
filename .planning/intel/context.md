# Context

Source type: DOC. Extracted from `BRIEF.md` (precedence 2, lowest), plus scope-boundary notes from
the SPEC that are neither decisions nor technical contracts.

Most of BRIEF.md's normative content restates constraints owned by `STANDARDS.md` and the design
spec. Those restatements were deduplicated into `decisions.md` and `constraints.md` under their
owning source; what remains here is orientation, rationale and the facts BRIEF.md alone carries.

---

## Topic: What this package is
- source: BRIEF.md
- A Laravel package for HubSpot CRM covering **every CRM object type** — contacts, companies, deals,
  products, line items, tickets, quotes, custom objects — with **directional associations** as a
  first-class concept, plus **inbound webhooks**, which no maintained package in this ecosystem does
  properly.
- BRIEF.md is the designated entry point for whoever picks the project up.

## Topic: Repository status (unique to BRIEF.md)
- source: BRIEF.md
- Nothing has been built yet. The directory holds three documents and no code.
- **Not yet a git repository, and not yet on Packagist.** Phase 0 covers `git init`, the composer
  skeleton, the CI matrix, and branch protection on `main`.

## Topic: Why the package exists — ecosystem gap
- source: BRIEF.md; docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md §1
- Measured 2026-07-26: `hubspot/api-client` (official) ~16M installs, the API with no Laravel glue;
  `tapp/laravel-hubspot` 6,203 installs / 2 stars, contacts and companies only, outbound only;
  `stechstudio/laravel-hubspot`, Eloquent-style reads, 0.x; `concept7/hubspot-webhook-client`
  105 installs / 0 stars, inbound via `spatie/laravel-webhook-client`.
- The leading package has 6k installs. Inbound webhooks are effectively unoccupied.

## Topic: The tapp audit — why it is not a foundation to build on
- source: docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md §1.1; BRIEF.md
- tapp is well maintained (246 commits, 154 in the last 12 months, 0 open issues, real CI). It is not
  a foundation to build on for one structural reason.
- 2,652 LOC src / 1,635 LOC tests. `HubspotContactService` (601 lines) and `HubspotCompanyService`
  (405 lines) are independently hand-written near-duplicates with diverging method names and return
  types (`createContact(): array` vs `createOrFindCompany(): ?string`).
- Zero occurrences of deal, product, line item, ticket or quote in `src/`.
- Config is per-object by construction: `contact_id_column`, `company_id_column`.
- Associations exist only as contact↔company, buried inside the company service.
- `HubspotCompanyService` — the second-largest file — has no dedicated test.
- `MockHubspotClient` is not a fake; it throws on `crm()`. There is no HTTP-level test double.
- PHPStan level 5 with a baseline.
- Root cause: tapp uses the SDK's per-type clients (`crm()->contacts()`, `crm()->companies()`), each
  carrying its own duplicate model classes, forcing one hand-written service per object type. Adding
  the five missing object types their way is ~2,500 lines of near-duplicate code, and associations —
  where the real difficulty lives — are unmodelled.
- This audit is the evidence base for the code-shape limits in STANDARDS.md §6b, which cites the same
  601-line and 405-line files.

## Topic: Scope boundaries — non-goals
- source: docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md §2
- **A CRM-agnostic driver layer.** CRM abstractions leak; nobody has asked for one.
- **Replacing the official SDK.** We wrap it; `Gateway` is the only layer that names `HubSpot\*`.
- **Marketing/CMS/Conversations APIs.** CRM only, until someone asks.

## Topic: Things that will bite you if you skip the spec
- source: BRIEF.md
- 1. **Association type ids are directional and different in each direction.** Contact→Company is 279;
  Company→Contact is 280. Note→Contact is 202; Contact→Note is 201. Writing the inverse id silently
  associates the wrong way. The single most common HubSpot integration bug and the reason this
  package's shape is what it is. (Owned by CON-association-direction.)
- 2. **There is no unarchive endpoint.** The SDK's delete is `archive()`. The package can never
  programmatically undo one, which is why delete propagation is guarded by default. (Owned by
  CON-auto-sync-and-delete-policy.)
- 3. **`$request->fullUrl()` breaks webhook signatures.** Symfony's `getQueryString()` sorts query
  parameters; HubSpot signs the URI byte-for-byte. Reconstruct the raw URI. (Owned by
  CON-webhook-signature.)
- 4. **The webhook secret is the app's client secret, not the PAT.** Two different credentials.
  (Owned by CON-webhook-delivery.)
- 5. **Bindings are many-to-one.** Several local models can map to one HubSpot object type — the
  originating app has three models mapping to `contacts`. Config keyed by object type cannot express
  that. (Owned by CON-model-binding.)

## Topic: How to start
- source: BRIEF.md
- `/gsd-ingest-docs` bootstraps `.planning/` (PROJECT.md plus a phased ROADMAP.md) from the design
  spec rather than re-deriving requirements from scratch. Then work phase by phase with
  `/gsd-plan-phase` and `/gsd-execute-phase`.
- The spec proposes six phases in §13. **Phase 0 is not optional and must come first** — it contains
  an empirical probe whose answer changes an API default, plus getting every standards gate green on
  an empty package. **Turning gates on later never happens.**

## Topic: Open sign-off items and how to handle them
- source: BRIEF.md; docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md §14; STANDARDS.md
- Six decisions in STANDARDS.md are unsigned: #0 merge-commits vs release-please, #1 PHP floor,
  #3 `strict_types`, #4 coverage/MSI floors, #5 `final` by default, #6 function length ceiling.
  Decision #2 (Pest) is settled.
- All three documents agree: **none block Phase 0.**
- BRIEF.md instruction: "Ask Mario rather than assuming."

## Topic: Agent hazard — Pest conversion
- source: STANDARDS.md sign-off item #2; CLAUDE.md; BRIEF.md
- An agent working in this workspace will read `apps/laravel`'s CLAUDE.md, which mandates PHPUnit and
  says to convert Pest to PHPUnit, and will try to convert the suite. That rule is app-scoped and does
  not carry here. The package ships its own CLAUDE.md stating Pest is deliberate.
- Related workspace-default overrides recorded in CLAUDE.md: no Sail and no Docker (run
  `vendor/bin/pest`, `vendor/bin/pint`, `vendor/bin/phpstan` directly, test the matrix via
  `orchestra/testbench`), and the package targets PHP `^8.2` rather than the application's `^8.3`.

## Topic: When the spec is silent
- source: CLAUDE.md
- Ask rather than invent. The six unsigned decisions in STANDARDS.md and the association-inverse
  question in design spec §6.4 are the known gaps — §6.4 is an empirical probe with a defined
  procedure, so run the probe rather than guessing the answer.
- If a genuine flaw in the design is found, say so and stop. Do not route around it silently.
