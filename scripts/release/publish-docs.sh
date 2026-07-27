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

[[ -d "$SITE_DIST" ]] || { echo "error: $SITE_DIST not found -- run \`pnpm --filter './site' build\` first" >&2; exit 1; }
[[ -f "$SITE_DIST/index.html" ]] || { echo "error: $SITE_DIST/index.html missing -- build incomplete" >&2; exit 1; }
[[ -n "${GITHUB_TOKEN:-}" ]] || { echo "error: GITHUB_TOKEN required" >&2; exit 1; }

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
# deployment when pushed to. Wiping it kills the deploy trigger silently: the push
# to docs-pages still succeeds, but Pages never updates, and rotating a token does
# not fix it -- it requires re-adding the workflow file to the branch by hand.
readonly -a PRESERVE=(
    ".github"
)

push_attempt() {
    local work
    work="$(mktemp -d)"
    trap 'rm -rf "$work"' RETURN

    git clone --branch docs-pages --depth 1 \
        "https://x-access-token:${GITHUB_TOKEN}@github.com/${REPO}.git" "$work"

    cd "$work"
    # Identity scoped to this clone so it doesn't leak into any shared runner-level
    # git config.
    git config user.email "release@reyem.tech"
    git config user.name "laravel-hubspot-release-bot"

    # Stash the preserved paths (-a keeps mode and recurses into directories --
    # cp -p alone fails on dirs), wipe everything else, copy the fresh build
    # output on top, then restore what was stashed.
    local stash
    stash="$(mktemp -d)"
    for f in "${PRESERVE[@]}"; do
        [[ -e "$f" ]] && cp -a "$f" "$stash/"
    done

    find . -mindepth 1 -maxdepth 1 ! -name ".git" -exec rm -rf {} +
    cp -R "$OLDPWD/$SITE_DIST"/. .
    for f in "${PRESERVE[@]}"; do
        [[ -e "$stash/$f" ]] && cp -a "$stash/$f" "$f"
    done
    rm -rf "$stash"

    git add -A
    if git diff --staged --quiet; then
        echo "-> no doc changes; nothing to publish"
        return 0
    fi
    git commit -m "chore(docs): publish Starlight site"
    git push origin docs-pages
}

if push_attempt; then
    echo "docs published to docs-pages"
    exit 0
fi

echo "-> push failed; retrying once"
sleep 5
push_attempt
echo "docs published to docs-pages (on retry)"
