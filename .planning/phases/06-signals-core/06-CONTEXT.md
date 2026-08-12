# Phase 6: Signals Core - Context

**Gathered:** 2026-08-11
**Status:** Ready for planning

<domain>
## Phase Boundary

An application records behavioural signals against an anonymous visitor, binds them to a person the
moment an email finally appears, and HubSpot receives one batched property write — with **no API
call ever occurring in a request lifecycle**.

Requirements: SIG-01 … SIG-08.

**In scope:** the `hubspot_signals` buffer, `Hubspot::signal()`, the declarative signal map with its
closed four-verb vocabulary, `RollUpCalculator` as a zero-dependency pure function,
`Hubspot::identify()` with subject backfill and `SignalException`, `FlushSignalsJob`, the
`SignalStore` contract with its `local` driver, and the three fake assertions.

**Out of scope (Phase 7):** the `custom_object` and `timeline` drivers, attribution surviving the
sales cycle, and `hubspot:signals:prune` / bounding the buffer. **Out of scope (Phase 8):**
anything in `Frontend`.

</domain>

<decisions>
## Implementation Decisions

### Subject resolution

- **D-01:** `FlushSignalsJob` resolves a subject to its HubSpot record by **upserting on
  `id_property`** through `ObjectGateway`, reading the object type and `id_property` from the
  **existing `hubspot.models` config key**. This is the only option with zero coupling to `Sync` —
  not even an inversion — so D-35 is satisfied outright. Reading a *config key* is not a dependency
  on a `Sync` *class*, so the namespace-based architecture tests stay green; `Signals` must NOT
  import `Sync\ModelBindings` and needs its own reader.
  Accepted consequence: a flush can **create** a contact. This is safe because anonymous rows never
  flush — `subject_type`/`subject_id` stay null until `identify()` — so only people the app
  explicitly identified can ever reach HubSpot.
  — **Reversibility:** costly — changing it later moves the identity source for every flush and
  would alter which HubSpot records existing installs write to.

- **D-02:** `identify()` given a subject whose `id_property` value is missing or blank **throws
  `SignalException` in the caller's stack**, not at flush. `identify()` issues no HTTP, so throwing
  there is cheap; the alternative surfaces hours later in a worker log detached from its cause —
  the acknowledged-then-lost shape Phase 5 spent a review round eliminating.

- **D-03:** A signal whose map `object` differs from the object type `hubspot.models` binds the
  subject to is **refused** with `ConfigurationException` naming both sides. Preserves SIG-06's
  one-batch-write-per-flush proof. Largely boot-checkable: every `object` named in the map must be
  claimed by some bound model, which needs no runtime subject.

### Flush triggering and concurrency

- **D-04:** The package ships **`hubspot:signals:flush`** and documents one scheduler line; it
  registers **no** schedule itself. Matches the `hubspot:webhooks:prune` precedent and keeps the
  default install inert. Frequency, queue, overlap and environment stay the consumer's decisions.

- **D-05 (revised 2026-08-12):** A scheduled flush **groups pending subjects by
  `(objectType, idProperty)` and chunks each group at 100**, rather than one job per subject.
  Request count is `sum(ceil(groupSize / 100))`, and that is the number SIG-06's
  `assertRequestCount` is written against — not a flat one.
  **The grouping is not a refinement, it is a correctness requirement.**
  `Gateway\Contracts\ObjectGatewayContract::upsertMany(string $objectType, string $idProperty, array $records)`
  (line 93) carries **one** object type and **one** id property per request, so a chunk assembled from
  arbitrary subjects cannot be sent at all. The first draft of this decision said "chunk at 100
  across subjects" and skipped the grouping; that was wrong against the real signature.
  Phase 2's treatment of HTTP 207 as partial success still applies within a chunk, so one bad record
  does not sink the other 99. Phase 4's batch job is the SHAPE to copy and **not** a class to import —
  it lives in `Sync`, which `Signals` may not depend on.

