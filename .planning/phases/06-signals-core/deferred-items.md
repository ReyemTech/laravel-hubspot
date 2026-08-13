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

3. **`src/Signals/SignalReconciler.php:195`** -- `reconcileChunk()`'s correlation loop uses
   `continue` when a `findMany()` result record carries no echoed id property, to skip just that
   record. Mutating it to `break` also passes the whole suite: no existing test constructs a batch
   read response where a record missing the id property is followed by a LATER record that has it,
   so nothing proves `break` would silently drop every record after the malformed one instead of
   just the malformed one. This is the one gap of the three with a real (if narrow) production
   consequence -- a malformed record early in HubSpot's own batch response would silently un-reconcile
   every subject after it in the same read.

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
