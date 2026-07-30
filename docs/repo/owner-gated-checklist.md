# Owner-Gated Handoff Register

Everything in this document requires action only the repository owner (`@mariomeyer`,
`.github/CODEOWNERS`) can take — a GitHub settings page, a mailbox, a paid plan, or a credential
this executing agent does not hold. Phase 1 ships every gate these items protect; it cannot flip
the switches itself.

`tests/Ci/RequiredChecksTest.php` machine-checks the "Required status checks" list below against
the real job ids shipped in `.github/workflows/*.yml`, in both directions: a job shipped and not
listed here fails the test, and a name listed here that resolves to no real job also fails it.
That test is what stops a gate being built in a later phase and never made required — which is how
required checks quietly stop mattering.

## Branch protection on `main`

`ReyemTech/laravel-hubspot`'s `main` currently has **no branch protection at all**
(STANDARDS §12b). This must be configured in GitHub's repository settings
(**Settings → Branches → Branch protection rules**) by the owner:

- Require a pull request before merging. No direct pushes.
- Require status checks to pass before merging — the exact list below.
- Require branches to be up to date before merging.
- No force-push, no branch deletion, for anyone (including admins, if the plan is enforced
  consistently).
- **Merge commits, not squash** (D-25) — configure the repository's "Allow merge commits" setting
  as the only permitted merge strategy and disable "Allow squash merging". Squash-merging would
  delete the RED→GREEN commit history §6a exists to preserve.
- Protection rules may be limited by plan on a private repository — some options above (e.g.
  requiring a minimum number of approving reviews from a CODEOWNERS-derived list) may not be
  available on every GitHub plan tier. Configure whatever the plan allows; note in the PR template
  which parts are actually enforced versus merely requested.

### Required status checks

Every pull-request-triggered job shipped by this phase, grouped by the workflow file that ships
it. This list is exhaustive and machine-checked — `tests/Ci/RequiredChecksTest.php` fails if a job
here no longer exists, or if a job exists and is missing from here.

**`.github/workflows/ci.yml`** — the full support-matrix test run, `composer validate --strict`,
and the manifest-shape lock:

- `tests` — the 12-job PHP × Laravel × stability matrix (STANDARDS §1, Laravel 11 dropped
  2026-07-27), coverage floor `--min=95`
- `composer-validate` — `composer validate --strict`
- `manifest` — locks the manifest to a vendor allow-list (the exact keys "php" and
  "hubspot/api-client", every "illuminate/*" package by prefix, and "laravel/prompts" via its own
  enumerated exception), not a fixed count (`tests/Ci/ComposerManifestTest.php`, superseded
  2026-07-30 by D-03/D-19)

**`.github/workflows/arch.yml`** — the six-layer architecture boundary, proven live:

- `architecture-tests` — `vendor/bin/pest tests/Arch`
- `arch-rules-fire` — proves each of the ten rules fails a build under its own violation fixture

**`.github/workflows/quality.yml`** — static analysis, style, code shape, hygiene, mutation:

- `phpstan` — level max (resolves to 10), no baseline
- `pint` — the laravel preset, `pint --test`
- `code-shape` — 500 lines/file, 150 lines/function, cyclomatic complexity 10 (PHPCS + Slevomat)
- `source-hygiene` — rejects deferred-work markers anywhere in the tracked tree
- `quality-gates-fire` — proves PHPStan and the code-shape gate each reject a real violation
- `mutation` — `pest --mutate --min=80`

**`.github/workflows/governance.yml`** — repository governance and commit hygiene:

- `governance` — the security policy, Dependabot config, CODEOWNERS and PR template content checks
- `commitlint` — lints every commit in the PR (D-25/D-26), not only the head commit or PR title

**`.github/workflows/review-threads.yml`** — STANDARDS §12, "Automated review is review":

