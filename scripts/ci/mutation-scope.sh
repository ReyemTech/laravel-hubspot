#!/usr/bin/env bash
#
# Prints the comma-separated list of fully-qualified class names under src/ that a pull request
# changes, for `pest --mutate --class=...`.
#
# Why this exists
# ---------------
# The full mutation run takes 14-16 minutes and has never failed: ten consecutive runs measured
# 10.5-16m, all green, with MSI sitting at 96-99% against a floor of 80. As a *gate* at that margin
# it earns nothing; its real value is as a driver while tests are being written. Scoping the
# blocking run to what a PR actually changed keeps that signal exactly where the new code is and
# drops the runtime to seconds.
#
# The whole-src signal is NOT lost: quality.yml still runs the unscoped mutation on every push to
# main, so "a change in Sync broke a mutant Gateway's tests used to kill" is still caught — just
# after merge rather than before it, which is the deliberate trade.
#
# One consequence worth knowing: a scoped run computes MSI over a SMALLER sample, so the floor of 80
# bites harder. Two classes measured together scored 87.5% where the whole tree averages 96%+. That
# is the gate getting sharper on new code, not looser.
#
# Usage:
#   scripts/ci/mutation-scope.sh <base-ref>   # e.g. origin/main
#   scripts/ci/mutation-scope.sh --self-test
#
# Prints nothing when a pull request changes no PHP file under src/ — the caller reads empty as
# "nothing to mutate" and skips, rather than silently falling back to the full run.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

# src/Sync/PropertyMapper.php -> ReyemTech\Hubspot\Sync\PropertyMapper
#
# The prefix is read from composer.json's own psr-4 map rather than hard-coded, so renaming the
# namespace cannot leave this script silently resolving classes that no longer exist.
path_to_class() {
    local path="$1"
    local prefix
    local src_dir

    prefix="$(node -e '
        const c = require(process.argv[1] + "/composer.json");
        const psr4 = c.autoload["psr-4"];
        const [ns, dir] = Object.entries(psr4).find(([, d]) => d.replace(/\/$/, "") === "src");
        process.stdout.write(ns + "\t" + dir);
    ' "$ROOT")"

    src_dir="${prefix#*$'\t'}"
    prefix="${prefix%$'\t'*}"
    src_dir="${src_dir%/}"

    path="${path#"$src_dir"/}"
    path="${path%.php}"
    path="${path//\//\\}"

    printf '%s%s' "$prefix" "$path"
}

changed_classes() {
    local base="$1"
    local files
    local out=()
    local file

    # --diff-filter excludes deletions: a class that no longer exists cannot be mutated, and
    # passing it to --class would make pest fail on a class it cannot load.
    files="$(git -C "$ROOT" diff --name-only --diff-filter=d "${base}...HEAD" -- 'src/*.php' || true)"

    [ -z "$files" ] && return 0

    while IFS= read -r file; do
        [ -z "$file" ] && continue
        out+=("$(path_to_class "$file")")
    done <<< "$files"

    local IFS=','
    printf '%s' "${out[*]}"
}

self_test() {
    local failed=0
    local got

    got="$(path_to_class 'src/Sync/PropertyMapper.php')"
    if [ "$got" != 'ReyemTech\Hubspot\Sync\PropertyMapper' ]; then
        echo "Self-test FAILED: nested path mapped to '${got}'." >&2
        failed=1
    fi

    got="$(path_to_class 'src/ServiceProvider.php')"
    if [ "$got" != 'ReyemTech\Hubspot\ServiceProvider' ]; then
        echo "Self-test FAILED: top-level path mapped to '${got}'." >&2
        failed=1
    fi

    got="$(path_to_class 'src/Registry/Stores/DatabaseAssociationTypeStore.php')"
    if [ "$got" != 'ReyemTech\Hubspot\Registry\Stores\DatabaseAssociationTypeStore' ]; then
        echo "Self-test FAILED: deep path mapped to '${got}'." >&2
        failed=1
    fi

    # Every mapped class must actually be loadable, which is what proves the psr-4 read is right
    # rather than merely well-shaped.
    local file
    while IFS= read -r file; do
        got="$(path_to_class "$file")"
        if ! php -r 'require "vendor/autoload.php"; exit(class_exists($argv[1]) || interface_exists($argv[1]) || trait_exists($argv[1]) || enum_exists($argv[1]) ? 0 : 1);' "$got" 2>/dev/null; then
            echo "Self-test FAILED: ${file} mapped to '${got}', which does not autoload." >&2
            failed=1
        fi
    done < <(git -C "$ROOT" ls-files -- 'src/*.php')

    if [ "$failed" -ne 0 ]; then
        return 1
    fi

    echo "Self-test passed: every tracked src/ file maps to a class that autoloads."

    return 0
}

if [ "${1:-}" = "--self-test" ]; then
    self_test
    exit $?
fi

if [ $# -lt 1 ]; then
    echo "Usage: $0 <base-ref> | --self-test" >&2
    exit 2
fi

changed_classes "$1"
