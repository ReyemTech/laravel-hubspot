# Deferred items

Out-of-scope discoveries found while closing the two PR #82 review findings (reconcile durability,
P1; unsaved-subject refusal, P2) on `feat/phase-6-signals-core`. Logged per the scope-boundary rule
("only auto-fix issues directly caused by the current task's changes") -- these are pre-existing,
unrelated to that diff, and verified rather than fixed.

Each was verified by manually re-applying the exact mutation `pest --mutate` reports and running the
full suite (`vendor/bin/pest`, 1491 tests) against the mutated file; all pass, confirming the gap is
real and not a scoped-run artefact.

## Genuine pre-existing coverage gaps (killable, left unfixed)

1. **`src/Signals/IdentityResolver.php:80`** -- `private readonly bool $featureEnabled = true` is
   never exercised with its default omitted; the container always resolves this constructor with an
   explicit value read from config. Mutating the default to `false` passes the whole suite.

2. **`src/Signals/IdentityResolver.php:115`** -- `identify()`'s own conditional `UPDATE` sets
   `'updated_at' => Carbon::now()` alongside `subject_type`/`subject_id`; no test asserts
   `updated_at` changed. Removing the array item passes the whole suite.

3. ~~**`src/Signals/SignalReconciler.php:195`** -- `reconcileChunk()`'s correlation loop uses
   `continue` when a `findMany()` result record carries no echoed id property, to skip just that
   record.~~ **Resolved 2026-08-12** (absent-vs-confirmed-empty P1 fix, same file):
   `test_the_correlation_loop_continues_past_an_uncorrelatable_record_to_the_next_one` in
   `FlushReconcileAbsenceTest.php` now constructs exactly the two-record response this note asked
   for and kills the mutant. A sibling `ContinueToBreak` this same fix introduced (the new
   unconfirmed-subject branch's own `continue`) is killed by
   `test_the_per_subject_loop_continues_past_a_stripped_unconfirmed_subject_to_the_next_one` in the
   same file.

## New findings this session (2026-08-12, absent-vs-confirmed-empty P1 fix + its own codex review)

Two `codex review --base main` passes were run this session. The first (scoped to this session's
own commits) surfaced only the `FlushSignalsJob.php` P1 fixed in this same task (see the
commit history) and the `SignalRecorder.php` P2 below. The second (`--base main`, the full branch
diff) additionally surfaced the `IdentityResolver.php` P1 below -- neither run's scope includes
`SignalReconciler.php` for either of these two, and neither file was touched by this session's
diff, so both are logged rather than fixed here (scope-boundary rule). **Per `CLAUDE.md`'s own
review policy a P1 blocks merge** -- the `IdentityResolver.php` finding below should not be
treated as closed just because it is out of scope for this particular task.

- **`src/Signals/IdentityResolver.php:109-116` (P1, codex review, out of scope for this diff)** --
  two concurrent first-time `identify()` calls for the same anonymous visitor with DIFFERENT
  subjects can both pass `refuseRebindToADifferentSubject()`'s pre-check before either's
  conditional `UPDATE` runs; the losing call's `UPDATE` then affects zero rows, and the method
  returns as if it succeeded -- the caller believes its subject was bound, but the signals stay
  bound to the OTHER subject and no flush is ever dispatched for the caller's own subject. Needs a
  re-check of the actual binding when the conditional update affects zero rows, throwing the rebind
  exception if it landed on a different subject (the fix codex itself suggests).

- **`src/Signals/SignalRecorder.php:111-122` (P2, codex review, out of scope for this diff)** --
  codex review flagged that a caller-supplied visitor id (or mapped signal name) containing a NUL
  byte is rejected by PostgreSQL's `varchar` insert but silently accepted by MySQL/SQLite, giving
  driver-dependent behaviour and a late raw-query failure. `SignalRecorder.php` is untouched by
  this session's diff (`SignalReconciler.php`, `FlushSignalsJob.php`, `Testing/DefaultResponses.php`
  only) and the finding is unrelated to the absent-vs-empty reconcile distinction this task fixes --
  logged per the scope-boundary rule rather than fixed here. Suggested fix mirrors the local trail
  store's existing NUL-byte rejection for its own string columns.
  **Resolved 2026-08-12**: `IdentityResolver.php`'s P1 rebind race and this P2 were fixed together
  (`fix(06): close identify()'s rebind race and reject NUL bytes in signal identifiers`). The sweep
  covered `SignalRecorder::bounded()` (`visitorId`, `signalName`) and the one caller-supplied
  identifier `IdentityResolver::identify()` itself reads (`visitorId`); `LocalSignalStore::bounded()`
  already rejected NUL bytes for `subject_type`/`subject_id`/`signal_name` on the trail-append path,
  so it needed no change. `subject_type`/`subject_id` on the `hubspot_signals` buffer were
  deliberately left unchecked -- the migration's own docblock records why: both are package-controlled
  (an `::class` string and a cast primary key), not application-supplied free text -- and an
  `id_property` VALUE (e.g. a subject's email) never reaches a `hubspot_signals`/`hubspot_signal_trail`
  column at all; it is sent only to HubSpot's own HTTP API in `FlushSignalsJob`, out of scope for a
  local NUL-byte defect.

## Pre-existing mutation gaps confirmed during the P1/P2 fix above (2026-08-12)

Re-verified by running `pest --mutate` against the UNFIXED `git show HEAD~2:...` revision of each
file with its then-current tests, scoped to the single class -- each mutant below was ALREADY
untested before this session's diff, confirming they are not something the P1/P2 fix introduced or
regressed. Left unfixed per the scope-boundary rule (pre-existing, unrelated to the two findings this
session closes).

- **`src/Signals/SignalRecorder.php`** -- `MAX_COLUMN_LENGTH = 191` (the `const` itself, no executed
  line for `pest --mutate` to attribute coverage to -- the same shape `ServiceProvider::supportedStores()`
  documents), the `featureEnabled = true` constructor default (mirrors `IdentityResolver.php`'s own
  documented gap above), every INSERT array item (`subject_type`, `subject_id`, the `occurred_at ??`
  coalesce, `flushed_at`, `reconciled_at`, `reconciled_properties`, `created_at`), the
  `recordSignalBuffered(...)`'s `$occurredAt ?? $now` coalesce, and `bounded()`'s LENGTH check
  (`strlen($value) > self::MAX_COLUMN_LENGTH`, both the `>`/`>=` boundary and its message
  concatenation) -- no existing test exercises the length-throw path on a value sitting exactly at
  the 191/192-byte boundary, nor asserts the message's exact wording. `SignalTracerTest`'s
  `str_repeat('a', 192)` visitor id proves the check fires at all, but 192 is well past the boundary
  either way, so switching `>` to `>=` changes nothing observable.

## Verified equivalent mutants (cannot be killed, no fix applicable)

Re-applying each and running the full suite passed for the same reason in every case: the mutated
line's own effect is fully absorbed elsewhere in the same method, so no observable output ever
differs.

- **`SignalReconciler.php:43`** (`UnwrapArrayUnique`) -- `candidateProperties()`'s per-row
  `array_unique()` on signal names: without it, `foreach ($signalNames as $signalName)` just
  revisits the same name and re-sets the same `$properties[$property] = true` key, which is
  idempotent. Same result either way.
- **`SignalReconciler.php:50`** (`TrueToFalse`) -- `$properties[$property] = true` inside that same
  loop: only `array_keys($properties)` is ever read back; the boolean value itself is never
  inspected.
- **`SignalReconciler.php:112`** (`RemoveStringCast`, introduced by this fix's own
  `withPersistedProperties()`) -- `(string) $property`: PHP coerces a canonical-integer STRING array
  key to an `int` key regardless of an explicit cast (verified directly:
  `php -r '$a["5"]=1; var_dump(array_key_exists("5",$a), array_key_exists(5,$a));'` prints
  `true, true` either way), so the cast has no runtime effect. Kept for the same documented reason
  `FlushSignalsJob::decodeProperties()`'s own identical cast is kept -- it satisfies the declared
  `array<string, string>` return type for PHPStan even though the interpreter does not need it.
- **`SignalReconciler.php:141`** (`RemoveEarlyReturn`) -- `reconcile()`'s `if ($candidates === [])
  { return $group; }`: `array_chunk([], 100, true)` is `[]`, so the `foreach` below it is a no-op on
  empty candidates regardless, and the method still ends on `return $group;`. Removing the early
  return changes nothing.
- **`SignalReconciler.php:167` and `:182`** (`UnwrapArrayUnique` / `UnwrapArrayValues`, both lines,
  both mutations) -- `reconcileChunk()` dedups `$reconcileProperties` twice: once building it
  (line 167) and once folding it into `$requestedProperties` with the id property (line 182).
  Removing either dedup independently still leaves the OTHER one to catch a property name shared by
  two subjects in the same chunk, which is why every one of these four individual mutations passes
  the full suite in isolation. Removing BOTH at once would not be equivalent, but that is two
  mutations, not one -- outside what a single-mutant kill-or-verify pass covers. Worth a follow-up
  note if either line is ever refactored: the pair only stays behaviourally redundant as long as
  BOTH survive.