- **D-06 (revised 2026-08-12):** Overlapping and retried flushes are made safe by **trail
  idempotence PLUS subject-level serialization** — the first draft claimed idempotence alone was
  enough, and it is not.

  Unchanged and still correct: each event-trail entry is keyed on the `hubspot_signals` row id it
  came from, so re-appending is a no-op; `reconcile` is gated on `reconciled_at`; roll-ups are
  absolute (D-40).

  **What the first draft got wrong.** Absolute values are idempotent under a *retry of the same
  input*. They are not idempotent under *two workers computing over different row sets*, which is
  exactly what an identify-triggered flush racing the scheduled one produces:

  ```
  Worker A reads rows {1,2}     -> count = 2
  Worker B reads rows {1,2,3}   -> count = 3, writes 3, marks 1,2,3 flushed
  Worker A writes 2             -> overwrites 3 with a stale value, permanently
  ```

  Nothing repairs it, because every row is already flushed. Trail dedup and `reconciled_at` do not
  order the **property write**. Scheduler `withoutOverlapping()` does not close it either — the lock
  covers the command, and SIG-06 *requires* `identify()` to dispatch a flush that races it.

  **The fix:** a subject-level atomic claim around calculate-and-write. A conditional UPDATE claims
  a subject's unflushed rows; the worker that affects zero rows claims nothing and skips that
  subject. This is the affected-row-count pattern `Webhooks\Stores\DatabaseWebhookEventStore`
  already uses for lease recovery — decided on affected rows, never read-then-write. It is
  per-subject, so it does not serialize the whole flush.
  — **Reversibility:** one-way — the trail's unique key and the claim column ship in migrations;
  changing either later needs a migration against installed data.

### Signal map and validation

- **D-07:** The map is validated in the service provider's **boot, guarded by
  `hubspot.signals.enabled`** — so the cost lands only on apps that opted in and is zero otherwise.
  This deliberately differs from Phase 5's `HandlerMap::validate()`, which runs at job time: a bad
  handler map costs one webhook claim, whereas a bad signal map silently drops buffered attribution
  the feature exists to protect. Fail-fast is worth more here than there.

- **D-08:** **The closure escape hatch becomes an invokable class-string.** `'intent_score' =>
  IntentScore::class`, where `IntentScore::__invoke(Collection $signals)` returns the value.
  — **SPEC AMENDMENT REQUIRED**, see `<specifics>`. Loses no expressive power, keeps config a plain
  serializable array, and makes the rule unit-testable without booting config. Mirrors how the
  webhook handler map already resolves configured behaviour by class name.
  — **Reversibility:** costly — it is the documented public shape of the signal map.

### Identity binding

- **D-09:** **One subject may be bound to many visitor ids.** Every buffered row for each visitor id
  backfills to the same subject and roll-ups compute across the union — so `first_wins` picks the
  genuinely earliest touch across devices, which is the attribution the feature exists to capture.
  The visitor-side rule is unchanged and asymmetric: one visitor id binding to a *different* subject
  still throws `SignalException` (SIG-05).
  Accepted consequence: a shared device can merge two people. That is the app's visitor-id problem,
  not the package's — D9 puts visitor-id issuance on the app.

- **D-10:** `RollUpCalculator` computes over **all rows for the subject, flushed included.**
  `flushed_at` marks what has been written and is **never an input to the maths**; otherwise
  `increment` and `sum` would restart at zero on the second flush and overwrite the correct HubSpot
  value with a partial one, turning absolute roll-ups into accidental deltas. Keeps
  `RollUpCalculator` the pure `(signals, map)` function SIG-04 requires.
  — **Reversibility:** one-way — installs would have already written values computed this way.

### Claude's Discretion

- An **unbound** subject passed to `identify()` throws, mirroring `Sync\ModelBindings::for()`'s
  `unboundSyncModel()` precedent — every miss throws rather than returning null.
