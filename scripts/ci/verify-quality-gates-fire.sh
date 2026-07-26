#!/usr/bin/env bash
#
# Quality-gate firing harness for reyemtech/laravel-hubspot.
#
# A green `vendor/bin/phpstan analyse`, `vendor/bin/pint --test` or
# `vendor/bin/phpcs` run against the shipped tree is not, on its own, evidence
# any of those tools are actually configured to catch anything -- an empty
# ruleset is also green. This harness generates one deliberate violation per
# gate in a scratch directory outside the tracked tree, runs the shipped
# config against it, and asserts the gate rejects it. It never writes inside
# the repository itself.
#
# Scope: PHPStan (baseline absence + a deliberate type error) and the
# code-shape gate (file length, function length, cyclomatic complexity, and
# Pint's style check). The architecture-rules firing harness is a separate,
# differently-scoped script: scripts/ci/verify-arch-rules-fire.sh (plan 04).
#
# Usage:
#   scripts/ci/verify-quality-gates-fire.sh              # run every gate
#   scripts/ci/verify-quality-gates-fire.sh --only=phpstan
#   scripts/ci/verify-quality-gates-fire.sh --only=shape
#
# See STANDARDS.md §3, §5, §6b and .planning/phases/01-foundation-gates/01-05-PLAN.md.
set -euo pipefail

ROOT="$(git rev-parse --show-toplevel)"
cd "$ROOT"

ONLY="all"
for arg in "$@"; do
    case "$arg" in
        --only=*) ONLY="${arg#--only=}" ;;
    esac
done

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

failures=()

log() {
    echo "==> $*"
}

# ---------------------------------------------------------------------------
# PHPStan: no baseline file anywhere, and a deliberate type error is rejected
# ---------------------------------------------------------------------------
check_phpstan() {
    log "phpstan: asserting no baseline file exists anywhere in the repository"

    if find "$ROOT" \
        \( -path "$ROOT/vendor" -o -path "$ROOT/node_modules" \) -prune -o \
        -iname 'phpstan-baseline.neon' -print \
        | grep -q .; then
        failures+=("phpstan: a phpstan-baseline.neon file exists in the repository (D-04 forbids it)")
    fi

    if [ -f "$ROOT/phpstan.neon" ] && grep -qi 'baseline' "$ROOT/phpstan.neon"; then
        failures+=("phpstan: phpstan.neon references a baseline")
    fi

    if [ ! -f "$ROOT/phpstan.neon" ]; then
        failures+=("phpstan: phpstan.neon does not exist yet")

        return
    fi

    log "phpstan: asserting a deliberate type error is rejected under the shipped config"

    local fixture_dir="$TMP_DIR/phpstan-fixture"
    mkdir -p "$fixture_dir"
    cat > "$fixture_dir/DeliberateTypeError.php" <<'PHP'
<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\QualityGateFixtures;

/**
 * Deliberate violation fixture for scripts/ci/verify-quality-gates-fire.sh.
 * Never production code: proves phpstan.neon actually rejects a type error
 * rather than reporting green over an unconfigured analyser.
 */
final class DeliberateTypeError
{
    public function needsString(): string
    {
        return 42;
    }
}
PHP

    local log_file="$TMP_DIR/phpstan-fixture.log"

    if vendor/bin/phpstan analyse -c "$ROOT/phpstan.neon" --no-progress "$fixture_dir" > "$log_file" 2>&1; then
        failures+=("phpstan: accepted a deliberate type error under its own config (see ${log_file})")
    fi
}

run() {
    case "$ONLY" in
        phpstan) check_phpstan ;;
        shape) log "shape: not yet implemented" ;;
        all)
            check_phpstan
            ;;
        *)
            echo "Unknown --only value: ${ONLY}" >&2
            exit 2
            ;;
    esac
}

run

if [ "${#failures[@]}" -gt 0 ]; then
    echo "Quality-gate firing harness FAILED:" >&2
    for f in "${failures[@]}"; do
        echo "  - ${f}" >&2
    done
    exit 1
fi

echo "Quality-gate firing harness passed."
exit 0
