---
phase: 06-signals-core
plan: 08
subsystem: signals
tags: [signals, testing, fake, determinism, mutation-testing, sig-08]

requires:
  - phase: 06-signals-core
    provides: "Signals\\SignalRecorder::record() (06-02), Signals\\FlushSignalsJob completed (06-01/06-06/06-07) -- the two call sites this plan reports a receipt from"

provides:
  - "Signals\\Contracts\\SignalReceiptRecorder -- the THIRD instance of the Sync\\SyncStateContract / Webhooks\\Contracts\\WebhookReceiptRecorder inversion R5 forces"
  - "Testing\\SignalReceiptLog -- the inbound receipt log Hubspot::assertSignalRecorded()/assertSignalFlushed()/assertPropertyRolledUp() read"
  - "HubspotManager implements SignalReceiptRecorder; owns the canonical SignalReceiptLog; resets it in flushState() alongside $fake/$syncingSuppressed/$webhookReceipts"
  - "HubspotFake::__construct() gains a trailing defaulted signalReceipts parameter -- appended, never inserted, released signature stays a strict prefix (pinned by reflection)"
  - "Three assertions on Hubspot::fake()/Hubspot:: facade: assertSignalRecorded(), assertSignalFlushed(), assertPropertyRolledUp() -- accepting a Model or a 'SubjectType#subjectId' string"
  - "SignalDeterminismTest -- occurred_at from a frozen Carbon, no Faker in the signals test tree, the flush's own default response sharing DefaultResponses' fixed-instant/counter guarantees, the whole suite green with HUBSPOT_TOKEN explicitly unset"
  - "README.md 'Testing' subsection under Signals, documenting the three assertions and the sum(ceil(groupSize/100)) arithmetic behind assertRequestCount()"

affects: []

actuals:
  tokens: 18674
  tasks: 3
  commits: 5

tech-stack:
  added: []
  patterns:
    - "The THIRD instance of the inverted-port pattern (Sync\\SyncStateContract, Webhooks\\Contracts\\WebhookReceiptRecorder, now Signals\\Contracts\\SignalReceiptRecorder) -- Signals declares the port, HubspotManager implements it, ServiceProvider binds them on the line beside the other two."
    - "A docblock naming a sibling class in PROSE, never an {@see} tag, when the sibling lives in a layer R5/R7 forbids importing -- Pint's fully_qualified_strict_types fixer turns an {@see} tag into a real `use` statement regardless of layer rules, which is exactly the trap RequestLog's own docblock already documents for the identical reason."
    - "Model/string subject resolution (resolveSubject()) lives ONLY in HubspotFake, not duplicated in HubspotManager -- HubspotManager's other Model-accepting assertions (assertSynced) duplicate the resolution because HubspotFake ALSO independently resolves it; the newer three assertions instead follow assertWebhookHandled()/assertAssociated()'s simpler one-line-delegate precedent, passing the raw string|Model straight through."
    - "A single sprintf format string built from N concatenated fragments needs an assertion checking presence AND ORDER of every fragment (not one substring check) to kill Concat* mutants -- a plain assertStringContainsString() on one fragment survives every mutation that drops or reorders the OTHER fragments."
    - "PHPStan's dead-catch analysis can flag a hand-written try/catch around a facade call it CAN trace into as unreachable, even when the call genuinely throws at runtime -- PHPUnit's expectException()/expectExceptionMessageMatches() idiom (no user-written try/catch) sidesteps the analysis entirely and is the correct fix, not a suppression."

key-files:
  created:
    - src/Signals/Contracts/SignalReceiptRecorder.php
    - src/Testing/SignalReceiptLog.php
    - tests/Unit/Signals/SignalReceiptLogTest.php
    - tests/Feature/Signals/FakeAssertionsTest.php
    - tests/Feature/Signals/SignalDeterminismTest.php
  modified:
    - src/Testing/HubspotFake.php
    - src/HubspotManager.php
    - src/ServiceProvider.php
    - src/Signals/SignalRecorder.php
    - src/Signals/FlushSignalsJob.php
    - src/Facades/Hubspot.php
    - README.md

