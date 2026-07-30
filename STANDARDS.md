# Engineering Standards — reyemtech/laravel-hubspot

**Status:** Draft for review
**Date:** 2026-07-26

These are binding rules, not aspirations. Every one of them is enforced by CI or it does not
belong in this document. A standard that depends on someone remembering it is not a standard.

Where a rule diverges from existing ReyemTech practice (`apps/laravel`, `packages/sail`), the
divergence is called out and justified — this is a public library, and it is held to a higher
bar than an application nobody else has to integrate with.

---

## 1. Runtime and support matrix

| | Standard | Rationale |
|---|---|---|
| PHP | `^8.3` | Pest 4 requires it — see the tooling note below. Raised from `^8.2` on 2026-07-26 |
| Laravel | 12.x, 13.x | Laravel 11 dropped 2026-07-27 — see the EOL note below |
| HubSpot SDK | `hubspot/api-client:^14.1` | Matches what `apps/laravel` already runs |

**Laravel 11 dropped 2026-07-27, reversing the 2026-07-26 "reach over tidiness" sign-off.** Every
published Laravel `11.x` release is blocked by live security advisories — `PKSA-m5cs-t1y6-qpcs`,
`PKSA-3r5d-mb8f-1qw9`, `PKSA-mdq4-51ck-6kdq` — and Laravel 11 reached end of security support on
12 March 2026, so none of them will ever be patched. §12c makes `composer audit` fail the build on
any advisory, with no escape hatch; keeping Laravel 11 in the support matrix put that rule in
direct conflict with the migration-reach rationale that originally justified supporting an EOL
framework major. The owner chose to drop Laravel 11 rather than suppress the advisories or weaken
the audit gate. Advisories are not suppressed anywhere in this repository.