- `ConfigurationException` / `SignalException` message wording follows STANDARDS §9's directed-error
  rules.
- Queue retry and backoff follow the package's existing queued-job conventions.
- The `local` `SignalStore` driver's table shape, beyond D-06's unique key on the source row id.
- `first_wins` tie-break when two signals share an `occurred_at` — not discussed; pick a
  deterministic rule and state it.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Phase specification
- `docs/superpowers/specs/2026-07-26-signals-attribution-and-frontend-design.md` — the phase's
  primary spec. §2 decisions D1–D10, §3 architecture (six layers), §4 components, §5 data flow,
  §5.1 the `reconcile` caveat, §6 the signal map, §7 identity and buffering, §8 storage drivers,
  §11 errors, §12 testing, §15 phasing. **Note §6's closure example is superseded by D-08 above.**
- `.planning/REQUIREMENTS.md` — SIG-01 … SIG-08 acceptance criteria (lines 546–636).
- `.planning/ROADMAP.md` — Phase 6 goal and its five success criteria; Phase 7 for what is
  deliberately deferred.

### Package-wide contracts
- `STANDARDS.md` — binding. §1 support matrix and the Octane mutable-state rule, §6 determinism,
  §9 the exception hierarchy and directed errors, §11 no API call in a request lifecycle.
- `docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md` — core design. §5 `$hubspotMap`'s
  three-tier flexibility that D-08 diverges from, §6.3 the store-driver pattern `SignalStore`
  mirrors, §10 the fake's assertion surface SIG-08 extends.
- `CLAUDE.md` / `AGENTS.md` — the working contract; these two must not diverge.

### Precedents this phase reuses
- `src/Webhooks/Console/PruneWebhookEventsCommand.php` — the ship-a-command, app-schedules-it
  precedent behind D-04.
- `src/Webhooks/HandlerMap.php` — configured behaviour resolved by class name (D-08), and the
  validate-at-job-time decision D-07 deliberately departs from.
- `src/Webhooks/NormalizedWebhookEvent.php` — `deliveryIdentity()` and the identity-not-timing
  lesson behind D-06.
- `src/ServiceProvider.php` `migrationGroups()` — the third-group gating pattern SIG-01's migration
  follows, keyed on `hubspot.signals.enabled`.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- **`ServiceProvider::migrationGroups()`** (`src/ServiceProvider.php:387`): a `path => active` map.
  Signals adds a fourth group at `database/migrations/signals`, active on
  `hubspot.signals.enabled`. Publishing is never gated; only loading is. The migration is executable
  PHP where it sits, never a `.php.stub` — the migrator globs `*_*.php` and never finds a stub.
- **Phase 4 chunked batch transport**: already does homogeneous groups of at most 100. D-05 reuses
  it rather than inventing a second chunking path.
- **Phase 2 HTTP 207 handling**: partial success per record, which is what makes D-05's batching
  safe.
- **`Testing\HubspotFake` + `Testing\DefaultResponses`**: SIG-08's three assertions extend this
  surface. `WebhookReceiptLog` is the precedent for a per-concern log owned by `HubspotManager` and
  reset in `flushState()`.
- **`Exceptions\HubspotException`** hierarchy: `SignalException` is the fifth member (SIG-05, spec
  §11). Architecture rules R2–R5 already admit `ReyemTech\Hubspot\Exceptions` from every layer.

### Established Patterns
- **`src/Signals/` and `src/Frontend/` are `.gitkeep` placeholders** — genuinely greenfield.
  `config/hubspot.php` has **no** `signals` block yet.
- **Store contract + drivers**: `Registry/Contracts/AssociationTypeStore` with Array/Cache/Database
  drivers, and `Webhooks/Contracts/WebhookEventStore` with its Database driver. `SignalStore`
  follows the same shape, resolved from `HUBSPOT_SIGNAL_STORE`.
