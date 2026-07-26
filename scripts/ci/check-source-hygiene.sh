#!/usr/bin/env bash
#
# Source-hygiene gate for reyemtech/laravel-hubspot (D-07, STANDARDS §12).
#
# Rejects deferred-work marker comments -- the conventional two four-letter
# tokens meaning "decision deferred" -- anywhere in the tracked PHP,
# JavaScript, TypeScript and workflow files. A marker is a decision deferred
# where nobody will find it; CI turns it into a tracked issue instead.
#
# The marker literals are built from concatenated fragments below, so this
# script's own source never contains either marker as a contiguous
# substring. It necessarily has to search for the marker text, and a hygiene
# check that matches its own definition is a self-tripping gate that gets
# disabled within a week -- worse than not having one. This file's own path
# is also excluded explicitly, as a second, independent line of defence.
#
# Usage:
#   scripts/ci/check-source-hygiene.sh              # scan the tracked tree
#   scripts/ci/check-source-hygiene.sh --self-test  # prove the scan fires
set -euo pipefail

ROOT="$(git rev-parse --show-toplevel)"
cd "$ROOT"

SELF_PATH="scripts/ci/check-source-hygiene.sh"

MARKER_ONE="TO""DO"
MARKER_TWO="FIX""ME"
MARKER_PATTERN="\\b(${MARKER_ONE}|${MARKER_TWO})\\b"

is_excluded_path() {
    local path="$1"

    case "$path" in
        vendor/*|node_modules/*|site/dist/*|resources/js/coverage/*|.planning/*)
            return 0
            ;;
        "$SELF_PATH")
            return 0
            ;;
        *)
            return 1
            ;;
    esac
}

# The atomic detection primitive: does this one file, on disk right now,
# contain either marker? Used both by the tree-wide scan below and by
# --self-test, so the self-test proves the exact mechanism the real scan
# relies on, not a parallel reimplementation of it.
file_has_marker() {
    local file="$1"

    grep -nE "${MARKER_PATTERN}" "$file" > /dev/null 2>&1
}

scan_tree() {
    local violations=()
    local file

    while IFS= read -r file; do
        if is_excluded_path "$file"; then
            continue
        fi

        if [ ! -f "$file" ]; then
            continue
        fi

        if file_has_marker "$file"; then
            violations+=("$file")
        fi
    done < <(git ls-files -- '*.php' '*.js' '*.ts' '*.yml' '*.yaml')

    if [ "${#violations[@]}" -gt 0 ]; then
        echo "Source hygiene check FAILED: deferred-work marker(s) found in:" >&2
        for v in "${violations[@]}"; do
            echo "  - ${v}" >&2
        done

        return 1
    fi

    echo "Source hygiene check passed: no deferred-work markers found in the tracked tree."

    return 0
}

self_test() {
    local tmp_dir
    tmp_dir="$(mktemp -d)"
    trap 'rm -rf "${tmp_dir}"' RETURN

    local fixture="${tmp_dir}/HygieneFixture.php"
    {
        echo '<?php'
        echo "// ${MARKER_ONE}: deliberate self-test fixture, never committed"
    } > "${fixture}"

    if ! file_has_marker "${fixture}"; then
        echo "Self-test FAILED: the scan did not reject a fixture carrying the first marker." >&2

        return 1
    fi

    : > "${fixture}"
    {
        echo '<?php'
        echo "// ${MARKER_TWO}: deliberate self-test fixture, never committed"
    } > "${fixture}"

    if ! file_has_marker "${fixture}"; then
        echo "Self-test FAILED: the scan did not reject a fixture carrying the second marker." >&2

        return 1
    fi

    : > "${fixture}"
    echo '<?php // no deferred-work marker here at all' > "${fixture}"

    if file_has_marker "${fixture}"; then
        echo "Self-test FAILED: the scan rejected a fixture that carries no marker at all." >&2

        return 1
    fi

    echo "Self-test passed: the scan rejects both markers and accepts marker-free content."

    return 0
}

if [ "${1:-}" = "--self-test" ]; then
    self_test
    exit $?
fi

scan_tree
exit $?
