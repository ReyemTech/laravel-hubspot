#!/usr/bin/env bash
#
# Answers one question: did this change touch any path matching the given patterns?
#
#   paths-changed.sh <base-ref> <pattern> [<pattern>...]
#
# Exit 0 -> yes, at least one matching path changed (or the answer could not be trusted; see
#           "failing open" below).
# Exit 1 -> no matching path changed.
#
# ## Why this exists rather than a workflow-level `paths:` filter
#
# Every job this gates is a REQUIRED status check. The two ways of not doing work are not
# interchangeable:
#
#   workflow-level `paths:`  -> the check never reports, and the pull request is blocked for ever
#                               on "Expected -- waiting for status to be reported". There is no run
#                               to open and nothing to re-run.
#   this script, in a step   -> the job runs, reports green, and simply skips the expensive steps.
#
# So the gate is kept and the bill is not. A skipped install-and-build costs ~20 seconds of runner
# instead of two to three minutes.
#
# ## Failing OPEN, deliberately
#
# Every other CI script in this repository fails CLOSED, and this one does the opposite on purpose.
# The others answer "is this code acceptable"; a broken checkout must not be allowed to answer yes.
# This one answers "is it worth looking", and the cost of being wrong is asymmetric: a false "yes"
# wastes two minutes of runner, while a false "no" silently skips a real gate and lets a genuine
# regression merge unexamined. When git cannot tell us -- no base ref, a shallow clone, a diff that
# errors -- the honest answer is "assume it changed" and pay the two minutes.
#
# That asymmetry is why the caller must NOT wrap this in `|| true`: the script already resolves its
# own uncertainty in the safe direction, and a suppression on top would convert a hard failure of
# the script itself into a silent skip, which is the one outcome the design rules out.

set -uo pipefail

if [ "$#" -lt 2 ]; then
    echo "usage: paths-changed.sh <base-ref> <pattern> [<pattern>...]" >&2
    exit 0
fi

base="$1"
shift

if [ -z "$base" ]; then
    echo "paths-changed: no base ref given, assuming the paths changed." >&2
    exit 0
fi

if ! git rev-parse --verify --quiet "$base" >/dev/null; then
    echo "paths-changed: base ref \"$base\" is not reachable, assuming the paths changed." >&2
    exit 0
fi

if ! changed="$(git diff --name-only "$base"...HEAD 2>/dev/null)"; then
    echo "paths-changed: git diff against \"$base\" failed, assuming the paths changed." >&2
    exit 0
fi

# A diff that is legitimately empty is not the same as one that could not be computed. An empty
# diff means nothing changed, which means nothing matching changed -- answer no, and skip.
if [ -z "$changed" ]; then
    echo "paths-changed: no files changed against $base."
    exit 1
fi

while IFS= read -r file; do
    [ -n "$file" ] || continue

    for pattern in "$@"; do
        # shellcheck disable=SC2254 # The pattern is a glob on purpose; that is the whole interface.
        case "$file" in
            $pattern)
                echo "paths-changed: \"$file\" matches \"$pattern\"."
                exit 0
                ;;
        esac
    done
done <<<"$changed"

echo "paths-changed: nothing matching [$*] changed against $base."
exit 1