- `review-threads` — fails when a resolved review thread has no reply from a human author
  (`scripts/ci/check-review-threads.sh`). Its own workflow file, not governance.yml, purely so its
  *permissions*/*env* (for the `gh api graphql` call the script makes) do not spread onto
  governance.yml's other jobs, which need neither.

  **Known gap, accepted rather than closed (PR #10, discussion_r3657716045).** This check runs on
  *pull_request*'s default activity types (opened, synchronize, reopened) only. A review thread
  resolved via the GitHub UI with no accompanying commit does not re-trigger it, so a stale
  "success" from the previous push stands even after a finding is silently resolved — branch
  protection would allow the merge regardless. An attempt to fix this by also triggering on
  *pull_request_review_thread* (resolved/unresolved) was made and reverted: that event is real as
  a webhook/GitHub-App delivery but is **not a valid GitHub Actions "on:" trigger**, confirmed
  empirically against this repository (a minimal workflow file declaring only that trigger was
  pushed to a throwaway branch and GitHub rejected it outright as an invalid workflow file; a
  control file using the genuinely valid *pull_request_review* trigger was accepted). Natively
  closing this gap would need a webhook receiver (a GitHub App or a small external service)
  subscribed to that event, entirely outside GitHub Actions — materially more infrastructure than
  this phase's scope. **Owner decision needed:** accept this gap (branch protection's
  conversation-resolution requirement still blocks an *unresolved* thread from merging; this only
  degrades to "checked at last push" for threads resolved afterward), or scope a webhook-based
  follow-up.

**`.github/workflows/js.yml`** — the frontend coverage floor:

- `coverage` — Vitest, 95% floor on lines/functions/branches/statements, `resources/js/` only

**`.github/workflows/docs.yml`** — the documentation site build:

- `build` — Astro/Starlight build, `site/` only, asserts `site/dist/index.html` exists

**`.github/workflows/supply-chain.yml`** — dependency and backward-compatibility gates:

- `composer-audit` — fails the build on any advisory (D-31)
- `bc-check` — `roave/backward-compatibility-check`, single PHP-8.4 job, skips with a loud
  message (never silently) only when no git tag exists yet

That is 18 required status checks in total, by job id. `.github/workflows/release-please.yml`'s
own job is deliberately **not** in this list — it triggers on push to the default branch only,
never on a pull request, so it is not a check a contributor's PR needs to pass.

**Job-id count vs. the real branch-protection list.** The 18 above counts distinct job *ids*, the
unit this document and `tests/Ci/RequiredChecksTest.php` both track. GitHub's actual "Required
status checks" setting is keyed on each job's rendered **check name**, and `tests`
(`.github/workflows/ci.yml`) is a 3×2×2 matrix (PHP 8.3/8.4/8.5 × Laravel 12/13 ×
prefer-lowest/prefer-stable) that GitHub lists as 12 separate named checks, not one — so the real
required-checks list the owner configures has **29** entries (18 − 1 for the collapsed `tests` row
+ 12 matrix cells), not 18. Whichever number is live in **Settings → Branches** at any given time,
re-derive it as "job ids in this section, with `tests` expanded to its current matrix cell count"
rather than trusting a number written down here — the matrix shape itself can change (STANDARDS
§1 already dropped Laravel 11 mid-project).

**Re-apply branch protection whenever this list changes.** GitHub's required-status-check list is
a snapshot the owner configures by hand at **Settings → Branches → Branch protection rules**; it
does not update itself when a new job is added here or removed. `review-threads` above is new as
of this document's revision — until the owner adds it to that setting, main can merge a PR with
a silently-resolved review thread, exactly the failure STANDARDS §12 exists to prevent, without
any red X appearing. A required check that exists in CI but not in that setting is not required at
all; it is only advisory. Treat every diff to this section as a reminder to open that settings page
and reconcile it, not as documentation that stays true on its own.

## Dependabot auto-merge

`.github/dependabot.yml` (plan 02) groups patch/minor dev-dependency bumps specifically so
auto-merge-on-green is possible, matching `packages/sail`'s existing convention.
`.github/workflows/dependabot-auto-merge.yml` now ships the workflow half of this: it reads each
Dependabot PR's update type via `dependabot/fetch-metadata` and calls `gh pr merge --auto --merge`
only for `version-update:semver-patch`/`semver-minor` bumps to `direct:development` dependencies —
never production, never major, matching STANDARDS §12c exactly. It is deliberately excluded from
`tests/Ci/RequiredChecksTest.php`'s required-checks comparison (see that test's
`requiredChecksAllowlistedWorkflowFiles()`), since it is PR-triggered automation gated on
`github.actor == 'dependabot[bot]'`, not a status check every contributor's own PR needs to pass.

**One repository setting still needs the owner:** `gh pr merge --auto` only *queues* the merge —
it requires **Settings → General → Pull Requests → Allow auto-merge** to be enabled on the
repository, or the command fails outright even when the update-type/dependency-type gate above
passes. Not yet enabled.

## Packagist registration and publishing (REL-02, Phase 9)

`release-please.yml` (this plan) cuts the tag and the GitHub release. **It does not publish
anywhere else.** Two things block going further, and both are true simultaneously:

1. **The owner has explicitly deferred publishing** until they review the package themselves.
2. **Packagist requires a public repository**, and `reyemtech/laravel-hubspot` is currently
   private. Registration would fail even if attempted today.

What Phase 9 (SHIP, REL-02) needs from the owner once both blockers clear:

- Make the repository public.
- Claim `reyemtech/laravel-hubspot` on Packagist (ideally already reserved earlier per
  STANDARDS §12 — "the Packagist name is claimed in Phase 0, before the vendor namespace, README
  and docs are written against it" — confirm this actually happened; if not, do it now, since a
  name collision found at first release is a rename across the whole package).
- Install the GitHub↔Packagist integration (the Packagist GitHub App, or a manually configured
  webhook) on the repository. **Without this integration, tags land on GitHub and Packagist never
  notices** — the package looks abandoned while `main` stays green. This is the single most
  important item in this section: release-please alone is not enough.
- Verify end-to-end at the first tagged release, per STANDARDS §12: tag → release-please →
  Packagist shows the version → `composer require reyemtech/laravel-hubspot` resolves in a clean
  project.

## GitHub Pages deploy

The documentation site (`site/`, plan 06) builds green in CI (`docs.yml`'s `build` job).
`.github/workflows/deploy-docs.yml` and `.github/workflows/deploy-pages.yml` now ship the deploy
procedure itself (the two-workflow split, the PAT-vs-`GITHUB_TOKEN` rationale, and the
`docs-pages` branch preserve-list mechanism — all recorded in **`docs/repo/docs-site-deploy.md`**,
plan 06), shipped ahead of the rest of Phase 9 with explicit owner approval to prepare it now.
Both workflows are guarded to stay inert (a loud warning, not a red X) until the two things below
are true. Nothing further needs deciding — only these two owner actions remain:

1. **Create the `RELEASE_TOKEN` repository secret** — a personal access token added at
   **Settings → Secrets and variables → Actions**. It needs **two** permissions, not one:
   - push access to this repository, and
   - the **`workflow` scope** (classic PAT) or **Workflows: write** (fine-grained PAT).

   The second is easy to miss and is not optional. `publish-docs.sh` copies
   `.github/workflows/deploy-pages.yml` onto the `docs-pages` branch — without it that branch has
   no workflow to trigger and Pages never deploys — and **GitHub rejects any push that adds or
   updates a file under `.github/workflows/` when the token lacks workflow permission.** A PAT with
   push access alone makes both publish attempts fail at `git push`. Without the secret entirely,
   `deploy-docs.yml` still runs and still succeeds (it falls back to `GITHUB_TOKEN`), but
   `deploy-pages.yml` never fires automatically from that push — see
   `docs/repo/docs-site-deploy.md` for why.
2. **Enable GitHub Pages** at **Settings → Pages**, source set to **GitHub Actions** — not
   "Deploy from a branch". `deploy-pages.yml` deploys via `actions/configure-pages`,
   `actions/upload-pages-artifact` and `actions/deploy-pages`, which requires the Actions
   publishing source; per GitHub's docs, using a custom Actions workflow to publish "requires you
   to first enable them for your current repository" via that source setting. Selecting "Deploy
   from a branch" configures the legacy Jekyll-or-static branch-based build instead, which
   `actions/deploy-pages` cannot drive — the custom workflow would not operate as designed. The
   `docs-pages` branch stays relevant only as `deploy-pages.yml`'s own `push` trigger (see
   `docs/repo/docs-site-deploy.md`); it is not the Pages source setting. The organization is on the
   **Team** plan, so Pages *is* available from a private repository (the free tier is the one that
   blocks this, not Team) — but the plan does not make the published site private: **once Pages is
   enabled, the built documentation site is publicly visible at its default URL even while the
   repository itself stays private.** Worth the owner seeing that stated plainly before flipping
   the switch, not discovering it after.

The `docs-pages` branch itself has not been bootstrapped yet either (the one-time orphan-branch
creation in `docs/repo/docs-site-deploy.md`'s "Reference commands" section) — `deploy-docs.yml`
also skips cleanly, not noisily, until that exists.

## Confirm the `security@reyem.tech` mailbox

`SECURITY.md` (plan 02) publishes `security@reyem.tech` as the private vulnerability-disclosure
address, and commits to a 48-hour patch turnaround for confirmed vulnerabilities. **This has not
been independently confirmed to be a real, monitored mailbox** — plan 02 flagged this explicitly.
An address nobody reads is worse than publishing none, since a reporter would reasonably believe
their report was received. Confirm before the first public release.

## FOUND-03: the association-inverse probe

**DONE — no longer owner-gated.** Run on 2026-07-27 against a developer test account, with a
Service Key supplied by the owner. **The inverse IS automatic, and carries its own distinct
`typeId`** (`3 → 4` unlabelled, `1 → 2` for a user-defined paired label). `inverse_type_id`
therefore stays read/verification-only exactly as design spec §6.2 specifies.

Full results, raw response bodies and three incidental findings are recorded in
**`docs/probes/association-inverse-probe.md`**. The script
(**`scripts/probes/association-inverse-probe.sh`**) remains runnable for re-verification.

Nothing is required of the owner here any more. Two things worth knowing:

- The Service Key used for the run should be revoked if it has not been already — it existed to
  answer one question.
- Re-running is a verification exercise, not an unblocking one, and needs a developer test account,
  a Service Key with five CRM scopes, and a user-defined `deals → contacts` label. See the probe
  doc's *Re-running it* section.
