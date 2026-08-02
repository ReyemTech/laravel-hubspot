#!/usr/bin/env bash
#
# Answers one question: did this change touch any path matching the given patterns?
#
#   paths-changed.sh <mode> <ref> <pattern> [<pattern>...]
#
# `mode` is `branch` or `push`, and naming it is mandatory because the two ask genuinely different
# questions of git (Codex, local review):
#
#   branch <base>   ->  git diff <base>...HEAD   (three dot: merge-base to tip)
#                       "what does this branch change relative to where it forked?" -- the right
#                       question for a pull request, because it ignores whatever main did meanwhile.
#   push <before>   ->  git diff <before>..HEAD  (two dot: old tip to new tip)
#                       "what did this push do to this ref?" -- the right question for a push event.
#                       Three-dot is WRONG here: if the previous tip is no longer an ancestor of the
#                       new one, as after a divergent force-push, three-dot silently drops every file
#                       that only ever changed on the old side. A force-push that REVERTS a docs
#                       change would then skip the docs gate.
#
# main forbids force pushes today, so the second case is currently unreachable there. It is still
# spelled correctly rather than left to a repository setting that is not this script's to rely on.
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

# `--self-test` proves the four behaviours this script promises, against a real throwaway
# repository rather than a mocked git. Every other CI script here carries one for the same reason:
# a gate nobody has watched fail is a gate nobody knows works.
if [ "${1:-}" = "--self-test" ]; then
    work="$(mktemp -d)"
    trap 'rm -rf "$work"' EXIT
    failures=0

    # A ref the fixture failed to create resolves to nothing, and this script FAILS OPEN on an
    # unresolvable ref -- so a broken fixture would make every check pass for the wrong reason.
    # That is not hypothetical: `git tag` needs a message under some global configs, the tag was
    # never created, and the rename check passed with the fix removed. Assert the fixture.
    require_ref() {
        if ! (cd "$work" && git rev-parse --verify --quiet "$1" >/dev/null); then
            echo "  FAIL self-test fixture: ref \"$1\" was never created."
            failures=$((failures + 1))
            return 1
        fi
    }

    check() {
        local label="$1" expected="$2"
        shift 2
        (cd "$work" && bash "$OLDPWD/scripts/ci/paths-changed.sh" "$@" >/dev/null 2>&1)
        local actual=$?

        if [ "$actual" = "$expected" ]; then
            echo "  ok   $label (exit $actual)"
        else
            echo "  FAIL $label: expected exit $expected, got $actual"
            failures=$((failures + 1))
        fi
    }

    (
        cd "$work" || exit 1
        git init -q .
        git config user.email ci@example.com
        git config user.name CI
        mkdir -p site nested/deep other
        echo a > site/page.ts
        # Renamed UNMODIFIED below, and given enough content to be an unambiguous 100%-similarity
        # move. Git's rename detection needs that similarity to fire at all, and a rename it does
        # not detect proves nothing about a flag that only matters when it does.
        printf 'line %s\n' 1 2 3 4 5 6 7 8 9 10 > site/pure.ts
        echo b > nested/deep/file.ts
        echo c > other/unrelated.txt
        git add -A && git commit -qm base
        git branch base-ref
    ) || { echo "self-test: could not build the fixture repository"; exit 1; }

    echo "paths-changed --self-test:"

    (cd "$work" && echo changed > other/unrelated.txt && git add -A && git commit -qm unrelated)
    require_ref base-ref \
        && check "an unrelated change does not match" 1 branch base-ref 'site/*'

    (cd "$work" && echo more >> site/page.ts && git add -A && git commit -qm sitechange)
    check "a change inside the gated directory matches" 0 branch base-ref 'site/*'

    # The rename case, which is why `--no-renames` is on the diff. With detection enabled git
    # reports the DESTINATION only, so the source directory losing a file would look like no
    # change at all and a required gate would skip.
    #
    # Checked against its OWN base, immediately before the rename, and on a file that moves
    # UNMODIFIED. The first draft did neither -- it reused the shared base, whose accumulated diff
    # already matched for other reasons, and renamed a file it had just edited, which git scored
    # below the similarity threshold. It passed with `--no-renames` removed, which means it pinned
    # nothing. Verified the other way now: strip the flag and this check fails.
    (cd "$work" && git branch pre-rename && git mv site/pure.ts other/pure.ts && git commit -qm renameout)
    require_ref pre-rename \
        && check "a file renamed OUT of the gated directory still matches" 0 branch pre-rename 'site/*'

    # Non-ASCII, which git C-quotes under its default core.quotePath. A Canadian company writes
    # accented filenames; this is not an exotic case here.
    # Non-ASCII, which git C-quotes under its default core.quotePath. A Canadian company writes
    # accented filenames; this is not an exotic case here.
    #
    # Two things this check needs that earlier drafts of it lacked, both caught by review rather
    # than by writing it:
    #
    # 1. ANSI-C quoting ($'...'), NOT a double-quoted string. Bash does not interpret \xNN inside
    #    double quotes, so "caf\xc3\xa9.mdx" creates a PURE-ASCII name with literal backslashes
    #    and exercises nothing. Verified at the byte level: this produces c a f 303 251.
    # 2. Its own base ref AND a directory only this file lives in. Pointed at `site/*` from the
    #    shared base, the accented file was irrelevant -- other files under site/ had changed by
    #    then, so the glob matched regardless and the check passed with the fix removed.
    (
        cd "$work" || exit 1
        git branch pre-accent
        mkdir -p accented
        printf 'accented\n' > $'accented/caf\xc3\xa9.mdx'
        git add -A && git commit -qm accented
    )
    require_ref pre-accent \
        && check "an accented filename still matches its glob" 0 branch pre-accent 'accented/*'

    # `*` in a `case` pattern spans slashes, which is what makes one pattern cover a whole tree.
    # The file has to actually CHANGE to appear in the diff: the first draft of this check asserted
    # against a file that only ever existed in the base commit, and the self-test caught it.
    (cd "$work" && echo deeper >> nested/deep/file.ts && git add -A && git commit -qm nested)
    check "a nested path matches the directory glob" 0 branch base-ref 'nested/*'

    # The divergent case: a ref that is reachable but is NOT an ancestor of HEAD, which is what a
    # force-push leaves behind. `branch` mode compares merge-base to tip and cannot see the file
    # that only ever existed on the abandoned side; `push` mode compares tip to tip and can.
    (
        cd "$work" || exit 1
        git checkout -q --detach base-ref
        mkdir -p site
        echo forced > site/only-on-the-old-side.ts
        git add -A && git commit -qm oldside
        git branch old-tip
        git checkout -q -
    )
    require_ref old-tip \
        && check "a divergent old tip is invisible to branch mode" 1 branch old-tip 'site/only-on-the-old-side.ts' \
        && check "a divergent old tip IS seen by push mode" 0 push old-tip 'site/only-on-the-old-side.ts'

    check "an unreachable base ref fails OPEN" 0 branch no-such-ref 'site/*'
    check "an unknown mode fails OPEN" 0 sideways base-ref 'site/*'
    check "a missing pattern argument fails OPEN" 0 branch base-ref

    if [ "$failures" -ne 0 ]; then
        echo "paths-changed --self-test: $failures failure(s)."
        exit 1
    fi

    echo "paths-changed --self-test: all checks passed."
    exit 0
