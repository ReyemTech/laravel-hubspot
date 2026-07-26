#!/usr/bin/env bash
#
# Source-hygiene gate for reyemtech/laravel-hubspot (D-07, STANDARDS §12).
#
# Rejects deferred-work marker comments -- the conventional two four-letter
# tokens meaning "decision deferred" -- anywhere in the tracked PHP,
# JavaScript, TypeScript and workflow files. A marker is a decision deferred
# where nobody will find it; CI turns it into a tracked issue instead.
#
# Usage:
#   scripts/ci/check-source-hygiene.sh              # scan the tracked tree
#   scripts/ci/check-source-hygiene.sh --self-test  # prove the scan fires
set -euo pipefail

ROOT="$(git rev-parse --show-toplevel)"
cd "$ROOT"

# RED (D-13): detection is not implemented yet. --self-test must fail until
# the real marker-matching logic lands in the next commit.
file_has_marker() {
    local file="$1"

    return 1
}

self_test() {
    local tmp_dir
    tmp_dir="$(mktemp -d)"
    trap 'rm -rf "${tmp_dir}"' RETURN

    local fixture="${tmp_dir}/HygieneFixture.php"
    echo '<?php // deliberate self-test fixture, never committed' > "${fixture}"

    if ! file_has_marker "${fixture}"; then
        echo "Self-test FAILED: the scan did not reject the fixture." >&2

        return 1
    fi

    echo "Self-test passed."

    return 0
}

if [ "${1:-}" = "--self-test" ]; then
    self_test
    exit $?
fi

echo "Source hygiene check passed (placeholder -- not yet a real scan)."
exit 0
