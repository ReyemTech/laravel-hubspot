#!/usr/bin/env bash
#
# Review-thread reply gate for reyemtech/laravel-hubspot (STANDARDS.md §12,
# "Automated review is review").
#
# Fails the build when a *resolved* GitHub PR review thread carries no reply
# from a human author. An automated reviewer (Codex, Cursor/Bugbot, or
# whatever replaces them) authors the first comment in a thread; if the
# thread is resolved and every comment in it still comes from a bot, nobody
# replied before it was closed -- the silent-resolution failure this
# standard exists to prevent. A reply, whether a fix explanation or a
# reasoned rebuttal, is the evidence that somebody engaged. Branch
# protection's conversation-resolution requirement already blocks unresolved
# threads from merging, so this script only cares about resolved ones.
#
# Bot identification uses GraphQL's `author.__typename` ("Bot" vs "User"),
# verified empirically against this repository's real PRs (#3, #4, #5, #8,
# #9 -- all carrying chatgpt-codex-connector threads) rather than assumed:
# every automated-reviewer comment observed on this repo (both
# chatgpt-codex-connector review comments and cursor/Bugbot issue comments)
# reports `__typename: "Bot"`, and every human comment observed reports
# `__typename: "User"`. Checking for the *presence* of a "User" author,
# rather than the *absence* of a named bot login, means a new automated
# reviewer added later needs no change here -- only a human reply counts.
#
# Usage:
#   scripts/ci/check-review-threads.sh              # check the current PR
#   scripts/ci/check-review-threads.sh --self-test  # prove the rule fires
set -euo pipefail

ROOT="$(git rev-parse --show-toplevel)"
cd "$ROOT"

# The GraphQL document is deliberately single-quoted: its $owner/$name/$pr/
# $cursor tokens are GraphQL variables resolved server-side via gh api
# graphql's -f/-F flags below, not shell variables -- they must not expand
# locally.
# shellcheck disable=SC2016
REVIEW_THREADS_QUERY='
query($owner: String!, $name: String!, $pr: Int!, $cursor: String) {
  repository(owner: $owner, name: $name) {
    pullRequest(number: $pr) {
      reviewThreads(first: 50, after: $cursor) {
        pageInfo {
          hasNextPage
          endCursor
        }
        nodes {
          isResolved
          path
          line
          comments(first: 50) {
            nodes {
              url
              author {
                __typename
              }
            }
          }
        }
      }
    }
  }
}'

# --- decision primitives ----------------------------------------------
#
# The atomic detection primitive: given a newline-separated list of a
# thread's comment `author.__typename` values, did a human reply anywhere
# in it?
thread_has_human_reply() {
    local typenames="$1"

    grep -qx "User" <<< "$typenames"
}

# The decision primitive proper: given whether a thread is resolved and its
# comment typenames, is this thread a violation? Used both by the real PR
# scan below and by --self-test, so the self-test proves the exact
# mechanism the real scan relies on, not a parallel reimplementation of it.
thread_is_violation() {
    local is_resolved="$1"
    local typenames="$2"

    if [ "$is_resolved" != "true" ]; then
        return 1
    fi

    if thread_has_human_reply "$typenames"; then
        return 1
    fi

    return 0
}

# --- PR scan -------------------------------------------------------------

require_cmd() {
    if ! command -v "$1" >/dev/null 2>&1; then
        echo "check-review-threads.sh requires '$1' on PATH." >&2
        exit 1
    fi
}

# Resolves the PR number this run should check.
#
# Exit codes matter here and are read by the caller, not just asserted
# truthy/falsy: 0 = resolved, number printed to stdout. 1 = no PR in
# context at all (e.g. a push to main) -- the caller treats that as "skip
# cleanly", not a failure. 2 = a PR *is* in context but the number could
# not be determined (missing/unreadable/unparseable $GITHUB_EVENT_PATH) --
# a genuinely broken state the caller must fail loudly on, never merge
# silently into the "skip cleanly" case just because both are non-zero.
# Collapsing 1 and 2 into a single "nonzero means skip" signal would be
# exactly the silent-degrade bug this gate exists to avoid in its own
# CI wiring.
#
# $PR_NUMBER is an explicit override, mainly for local runs.
resolve_pr_number() {
    if [ -n "${PR_NUMBER:-}" ]; then
        printf '%s\n' "${PR_NUMBER}"
        return 0
    fi

    case "${GITHUB_EVENT_NAME:-}" in
        pull_request | pull_request_target) ;;
        *)
            return 1
            ;;
    esac

    if [ -z "${GITHUB_EVENT_PATH:-}" ] || [ ! -f "${GITHUB_EVENT_PATH}" ]; then
        echo "check-review-threads.sh: GITHUB_EVENT_NAME=${GITHUB_EVENT_NAME:-} but \$GITHUB_EVENT_PATH is missing or unreadable." >&2
        return 2
    fi

    local number
    number="$(jq -r '.pull_request.number // empty' "${GITHUB_EVENT_PATH}")"

    if [ -z "${number}" ]; then
        echo "check-review-threads.sh: could not read .pull_request.number from \$GITHUB_EVENT_PATH." >&2
        return 2
    fi

    printf '%s\n' "${number}"
}

