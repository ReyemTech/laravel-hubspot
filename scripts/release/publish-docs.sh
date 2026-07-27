#!/usr/bin/env bash
#
# scripts/release/publish-docs.sh
#
# Push the built Starlight site at site/dist/ to the docs-pages branch, preserving
# .github/ (the deploy-pages.yml workflow that actually lives on that branch --
# see docs/repo/docs-site-deploy.md for why wiping it silently kills the Pages
# deploy trigger). Adapted from ReyemTech/apps/stint's publish-docs.sh; this
# package's preserve-list is shorter -- no install.sh/CNAME, since this repository
# has no curl-installer script and no custom domain configured (site/astro.config.mjs
# serves the default GitHub Pages project-page URL).
#
# GITHUB_TOKEN is expected to be RELEASE_TOKEN (a PAT) when the caller has it, falling
# back to the default GITHUB_TOKEN otherwise -- see the calling workflow
# (.github/workflows/deploy-docs.yml) for why, and the loud warning this script emits
# below when the fallback is in effect.

set -euo pipefail

readonly SITE_DIST="${SITE_DIST:-site/dist}"
readonly REPO="ReyemTech/laravel-hubspot"

# Captured before anything below ever changes directory. push_attempt() cannot rely on
# $OLDPWD to find its way back to the checkout that has site/dist -- $OLDPWD is a
# process-wide shell variable, not scoped to a function or a single `cd`, so it gets
# clobbered by the *next* `cd` (the retry's clone), not restored when a function
# returns. SRC_DIR is a value captured once, never reassigned, and immune to that.
readonly SRC_DIR="$PWD"

[[ -d "$SITE_DIST" ]] || { echo "error: $SITE_DIST not found -- run \`pnpm --filter './site' build\` first" >&2; exit 1; }
[[ -f "$SITE_DIST/index.html" ]] || { echo "error: $SITE_DIST/index.html missing -- build incomplete" >&2; exit 1; }
[[ -n "${GITHUB_TOKEN:-}" ]] || { echo "error: GITHUB_TOKEN required" >&2; exit 1; }

# The workflow file that must exist on docs-pages for the Pages deploy to ever trigger.
# Copied fresh from this checkout on every publish (see push_attempt() below) rather than
# merely preserved from whatever docs-pages already carries: the documented bootstrap
# (docs/repo/docs-site-deploy.md's "Reference commands") creates docs-pages as a genuinely
# empty orphan branch, so on the first-ever publish there is nothing to preserve and a
# preserve-only strategy would leave the branch permanently without this file. Copying it
# from main unconditionally also means the docs-pages copy never drifts if this file
# changes later.
readonly DEPLOY_PAGES_WORKFLOW=".github/workflows/deploy-pages.yml"

[[ -f "$SRC_DIR/$DEPLOY_PAGES_WORKFLOW" ]] || {
    echo "error: $DEPLOY_PAGES_WORKFLOW not found in this checkout -- cannot publish without it" >&2
    exit 1
}

# The docs-pages branch is bootstrapped as a one-time manual step once both owner-gated
# blockers clear (see docs/repo/docs-site-deploy.md's "Reference commands" section); it is
# expected not to exist yet. Failing to clone a branch that was never created is not a build
# problem, so exit cleanly with an explanatory message rather than a red X -- same principle
# as the RELEASE_TOKEN fallback above, applied to this third not-yet-ready precondition.
if ! git ls-remote --exit-code --heads "https://x-access-token:${GITHUB_TOKEN}@github.com/${REPO}.git" docs-pages > /dev/null 2>&1; then
    echo "::warning::The docs-pages branch does not exist yet -- nothing to publish to." >&2
    echo "::warning::Bootstrap it once RELEASE_TOKEN exists and Pages is enabled (see docs/repo/docs-site-deploy.md's \"Reference commands\" section: git checkout --orphan docs-pages && git push origin docs-pages)." >&2
    exit 0
fi

# Loud, not silent: a GITHUB_TOKEN-authored push to docs-pages succeeds and reports
# green, but GitHub Actions suppresses workflow-trigger events for commits authored by
# GITHUB_TOKEN, so deploy-pages.yml's `push: branches: [docs-pages]` trigger never
# fires. Nothing here fails because of this -- the fallback exists precisely so the
# step doesn't hard-fail before RELEASE_TOKEN exists -- but the owner needs to know the
# downstream deploy did not run automatically.
if [[ "${RELEASE_TOKEN_SET:-false}" != "true" ]]; then
    echo "::warning::RELEASE_TOKEN secret is not configured -- pushing with GITHUB_TOKEN instead." >&2
    echo "::warning::GitHub Actions suppresses workflow triggers for GITHUB_TOKEN-authored commits, so deploy-pages.yml will NOT fire automatically from this push." >&2
    echo "::warning::Once RELEASE_TOKEN exists, trigger the Pages deploy manually: gh workflow run deploy-pages.yml --ref docs-pages" >&2
