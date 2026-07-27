# Docs site deploy procedure — SHIP-04 (Phase 9)

**Status: shipped, but inert until the owner acts.** The three files this document originally
recorded as a ready-to-run-but-not-yet-built procedure now exist:
`.github/workflows/deploy-docs.yml`, `.github/workflows/deploy-pages.yml`, and
`scripts/release/publish-docs.sh` — built directly from `ReyemTech/apps/stint`'s working
reference implementation while it was being read for Plan 06, and shipped ahead of the rest of
Phase 9 with explicit owner approval ("we can prepare it now .. we're going to open up the repo
later"). Everything below is now the as-built record, not a plan.

Two things are still blocked on the repository owner (D-47), not on anything technical:

1. **GitHub Pages needs a paid plan on private repositories.** `ReyemTech/laravel-hubspot` is
   private. Unblocked by either upgrading the plan or making the repository public.
2. **A `RELEASE_TOKEN` personal access token must exist as a repository secret** before the first
   real deploy (see below for why). Unblocked by the owner creating one and adding it at
   `Settings -> Secrets and variables -> Actions`.

Both blockers are independent of each other and of everything else in this document — the
workflows below can be written, committed, and will sit correctly inert until both are cleared.

## The two-workflow split

Mirrors the `stint` reference exactly: one workflow builds and publishes the built site to a
branch; a second, separate workflow deploys from that branch to Pages. They are split because
GitHub Pages deploys from a branch, not from an arbitrary workflow run, and because path-filtering
the first workflow to `site/**` would be meaningless if it also tried to own the Pages deploy step
(which must trigger on push to the publishing branch, not on push to `main`).

```
push to main, path-filtered on site/**          push to docs-pages
        |                                               |
        v                                               v
 deploy-docs.yml (build-and-publish)          deploy-pages.yml (deploy)
   - pnpm build (site/)                          - actions/configure-pages
   - push site/dist/ -> docs-pages branch        - actions/upload-pages-artifact
     (PAT, not GITHUB_TOKEN — see below)          - actions/deploy-pages
```

- `deploy-docs.yml` triggers on `push: branches: [main], paths: ["site/**", ...]`, plus
  `workflow_dispatch` for a manual re-run.
- `deploy-pages.yml` triggers on `push: branches: [docs-pages]` only, and needs
  `permissions: pages: write, id-token: write` (in addition to `contents: read`) — the Pages
  deployment API requires the OIDC token, not just a write token.
- Each carries its own `concurrency` group (`docs-pages` for the first, `pages` for the second,
  both `cancel-in-progress: true`) so two rebuilds triggered close together don't race each other
  pushing to the same branch or deploying stale output.

## Why the push uses a PAT (`RELEASE_TOKEN`), not `GITHUB_TOKEN`

This is the detail most likely to be silently rediscovered wrong, because the failure mode
produces no error anywhere:

**GitHub Actions deliberately suppresses workflow-trigger events for commits authored by the
default `GITHUB_TOKEN`**, specifically to prevent workflow-triggers-workflow infinite loops. If
`deploy-docs.yml` pushes to `docs-pages` using `secrets.GITHUB_TOKEN`, that push succeeds — the
commit lands on the branch, the job reports green — but the `push: branches: [docs-pages]` trigger
on `deploy-pages.yml` **never fires**, because the commit's author is `GITHUB_TOKEN` and GitHub
does not count it as a new workflow-triggering event (except for `workflow_dispatch` and
`repository_dispatch`, neither of which apply here).

The symptom: a green build-and-publish job, a real commit on `docs-pages`, and a documentation
site that never updates. Nothing reports this as a failure, because nothing failed — the Pages
deploy simply never ran. This is why `RELEASE_TOKEN` (a personal access token, added as a
repository secret) has to be the credential the push authenticates with:

```yaml
env:
  # Push with RELEASE_TOKEN so the commit is PAT-authored, not GITHUB_TOKEN-authored — a
  # GITHUB_TOKEN-authored push to docs-pages would succeed silently while deploy-pages.yml's
  # push-triggered workflow never fires. Falls back to GITHUB_TOKEN only so the step doesn't
  # hard-fail before the secret exists; that fallback path deploys nothing until an owner
  # manually triggers deploy-pages.yml (`gh workflow run deploy-pages.yml --ref docs-pages`).
  GITHUB_TOKEN: ${{ secrets.RELEASE_TOKEN || secrets.GITHUB_TOKEN }}
run: scripts/release/publish-docs.sh
```

**Action for Phase 9:** confirm `RELEASE_TOKEN` exists as a repository secret before relying on
this workflow to deploy anything. If it is missing, the workflow still runs and still reports
green — it just falls back to the token that cannot trigger the next stage. A green
`deploy-docs.yml` run is not evidence the site was actually published; checking `docs-pages`'s own
commit history and `deploy-pages.yml`'s run history is.

## The `docs-pages` branch preserve-list

The publish script does not replace the `docs-pages` branch wholesale. It clones the branch,
**stashes a fixed list of paths**, wipes everything else, copies the fresh build output on top,
then restores the stashed paths — because the branch carries content the build output does not
produce and must not lose.

**`.github/` is the one entry every project on this pattern must keep.** The `docs-pages` branch
carries its own `.github/workflows/deploy-pages.yml` — a separate workflow file living on that
branch, not on `main` — and that file is what actually performs the Pages deployment when pushed
to. If the publish script wipes `.github/` along with everything else, the push to `docs-pages`
still succeeds (git doesn't care that a workflow file vanished), but the deploy trigger is gone
with it. Same failure shape as the PAT issue above: a green publish job, a real commit, and a site
that silently stops updating — except this one is worse, because it isn't fixed by rotating a
token; it requires re-adding the workflow file to the branch by hand.

`stint`'s preserve-list additionally carries `install.sh`, `install.sh.sha256` and `CNAME` —
a curl-installer script and its checksum, and a custom-domain record. **Neither applies to this
package.** This package has no install script to preserve, and (per the astro.config.mjs `site`/
`base` values chosen in Plan 06) no custom domain is configured — the site is served at the
default GitHub Pages project-page URL (`https://reyemtech.github.io/laravel-hubspot/`), so there
is no `CNAME` to protect either. This package's preserve-list is therefore expected to be just:

```bash
readonly -a PRESERVE=(
  ".github"
)
```

If a custom domain is added later, `CNAME` must be added to this list at the same time, or the
first subsequent publish will silently drop it and the custom domain will stop resolving to Pages
content until someone notices and re-adds the file.

## Reference commands (Phase 9, once both blockers clear)

```bash
# One-time: create the docs-pages branch if it doesn't exist yet (an orphan branch, no shared
# history with main — the build output is the only content it carries, plus .github/).
git checkout --orphan docs-pages
git rm -rf .
git commit --allow-empty -m "chore(docs): initialize docs-pages branch"
git push origin docs-pages

# Manually re-run the Pages deploy if the PAT fallback path ran instead of RELEASE_TOKEN:
gh workflow run deploy-pages.yml --ref docs-pages
```

## Files this procedure created (owner-gated setup, ahead of Phase 9)

- `.github/workflows/deploy-docs.yml` — build-and-publish, `push: main` path-filtered on
  `site/**`, `permissions: contents: write` (needed to push to `docs-pages`).
- `.github/workflows/deploy-pages.yml` — the Pages deploy itself, `push: docs-pages`,
  `permissions: contents: read, pages: write, id-token: write`, gated behind a
  `check-pages-enabled` job that skips the deploy cleanly (a loud warning, not a red X) until
  GitHub Pages is actually enabled. This file must also exist **on the `docs-pages` branch
  itself**, not only on `main` — GitHub reads workflow files from the ref being pushed to for
  branch-triggered workflows; `publish-docs.sh`'s preserve-list keeps it there across every
  publish.
- `scripts/release/publish-docs.sh` — the preserve-list-aware publish script, adapted from
  `apps/stint`'s script with this package's own (shorter) preserve-list (`.github` only — no
  `install.sh`/`CNAME`). Guards, loudly rather than silently, against each of the three
  not-yet-ready conditions in order: missing build output, a not-yet-bootstrapped `docs-pages`
  branch, and a missing `RELEASE_TOKEN` secret (falls back to `GITHUB_TOKEN`).

Neither workflow triggers on `pull_request`, so neither needed an entry in
`tests/Ci/RequiredChecksTest.php`'s required-checks comparison or its explicit exclusion
allowlist — confirmed via `vendor/bin/pest tests/Ci/` before and after these files were added.

These files ship a workflow that has never once run end-to-end — on a private repository with
GitHub Pages not yet enabled and the `RELEASE_TOKEN` secret not yet created, that is expected, not
a defect. Every not-yet-ready condition is guarded to skip cleanly rather than fail noisily (see
each workflow file's own comments and `publish-docs.sh`'s guards); the two remaining owner actions
are recorded in `docs/repo/owner-gated-checklist.md`'s "GitHub Pages deploy" section.