- **Container singletons must not hold mutable state** unless reset at Octane boundaries
  (STANDARDS §1). `DatabaseWebhookEventStore::isReady()` documents why a cached readiness latch was
  removed — do not reintroduce that shape in the signal buffer.
- **A `const` has no executed line `pest --mutate` can attribute a covering test to.** Existing code
  uses methods instead (`ServiceProvider::supportedStores()`,
  `ProjectWebhookComponent::maxConcurrentRequests()`). Relevant because SIG-04's zero-dependency
  `RollUpCalculator` is where the 80% MSI floor is meant to bite hardest.

### Integration Points
- `HubspotManager` gains `signal()` and `identify()`, surfaced through `Facades\Hubspot`.
- `ServiceProvider` gains the signals migration group, the store binding, the boot-time map
  validation (D-07), and the `hubspot:signals:flush` command registration.
- `ObjectGateway` is the only outbound path — `Signals` may name no `HubSpot\*` type (R4-equivalent
  for the new layer; the architecture rule ships in Phase 1 per D-35).

</code_context>

<specifics>
## Specific Ideas

**Three items need recording as amendments, not absorbed silently.**

0. **D-05 and D-06 were revised on 2026-08-12 after automated review of PR #81 found a P1.** Both
   original readings are preserved above rather than quietly replaced, because the mistake is
   instructive: "absolute values make retries safe" was generalised into "absolute values make
   concurrency safe", and those are different claims. A retry recomputes the *same* input; two
   overlapping flushes compute *different* inputs and the later write can be overwritten by an
   earlier worker's stale one. Any future decision that reaches for idempotence instead of
   coordination should be asked which of the two it actually establishes. The companion error in
   D-05 was citing a Phase 4 precedent without checking the signature it would be called through —
   `upsertMany()` takes one object type and one id property, so ungrouped chunks were unsendable.

1. **Spec §6 / SIG-03's closure-in-config breaks `php artisan config:cache`.** Laravel cannot
   serialize a closure in a config file — it fails with *"Your configuration files are not
   serializable"*. The pattern is legal for `$hubspotMap` (core §5) because those closures live on a
   **model class**; moving it into `config/hubspot.php` is what breaks it. D-08 replaces it with an
   invokable class-string. The signals spec §6, REQUIREMENTS SIG-03/SIG-04 and any D-41 wording that
   says "plus a closure" need amending to say "plus an invokable class-string".

2. **D-10 constrains Phase 7's pruning.** Because roll-ups compute over all rows including flushed
   ones, deleting flushed rows for an **identified** subject silently shrinks `increment` and `sum`
   on the next flush — HubSpot would be overwritten with a smaller, wrong value. Phase 7's
   `hubspot:signals:prune` must therefore either never delete identified rows, or materialise the
   roll-up before deleting them. `retention_days` (90, for the `li_fat_id` case) applies cleanly to
   **unidentified** rows, which is where the unbounded growth actually comes from.

</specifics>

<deferred>
## Deferred Ideas

- **Multi-object subjects** — one subject carrying signals for several object types, with one batch
  write per type. Rejected here because it breaks SIG-06's one-write proof and Phase 6 has no
  mechanism to resolve the second record. Revisit in Phase 7 alongside company-level attribution.
- **`hubspot:signals:check`** — an artisan command for CI to validate the map. Redundant once D-07's
  boot validation throws; note it if a consumer ever asks for a non-booting check.
- **Materialised per-subject roll-up table** — genuinely solves the D-10/prune tension and gives an
  O(1) flush read. Out of scope here (second table plus write path, unscoped), but it is the natural
  answer if Phase 7 finds the prune constraint too restrictive.
- **Boot-time validation against the portal's property schema** — the map can name a HubSpot
  property that does not exist; boot cannot know, so it fails at flush with a 400. Would need a
  portal read, which boot must never do.

</deferred>

---

*Phase: 6-Signals Core*
*Context gathered: 2026-08-11*