fi

if [ "$#" -lt 3 ]; then
    echo "usage: paths-changed.sh <branch|push> <ref> <pattern> [<pattern>...]" >&2
    exit 0
fi

mode="$1"
base="$2"
shift 2

case "$mode" in
    branch) range_separator="..." ;;
    push)   range_separator=".." ;;
    *)
        echo "paths-changed: unknown mode \"$mode\", assuming the paths changed." >&2
        exit 0
        ;;
esac

if [ -z "$base" ]; then
    echo "paths-changed: no base ref given, assuming the paths changed." >&2
    exit 0
fi

if ! git rev-parse --verify --quiet "$base" >/dev/null; then
    echo "paths-changed: base ref \"$base\" is not reachable, assuming the paths changed." >&2
    exit 0
fi

# Two flags, each load-bearing, each found by review rather than by thinking about it.
#
# `--no-renames`: with rename detection on, `--name-only` reports a rename as its DESTINATION only,
# so moving `site/page.ts` to `archive/page.ts` prints just `archive/page.ts` -- which matches no
# `site/*` pattern, and the docs gate would report green having never rebuilt a site that just lost
# a file. Disabling detection lists the deletion and the addition separately.
#
# `-z`: git C-QUOTES any path outside plain ASCII under its default `core.quotePath`, so
# `site/café.mdx` arrives as the 24-character string `"site/caf\303\251.mdx"` -- LEADING DOUBLE
# QUOTE included, which is why it matches no `site/*` pattern either. `-z` emits raw pathnames
# terminated by NUL and does no quoting at all. Measured, not assumed.
#
# The output goes to a FILE rather than a variable because bash discards NUL bytes in command
# substitution -- `$(git diff -z ...)` silently concatenates every path into one string, which
# would be a worse bug than the one being fixed. The file also keeps the distinction the fail-open
# rule depends on: a diff that FAILED (non-zero exit) is not a diff that is legitimately EMPTY.
changed_list="$(mktemp)"
trap 'rm -f "$changed_list"' EXIT

if ! git diff --name-only --no-renames -z "${base}${range_separator}HEAD" >"$changed_list" 2>/dev/null; then
    echo "paths-changed: git diff against \"$base\" failed, assuming the paths changed." >&2
    exit 0
fi

# A diff that is legitimately empty is not the same as one that could not be computed. An empty
# diff means nothing changed, which means nothing matching changed -- answer no, and skip.
if [ ! -s "$changed_list" ]; then
    echo "paths-changed: no files changed against $base."
    exit 1
fi

while IFS= read -r -d '' file; do
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
done <"$changed_list"

echo "paths-changed: nothing matching [$*] changed against $base."
exit 1
