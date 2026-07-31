#!/usr/bin/env bash
#
# Vendor-namespace gate for reyemtech/laravel-hubspot (D-04, D-19, D-20).
#
# Two independent directions, each swept over every tracked PHP file under src/:
#
#   Direction A -- every `Illuminate\<Segment>` root referenced anywhere in src/ must be backed
#   by a declared `illuminate/<segment>` require. This is the direction that would have caught
#   the live illuminate/console defect (D-19): three shipped console commands imported
#   Illuminate\Console\Command as a production dependency the manifest never declared, and the
#   `manifest shape` gate never looked at what src/ actually names, only how many requires it
#   counted.
#
#   Direction B -- no vendor namespace root other than HubSpot (R1 already governs where that may
#   appear) or Illuminate (Direction A governs it) may appear in src/, unless it is on the
#   enumerated grandfather list below. D-02 inverted the original shape of this check (from
#   "block Illuminate roots with no backing require" to "block non-Illuminate vendor roots"); this
#   script ships BOTH directions, because D-19 is exactly the live defect the original direction
#   would have caught, and neither direction is a substitute for the other.
#
# LIMITATION, stated rather than discovered: this check reads `use` imports and fully-qualified
# class references via a regex, not a full parser. It cannot see a call to a global helper
# function such as `data_get()`, which lives in `illuminate/collections`. `illuminate/collections`
# is declared in composer.json on evidence this gate cannot produce -- a future gate that wanted
# to close that hole would have to resolve function calls, not namespaces.
#
# Usage:
#   scripts/ci/check-vendor-namespaces.sh              # scan the tracked src/ tree
#   scripts/ci/check-vendor-namespaces.sh --self-test  # prove both directions fire, and that a
#                                                       # clean file is accepted by both
set -euo pipefail

ROOT="$(git rev-parse --show-toplevel)"
cd "$ROOT"

FIXTURES_DIR="$ROOT/tests/Ci/Fixtures/VendorNamespaces"

# Each entry below is a third-party vendor root grandfathered onto this allow-list, with its own
# written reason. Being listed is a DEFERRAL, not an approval: this gate exists so a FOURTH root
# cannot arrive in src/ unnoticed, not to bless these three forever. Fixing any of them means
# either a STANDARDS.md Sec.2 third-party declaration or a dependency inversion, and neither is
# this plan's to make.
GRANDFATHERED_VENDOR_ROOTS=(
    # src/Gateway/HubspotClientFactory.php and four files under src/Testing/. Arrives
    # transitively through hubspot/api-client, a declared require; declaring it directly would be
    # a third-party addition under STANDARDS.md Sec.2.
    "GuzzleHttp"
    # src/Testing/RecordedRequest.php and src/Testing/RequestLog.php -- PSR-7 message interfaces,
    # transitive through the same SDK.
    "Psr"
    # src/Testing/HubspotFake.php and its collaborator RequestLog.php use
    # PHPUnit\Framework\Assert, so a require-dev package is named from production code. This is
    # the same shape Laravel's own Illuminate\Support\Testing\Fakes has, and it is recorded here
    # rather than fixed here.
    "PHPUnit"
)

is_grandfathered_root() {
    local root="$1"
    local entry

    for entry in "${GRANDFATHERED_VENDOR_ROOTS[@]}"; do
        if [ "$root" = "$entry" ]; then
            return 0
        fi
    done

    return 1
}

# The PHP-side detection primitive lives in one generated helper script, so the real scan and
# --self-test drive the exact same code path -- never a parallel reimplementation of the regex.
HELPER_PHP=""
cleanup() {
    if [ -n "$HELPER_PHP" ] && [ -f "$HELPER_PHP" ]; then
        rm -f "$HELPER_PHP"
    fi
}
trap cleanup EXIT INT TERM

HELPER_PHP="$(mktemp "${TMPDIR:-/tmp}/vendor-namespace-scan.XXXXXX.php")"
cat > "$HELPER_PHP" <<'PHP'
<?php

declare(strict_types=1);

// argv[1] = the PHP file to scan, argv[2] = the composer.json to read "require" keys from.
// Prints one tab-separated line per finding: "DIRECTION_A\t<package>\t<file>" or
// "DIRECTION_B\t<root>\t<file>". Prints nothing for a clean file.
//
// Namespace references are read via PhpToken::tokenize(), not a hand-rolled regex over raw file
// contents. A regex over raw text cannot tell a real `use Illuminate\Console\Command;` import
// from a docblock mentioning `Gateway\ExceptionTranslator` in prose, or from a regex string
// literal like '/^p\d*_[a-z0-9_]+$/' -- both contain a letter, a backslash and more letters, and
// both were false positives caught while writing this gate against the real, shipped tree. The
// tokenizer only ever emits T_NAME_QUALIFIED / T_NAME_FULLY_QUALIFIED for genuine code-level
// qualified names, never for text inside a comment or a string.