This is the same reasoning already used to exclude Laravel 10 ("past EOL, supporting a dead branch
is unpaid work"), now applied to Laravel 11 for the additional, sharper reason that its EOL status
is no longer merely dead — it is actively unpatchable. A package cannot responsibly bless a
framework version with open, unfixable CVEs, migration reach notwithstanding.

**PHP floor raised to `^8.3` on 2026-07-26, during Phase 1 research** (unaffected by the Laravel 11
drop). The `^8.2` floor was signed off earlier the same day and is deliberately reversed here,
under the owner's standing instruction to change it *only if it hinders our ability to write
better code*. It does:

> Pest 4 — and `pest-plugin-arch` 4.x and `pest-plugin-laravel` 4.x — require PHP `^8.3`. Keeping a
> PHP 8.2 leg would mean dual constraints (`^3.8|^4.0`), so the 8.2 jobs would run
> `pest-plugin-arch` 3.1.1, last released April 2025 and no longer maintained. Architecture tests
> and mutation scoring are two of the three headline standards in this document. A gate that runs
> on an unmaintained plugin on some CI legs and a current one on others is not a gate — it is two
> different gates wearing one name.

**Support matrix.** Rectangular for the first time: PHP `8.2` was never part of the matrix and
Laravel 11 — the one major that stopped at PHP 8.4 — is gone, so every remaining PHP version
supports every remaining Laravel major.

| | PHP 8.3 | PHP 8.4 | PHP 8.5 |
|---|---|---|---|
| **Laravel 12** | ✓ | ✓ | ✓ |
| **Laravel 13** | ✓ | ✓ | ✓ |

Six valid combinations, each run against both `prefer-stable` and `prefer-lowest` — **12 jobs, no
`exclude:` entries needed.** A version we do not test is a version we do not support, and the
README says so.

**Consequence for the code:** the Illuminate constraint is `^12.0|^13.0`, so no framework API
introduced in 13 may be used without a compatibility shim. Review checks this.

## 2. Runtime dependencies: any `illuminate/*`, no third party

**Superseded 2026-07-30 (D-02, Phase 4).** This section previously fixed production `require` at
exactly seven entries and stated that "being first-party Laravel does not make a component free."
Both are wrong now, in the owner's own words: *"all of standard laravel packages should be
allowed .. we just don't want to install new stuff unless absolutely needed."* The restraint this
section encodes is about **third-party** additions, not first-party Laravel ones.

The rule as it now stands: **any `illuminate/*` component may be declared** as a production
require, the moment `src/` needs it, with no ceremony beyond `composer require` and its own
one-line purpose recorded below. **Any non-`illuminate/*` third-party package still needs written
justification in the PR description**, and the reviewer's default answer is still no. Production
`require` currently stands at eleven entries; that count is not the rule and will drift as the
package grows — the **vendor-allow-list CI gate** (`manifest shape (vendor allow-list)`,
`tests/Ci/ComposerManifestTest.php`) is authoritative on what is admitted:

- `php` and `hubspot/api-client` by exact key — this package's own surface.
- Any `illuminate/*` package, by prefix.
- `laravel/prompts` via its own enumerated exception, carrying its own reason (below), never via a
  `laravel/`-prefix rule that a third-party `laravel/*` package could slip through under.

Current production requires and their one-line purpose:

- `illuminate/contracts`
- `illuminate/support` — `ServiceProvider`, facades, the `Route` macro
- `illuminate/database` — the Eloquent `Model` the sync trait and observer are typed against
- `illuminate/view` — the `Frontend` layer's Blade components (added 2026-07-26)
- `illuminate/queue` — `InteractsWithQueue`, `SerializesModels` (added 2026-07-30, Phase 4, D-07)
- `illuminate/bus` — `Queueable`, `Batchable`, and the `Dispatcher` contract the sync job dispatches
  through, because `Illuminate\Foundation` has no split package (added 2026-07-30, Phase 4, D-07/D-08)
- `illuminate/collections` — `data_get()`, which lives here and not in `illuminate/support`, and the
  `iterable` collection surface `syncManyToHubspot()` accepts (added 2026-07-30, Phase 4, D-16)
- `illuminate/console` — `Illuminate\Console\Command`, which three shipped console commands already
  imported without declaring it. **This is a defect being closed, not a capability being added**
  (D-19): the package shipped an undeclared production dependency in 0.3.0, resolving only because
  every consumer and Testbench both happen to supply `laravel/framework` transitively.
- `laravel/prompts` — the optional installer (§7); first-party Laravel, ships with the framework
  (which is why the design spec calls it "no new dependency" — true of the vendor tree, not true of
  this package's `composer.json`), and is this section's one enumerated exception because it is
  admitted by name rather than by any `laravel/`-prefix rule.

Notably excluded — still third-party, still needs the justification above:

- `spatie/laravel-package-tools` — convenient, but it is a dependency to save perhaps 80 lines
  of service provider. tapp takes it; we hand-roll.
- `spatie/laravel-webhook-client` — forces its `webhook_calls` migration on every consumer,
  which contradicts the zero-migration install (§7).
- `fakerphp/faker` — test support only, `require-dev`, and every call site guarded by
  `class_exists()`. A test helper must never drag Faker into a production vendor tree.

## 3. Static analysis: level max, no baseline

PHPStan + Larastan at **level 9 (max)**, `checkModelProperties: true`.

**A baseline file is forbidden.** `apps/laravel` runs level 5 with a baseline and `packages/sail`
runs level 0 — that is how you get analysis that reports zero problems while the code has
plenty. On a greenfield package there is no legacy to grandfather, so there is no excuse.

Suppression is per-line, never per-file, and always carries a reason:

```php
/** @phpstan-ignore-line SDK codegen returns `mixed`; shape asserted in ObjectGatewayTest */
```

CI fails on any new error. There is no "fix it later" mode.

## 4. `declare(strict_types=1)` in every PHP file

Enforced by an architecture test, not by review. This diverges from both existing repos (1 file
out of 395 in `apps/laravel`). The justification is specific rather than dogmatic: this package
passes HubSpot object ids around as strings that look like integers. Coercive typing turns
`"0"` and `0` and `""` into silent equivalents, and a wrong object id writes to the wrong CRM
record. Strict types is the cheapest defence available.

## 5. Style

Pint, `laravel` preset, with a committed `pint.json` so the ruleset is explicit rather than
implied. CI runs `pint --test` and fails on any diff.

`apps/laravel` has no style gate in CI at all. That is the gap this closes.

## 6. Tests

**Floors, enforced in CI:**

| Metric | Floor | Tool |
|---|---|---|
| Line coverage (PHP) | **95%** | Pest + Xdebug/PCOV |
| Mutation score (MSI) | 80% | `pest --mutate` |
| Line coverage (JS) | **95%** | Vitest |
| Architecture rules | all pass | see below |

The JavaScript floor exists because the `Frontend` layer's booking listener validates
`event.origin` before trusting a `postMessage` payload — the most security-sensitive code in the
package — and PHP line coverage cannot see a single line of it. It is affordable because the
documentation site already brings Node into CI.

Coverage alone measures which lines ran, not whether an assertion would notice them breaking.
Mutation testing is what makes the coverage number mean something, and it is the standard that
most packages in this ecosystem — including tapp — do not have.

**Architecture tests enforce the layer boundaries**, because a boundary that lives only in a
design document erodes within three months:

```
Gateway   → may depend on: hubspot/api-client
Registry  → may depend on: Gateway
Sync      → may depend on: Registry, Gateway
Webhooks  → may depend on: Registry, Gateway
Signals   → may depend on: Registry, Gateway
Frontend  → may depend on: the public facade ONLY

Exceptions → cross-cutting; every layer above may depend on it, and it depends on no
             layer in return                          [amended 2026-07-27]
```

Anything reaching upward fails the build. `Gateway` is the only layer permitted to reference
`HubSpot\*` classes — that is what makes the SDK swappable and the rest of the package fast to
test.

**The `Exceptions` line was added 2026-07-27, and it is a correction rather than a relaxation.**
§9 requires one typed hierarchy rooted at a package-owned interface, which consumers catch, and
forbids a raw SDK exception ever reaching userland — so every layer must be able to throw it. The
allow-lists for `Registry`, `Sync`, `Webhooks` and `Signals` did not name `Exceptions`, which made
those two rules mutually impossible; the first place it bit was the association-type resolver seam,
where a `Registry` implementation of the Gateway-side contract cannot answer a miss with anything
but a throw, and the architecture test rejected it for throwing. No layer boundary moved: nothing
lets `Registry` see `Sync` or `Frontend` see the SDK, and `Frontend`'s two rules are unchanged.
`tests/Arch/ResolverSeamTest.php` pins the permission with a committed fixture per layer, so a later
tightening cannot quietly re-break it.

Two further rules, added 2026-07-26 with the `Signals` and `Frontend` layers:

- **`Signals` may not depend on `Sync` or `Webhooks`.** It is a peer, not a consumer. Signals are
  event-shaped and have no local model; `Sync` is model-shaped. Merging them would blur the
  largest boundary in the package.
- **`Frontend` may not reference `HubSpot\*`, `Gateway`, `Registry`, `Sync`, `Webhooks` or
  `Signals`.** It talks to the same public facade a consumer would, which stops the frontend
  becoming a back door around the boundary that makes the SDK swappable.

**Tests are deterministic or they are broken.** Time is frozen (`Carbon::setTestNow()`), never
`sleep()`; randomness is seeded; ordering is never assumed. A test that passes in isolation and
fails in the parallel suite is a failing test, not an environment quirk.

**No skipped, incomplete or risky tests on `main`.** PHPUnit runs with `failOnSkipped`,
`failOnIncomplete` and `failOnRisky` enabled. A test worth skipping is worth deleting or fixing.

**Flaky tests are quarantined within 24 hours** — reverted or marked and issued, never left to
rot in CI. One tolerated flake teaches everyone to re-run red builds.

**No test may perform real network I/O.** The suite runs green with no HubSpot credentials and
no internet. Integration tests against a live developer portal live in a separate, opt-in suite
gated on a secret, and are never required to merge.

## 6a. TDD, from the first commit

Every change starts as a **failing (RED) test** and is implemented until green. Not a preference
— the working method.

- The test commit precedes the implementation commit. With merge-commit SDLC (§12) that history
  survives into `main`, so the RED→GREEN sequence is visible in `git log` forever.
- Every bug fix opens with a test that reproduces the bug. The PR description names the commit
  where it was red.
- Review checks the sequence, since CI cannot: a PR whose tests were written after the code is
  sent back.

The honest caveat: this is one of two standards here that tooling cannot fully enforce — the other
is reading automated review before resolving it (§12, *Automated review is review*). It holds
because review enforces it, which means reviewers have to actually look.

## 6b. Code shape

| Limit | Hard fail | Review target |
|---|---|---|
| File length | **500 lines** | 300 |
| Function length | **150 lines** | 40 |
| Cyclomatic complexity | 10 | 5 |

Enforced by a CI script — anything over the hard limit fails the build; anything over the review
target needs a sentence in the PR saying why. Given everything else in this document, a
150-line function should be close to nonexistent. `HubspotContactService` in tapp is 601 lines,
and its sibling is a 405-line near-duplicate of it; that is the outcome these limits exist to
prevent.

**Logic used more than once is extracted and reused.** One qualifier, learned from the same
package: extract *behaviour*, not *shape*. tapp's contact and company services look alike, which
is why they were written twice and then quietly diverged into different method names and return
types. Two functions that resemble each other but answer different questions stay separate; two
that answer the same question become one, immediately — not on the third occurrence.

## 7. Install and consumer surface

- **Zero migrations on install.** The package works after `composer require` with no publish
  step and no `migrate`. Database-backed stores are opt-in via one env var (§ design spec).
- **`hubspot:install` is optional, never required.** A package that breaks without an install
  step gets abandoned at the README.
- **Config is documented inline.** Every key in `config/hubspot.php` carries a comment stating
  what it does and what breaks if it is wrong.
- **Env vars are namespaced `HUBSPOT_*`** and listed in the README with their defaults.

## 8. Public API and backward compatibility

- Semantic versioning, strictly. The public API is everything not marked `@internal`.
- Every class is `final` unless extension is an explicit, documented feature. Unsealing later is
  a patch; sealing later is a breaking change.
- `roave/backward-compatibility-check` runs on every PR to `main`. A detected break fails CI
  unless the PR is labelled `breaking` and targets the next major.
- Deprecations live for **two minor versions minimum**, emit `E_USER_DEPRECATED`, and name their
  replacement in the message.
- `UPGRADE.md` is updated in the same PR as any breaking change — not at release time.

## 9. Errors

A typed hierarchy rooted at a package-owned interface:

```
HubspotException (interface)
├── ConfigurationException      — missing token, unknown store, unmapped model
├── AssociationTypeException    — direction not in the registry, label unknown for that pair
├── ObjectTypeException         — unknown or unmappable object type
└── ApiException                — wraps the SDK's, preserving status, body and request id
```

**A raw `HubSpot\Client\...\ApiException` must never reach userland.** Consumers catch our
types, which means we can change SDKs without breaking their `catch` blocks.

Every exception message names the fix, not just the fault: *"HUBSPOT_STORE=database but
`hubspot_association_types` does not exist — run `php artisan migrate`."*

## 10. Security

- Tokens and client secrets are **never logged**, never in exception messages, never in
  `dd()`-able state. An architecture test greps for the config keys in log calls.
- Webhook signature verification **fails closed** by default.
- Signature comparison uses `hash_equals`, delegated to the SDK's validator — we do not
  hand-roll HMAC.
- `SECURITY.md` with a private disclosure address, published from day one.
- Dependabot enabled; security advisories are patch releases within 48 hours.

## 11. Performance

- Batch endpoints are used wherever HubSpot offers one. Syncing a collection issues one batch
  request, not N.
- **N+1 API calls are a test failure, not a code smell**: `Hubspot::fake()` counts requests, and
  the sync tests assert exact call counts.
- No API call in a request lifecycle by default — sync is queued unless explicitly told
  otherwise.

## 12. SDLC — branching, PRs, releases

**Branching:**

- Every feature branch starts from a **freshly pulled `main`**. No exceptions.
- **Branching from a branch is strictly forbidden.** If work depends on unmerged work, the
  dependency merges first. Stacked branches produce review diffs nobody can read and merge
  conflicts nobody can resolve.
- A stale branch is updated by **rebasing onto a freshly pulled `main`**. Rebase replays your
  commits in order, so the RED→GREEN sequence of §6a is preserved intact — only the parent
  changes. Merging `main` in is also acceptable; it just adds noise commits.
- Rebase means force-pushing, so: **always `--force-with-lease`**, never `--force`. This is safe
  here precisely because §12 forbids branching from a branch — every branch is single-author by
  construction, so a rewrite cannot destroy anyone else's work.
- One side effect worth knowing: rewritten SHAs mark existing review comments "outdated" on
  GitHub. Rebase before requesting review, not in the middle of one.
- Branch names: `feat/`, `fix/`, `chore/`, `docs/` + a short slug.

**Pull requests:**

- **Merge commits**, not squash. The commit history — including the failing-test commit — is
  part of the record.
- Every PR states what was verified and how. "Tests pass" is not a verification statement;
  naming the command and its result is.
- PRs are reviewable in one sitting. Over ~400 changed lines, the description must say why it
  could not be split.
- No PR merges red. Not "it's unrelated", not "it's flaky" — see §6.

### Automated review is review

`main` requires conversation resolution, which makes resolving a thread feel like a mechanical
step on the way to a green merge button. It is not. Resolving a thread is an assertion that
somebody considered the feedback.

- **Every automated review comment is read in full before its thread is resolved.** Codex, or
  whatever replaces it. There is no "these are just bot comments" exemption — they read the diff
  more carefully than a tired human does at 2am.
- **Every resolved thread carries a written reply — including the ones that were fixed.** A fix
  is not a reply. Resolving a thread silently because the code changed leaves no record of *what*
  changed, whether the fix matches what was asked, or what was deliberately left undone; the
  reviewer, and the next person reading the thread, sees only a closed conversation.

  **Only the bot-authored half of this is gate-enforced, and the gap is stated rather than
  papered over.** `scripts/ci/check-review-threads.sh` asks whether a resolved thread contains any
  comment authored by a `User`. On a Codex thread that is exactly right: the only way a `User`
  comment appears is if somebody replied. On a thread a *human* opened, their own opening comment
  satisfies the check, so a human thread can still be resolved in silence and the gate will pass
  it. The rule above binds either way — it is a standard, not a CI feature — but nobody should
  read a green `review-threads` check as evidence that a human-started thread was answered.

  The reply states which of four dispositions applies, and carries what that disposition needs.
  The requirements differ because two of them have no commit to point at:

  | Disposition | The reply must carry |
  |---|---|
  | **Fixed** | the SHA of the commit that carries the fix |
  | **Mitigated** | the SHA, **and** what remains unfixed |
  | **Judged wrong** | evidence — what was checked and what it showed, not an assertion |
  | **Correct but out of scope** | where the work went: the deferred-items file, requirement, or phase that owns it |

  Demanding a commit SHA for a finding judged wrong, or for one deferred rather than changed,
  makes the rule unsatisfiable in exactly the two cases where judgement matters most — and an
  unsatisfiable rule is ignored rather than followed.
- **Resolving to unblock a merge is forbidden.** This is the specific failure that produced this
  section, not a hypothetical.
- **A pull request merges only when a completed review names the head commit it is merging.**
  Codex reviews trigger on pull-request open, on a draft being marked ready, and on an explicit
  `@codex review` comment — **not on a push.** So the commits that fix a finding are, by default,
  never reviewed by the thing that found it. Absence of new comments after a fix push is absence
  of review, not a clean result, and must never be reported as one.

  Requesting the review is not enough, and "comment `@codex review` before merging" would be a
  rule you could satisfy and merge thirty seconds later, before the review arrived — and one that
  says nothing about a commit pushed *after* it arrives. Both paths reproduce the incident intact.
  So the bar is a **completed** review whose reviewed SHA equals the current head, re-established
  after **every** subsequent push.

  This is checkable rather than a matter of trust: Codex states `**Reviewed commit:** <sha>` in
  its own review body. Compare it against `gh pr view <n> --json headRefOid`. That comparison is
  exactly how the Phase 3 gap was found, months of process discipline having failed to notice it.
- **Verify before implementing.** A reviewer asserts; it does not prove. Several findings in the
  incident below were reproducible locally in minutes, and one recommendation would have been
  wrong to follow as written.

**Why this is here.** On 2026-07-27, nine Codex comments across three pull requests were resolved
without being read, purely to satisfy the conversation-resolution gate. Two were P1 defects that
merged as a result: a Dependabot auto-merge workflow that could never run, because Dependabot's
`pull_request` runs receive a read-only token regardless of the permissions the workflow requests;
and a documentation publish script that left the `docs-pages` branch without the workflow needed to
trigger it, so Pages would have silently never deployed — the exact failure the PAT design existed
to prevent. A third finding was a defect in this repository's own release configuration, where
`bump-patch-for-minor-pre-major` downgraded `feat:` commits to patch bumps below 1.0.0 while the
commit message introducing it claimed the opposite.

**The re-review rule has its own incident.** Phase 3 (2026-07-29) went the other way and handled
every finding properly: eight findings across five pull requests, all eight real, all read, all
answered, all resolved. But every one of those pull requests merged at a commit *later* than the
one Codex had reviewed, and no re-review was requested — so the commits fixing the findings were
never seen by the reviewer that raised them. PR #28 merged nine unreviewed commits, including a
~120-line feature added in response to a finding. Worse, an executor reported "no new Codex
findings" on those commits and that was relayed as a clean result, when Codex had simply never
looked. Given the project's hit rate at that point — nineteen findings, nineteen real — the fixes
were the last thing that should have gone unreviewed.

None of them were found by CI. All of them were sitting in threads someone had already closed.

**Enforcement.** `scripts/ci/check-review-threads.sh` fails the build when a resolved review thread
has no comment from a human author — which, **on a bot-opened thread**, means no reply. That catches
silent resolution of automated review, which is the mechanical half of the failure and the half this
section exists for.

**It does not catch the same thing on a human-opened thread**, and the wording above is deliberately
narrower than it used to be. There, the reviewer's own opening comment is itself a human comment, so
the check is satisfied before anyone answers. The reply rule still binds — it is a standard, not a CI
feature — but a green `review-threads` check is not evidence that a human-started thread was
answered, and this document should not imply otherwise.

Neither case can verify that anyone *understood* what they read. Like §6a's TDD ordering, that
remainder holds because review enforces it.

**Commits:**

- **Conventional Commits on every commit**, not merely the PR title — see the conflict noted in
  §12a. Enforced by commitlint in CI.
- No `TODO`/`FIXME` reaches `main`. CI greps for them; they become issues instead. A TODO is a
  decision deferred where nobody will find it.

**Releases:**

- release-please owns versioning and `CHANGELOG.md`. Nobody edits the changelog by hand.
- `main` is always releasable.
- **Packagist is wired, not manual.** release-please cuts the tag and the GitHub release; it does
  *not* publish. The GitHub↔Packagist integration (App or webhook) is what makes a tag appear as
  an installable version. Without it, tags land and Packagist never notices — the package looks
  abandoned while `main` is green.
- The Packagist name is claimed in Phase 0, before the vendor namespace, README and docs are
  written against it. A name collision found at first release is a rename across the whole
  package; found in Phase 0 it costs nothing. The trade-off accepted here is a public `dev-main`
  with no functionality until the first tag.
- Publishing is verified end-to-end at the first tagged release: tag → release-please → Packagist
  shows the version → `composer require reyemtech/laravel-hubspot` resolves in a clean project.

### 12a. Resolved: merge commits, with commitlint mandatory

`apps/laravel` squash-merges and gates on `semantic-pr-title`, so only the PR title has to be a
conventional commit — the messy commits inside the branch never reach `main`.

Merge commits change that. release-please derives the changelog and the version bump from the
**individual commits** on `main`, so with merge commits:

- every commit must be a valid Conventional Commit, or it is silently dropped from the changelog;
- a `feat:` commit buried in a branch bumps the minor version, even if the PR was labelled a fix;
- `semantic-pr-title` alone is no longer sufficient — **commitlint on every commit becomes
  mandatory**, and contributors will hit it.

Both work. They just require different tooling, and the merge-commit path puts more friction on
the contributor.

**Signed off 2026-07-26: merge commits stay, and commitlint on every commit is therefore
mandatory** — added to the required checks in §12b. The deciding argument is §6a: the whole point
of preserving the RED→GREEN sequence is that it "survives into `main`… visible in `git log`
forever". Squash-merging deletes exactly that, which would negate a standard set deliberately
elsewhere in this document. The accepted costs are contributor friction from commitlint, and the
need to watch for a stray `feat:` inside a branch bumping the minor version.

## 12b. Repository governance

- **`main` is protected**: PR required, CI required, no direct pushes, no force-push.
  `ReyemTech/laravel` currently has **no branch protection at all** — anyone can push to master.
  A public package cannot start that way.
- Required checks: tests (full matrix), Pint, PHPStan, `pest --mutate`, architecture tests,
  `composer audit`, BC check, **commitlint**, `composer validate --strict`.

  commitlint is required, not optional — see §12a. `composer validate --strict` is required
  because an invalid `composer.json` fails at Packagist submission time, which is the worst
  moment to discover it.
- `CODEOWNERS`, plus PR and issue templates. The PR template carries the Definition of Done
  below.
- **Definition of Done** — every box ticked before review is requested:
  1. Started as a RED test
  2. Full matrix green
  3. Coverage ≥95%, MSI ≥80%
  4. Pint and PHPStan clean, no new baseline
  5. Docs and `UPGRADE.md` updated in this PR
  6. No new runtime dependency (or justified in the description)
  7. Public API changes are semver-assessed

## 12c. Dependencies and audits

- `composer audit` runs in CI and **fails the build** on any advisory.
- Dependabot weekly; patch and minor dev-dependency bumps auto-merge on green, as
  `packages/sail` already does.
- Dependencies are updated at the start of a work cycle, never mixed into a feature PR.
- The matrix (§1) is what makes aggressive updating safe: every supported PHP × Laravel
  combination is exercised on `prefer-lowest` and `prefer-stable`, so a bump that breaks an older
  supported version fails before release, not in someone's application.

## 13. Documentation

- README opens with a **60-second quickstart** — install, one model, one sync. Anything longer
  before the first working example loses the reader.
- Every public method has a usage example in the docs. Signature-only reference is not
  documentation.
- The association direction table (279 vs 280, 19 vs 20, 201 vs 202) is documented prominently.
  It is the single most common source of HubSpot integration bugs and the reason this package
  exists in its current shape.
- `CONTRIBUTING.md` states these standards and the fact that CI enforces them, so nobody
  discovers the mutation-score floor from a red build.

---

## Decisions needing sign-off

Seven decisions are recorded here. **All seven are now signed off as of 2026-07-27 — nothing in
this document is pending.** Each diverged from current ReyemTech practice or set a cost, and was
flagged rather than assumed:

0. **~~Merge commits vs release-please~~ — SIGNED OFF 2026-07-26** (§12a). Merge commits stay;
   commitlint on every commit is mandatory and is a required check in §12b. Rationale: squashing
   would delete the RED→GREEN history that §6a exists to preserve.
1. **~~PHP floor `^8.2`, not `^8.3`~~ — SIGNED OFF 2026-07-26.** `^8.2` confirmed, and the Laravel
   range widened to 11.x/12.x/13.x, after verifying support dates against laravel.com and php.net.
   Both ends of the range are EOL or near it; kept deliberately for migration reach. See §1.
   **Superseded 2026-07-27:** Laravel 11 was dropped outright — every published `11.x` release
   carries unpatchable security advisories (`PKSA-m5cs-t1y6-qpcs`, `PKSA-3r5d-mb8f-1qw9`,
   `PKSA-mdq4-51ck-6kdq`), which put the migration-reach rationale in direct conflict with §12c's
   zero-tolerance `composer audit` gate. Migration reach lost. See §1 for the current matrix.
2. **Test framework: Pest.** `apps/laravel`'s CLAUDE.md mandates PHPUnit and says to convert
   Pest to PHPUnit — that rule is app-scoped and does not carry here.

   The reason is tooling, not taste. `spatie/package-skeleton-laravel` (the template most Laravel
   packages start from) and tapp both ship Pest + `pest-plugin-arch`. More decisively, two of the
   standards in this document are first-class Pest features and third-party bolt-ons under
   PHPUnit: mutation testing (`pest --mutate`, which runs only the tests covering each mutation)
   and architecture tests (`pest-plugin-arch`). PHPUnit would mean Infection plus deptrac/phpat —
   four tools for what Pest does in one runner.

   Nothing is lost: Pest runs on PHPUnit, so PHPUnit-style test classes work unmodified inside a
   Pest project. Costs: Pest's mutation engine is younger and less configurable than Infection,
   and `--mutate` wants precise `covers()` annotations.

   **Hazard:** an agent working in this workspace will read `apps/laravel`'s CLAUDE.md and try to
   convert the suite to PHPUnit. The package ships its own `CLAUDE.md` stating Pest is
   deliberate.
3. **~~`declare(strict_types=1)` everywhere~~ — SIGNED OFF 2026-07-26.** Required, enforced by an
   architecture test. New to both repos. Justified in §4.
4. **~~Coverage 95% / MSI 80%~~ — SIGNED OFF 2026-07-26.** Confirmed as written. These are real
   floors that will occasionally block a merge; that is the point.
5. **~~`final` by default~~ — SIGNED OFF 2026-07-27.** Confirmed as written in §8: every class is
   `final` unless extension is an explicit, documented feature. Extension happens through the layer
   interfaces, rebound in the container — the escape hatch the layer design already provides.
   The deciding argument is asymmetry: shipping unsealed and sealing later breaks everyone who
   subclassed, while shipping sealed and unsealing later is a patch nobody notices. Accepted cost:
   a consumer who wants to change one method must implement an interface rather than override, and
   test doubles must target the interface.
6. **~~Function hard limit at 150 lines~~ — SIGNED OFF 2026-07-26** (§6b). Confirmed at 500 file /
   150 function / 10 complexity, with review targets of 300 / 40 / 5. With everything else in this
   document a 150-line function should never survive review — the *review target* is the number
   that will actually operate.

---

## Not standards, deliberately

Rejected so nobody re-proposes them in month three:

- **Commit signing.** Real security value, real onboarding friction for outside contributors.
  Revisit if the package gains maintainers beyond ReyemTech.
- **100% coverage.** The last 5% is `__toString()` and unreachable defensive branches. 95% plus
  an 80% mutation score is a genuinely higher bar than 100% coverage with weak assertions.
- **Rector in CI.** Excellent for one-off upgrades, noisy as a gate. Run it deliberately at
  version bumps.
- ~~**A `docs/` site.**~~ **Adopted 2026-07-26.** This rejection was explicitly conditional —
  "README plus inline examples *until there is enough surface to justify one*". Adopting intent
  signals, attribution, a `Frontend` layer and a public `identify()` API is that condition firing,
  not a reversal of the reasoning. Astro + Starlight in `site/`, published to a `docs-pages`
  branch, following the pattern proven in `ReyemTech/apps/stint`.