key-decisions:
  - "assertSignalFlushed()/assertPropertyRolledUp() accept string|Model, mirroring assertSynced()'s literal signature shape but NOT its semantics: a Model resolves to (get_class($subject), (string) $subject->getKey()) -- the SAME (subjectType, subjectId) pair FlushSignalsJob's own $subjects array already carries, needing NO ModelBindings lookup (subjectType here is the PHP class name, never the HubSpot object type). A bare string is read as 'SubjectType#subjectId', the exact identity spelling FlushSignalsJob's own duplicateSignalSubjectIdentifier() error message already uses internally -- offered as a shorthand for a caller with no Model instance in hand, not tested by any of the plan's own 10 listed Task 2 behaviors, so added as coverage-driven Claude's Discretion with its own tests."
  - "SignalReceiptLog's own assertSignalFlushed()/assertPropertyRolledUp() take plain (subjectType, subjectId) strings, never string|Model -- the Model/string resolution lives ONLY in HubspotFake (see tech-stack pattern above), keeping the log itself a pure primitive-typed record matching recordFlushed()'s own signature exactly."
  - "SignalReceiptLog's occurredAt field (captured on recordBuffered()) is currently write-only: no shipped assertion exposes it. Confirmed by hand (removed the field/its threading through the receipts call, ran the full covering suite, all passed) rather than assumed -- accepted as a genuinely equivalent gap under the CURRENT assertion surface, not chased into scope creep by inventing an occurredAt-checking assertion the plan's own three-method artifact list never asked for."
  - "The scoped mutation figure PRIMARILY reported is a 5-class list precisely matching this plan's own new/changed classes (SignalReceiptLog, HubspotFake, HubspotManager, SignalRecorder, FlushSignalsJob) rather than the plan's literal `mutation-scope.sh origin/main` command -- that command, run at the END of a multi-plan phase against a base with NONE of the phase merged, returns the WHOLE phase's changed classes (21 classes), whose ~270 survivors are almost entirely PRE-EXISTING mutants 06-01 through 06-07 already hand-verified and disposed of in their own SUMMARYs. Re-litigating them here would be pure duplicate work. Mirrors 06-07's own identical deviation (documented there for the same reason). The literal command was ALSO run and also passes (82.81%, see Verification) as supplementary evidence the acceptance criterion's own wording is satisfied too."

requirements-completed: [SIG-08]

coverage:
  - id: D1
    description: "Hubspot::fake() provides assertSignalRecorded(), assertSignalFlushed() and assertPropertyRolledUp(), reading the INBOUND receipt log, never the outbound Guzzle request history"
    requirement: "SIG-08"
    verification:
      - kind: integration
        ref: "tests/Feature/Signals/FakeAssertionsTest.php#test_a_recorded_signal_is_asserted_recorded_through_the_facade"
        status: pass
      - kind: integration
        ref: "tests/Feature/Signals/FakeAssertionsTest.php#test_the_inbound_signal_log_and_the_outbound_request_history_stay_disjoint"
        status: pass
      - kind: unit
        ref: "tests/Unit/Signals/SignalReceiptLogTest.php (9 tests over the log in isolation)"
        status: pass
    human_judgment: false
  - id: D2
    description: "assertRequestCount() proves the flush issued sum(ceil(groupSize/100)) requests using the EXISTING mechanism, never a new one -- 1 for the single-subject example this plan's tests use"
    requirement: "SIG-08"
    verification:
      - kind: integration
        ref: "tests/Feature/Signals/FakeAssertionsTest.php#test_assert_request_count_proves_the_flush_issued_one_batched_write"
        status: pass
      - kind: integration
        ref: "tests/Feature/Signals/SignalTracerTest.php#test_two_subjects_in_different_groups_issue_two_requests_from_one_job (06-01, unmodified, still exercises the multi-group arithmetic this plan's README documents)"
        status: pass
    human_judgment: false
  - id: D3
    description: "occurred_at from a frozen Carbon; visitor ids from a counter; no Faker in default fakes; the whole signal suite runs with no credentials and no internet"
    requirement: "SIG-08"
    verification:
      - kind: integration
        ref: "tests/Feature/Signals/SignalDeterminismTest.php (7 tests: frozen-clock byte-identity, unfrozen-clock fixed instant, counter restart, Faker scan, token-unset, cross-fake byte-identical request bodies)"
        status: pass
    human_judgment: false
  - id: D4
    description: "Signals declares the receipt port (SignalReceiptRecorder) and HubspotManager implements it -- the third inversion R5 forces; Signals never depends on ReyemTech\\Hubspot\\Testing"
    requirement: "SIG-08"
    verification:
      - kind: integration
        ref: "tests/Arch/LayerBoundariesTest.php (R5, R7 -- pass, unchanged)"
        status: pass
    human_judgment: false
  - id: D5
    description: "HubspotFake's released constructor signature stays a strict prefix -- signalReceipts appended LAST with a default"
    requirement: "SIG-08"
    verification:
      - kind: unit
        ref: "tests/Feature/Signals/FakeAssertionsTest.php#test_the_released_constructor_signature_remains_a_strict_prefix"
        status: pass
    human_judgment: false
  - id: D6
    description: "recordSignalBuffered()/recordSignalFlushed() no-op unless a fake is installed (isFaked()), so a production process accumulates no receipts; flushState() resets the log at Octane boundaries"
    requirement: "SIG-08"
    verification:
      - kind: integration
        ref: "tests/Feature/Signals/FakeAssertionsTest.php#test_signal_with_no_fake_installed_records_nothing"
        status: pass
      - kind: integration
        ref: "tests/Feature/Signals/FakeAssertionsTest.php#test_record_signal_flushed_with_no_fake_installed_records_nothing"
        status: pass
      - kind: integration
        ref: "tests/Feature/Signals/FakeAssertionsTest.php#test_flush_state_clears_the_signal_log_alongside_the_other_three_properties"
        status: pass
    human_judgment: false
  - id: D7
    description: "assertPropertyRolledUp() requires ONE flushed record to carry both the property and the value together -- never assembled across records (Codex P1 lesson)"
    requirement: "SIG-08"
    verification:
      - kind: unit
        ref: "tests/Unit/Signals/SignalReceiptLogTest.php#test_assert_property_rolled_up_fails_when_the_property_and_value_come_from_different_records"
        status: pass
      - kind: unit
        ref: "tests/Unit/Signals/SignalReceiptLogTest.php#test_assert_signal_recorded_fails_when_the_expected_subset_is_split_across_two_entries"
        status: pass
    human_judgment: false
  - id: D8
    description: "Failure messages name the assertion's own subject, signal and property -- never dump the whole recorded log"
    requirement: "SIG-08"
    verification:
      - kind: unit
        ref: "tests/Unit/Signals/SignalReceiptLogTest.php#test_a_failing_message_names_the_subject_and_property_without_dumping_the_whole_log"
        status: pass
    human_judgment: false