fi

# Files / directories owned by other deploy scripts or by manual setup. Critically
# includes `.github/` -- the docs-pages branch carries its own
# `.github/workflows/deploy-pages.yml`, a separate workflow file living on that
# branch (not on main), and that file is what actually performs the Pages
# deployment when pushed to. Preserving whatever else may already live under
# `.github/` is a courtesy on top of the unconditional re-copy above, not a
# substitute for it.
readonly -a PRESERVE=(
    ".github"
)

# Bash suspends `set -e` for every command inside a function for the duration that the
# function itself is used as the condition of `if`/`while`/`&&`/`||` (this is standard,
# documented bash behaviour, not a bug in this script's use of `set -e`) -- so
# `if push_attempt; then` on the first attempt below would silently swallow a failure in
# any single command this function runs, letting execution fall through to `git commit`
# and `git push` against a half-updated working tree. Every fallible command here is
# therefore checked and returned from explicitly, rather than relying on `set -e` to stop
# the function -- this makes push_attempt's own exit status meaningful under both call
# styles: `if push_attempt; then …` (attempt 1) and the bare, unconditional `push_attempt`
# (the retry), where `set -e` reasserts itself normally.
push_attempt() {
    local work
    work="$(mktemp -d)" || return 1

    # cd back to SRC_DIR before deleting $work: the working directory is still inside
    # $work at the point this function returns (nothing below ever cd's back out), and
    # `rm -rf` on the process's own current directory leaves the shell's $PWD pointing at
    # a directory that no longer exists -- silently corrupting $OLDPWD for whatever runs
    # next (see SRC_DIR's comment above for the retry-path consequence of that).
    trap 'cd "$SRC_DIR" 2>/dev/null || true; rm -rf "$work"' RETURN

    git clone --branch docs-pages --depth 1 \
        "https://x-access-token:${GITHUB_TOKEN}@github.com/${REPO}.git" "$work" || return 1

    cd "$work" || return 1
    # Identity scoped to this clone so it doesn't leak into any shared runner-level
    # git config.
    git config user.email "release@reyem.tech" || return 1
    git config user.name "laravel-hubspot-release-bot" || return 1

    # Stash the preserved paths (-a keeps mode and recurses into directories --
    # cp -p alone fails on dirs), wipe everything else, copy the fresh build
    # output on top, then restore what was stashed.
    local stash
    stash="$(mktemp -d)" || return 1
    for f in "${PRESERVE[@]}"; do
        if [[ -e "$f" ]]; then
            cp -a "$f" "$stash/" || return 1
        fi
    done

    find . -mindepth 1 -maxdepth 1 ! -name ".git" -exec rm -rf {} + || return 1
    cp -R "${SRC_DIR}/${SITE_DIST}"/. . || return 1
    for f in "${PRESERVE[@]}"; do
        if [[ -e "$stash/$f" ]]; then
            cp -a "$stash/$f" "$f" || return 1
        fi
    done
    rm -rf "$stash"

    # Unconditional re-copy, after the preserve-restore above so it always wins: guarantees
    # deploy-pages.yml exists on docs-pages even on the very first publish to a freshly
    # bootstrapped, genuinely empty orphan branch (see DEPLOY_PAGES_WORKFLOW's comment).
    mkdir -p "$(dirname "$DEPLOY_PAGES_WORKFLOW")" || return 1
    cp -a "${SRC_DIR}/${DEPLOY_PAGES_WORKFLOW}" "$DEPLOY_PAGES_WORKFLOW" || return 1

    git add -A || return 1
    if git diff --staged --quiet; then
        echo "-> no doc changes; nothing to publish"
        return 0
    fi
    git commit -m "chore(docs): publish Starlight site" || return 1
    git push origin docs-pages || return 1
}

if push_attempt; then
    echo "docs published to docs-pages"
    exit 0
fi

echo "-> push failed; retrying once"
sleep 5
push_attempt
echo "docs published to docs-pages (on retry)"