[, $file, $composerJsonPath] = $argv;

$contents = (string) file_get_contents($file);

$composer = json_decode((string) file_get_contents($composerJsonPath), true, flags: JSON_THROW_ON_ERROR);
$require = is_array($composer['require'] ?? null) ? $composer['require'] : [];
$requireKeys = array_keys($require);

$qualifiedNames = [];

$tokens = PhpToken::tokenize($contents);

// Indices of the tokens that carry meaning, so lookahead can step over whitespace and comments
// without counting them.
$significant = [];

foreach ($tokens as $index => $token) {
    if (in_array($token->id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
        continue;
    }

    $significant[] = $index;
}

// GROUP USE must be reassembled before anything is classified.
//
// `use Illuminate\{Console\Command, Support\Carbon};` does NOT tokenize the way a reader expects.
// PHP emits the prefix and the members as separate tokens -- T_STRING "Illuminate", T_NS_SEPARATOR,
// then T_NAME_QUALIFIED "Console\Command" and T_NAME_QUALIFIED "Support\Carbon". Classified naively,
// each member's FIRST segment reads as a vendor root, so `Console` and `Support` get reported as
// unapproved third-party roots and this gate rejects a legal import. Worse in the other direction:
// an undeclared Illuminate root hidden inside a group use would never be seen at all, because the
// root token says `Contracts`, not `Illuminate`.
//
// Codex raised this on PR #37 and it reproduced exactly: a tracked src/ file importing
// `use Illuminate\{Console\Command, Support\Carbon};` drove Direction B red naming both members.
//
// The prefix is stitched back on here, and the member tokens are marked consumed so the generic
// pass below cannot classify them a second time under the wrong root.
$consumed = [];
$count = count($significant);

for ($k = 0; $k < $count; $k++) {
    if ($tokens[$significant[$k]]->id !== T_USE) {
        continue;
    }

    $cursor = $k + 1;

    // `use function Foo\{...}` and `use const Foo\{...}` put a keyword between T_USE and the prefix.
    if (isset($significant[$cursor]) && in_array($tokens[$significant[$cursor]]->id, [T_FUNCTION, T_CONST], true)) {
        $cursor++;
    }

    $prefixIndex = $significant[$cursor] ?? null;
    $separatorIndex = $significant[$cursor + 1] ?? null;
    $braceIndex = $significant[$cursor + 2] ?? null;

    if ($prefixIndex === null || $separatorIndex === null || $braceIndex === null) {
        continue;
    }

    $prefixToken = $tokens[$prefixIndex];

    // A group use is exactly: <prefix> \ { -- anything else is an ordinary import the generic pass
    // already handles correctly.
    if (! in_array($prefixToken->id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
        continue;
    }

    if ($tokens[$separatorIndex]->id !== T_NS_SEPARATOR || $tokens[$braceIndex]->text !== '{') {
        continue;
    }

    $prefix = ltrim($prefixToken->text, '\\');
    $consumed[$prefixIndex] = true;

    $member = $cursor + 3;
    $skipAlias = false;

    while ($member < $count) {
        $memberIndex = $significant[$member];
        $memberToken = $tokens[$memberIndex];

        if ($memberToken->text === '}') {
            break;
        }

        if ($memberToken->id === T_AS) {
            // The identifier after `as` is a local alias, not a namespace segment.
            $skipAlias = true;
            $member++;

            continue;
        }

        if (in_array($memberToken->id, [T_STRING, T_NAME_QUALIFIED], true)) {
            $consumed[$memberIndex] = true;

            if ($skipAlias) {
                $skipAlias = false;
            } else {
                $qualifiedNames[] = $prefix.'\\'.$memberToken->text;
            }
        }

        $member++;
    }

    $k = $member;
}

foreach ($tokens as $index => $token) {
    if (isset($consumed[$index])) {
        continue;
    }

    // T_NAME_RELATIVE (`namespace\Foo`) is deliberately excluded: it always resolves relative to
    // the current file's own namespace, so it can never name a vendor root.
    if (in_array($token->id, [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
        $qualifiedNames[] = ltrim($token->text, '\\');
    }
}

$illuminateSegments = [];
$vendorRoots = [];

foreach (array_unique($qualifiedNames) as $qualifiedName) {
    $segments = explode('\\', $qualifiedName);

    if (count($segments) < 2) {
        continue;
    }

    $root = $segments[0];

    if ($root === 'Illuminate') {
        $illuminateSegments[] = $segments[1];

        continue;
    }

    if (in_array($root, ['ReyemTech', 'HubSpot'], true)) {
        continue;
    }

    $vendorRoots[] = $root;
}

// Direction A: every Illuminate\<Segment> root, mapped to the require key it must be backed by.
foreach (array_unique($illuminateSegments) as $segment) {
    $package = 'illuminate/'.strtolower($segment);

    if (! in_array($package, $requireKeys, true)) {
        echo "DIRECTION_A\t{$package}\t{$file}\n";
    }
}

// Direction B: every other vendor namespace root with at least two segments. Filtering against
// the grandfather list happens in the caller, not here, so both the real scan and --self-test
// apply the identical, single filtering step.
foreach (array_unique($vendorRoots) as $root) {
    echo "DIRECTION_B\t{$root}\t{$file}\n";
}
PHP

# The atomic per-file detection primitive, shared by the real scan and --self-test: given one PHP
# file and one composer.json, print every raw finding for BOTH directions (Direction B is not yet
# filtered against the grandfather list -- callers do that, identically, in both paths).
detect_violations() {
    local file="$1"
    local composer_json="$2"

    php "$HELPER_PHP" "$file" "$composer_json"
}

scan_tree() {
    local composer_json="$ROOT/composer.json"
    local violations_a=()
    local violations_b=()
    local file
    local direction
    local package_or_root
    local source_file

    while IFS= read -r file; do
        [ -f "$file" ] || continue

        while IFS=$'\t' read -r direction package_or_root source_file; do
            [ -z "$direction" ] && continue

            if [ "$direction" = "DIRECTION_A" ]; then
                violations_a+=("${source_file}: imports an Illuminate root with no backing require -- add \"${package_or_root}\" to composer.json's require block")
            elif [ "$direction" = "DIRECTION_B" ] && ! is_grandfathered_root "$package_or_root"; then
                violations_b+=("${source_file}: imports vendor root \"${package_or_root}\", which is neither package-owned, HubSpot, Illuminate, nor on the enumerated grandfather list")
            fi
        done < <(detect_violations "$file" "$composer_json")
    done < <(git ls-files -- 'src/*.php')

    if [ "${#violations_a[@]}" -gt 0 ] || [ "${#violations_b[@]}" -gt 0 ]; then
        echo "Vendor-namespace check FAILED:" >&2
        for v in "${violations_a[@]}"; do
            echo "  [Direction A] ${v}" >&2
        done
        for v in "${violations_b[@]}"; do
            echo "  [Direction B] ${v}" >&2
        done

        return 1
    fi

    echo "Vendor-namespace check passed: every Illuminate root referenced under src/ is backed by a declared require, and no unapproved third-party vendor root appears there."

    return 0
}

self_test() {
    local tmp_dir
    tmp_dir="$(mktemp -d "${TMPDIR:-/tmp}/vendor-namespace-self-test.XXXXXX")"
    trap 'rm -rf "${tmp_dir}"' RETURN

    local scratch_composer="${tmp_dir}/composer.json"
    cat > "$scratch_composer" <<'JSON'
{
    "require": {
        "php": "^8.3",
        "hubspot/api-client": "^14.1",
        "illuminate/contracts": "^12.0|^13.0",
        "illuminate/support": "^12.0|^13.0",
        "illuminate/database": "^12.0|^13.0",
        "illuminate/view": "^12.0|^13.0",
        "illuminate/queue": "^12.0|^13.0",
        "illuminate/bus": "^12.0|^13.0",
        "illuminate/collections": "^12.0|^13.0",
        "illuminate/console": "^12.0|^13.0",
        "laravel/prompts": "^0.3.21"
    }
}
JSON

    local scratch_direction_a="${tmp_dir}/UndeclaredIlluminateRoot.php"
    local scratch_direction_b="${tmp_dir}/ThirdPartyVendorInSrc.php"
    local scratch_clean="${tmp_dir}/Clean.php"

    cp "$FIXTURES_DIR/UndeclaredIlluminateRoot.php" "$scratch_direction_a"
    cp "$FIXTURES_DIR/ThirdPartyVendorInSrc.php" "$scratch_direction_b"

    cat > "$scratch_clean" <<'PHP'
<?php

declare(strict_types=1);

/**
 * Self-test-only fixture (scripts/ci/check-vendor-namespaces.sh) -- never production code.
 * References no vendor namespace at all, proving --self-test's clean-file case: a gate that
 * matches everything would reject this too, and this asserts it does not.
 */

namespace ReyemTech\Hubspot\Sync;

final class Clean
{
    public function noop(): void
    {
    }
}
PHP

    local failed=0

    local direction_a_output
    direction_a_output="$(detect_violations "$scratch_direction_a" "$scratch_composer")"
    if ! echo "$direction_a_output" | grep -q '^DIRECTION_A'; then
        echo "Self-test FAILED: Direction A did not reject UndeclaredIlluminateRoot.php." >&2
        failed=1
    fi

    local direction_b_output
    direction_b_output="$(detect_violations "$scratch_direction_b" "$scratch_composer")"
    local direction_b_rejected=0
    while IFS=$'\t' read -r direction package_or_root source_file; do
        [ "$direction" = "DIRECTION_B" ] || continue
        if ! is_grandfathered_root "$package_or_root"; then
            direction_b_rejected=1
        fi
    done <<< "$direction_b_output"
    if [ "$direction_b_rejected" -ne 1 ]; then
        echo "Self-test FAILED: Direction B did not reject ThirdPartyVendorInSrc.php." >&2
        failed=1
    fi

    local clean_output
    clean_output="$(detect_violations "$scratch_clean" "$scratch_composer")"
    if [ -n "$clean_output" ]; then
        echo "Self-test FAILED: a clean file with no vendor-namespace reference at all was rejected:" >&2
        echo "$clean_output" >&2
        failed=1
    fi

    # PHPUnit is approved only under src/Testing/ -- named from production code
    # (src/Testing/RequestLog.php) with no backing require (declaring phpunit/phpunit would ship a
    # test framework to every consumer's production vendor tree). The scoping itself is what makes
    # the exception safe, so both halves are asserted: named inside src/Testing/ is accepted, named
    # anywhere else in src/ still fails the gate exactly like an unapproved root would.
    mkdir -p "${tmp_dir}/src/Testing" "${tmp_dir}/src/Registry"

    local scratch_phpunit_scoped_ok="${tmp_dir}/src/Testing/PhpUnitScopedOk.php"
    cat > "$scratch_phpunit_scoped_ok" <<'PHP'
<?php

declare(strict_types=1);

/**
 * Self-test-only fixture (scripts/ci/check-vendor-namespaces.sh) -- never production code.
 * Named under src/Testing/, exactly where the PHPUnit exception is scoped to.
 */

namespace ReyemTech\Hubspot\Testing;

use PHPUnit\Framework\Assert as PHPUnitAssert;

final class PhpUnitScopedOk
{
    public function assertSomething(mixed $actual): void
    {
        PHPUnitAssert::assertNotNull($actual);
    }
}
PHP

    local scratch_phpunit_scoped_bad="${tmp_dir}/src/Registry/PhpUnitOutsideTesting.php"
    cat > "$scratch_phpunit_scoped_bad" <<'PHP'
<?php

declare(strict_types=1);

/**
 * Self-test-only fixture (scripts/ci/check-vendor-namespaces.sh) -- never production code.
 * Named OUTSIDE src/Testing/ on purpose: the PHPUnit exception must not reach here.
 */

namespace ReyemTech\Hubspot\Registry;

use PHPUnit\Framework\Assert as PHPUnitAssert;

final class PhpUnitOutsideTesting
{
    public function assertSomething(mixed $actual): void
    {
        PHPUnitAssert::assertNotNull($actual);
    }
}
PHP

    local phpunit_ok_output
    phpunit_ok_output="$(detect_violations "$scratch_phpunit_scoped_ok" "$scratch_composer")"
    local phpunit_ok_rejected=0
    while IFS=$'\t' read -r direction package_or_root source_file; do
        [ "$direction" = "DIRECTION_B" ] || continue
        if ! is_grandfathered_root "$package_or_root"; then
            phpunit_ok_rejected=1
        fi
    done <<< "$phpunit_ok_output"
    if [ "$phpunit_ok_rejected" -ne 0 ]; then
        echo "Self-test FAILED: PHPUnit named under src/Testing/ was rejected, but the exception is" >&2
        echo "scoped to admit it there." >&2
        failed=1
    fi

    local phpunit_bad_output
    phpunit_bad_output="$(detect_violations "$scratch_phpunit_scoped_bad" "$scratch_composer")"
    local phpunit_bad_rejected=0
    while IFS=$'\t' read -r direction package_or_root source_file; do
        [ "$direction" = "DIRECTION_B" ] || continue
        if ! is_grandfathered_root "$package_or_root"; then
            phpunit_bad_rejected=1
        fi
    done <<< "$phpunit_bad_output"
    if [ "$phpunit_bad_rejected" -ne 1 ]; then
        echo "Self-test FAILED: PHPUnit named outside src/Testing/ was accepted; the exception must" >&2
        echo "not reach beyond the one directory it is scoped to." >&2
        failed=1
    fi

    # Group use, all three ways. The accept half proves the gate does not reject a legal
    # `use Illuminate\{Console\Command, Support\Carbon};`; the reject halves prove it did not buy
    # that by going blind, which is the failure mode that matters -- before this was handled, a
    # violation inside a group use was not misfiled, it was invisible, because the token said
    # `Cache`, not `Illuminate`.
    #
    # These are heredocs rather than committed fixtures, unlike Direction A's and B's, for a
    # deliberate reason: pint.json applies the `laravel` preset repo-wide with no exclusions, and
    # that preset includes `single_import_per_statement`. A committed fixture using group-use
    # syntax would be rewritten by the formatter and fail `pint --test` in CI. That same fact is
    # why this blind spot was latent rather than live -- Pint rejects a grouped import in src/
    # before this gate ever sees it. The gate is fixed anyway: its correctness should not depend
    # on another tool's style preset continuing to forbid the syntax.
    local scratch_group_ok="${tmp_dir}/GroupUseImports.php"
    cat > "$scratch_group_ok" <<'PHP'
<?php

declare(strict_types=1);

/**
 * Self-test-only fixture -- never production code, and never committed as a .php file, because
 * Pint's laravel preset would rewrite its grouped imports.
 *
 * Every root named here IS declared and every alias is a local name rather than a namespace
 * segment, so this file must produce no finding in either direction.
 */

namespace ReyemTech\Hubspot\Sync;

use Illuminate\{Console\Command, Support\Carbon};
use Illuminate\Contracts\Bus\{Dispatcher as Bus};
use Illuminate\Database\{Connection, QueryException};

final class GroupUseImports extends Command
{
    public function __construct(private Connection $connection, private Bus $bus)
    {
        parent::__construct();
    }

    public function stampedAt(): Carbon
    {
        return Carbon::now();
    }

    /**
     * @throws QueryException
     */
    public function touch(): void
    {
        $this->connection->statement('select 1');
    }
}
PHP

    local group_ok_output
    group_ok_output="$(detect_violations "$scratch_group_ok" "$scratch_composer")"
    if [ -n "$group_ok_output" ]; then
        echo "Self-test FAILED: a legal grouped import of declared roots was rejected:" >&2
        echo "$group_ok_output" >&2
        failed=1
    fi

    local scratch_group_a="${tmp_dir}/GroupUseHidesUndeclared.php"
    cat > "$scratch_group_a" <<'PHP'
<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Sync;

use Illuminate\{Cache\Repository, Support\Carbon};

final class GroupUseHidesUndeclared
{
    public function __construct(private Repository $cache, private Carbon $at) {}
}
PHP

    # $'\t' is required: in a POSIX grep pattern '\t' is a literal "t", so a plain-quoted pattern
    # here silently never matches and the assertion passes vacuously. Caught by this self-test
    # failing when the behaviour it checks was already correct.
    if ! detect_violations "$scratch_group_a" "$scratch_composer" | grep -q "^DIRECTION_A"$'\t'"illuminate/cache"; then
        echo "Self-test FAILED: Direction A missed an undeclared Illuminate root hidden inside a group use." >&2
        failed=1
    fi

    local scratch_group_b="${tmp_dir}/GroupUseHidesThirdParty.php"
    cat > "$scratch_group_b" <<'PHP'
<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Sync;

use Symfony\{Component\Console\Input\InputInterface};

final class GroupUseHidesThirdParty
{
    public function __construct(private InputInterface $input) {}
}
PHP

    if ! detect_violations "$scratch_group_b" "$scratch_composer" | grep -q "^DIRECTION_B"$'\t'"Symfony"; then
        echo "Self-test FAILED: Direction B missed a third-party vendor root hidden inside a group use." >&2
        failed=1
    fi

    if [ "$failed" -ne 0 ]; then
        return 1
    fi

    echo "Self-test passed: Direction A rejects an undeclared Illuminate root, Direction B rejects an unapproved third-party vendor root, a clean file is accepted by both, and group use is handled in all three ways -- legal imports accepted, and violations hidden inside a group still rejected in both directions."

    return 0
}

if [ "${1:-}" = "--self-test" ]; then
    self_test
    exit $?
fi

scan_tree
exit $?
