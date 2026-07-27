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
# Usage:
#   scripts/ci/check-review-threads.sh              # check the current PR
#   scripts/ci/check-review-threads.sh --self-test  # prove the rule fires
set -euo pipefail

ROOT="$(git rev-parse --show-toplevel)"
cd "$ROOT"

# The atomic decision primitive: given whether a thread is resolved and a
# newline-separated list of that thread's comment `author.__typename`
# values (in comment order), is this thread a violation? Used both by the
# real PR scan below and by --self-test, so the self-test proves the exact
# mechanism the real scan relies on, not a parallel reimplementation of it.
#
# RED (D-13): resolved+no-human-reply detection is not implemented yet.
# Always reports "not a violation" until the next commit.
thread_is_violation() {
    local is_resolved="$1"
    local typenames="$2"

    return 1
}

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

echo "Review-thread check passed (placeholder -- not yet a real scan)."
exit 0
