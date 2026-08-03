# AGENTS.md — reyemtech/laravel-hubspot

Instructions for any coding agent working in this repository. `CLAUDE.md` is the same contract
written for one particular tool; **the two must not diverge.** If you change a rule here, change it
there in the same commit.

Read in this order: `BRIEF.md` → `docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md` →
`STANDARDS.md`.

`STANDARDS.md` is **binding**. It is not advisory and it is not a starting point for negotiation.

---

## This is a standalone public package

It lives inside a ReyemTech workspace but is **not** part of the Laravel application beside it.
Rules that apply to `../../apps/laravel` do not apply here.

Three consequences that catch agents out:

- **Pest is deliberate. Do not convert anything to PHPUnit.** `apps/laravel`'s instructions say to
  convert Pest tests on sight; that rule is app-scoped, and an agent that reads it will try. Pest
  is locked (D-08): `pest --mutate` and `pest-plugin-arch` provide the mutation floor and the
  layer-boundary tests as first-class features, which under PHPUnit would need Infection plus
  deptrac. Pest runs on PHPUnit, so PHPUnit-style test classes are fine if you prefer them.
- **No Sail, no Docker.** Run `vendor/bin/pest`, `vendor/bin/pint`, `vendor/bin/phpstan` directly.
  The CI matrix is exercised through `orchestra/testbench`.
- **Target `STANDARDS.md` §1's support matrix**, not the workspace's versions.

---

## Rules specific to this codebase

- **`Gateway` is the only layer that may name `HubSpot\*` classes.** Importing an SDK class in
  `Sync`, `Registry` or `Webhooks` means the design is being violated — add the capability to
  `Gateway` instead. Architecture tests fail the build anyway.
- **Never write an association without an explicit direction.** `(from, to)` is the primitive. A
  signature that lets a caller pass two objects without an order is a wrong signature.
- **Never fall back to the inverse typeId on a registry miss.** Throw. A silent fallback writes the
  wrong direction and nobody notices for months.
- **Never hand-roll HMAC.** Signature verification delegates to `HubSpot\Utils\Signature::isValid()`.
- **Never use `$request->fullUrl()` for signature verification.** Symfony sorts query params;
  HubSpot signs the raw URI.
- **Never add a PHPStan baseline.** Fix it, or suppress per-line with a written reason.
- **Never let a raw SDK exception reach userland.** Wrap it in the package's hierarchy.
- **Never run tests against a real HubSpot portal** in the default suite. Integration tests are a
  separate, opt-in, secret-gated suite and are never required to merge.
- **Third-party runtime dependencies need justification** in the PR description, and the reviewer's
  default answer is still no. Any `illuminate/*` component may be declared as a production require.
  The vendor allow-list CI gate (`tests/Ci/ComposerManifestTest.php`) is authoritative on what is
  admitted: `php`, `hubspot/api-client`, any `illuminate/*`, and `laravel/prompts` by enumerated
  exception. Do not treat the current `require` count as the rule — it drifts.

---

## Working method

- **TDD is the method, not a preference.** Write the failing test, watch it fail, commit it, then
  implement. The RED commit precedes the GREEN commit in history.
- **Verify every new test actually fails against the unfixed code.** Tests that pass for the wrong
  reason prove nothing, and this repository has produced several.
- **Feature branch from a freshly pulled `main`. Never branch from a branch.** Update a stale branch
  by rebasing onto `main` with `--force-with-lease`.
- **Conventional Commits on every commit**, not just the PR title.
- **Commit header and body lines cap at 100 CHARACTERS.** commitlint runs in CI only, so a violation
  is found after push — and rewriting history to fix it throws away the review attached to those
  commits. Count characters, not bytes; `awk` counts bytes and lets em dashes through.
- Before finalising: `vendor/bin/pint`, `vendor/bin/phpstan analyse`, `vendor/bin/phpcs`,
  `vendor/bin/pest --coverage --min=100`, and the architecture tests. All green, no exceptions, no
  disabled gates.
- **Run mutation once per plan**, scoped, not per commit:
  `vendor/bin/pest --mutate --parallel --min=80 --class="$(bash scripts/ci/mutation-scope.sh origin/main)"`.
  Unscoped it takes ~15 minutes and effectively never fails. A scoped MSI is not comparable to a
  whole-tree MSI — say which one you are quoting.
