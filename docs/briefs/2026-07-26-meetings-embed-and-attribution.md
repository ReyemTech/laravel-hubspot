# Candidate scope — Meetings embed + attribution properties

**Status:** Candidate, NOT approved. Planning is still open.
**Date:** 2026-07-26
**Source:** `ReyemTech/laravel` → `BRIEF-conversion-tracking-2026-07-26.md` (paid-acquisition tracking work)
**Relates to:** `../superpowers/specs/2026-07-26-laravel-hubspot-design.md`

> ⚠️ This document was written **after** `/gsd-ingest-docs` ran, so it is not in
> `.planning/ingest-manifest.yml`. If any of it is adopted, re-ingest or fold it into the design
> spec explicitly — do not let it drift as a shadow requirement.

---

## Why this exists

A separate piece of work in the consuming app (instrumenting the funnel before ~$1,500–2,500/month
of paid ads starts) surfaced two HubSpot integration problems that are **not app-specific**. Both are
things any Laravel app doing HubSpot-backed lead capture has to solve, and both are currently
solved by hand-rolled code in every such app.

Captured here so the decision is deliberate rather than discovered later.

## Candidate 1 — HubSpot Meetings embed component

**What the app does today.** `resources/views/livewire/page/book-a-call.blade.php` embeds:

```blade
<div class="meetings-iframe-container" data-src="{{ $meetingEmbedUrl }}"></div>
<script src="https://static.hsappstatic.net/MeetingsEmbed/ex/MeetingsEmbedCode.js"></script>
```

…plus a CSP `frame-src` allowlist entry for `https://meetings.hubspot.com` in
`app/Http/Middleware/SecurityHeaders.php`.

**What it needs and does not have.** A reliable signal that a booking succeeded. HubSpot's embed
emits a `meetingBookSucceeded` **postMessage**, which requires:

- validating `event.origin` against `https://meetings.hubspot.com` before trusting the payload —
  omitting this is a real vulnerability, any page can postMessage;
- a CSP nonce on the listener script (`nonce="{{ app('csp-nonce') }}"`);
- deduplication against a server-side confirmation, because the postMessage is
  **community-documented, not a versioned HubSpot API**, and must be treated as an enhancement
  rather than the source of truth.

**What the package could ship:**

```blade
<x-hubspot::meetings :url="$meetingEmbedUrl" :topic="$topic" />
```

A Blade component rendering the embed, a nonce-aware listener that validates origin, and a
`HubspotMeetingBooked` browser event (and optionally a server-side ping) carrying the topic. Plus a
documented CSP snippet, because everyone rediscovers that allowlist the hard way.

**Honest scope tension.** The design spec's §2 non-goals say *"CRM only"* and exclude
Marketing/CMS/Conversations. A Meetings embed is arguably outside that line — it is a frontend
widget, not a CRM API call. Two defensible readings:

1. **In scope.** The package's differentiator is *inbound* — receiving signal from HubSpot. A booking
   confirmation is inbound signal, and postMessage origin validation is the same class of problem as
   webhook signature verification: trusting a message that claims to be from HubSpot.
2. **Out of scope.** It ships JS and Blade, which drags a frontend surface into a package whose four
   layers are all PHP, and gives the architecture tests nothing to enforce.

Recommendation if adopted: ship it as a clearly separate, optional namespace, so the CRM core stays
frontend-free and the layer boundaries stay meaningful.

## Candidate 2 — Attribution properties on contacts

**The problem.** Paid click IDs (`gclid`, `gbraid`, `wbraid`, `fbclid`, `li_fat_id`) plus first-touch
landing page and timestamp need to reach the HubSpot contact, or ad spend cannot be traced to
pipeline. Two details that are easy to get wrong and that the consuming app had to reason out:

- **First touch must win over last touch.** With a 3–10 week sales cycle the prospect returns via
  branded or direct search before converting; last-touch overwrite destroys paid attribution.
- **`li_fat_id`'s own cookie is 30 days**, shorter than the sales cycle, so app-side persistence
  (90-day TTL) is what makes it survive at all.

**Why this fits the package cleanly.** This is contact property mapping — exactly the `$hubspotMap`
surface in design spec §5. Unlike Candidate 1, it needs no frontend commitment: the package can
accept an attribution payload and map it onto contact properties, leaving capture to the app.

**What the package could ship:**

- A documented convention for attribution property names, so two apps do not invent
  `first_gclid` and `gclid_first` for the same field.
- A `first-touch wins` merge rule on sync: never overwrite a non-empty attribution property on an
  existing contact. This is genuinely package-level behaviour, since it depends on reading the
  current HubSpot value before writing — precisely what `ObjectGateway` does.
- Optionally a small JS helper for the capture/persist half, sharing the Candidate 1 decision about
  whether this package ships frontend assets at all.

**The subtle bit worth encoding:** "don't overwrite if already set" is a *read-then-write*, which
means it needs the object fetched first, and it must not silently clobber under concurrency. That is
a real behaviour with a real test, not a config flag.

## What is explicitly NOT package material

From the same source brief, for the avoidance of doubt:

- GTM container work, GA4 key events, conversion values — ad-platform configuration, not code.
- Adding GTM to `sales.reyem.tech` — HubSpot domain-level settings, not a repo change.
- The `?topic=` deep-link convention on `/book-a-call` — app IA, not package concern.
- Anything about the Health Check intake funnel — entirely app domain.

## Open questions for whoever picks this up

1. **Does this package ship frontend assets at all?** Candidate 1 needs it; Candidate 2 can avoid it.
   Answering this once, deliberately, prevents the question being re-litigated per feature.
2. If yes, does that break the four-layer architecture test, or does a `Frontend` layer join the
   dependency graph with its own rules?
3. Is first-touch-wins the right *default* for attribution merging, or must it always be explicit?
4. Neither candidate is in `.planning/`. If adopted, do they become Phase 6+, or a separate
   milestone after v1.0?