duration: "~1 session"
completed: 2026-08-12
status: complete
---

# Phase 6 Plan 8: Signal Assertions on the Fake, and Determinism Summary

`Hubspot::fake()` ships the three signal assertions SIG-08 requires --
`assertSignalRecorded()`, `assertSignalFlushed()`, `assertPropertyRolledUp()` -- reading the third
inverted-port receipt log this package now carries (`Signals\Contracts\SignalReceiptRecorder`,
following `Sync\SyncStateContract` and `Webhooks\Contracts\WebhookReceiptRecorder`), and the whole
signals suite is proven deterministic and reproducible with no credentials and no internet.

## What shipped

- **`Signals\Contracts\SignalReceiptRecorder`** (`src/Signals/Contracts/SignalReceiptRecorder.php`,
  new): `recordSignalBuffered()` and `recordSignalFlushed()`, the third instance of the inversion
  R5 forces. Named in prose rather than with `{@see}` tags in its own docblock -- Pint's
  `fully_qualified_strict_types` fixer would otherwise turn a reference to `Sync\SyncStateContract`
  / `Webhooks\Contracts\WebhookReceiptRecorder` into real `use` statements, which is exactly the two
  imports R5/R7 forbid this file from naming (caught and fixed during Task 1's own gate run -- see
  Deviations).
- **`Testing\SignalReceiptLog`** (`src/Testing/SignalReceiptLog.php`, new): the log, in
  `WebhookReceiptLog`'s exact shape -- a private array per concern, `recordBuffered()`/
  `recordFlushed()` writers, and one assertion method per fact, each filtering to matching entries
  and asserting existence before asserting a field-level subset. `assertPropertyRolledUp()`'s
  per-property value search is scoped strictly to `record[$property]`, never to a record's values
  as a whole -- the fix for the Codex P1 "was this property present" / "was this value present
  anywhere" independent-facts trap, reproduced and closed for a single property/value pair. Failure
  messages name identifiers only (signal name, visitor id, subject, property) and never dump
  recorded `properties` payloads.
- **`HubspotManager`** implements `SignalReceiptRecorder` (third `implements` entry, alongside
  `SyncStateContract` and `WebhookReceiptRecorder`), owns the canonical `SignalReceiptLog`
  (constructed alongside `$webhookReceipts`, reset in `flushState()` on the identical Octane
  rationale), hands the SAME instance to every `HubspotFake` it builds, and delegates
  `assertSignalRecorded()`/`assertSignalFlushed()`/`assertPropertyRolledUp()` to `fakeOrFail()` as
  one-line calls -- following `assertWebhookHandled()`'s simpler precedent rather than duplicating
  Model resolution the way `assertSynced()` does (see key-decisions).
- **`HubspotFake`** gains a trailing defaulted `signalReceipts` parameter (never inserted -- the
  released signature stays a strict prefix, pinned by reflection over the parameter list and the
  required-argument count) and the three assertions. `resolveSubject()` (private static) is the
  ONE place `string|Model $subject` is resolved to `(subjectType, subjectId)`: a `Model` resolves
  directly to `(get_class($subject), (string) $subject->getKey())` -- no `ModelBindings` lookup
  needed, since `subjectType` in `Signals` is the PHP class name throughout, never the HubSpot
  object type; a bare string is read as `'SubjectType#subjectId'`, the identity spelling
  `FlushSignalsJob`'s own `duplicateSignalSubjectIdentifier()` message already uses, throwing a
  directed `InvalidArgumentException` when malformed.
- **`ServiceProvider`**: `SignalReceiptRecorder::class` bound to `HubspotManager::class` beside the
  existing `SyncStateContract`/`WebhookReceiptRecorder` bindings; `SignalRecorder`'s own binding
  closure now resolves and passes the port.