resolve_repo() {
    if [ -n "${GITHUB_REPOSITORY:-}" ]; then
        printf '%s\n' "${GITHUB_REPOSITORY}"
        return 0
    fi

    gh repo view --json nameWithOwner --jq '.nameWithOwner'
}

# Evaluates one thread (a single-line JSON reviewThreads node) against
# thread_is_violation. On a violation, echoes a one-line, human-readable
# description of it and returns 0; on a pass, prints nothing and returns 1.
evaluate_thread_line() {
    local line="$1"
    local is_resolved typenames

    is_resolved="$(jq -r '.isResolved' <<< "$line")"
    typenames="$(jq -r '.comments.nodes[].author.__typename // "null"' <<< "$line")"

    if ! thread_is_violation "$is_resolved" "$typenames"; then
        return 1
    fi

    jq -r '
        (.path // "(unknown file)")
        + (if .line then ":" + (.line | tostring) else "" end)
        + " -- " + ((.comments.nodes[0].url) // "(no comment url)")
    ' <<< "$line"
}

scan_pr_threads() {
    local owner="$1" name="$2" pr="$3"
    local cursor="null"
    local has_next="true"
    local violations=()

    while [ "$has_next" = "true" ]; do
        local page
        page="$(gh api graphql \
            -f query="$REVIEW_THREADS_QUERY" \
            -f owner="$owner" \
            -f name="$name" \
            -F pr="$pr" \
            -F cursor="$cursor")"

        has_next="$(jq -r '.data.repository.pullRequest.reviewThreads.pageInfo.hasNextPage' <<< "$page")"
        cursor="$(jq -r '.data.repository.pullRequest.reviewThreads.pageInfo.endCursor // "null"' <<< "$page")"

        local violation_desc
        while IFS= read -r line; do
            [ -z "$line" ] && continue

            if violation_desc="$(evaluate_thread_line "$line")"; then
                violations+=("$violation_desc")
            fi
        done < <(jq -c '.data.repository.pullRequest.reviewThreads.nodes[]' <<< "$page")
    done

    if [ "${#violations[@]}" -gt 0 ]; then
        echo "Review-thread check FAILED: ${#violations[@]} resolved thread(s) with no human reply:" >&2
        for v in "${violations[@]}"; do
            echo "  - ${v}" >&2
        done
        return 1
    fi

    echo "Review-thread check passed: every resolved thread on PR #${pr} has at least one human reply."
    return 0
}

# --- self-test -----------------------------------------------------------

self_test() {
    local all_bot=$'Bot\nBot'
    local with_human=$'Bot\nUser\nBot'

    # Case 1: resolved, every comment from a bot -- must be flagged.
    if thread_is_violation "true" "$all_bot"; then
        :
    else
        echo "Self-test FAILED: a resolved thread with only bot comments was not flagged." >&2
        return 1
    fi

    # Case 2: resolved, at least one human reply -- must pass.
    if thread_is_violation "true" "$with_human"; then
        echo "Self-test FAILED: a resolved thread with a human reply was flagged as a violation." >&2
        return 1
    fi

    # Case 3: unresolved, all-bot -- must pass (branch protection already
    # blocks an unresolved thread from merging).
    if thread_is_violation "false" "$all_bot"; then
        echo "Self-test FAILED: an unresolved thread was flagged as a violation (branch protection already covers that case)." >&2
        return 1
    fi

    echo "Self-test passed: resolved all-bot threads are flagged; a human reply, or an unresolved thread, is not."
    return 0
}

if [ "${1:-}" = "--self-test" ]; then
    self_test
    exit $?
fi

# Guarded by `if` (not a bare assignment) so `set -e` does not abort the
# script before this can branch on resolve_pr_number's exit code -- see its
# own comment for why 1 (skip) and 2 (hard error) must not be collapsed.
if pr_number="$(resolve_pr_number)"; then
    :
else
    resolve_rc=$?

    if [ "${resolve_rc}" -eq 1 ]; then
        echo "check-review-threads.sh: no pull request in context (GITHUB_EVENT_NAME=${GITHUB_EVENT_NAME:-<unset>}) -- skipping."
        exit 0
    fi

    # resolve_rc == 2 (or anything else unexpected): a PR *is* in context
    # but the number could not be determined. resolve_pr_number already
    # printed the specific reason to stderr; fail loudly rather than
    # silently skip.
    exit "${resolve_rc}"
fi

require_cmd gh
require_cmd jq

if ! gh auth status >/dev/null 2>&1; then
    echo "check-review-threads.sh: gh is not authenticated (no GH_TOKEN/GITHUB_TOKEN, and no stored gh auth) -- cannot query PR #${pr_number}." >&2
    exit 1
fi

repo="$(resolve_repo)"
owner="${repo%%/*}"
name="${repo#*/}"

scan_pr_threads "$owner" "$name" "$pr_number"
