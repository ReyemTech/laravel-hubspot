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

    # Strip full-line comments before checking for a real `baseline:` key, so
    # this doesn't self-trip on prose (in this very file's header comment,
    # or phpstan.neon's own explanatory comments) that merely mentions the
    # word "baseline" without configuring one.
    if [ -f "$ROOT/phpstan.neon" ] \
        && grep -v '^\s*#' "$ROOT/phpstan.neon" | grep -qiE '^\s*baseline\s*:'; then
        failures+=("phpstan: phpstan.neon configures a baseline key")
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

# ---------------------------------------------------------------------------
# Code shape: Pint style, file length, function length, cyclomatic complexity
# ---------------------------------------------------------------------------
check_shape() {
    if [ ! -f "$ROOT/pint.json" ]; then
        failures+=("shape: pint.json does not exist yet")
    fi

    if [ ! -f "$ROOT/phpcs.xml" ]; then
        failures+=("shape: phpcs.xml does not exist yet")
    fi

    if [ ! -f "$ROOT/pint.json" ] || [ ! -f "$ROOT/phpcs.xml" ]; then
        return
    fi

    log "shape: asserting Pint rejects a misformatted file"

    local pint_fixture="$TMP_DIR/Misformatted.php"
    cat > "$pint_fixture" <<'PHP'
<?php
declare(strict_types=1);
namespace ReyemTech\Hubspot\Tests\QualityGateFixtures;
class Misformatted{
public function foo(){
return 'bad';
}
}
PHP

    if vendor/bin/pint --config="$ROOT/pint.json" --test "$pint_fixture" > "$TMP_DIR/pint-fixture.log" 2>&1; then
        failures+=("shape: pint --test accepted a misformatted file (see $TMP_DIR/pint-fixture.log)")
    fi

    log "shape: asserting phpcs rejects a 501-line file (independent of function length/complexity)"

    local file_length_dir="$TMP_DIR/shape-file-length"
    mkdir -p "$file_length_dir"
    {
        echo '<?php'
        echo 'declare(strict_types=1);'
        echo 'namespace ReyemTech\Hubspot\Tests\QualityGateFixtures;'
        echo 'final class LongFileOnly'
        echo '{'
        for i in $(seq 1 500); do
            echo "    public const PADDING_${i} = ${i};"
        done
        echo '}'
    } > "$file_length_dir/LongFileOnly.php"

    if vendor/bin/phpcs --standard="$ROOT/phpcs.xml" -q "$file_length_dir/LongFileOnly.php" \
        > "$TMP_DIR/shape-file-length.log" 2>&1; then
        failures+=("shape: phpcs accepted a 501-line file (see $TMP_DIR/shape-file-length.log)")
    elif ! grep -qi 'file is too long' "$TMP_DIR/shape-file-length.log"; then
        failures+=("shape: phpcs rejected the 501-line fixture but not for file length (see $TMP_DIR/shape-file-length.log)")
    fi

    log "shape: asserting phpcs rejects a 151-line function (independent of file length/complexity)"

    local function_length_dir="$TMP_DIR/shape-function-length"
    mkdir -p "$function_length_dir"
    {
        echo '<?php'
        echo 'declare(strict_types=1);'
        echo 'namespace ReyemTech\Hubspot\Tests\QualityGateFixtures;'
        echo 'final class LongFunctionOnly'
        echo '{'
        echo '    public function run(): int'
        echo '    {'
        echo '        $total = 0;'
        for i in $(seq 1 150); do
            echo "        \$total += ${i};"
        done
        echo '        return $total;'
        echo '    }'
        echo '}'
    } > "$function_length_dir/LongFunctionOnly.php"

    if vendor/bin/phpcs --standard="$ROOT/phpcs.xml" -q "$function_length_dir/LongFunctionOnly.php" \
        > "$TMP_DIR/shape-function-length.log" 2>&1; then
        failures+=("shape: phpcs accepted a 151-line function (see $TMP_DIR/shape-function-length.log)")
    elif ! grep -qi 'function is too long' "$TMP_DIR/shape-function-length.log"; then
        failures+=("shape: phpcs rejected the 151-line-function fixture but not for function length (see $TMP_DIR/shape-function-length.log)")
    fi

    log "shape: asserting phpcs rejects cyclomatic complexity 11 (independent of file/function length)"

    local complexity_dir="$TMP_DIR/shape-complexity"
    mkdir -p "$complexity_dir"
    {
        echo '<?php'
        echo 'declare(strict_types=1);'
        echo 'namespace ReyemTech\Hubspot\Tests\QualityGateFixtures;'
        echo 'final class ComplexFunction'
        echo '{'
        echo '    public function run(int $x): int'
        echo '    {'
        echo '        $y = 0;'
        for i in $(seq 1 10); do
            echo "        if (\$x === ${i}) { \$y = ${i}; }"
        done
        echo '        return $y;'
        echo '    }'
        echo '}'
    } > "$complexity_dir/ComplexFunction.php"

    if vendor/bin/phpcs --standard="$ROOT/phpcs.xml" -q "$complexity_dir/ComplexFunction.php" \
        > "$TMP_DIR/shape-complexity.log" 2>&1; then
        failures+=("shape: phpcs accepted a cyclomatic-complexity-11 function (see $TMP_DIR/shape-complexity.log)")
    elif ! grep -qi 'cyclomatic complexity' "$TMP_DIR/shape-complexity.log"; then
        failures+=("shape: phpcs rejected the complexity-11 fixture but not for cyclomatic complexity (see $TMP_DIR/shape-complexity.log)")
    fi
}

run() {
    case "$ONLY" in
        phpstan) check_phpstan ;;
        shape) check_shape ;;
        all)
            check_phpstan
            check_shape
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