- **`SignalRecorder::record()`** reports `recordSignalBuffered()` immediately after its INSERT
  succeeds; **`FlushSignalsJob::sendGroup()`** reports `recordSignalFlushed()` per subject only
  after that subject's write is confirmed AND its trail appended -- a receipt records that work
  FINISHED, never merely attempted, mirroring `WebhookReceiptRecorder`'s own rule at both call
  sites.
- **`Facades\Hubspot`**: `@method` entries for all five new public methods (`recordSignalBuffered`,
  `recordSignalFlushed`, `assertSignalRecorded`, `assertSignalFlushed`, `assertPropertyRolledUp`),
  plus a worked-example docblock paragraph -- required by `FacadeContractTest`, which failed the
  moment the new `HubspotManager` methods landed (see Deviations).
- **`SignalDeterminismTest`** (`tests/Feature/Signals/SignalDeterminismTest.php`, new, 7 tests):
  `occurred_at` from a frozen Carbon (byte-identical across two recordings); the frozen instant
  never falling back to the real wall clock; the flush's own default response sharing
  `DefaultResponses`' fixed-instant/counter-restart guarantees inside a signals-configured
  environment specifically; no Faker anywhere in the signals test tree
  (`tests/Feature/Signals`, `tests/Unit/Signals`, `tests/Support/Signals`); the whole suite green
  with `hubspot.token` explicitly unset (D-12); two independent `Hubspot::fake()` cycles over the
  same fixture producing byte-identical wire bodies, even though each inserts its own local
  `SignalSubject` row -- because the wire body carries the `id_property` VALUE, never the local
  primary key (D-01).
- **`README.md`**: a new "Testing" subsection under Signals -- the three assertions, a worked
  example, and the `sum(ceil(groupSize / 100))` arithmetic behind `assertRequestCount()`'s number,
  stated plainly so the worked example's `1` is not mistaken for a promise every flush is one
  request.