- **Local green is not evidence.** Phase 1 shipped four gate failures that passed locally and failed
  in CI, none reachable without pushing. Push, watch the real checks, report what GitHub says.

---

## Review

Automated review is review. `codex review --base main` before every push — same reviewer as the
GitHub bot, run locally. Its *findings* are reliable; its *test runs* are not (read-only sandbox).

**Policy (owner decision, 2026-08-02):** a **P1 blocks the merge**. A **P2/P3 gets a written reply
plus either a cheap fix or a follow-up issue.** Cap the loop at roughly three rounds — review
reviews new code, so fixes beget findings.

- **Never resolve a thread you have not read**, and **every resolved thread gets a written reply,
  including the ones you fixed.** A fix is not a reply; otherwise the thread records only that
  somebody clicked resolve. Say which disposition applies and carry what it needs: **fixed** → the
  commit SHA; **mitigated** → SHA plus what remains; **judged wrong** → evidence, what you checked
  and what it showed; **out of scope** → where the work went. The last two have no SHA; do not
  invent one.
- **Verify a finding's premise before accepting it.** Findings have been confidently wrong here —
  one claimed a constructor was released public API when the class was in no tag at all. Check, then
  fix or rebut with evidence.
- **Reply via file**, never inline: backticks break quoting.
  `gh api repos/ReyemTech/laravel-hubspot/pulls/<n>/comments/<id>/replies -F body=@reply.md`
- **Merge only when a completed review names the commit you are merging.** Codex reviews trigger on
  PR open, on draft-ready, and on an `@codex review` comment — **never on a push**. So the commits
  that fix a finding are by default never reviewed by the thing that found it, and "no new comments
  appeared" means nobody looked. Compare Codex's `**Reviewed commit:** <sha>` against
  `gh pr view <n> --json headRefOid`.
- **A clean verdict naming your head does not mean a clean PR.** The verdict and the inline findings
  arrive on *different endpoints* and can come from *different rounds*. A "no major issues" comment
  on your head can coexist with an unread P2 from an earlier round. Check
  `/pulls/<n>/comments` and the unresolved-thread list too, not just the verdict. This nearly
  shipped an unread P2 on PR #65.
- A clean verdict is posted as an **issue comment**, not a review — poll `/issues/<n>/comments` as
  well as `/pulls/<n>/reviews`.
- **Documentation and planning commits do not need a fresh review naming head.** Prove it, don't
  assume it:
  `git diff --no-renames --name-only <reviewed-sha>..HEAD | grep -vE '^(\.planning/|docs/|[^/]+\.md$)'`
  must print nothing. Changes under `config/`, `database/`, `resources/`, `scripts/` and `tests/`
  require a review of the head. A changed string literal is source.

---

## Habits that have repeatedly paid for themselves

- **Sweep for the CLASS of a defect, not the reported instance.** Two review rounds on PR #56 went
  on documentation that contradicted code already fixed. PR #65's scope bug had two siblings.
- **Consolidating a lifecycle keeps the WEAKER behaviour unless you check.** PR #65 merged two
  withdrawal paths — one wrote unscoped deliberately, one did not — and inherited the scoped one.
  One owner only pays if the survivor is the stronger half. Ask this of every consolidation.
- **Test whether a file existed at a release with `git ls-tree -r --name-only <tag> -- <path>`.**
  `git show TAG:path` prints the tagged commit, and `… | grep … || echo absent` fires on grep's exit
  code. Both have given false answers here.
- **A promoted constructor parameter's default is not a property default.** It does not survive
  `newInstanceWithoutConstructor()`, which is how Laravel restores a queued job.

---

## GSD planning state

`.planning/` is the source of truth for what is done. `.planning/STATE.md` frontmatter and
`.planning/ROADMAP.md` checkboxes must agree with the `*-SUMMARY.md` files on disk — a plan with a
SUMMARY is complete. **Update STATE.md when you finish a plan**; it drifted three plans behind once
and would have sent the next agent to redo finished work.

---

## When the spec is silent

Ask rather than invent. Several decisions in `STANDARDS.md` are explicitly unsigned, and the
association-inverse question in design spec §6.4 was an empirical probe with a defined procedure —
run the probe, do not guess.

If you find a genuine flaw in the design, say so and stop. Do not route around it silently.