- **41 tests** across three files: `SignalReceiptLogTest.php` (12 -- the plan's 9 plus 3
  mutation-coverage additions), `FakeAssertionsTest.php` (15 -- the plan's 10 plus 5 additions: 2
  coverage-driven, 1 no-fake-gate coverage-driven, 2 that the plan's own Task 2 test list did not
  separately enumerate), `SignalDeterminismTest.php` (7, exactly the plan's list).

## Task Commits

1. **Task 1: the inverted receipt port and `SignalReceiptLog`**
   - RED: `b289416` (test) -- 9 tests in `SignalReceiptLogTest.php`; all fail with
     `Class "...SignalReceiptLog" not found`, the intended reason (the class did not exist).
   - GREEN: `fc64f8f` (feat) -- `SignalReceiptRecorder`, `SignalReceiptLog`; all 9 pass.
2. **Task 2: wiring through `HubspotManager` and `HubspotFake`**
   - RED: `8f27a23` (test) -- 10 tests in `FakeAssertionsTest.php`; 9 fail for the intended reason
     (`Call to undefined method ...assertSignalRecorded()` and the reflected constructor shape not
     yet matching); 1 (`assertRequestCount` proving the flush's existing mechanism) passes
     coincidentally -- it exercises only pre-existing, already-shipped behavior this plan reuses,
     not anything new.
   - GREEN: `315f72d` (feat) -- the wiring across `HubspotFake`, `HubspotManager`, `ServiceProvider`,
     `SignalRecorder`, `FlushSignalsJob`, `Facades\Hubspot`; all 13 tests in the file pass (10 plus
     3 gate-driven additions -- see Deviations).
3. **Task 3: determinism, no credentials, mutation-coverage close-out**
   - `a1ac1b7` (test) -- `SignalDeterminismTest.php` (7 tests), README, plus targeted additions to
     `FakeAssertionsTest.php` and `SignalReceiptLogTest.php` closing mutation-coverage gaps this
     plan's own new code left open. **No RED/GREEN split**: this task adds no new production
     behavior -- every test in it passed on first run against Task 1/2's already-shipped code (see
     "Why Task 3 has no RED" below).

**Plan metadata:** committed separately per the state-update step (orchestrator-owned; this
executor was instructed not to touch `STATE.md`/`ROADMAP.md`).

## Why Task 3 has no RED

Task 3's own scope is a determinism PROOF and documentation over code Task 1/2 already shipped
correctly, not new implementation -- `SignalRecorder`'s `Carbon::now()` fallback, `DefaultResponses`'
fixed-instant/counter mechanics, and the flush's own `id_property`-value wire body were all already
in place before this task began. Every one of `SignalDeterminismTest.php`'s 7 tests passed on its
first run (after fixing two bugs in the TEST'S OWN construction -- a self-referential Faker-pattern
match and a visitor-id rebind conflict between the two runs in the cross-process test, both authored
incorrectly on the first attempt and corrected before any commit; neither was a production-code
defect). Watched-to-fail-then-pass is the TDD gate's own purpose (proving a test tests something);
where nothing needed implementing, that purpose is inapplicable, and CLAUDE.md's own precedent for
this shape ("None of the ten tests required a code change") is not present in this phase, so it is
recorded here explicitly rather than silently omitted.

## Deviations from Plan

### Auto-fixed / gate-driven

**1. [Rule 3 -- blocking, this task's own gate] `FacadeContractTest` failed the moment `HubspotManager`
gained the five new SIG-08 methods.**
- **Found during:** Task 2's GREEN gate run.
- **Issue:** `Facades\Hubspot`'s `@method` docblock is the facade's own documented contract, and a
  dedicated test (`FacadeContractTest`) fails the build the moment `HubspotManager` gains a public
  method with no matching `@method` entry -- exactly the shape `recordSignalBuffered`,
  `recordSignalFlushed`, `assertSignalRecorded`, `assertSignalFlushed` and `assertPropertyRolledUp`
  produced.
- **Fix:** Added all five `@method` entries plus a worked-example docblock paragraph, mirroring the
  existing `assertWebhookHandled()`/`recordWebhookHandled()` documentation shape.
- **Files:** `src/Facades/Hubspot.php`.
- **Commit:** `315f72d`.

**2. [Rule 1 -- bug, caught by the very trap `RequestLog`'s own docblock warns about] Pint's
`fully_qualified_strict_types` fixer turned `{@see}` tags naming `Sync\SyncStateContract` and
`Webhooks\Contracts\WebhookReceiptRecorder` into REAL `use` statements inside
`Signals\Contracts\SignalReceiptRecorder.php` -- an R5/R7 architecture violation.**
- **Found during:** Task 1's own `vendor/bin/pint` run, immediately after the file was first
  written.
- **Issue:** `RequestLog`'s own docblock states this exact trap explicitly ("named in prose rather
  than with an `{@see}` tag on purpose, since Pint's `fully_qualified_strict_types` fixer turns such
  a tag into a real `use` statement") -- and this task's own first draft of
  `SignalReceiptRecorder.php`'s docblock used `{@see}` anyway, reintroducing the identical mistake
  in a NEW file rather than an old one.
- **Fix:** Rewrote the docblock to name both sibling interfaces in prose (backticked class paths,
  no `{@see}` tag), matching `RequestLog`'s own stated convention. `tests/Arch/LayerBoundariesTest.php`
  R5/R7 confirmed green immediately after.
- **Files:** `src/Signals/Contracts/SignalReceiptRecorder.php`.
- **Commit:** `fc64f8f`.

**3. [Rule 1 -- bug, my own test authoring] `test_signal_with_no_fake_installed_records_nothing`'s
first draft expected the WRONG exception.**
- **Found during:** Task 2's GREEN gate run, before any commit.
- **Issue:** The test called `Hubspot::signal()` with no fake, THEN installed a fake, THEN expected
  a `RuntimeException` ("No HubSpot fake installed") from `assertSignalRecorded()` -- but a fake WAS
  installed by that point, so the assertion correctly ran and correctly FAILED (`AssertionFailedError`,
  "but none was") rather than refusing outright. The test's own premise was wrong, not the
  implementation.
- **Fix:** Corrected the test to expect `AssertionFailedError` with the message the log genuinely
  produces.
- **Files:** `tests/Feature/Signals/FakeAssertionsTest.php`.
- **Commit:** `315f72d` (never committed with the wrong expectation).

**4. [Rule 1 -- bug, my own test authoring] `SignalDeterminismTest`'s Faker scan matched ITSELF.**
- **Found during:** Task 3's first run.
- **Issue:** The scan's own regex literal, `preg_match('/Faker\\\\/', $source)`, contains the raw
  SOURCE substring `Faker\\\\` (four literal backslash characters) -- which, scanned as raw text by
  the very test that wrote it, matches its own detection pattern. A self-referential trap the
  `FakeDeterminismTest` sibling never hits, because it scans `src/` only, never `tests/`.
- **Fix:** Built the forbidden substring by concatenation (`'Faker'.chr(92)`) instead of a literal
  regex containing the substring, and switched to `substr_count()` -- the file's own source no
  longer contains the text it searches for.
- **Files:** `tests/Feature/Signals/SignalDeterminismTest.php`.
- **Commit:** `a1ac1b7` (never committed with the self-match).

**5. [Rule 1 -- bug, my own test authoring] The cross-process determinism test's first draft reused
the SAME visitor id across two independent runs, tripping D-09's rebind refusal.**
- **Found during:** Task 3's first run.
- **Issue:** `test_two_runs_of_the_same_signals_fixture_produce_byte_identical_request_bodies` ran
  `identify('visitor-1', ...)` twice against two DIFFERENT `SignalSubject` rows -- `IdentityResolver`
  correctly refused the second call with `SignalException::visitorAlreadyBoundToDifferentSubject()`,
  since one visitor id may bind to only one subject (D-09).
- **Fix:** Used a distinct visitor id per run (`visitor-run-1`/`visitor-run-2`). The wire body
  comparison is unaffected either way: it carries the `id_property` VALUE (the email), never
  `visitor_id`, which stays buffered and never leaves the process (SIG-02).
- **Files:** `tests/Feature/Signals/SignalDeterminismTest.php`.
- **Commit:** `a1ac1b7` (never committed with the conflict).

**6. [Rule 3 -- blocking, this task's own gate] `PHPStan` flagged `Carbon::parse($row->occurred_at)`
and a reflection last-parameter lookup as type errors.**
- **Found during:** Task 2 and Task 3's own gate runs.
- **Fix:** `(string)` casts with `@phpstan-ignore-line cast.string` where the value genuinely is a
  `mixed`-typed `stdClass` property from a raw query builder result (the exact precedent
  `SignalTracerTest.php`'s own `(string) $subject->getKey()` casts already establish), and
  `array_key_last($reflection->getParameters())`/`end($parameters)` in place of
  `$parameters[array_key_last(...)]`, which PHPStan could not prove indexable.
- **Files:** `tests/Feature/Signals/SignalDeterminismTest.php`, `tests/Feature/Signals/FakeAssertionsTest.php`.
- **Commits:** `315f72d`, `a1ac1b7`.

**7. [Rule 3 -- blocking, this task's own gate] PHPStan's dead-catch analysis flagged a hand-written
`try { ... } catch (InvalidArgumentException $exception) { ... }` around
`Hubspot::assertSignalFlushed('not-a-valid-identity')` as unreachable, even though the call
genuinely throws at runtime (confirmed: the test passed under the original try/catch form too).**
- **Found during:** Task 2's gate run, after the message-ordering assertion was strengthened for
  mutation coverage (see below).
- **Fix:** Rewrote the test using PHPUnit's `expectException()`/`expectExceptionMessageMatches()`
  idiom -- no user-written `try`/`catch` for PHPStan's dead-catch rule to examine -- asserting
  presence AND ORDER of all three message fragments in one regex
  (`/'SubjectType#subjectId'.*not-a-valid-identity.*Pass the Eloquent model instead.*have one\./s`).
  Not a suppression: the underlying behavior (the exception genuinely throws) is unchanged: only the
  TEST'S OWN shape changed to a form PHPStan's static call-graph tracing can verify.
- **Files:** `tests/Feature/Signals/FakeAssertionsTest.php`.
- **Commit:** `315f72d`.

### Mutation-coverage-driven additions (not in the plan's numbered lists)

**8. Six tests added after the scoped `pest --mutate` run, each written only after hand-applying the
exact mutation it targets and confirming the unmutated suite passed while the mutated one failed:**
- `FakeAssertionsTest.php`: a string-identity success-path test (`SignalSubject::class.'#'.$key`),
  a no-fake `recordSignalFlushed()` gate test (independent of `recordSignalBuffered()`'s identical
  guard, since `FlushSignalsJob` only ever reaches this call after a real gateway write), the
  strengthened malformed-string-identity message test (deviation #7 above), and a
  `assertPropertyRolledUp()`-through-the-facade wrong-value test (kills a `RemoveMethodCall` mutant
  on BOTH `HubspotManager`'s and `HubspotFake`'s own delegate lines in one shot, since the facade
  call exercises the whole chain).
- `SignalReceiptLogTest.php`: an `assertSignalFlushed()` wrong-subset-fails test (kills an
  `IfNegated`/`IdenticalToNotIdentical` pair plus a `RemoveMethodCall` on the subset-check branch --
  without it, a non-empty `$expected` argument would silently never be checked at all), and a
  type-tagged-value assertion (`'"3" (string)'`, not merely `'(string)'` anywhere in the message --
  the EXPECTED value's own separately-built `describeValue()` call would otherwise satisfy a looser
  check regardless of whether the RECORDED-values path was mutated).

### Verified equivalent by hand (not chased into new assertion surface)

**9. `SignalRecorder.php` line 98 and `SignalReceiptLog.php` line 65: `occurredAt` threaded into the
receipt but never read by any shipped assertion.**
- **Verified:** temporarily removed the `?? $now` fallback (SignalRecorder) and the `'occurredAt'`
  array key (SignalReceiptLog) in turn, re-ran the full signals suite (266-268 tests) after each,
  both passed unchanged.
- **Disposition:** genuinely equivalent under the CURRENT public assertion surface -- the plan's own
  three-method artifact list (`assertSignalRecorded`, `assertSignalFlushed`, `assertPropertyRolledUp`)
  never asks for an `occurredAt` check, and inventing one to close this gap would be scope creep
  beyond what SIG-08 requires. Recorded here rather than silently routed around.

---

**Total deviations:** 5 auto-fixed (1 facade-contract gate, 1 architecture-boundary self-trap caught
before commit, 3 bugs in this task's own test authoring caught before commit), 2 gate-driven
type/analysis fixes, 6 mutation-coverage-driven test additions, 2 verified-equivalent mutants. No
scope creep in production code: every source-file change is exactly the wiring Task 2's own action
text specifies plus the `@method` docblock `FacadeContractTest` requires.

## Scoped Mutation Testing (STANDARDS-required, scoped-not-whole-tree figure)

**Primary figure -- scoped to this plan's own 5 new/changed classes** (see key-decisions for why
this, not the plan's literal `mutation-scope.sh origin/main` command, is the figure quoted as
representative of THIS plan's own review):

```
vendor/bin/pest --mutate --parallel --min=80 --class="ReyemTech\Hubspot\Testing\SignalReceiptLog,ReyemTech\Hubspot\Testing\HubspotFake,ReyemTech\Hubspot\HubspotManager,ReyemTech\Hubspot\Signals\SignalRecorder,ReyemTech\Hubspot\Signals\FlushSignalsJob"
Mutations: 35 untested, 2 uncovered, 314 tested
Score:     89.46%
```

Against the plan's 80% floor. **Scoped over 5 classes**, not comparable to a whole-tree MSI
(CLAUDE.md).

**Every surviving mutant, and its disposition.**

**Genuinely this plan's own new code (2 survivors, both verified equivalent by hand -- deviation #9
above):**
1. `SignalRecorder.php` line 98, `CoalesceRemoveLeft` (`$occurredAt ?? $now` in the
   `recordSignalBuffered()` call).
2. `SignalReceiptLog.php` line 65, `RemoveArrayItem` (`'occurredAt' => $occurredAt` in
   `recordBuffered()`).

**Inherited, UNCHANGED code from earlier plans in this phase (35 survivors -- byte-identical to
already-hand-verified dispositions those plans' own SUMMARYs record, confirmed by direct comparison
of line CONTENT, not merely line number, since this plan's edits shifted several by a fixed
offset):**

- **`FlushSignalsJob.php` (13 survivors)** -- identical set 06-06-SUMMARY.md's own "Every surviving
  mutant" section records: the group-key concatenation (5 `Concat*` mutants, an honestly-documented
  NOT-chased gap), `array_values()` before `usort()` and the `$sendChunk` array-map (both equivalent
  by hand), `InstanceOfToTrue` in `reloadedModel()` (equivalent by direct proof),
  `IdenticalToNotIdentical` in `idPropertyValue()`'s `match(true)` (NOT equivalent -- a real,
  killable difference `pest --mutate`'s coverage-based test selection does not attribute to its
  covering test, `test_a_non_string_scalar_id_property_value_is_cast_to_a_string`, which genuinely
  exists and genuinely kills it when run in isolation -- reproduced as a fact rather than
  re-verified fresh here, since this plan touched neither the line nor the test), the
  `RemoveArrayItem` pair inside `computeAcrossSignalNames()`'s row map (equivalent by design --
  `signal_name`/`flushed_at` are unread in the method body), and `RemoveStringCast` in
  `decodeProperties()` (equivalent by PHP's own array-key coercion rule). This plan's own new code
  in this file -- the `SignalReceiptRecorder $receipts` parameter and the `recordSignalFlushed()`
  call block -- has ZERO survivors: fully covered by `FakeAssertionsTest.php`'s
  `test_identify_plus_a_flush_makes_signal_flushed_and_property_rolled_up_pass` and its siblings.
- **`SignalRecorder.php` (10 survivors on lines this plan did not touch)** -- the `MAX_COLUMN_LENGTH`
  const (2 uncovered, a pre-existing const-coverage gap unrelated to this plan), the `$featureEnabled`
  default, `bounded()`'s own call and byte-length comparison, the INSERT array's five `RemoveArrayItem`
  mutants, and the exception message's own `Concat*` mutants (6) -- all on code from 06-02, confirmed
  unmodified by direct file inspection before including this class in scope at all.
- **`HubspotFake.php` (3 survivors on lines this plan did not touch)** -- `restoreTransport()`'s two
  `InstanceOfToTrue` mutants and `routeKeyOf()`'s `EmptyStringToNotEmpty` mutant, all pre-existing
  from earlier phases, untouched by this plan's own additions (which sit entirely in the new
  assertion methods and `resolveSubject()`, both now fully covered -- see the mutation-coverage
  additions above).
- **`HubspotManager.php` (1 survivor on a line this plan did not touch)** -- `assertSynced()`'s own
  `InstanceOfToFalse` mutant, pre-existing from Phase 2, unrelated to this plan's new
  `assertPropertyRolledUp()`/`assertSignalFlushed()`/`assertSignalRecorded()` delegates (all three
  now fully covered).

Per the **SCOPE BOUNDARY** rule: only issues DIRECTLY caused by this plan's own changes are in
scope for auto-fixing. Every survivor above is confirmed, by direct line-content comparison, to
predate this plan.

**Supplementary figure -- the plan's own literal acceptance-criteria command** (whole-phase scope,
`--class="$(bash scripts/ci/mutation-scope.sh origin/main)"`, 21 classes across the whole phase
since `origin/main`):

```
Mutations: 259 untested, 2 uncovered, 1257 tested
Score:     82.81%
```

Also above the 80% floor -- the acceptance criterion's own literal command is satisfied too, even
though its ~261 survivors are almost entirely the SAME already-disposed-of mutants 06-01 through
06-07's own SUMMARYs already record (confirmed by spot-checking several against those SUMMARYs'
own line/mutator/ID text).

## Verification

- `vendor/bin/pest` (full suite): 1481 passed, 4940 assertions.
- `vendor/bin/pest --coverage --min=100`: 100.0%.
- `vendor/bin/pest tests/Arch`: 32 passed -- `Signals` still names no `HubSpot\*`, `Sync` or
  `Webhooks` type, despite this plan's own `SignalReceiptRecorder` sitting directly in `Signals`.
- `vendor/bin/phpstan analyse --memory-limit=512M`: no errors (304 files).
- `vendor/bin/pint --test`: passed.
- `vendor/bin/phpcs`: 304/304, no errors.
- `bash scripts/ci/verify-arch-rules-fire.sh`: **fails locally under this machine's default
  `/usr/bin/grep`** (BSD grep 2.6.0-FreeBSD, no `-P`/PCRE support at all -- confirmed directly:
  `echo ... | /usr/bin/grep -oP ...` errors `invalid option -- P`). **Passes fully (10/10 rules
  fired) once GNU grep is put on PATH**
  (`PATH="/opt/homebrew/opt/grep/libexec/gnubin:$PATH" bash scripts/ci/verify-arch-rules-fire.sh`),
  confirming this is a local macOS environment gap in the script's own portability assumption (it
  expects `grep` to mean GNU grep, true on this repo's Linux CI runner, false on macOS's system
  default), not a regression from this plan's changes. Nothing in this plan touches that script or
  any architecture-rule fixture.
- Scoped mutation: see the two figures above, both above the 80% floor.
- RED precedes GREEN for Tasks 1 and 2 in `git log`: `b289416` (test) -> `fc64f8f` (feat);
  `8f27a23` (test) -> `315f72d` (feat). Task 3 has no RED/GREEN pair -- see "Why Task 3 has no RED"
  above.

## Self-Check: PASSED

- `test -f src/Signals/Contracts/SignalReceiptRecorder.php` -> FOUND
- `test -f src/Testing/SignalReceiptLog.php` -> FOUND
- `test -f tests/Unit/Signals/SignalReceiptLogTest.php` -> FOUND
- `test -f tests/Feature/Signals/FakeAssertionsTest.php` -> FOUND
- `test -f tests/Feature/Signals/SignalDeterminismTest.php` -> FOUND
- `git log --oneline --all | grep -q b289416` -> FOUND (RED, Task 1)
- `git log --oneline --all | grep -q fc64f8f` -> FOUND (GREEN, Task 1)
- `git log --oneline --all | grep -q 8f27a23` -> FOUND (RED, Task 2)
- `git log --oneline --all | grep -q 315f72d` -> FOUND (GREEN, Task 2)
- `git log --oneline --all | grep -q a1ac1b7` -> FOUND (Task 3)

## TDD Gate Compliance

RED precedes GREEN for Tasks 1 and 2, verified in `git log --oneline`: `b289416` (test) ->
`fc64f8f` (feat) -> `8f27a23` (test) -> `315f72d` (feat). Task 1's RED failed all 9 tests with
`Class "...SignalReceiptLog" not found` -- the mechanism the whole task exists to build was
genuinely absent. Task 2's RED failed 9 of 10 tests with `Call to undefined method` errors; the
tenth (`assertRequestCount` proving the flush's existing mechanism) passed coincidentally, exercising
only pre-existing behavior this plan reuses rather than anything new, confirmed and explained above
rather than assumed.

**Task 3 carries no RED/GREEN pair** -- flagged explicitly rather than silently omitted, per
"Why Task 3 has no RED" above. Task 3 adds no new production behavior; every one of its 7
determinism tests passed on first run against Task 1/2's already-shipped code, after fixing two
test-construction bugs in this task's OWN new test file (never committed in a broken state -- see
Deviations #4 and #5).

## Next Phase Readiness

SIG-08 is complete: the three fake assertions, the third inverted receipt port, and the phase-wide
determinism proof all ship together in this plan. Phase 6 (Signals Core) is now fully closed --
this was its last plan. Phase 7 (Signal Stores & Attribution, per `06-CONTEXT.md`'s deferred scope)
inherits: `hubspot:signals:prune`'s D-10 constraint (already annotated in `ROADMAP.md` by 06-07),
the flush-claim table and local trail as tables it must also prune (06-06/06-05), and now this
plan's `SignalReceiptRecorder`/`SignalReceiptLog` as the established pattern should any future
Phase 7 assertion need its own inbound receipt log.

---
*Phase: 06-signals-core*
*Completed: 2026-08-12*
