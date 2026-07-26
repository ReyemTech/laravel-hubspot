#!/usr/bin/env bash
set -euo pipefail

# scripts/ci/verify-arch-rules-fire.sh
#
# Proves every architecture rule in tests/Arch/rules.json is a *live* gate, not a rule
# passing vacuously over an empty namespace (see 01-RESEARCH.md "Pitfall 1"). For each
# rule it assembles a scratch copy of src/ with exactly that rule's violation
# fixture(s) merged in, points a single pest run at that scratch tree (via a
# ClassLoader PSR-4 override — see scripts/ci/arch-fire-bootstrap.php for why a scratch
# git-worktree-plus-symlinked-vendor was tried first and does not work), and asserts
# the run goes red. It never writes into the real working tree: every artefact this
# script creates lives under a mktemp directory that is removed on exit, success,
# failure, or signal.
#
# Exit codes:
#   0 - every rule in the manifest fired under its own fixture.
#   1 - at least one rule did not fire, a fixture was missing, or the manifest/fixture
#       agreement check itself failed. The failing rule id(s) are named on stdout.

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
RULES_FILE="$REPO_ROOT/tests/Arch/rules.json"
FIXTURES_DIR="$REPO_ROOT/tests/Arch/Fixtures"
BOOTSTRAP="$REPO_ROOT/scripts/ci/arch-fire-bootstrap.php"
ALL_RULE_TEST_FILES=(
  "tests/Arch/LayerBoundariesTest.php"
  "tests/Arch/StrictTypesTest.php"
  "tests/Arch/SecretLoggingTest.php"
)

# Only pass files that exist. Before task 2 lands the rules, none of these exist yet —
# that is the expected pre-rules state (every rule reports NOT FIRING), not a harness
# error. Passing zero path arguments to pest would fall back to phpunit.xml's default
# testsuite instead of running nothing, so guard against that explicitly below.
RULE_TEST_FILES=()
for FILE in "${ALL_RULE_TEST_FILES[@]}"; do
  if [ -f "$REPO_ROOT/$FILE" ]; then
    RULE_TEST_FILES+=("$FILE")
  fi
done

SCRATCH_ROOT=""
cleanup() {
  if [ -n "$SCRATCH_ROOT" ] && [ -d "$SCRATCH_ROOT" ]; then
    rm -rf "$SCRATCH_ROOT"
  fi
}
trap cleanup EXIT INT TERM

SCRATCH_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/arch-fire.XXXXXX")"

# --- Step 0: the manifest/fixture agreement check itself is part of the gate. -------
# An unfired rule and a missing fixture are the same defect (plan 01-04, task 1).
if ! (cd "$REPO_ROOT" && vendor/bin/pest tests/Arch/FiringHarnessTest.php > "$SCRATCH_ROOT/firing-harness-test.log" 2>&1); then
  echo "FAIL: tests/Arch/FiringHarnessTest.php did not pass — the rule manifest and the" >&2
  echo "      fixture directory disagree. Fix that before any rule can be proven to fire." >&2
  cat "$SCRATCH_ROOT/firing-harness-test.log" >&2
  exit 1
fi

# --- Step 1: enumerate the manifest. -------------------------------------------------
if [ ! -f "$RULES_FILE" ]; then
  echo "FAIL: rule manifest not found at $RULES_FILE" >&2
  exit 1
fi

MANIFEST_TSV="$SCRATCH_ROOT/manifest.tsv"
php -r '
$data = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
foreach ($data["rules"] as $rule) {
    echo $rule["id"] . "\t" . implode(",", $rule["fixtures"]) . "\n";
}
' "$RULES_FILE" > "$MANIFEST_TSV"

if [ ! -s "$MANIFEST_TSV" ]; then
  echo "FAIL: rule manifest at $RULES_FILE declares zero rules." >&2
  exit 1
fi

NOT_FIRING=()
ERRORED=()
FIRED_COUNT=0
TOTAL_COUNT=0

# --- Step 2: for each rule, build a scratch src/ with only that rule's fixture(s), --
#             point one pest run at it, and require the run to go red. ---------------
while IFS=$'\t' read -r RULE_ID FIXTURES_CSV; do
  TOTAL_COUNT=$((TOTAL_COUNT + 1))

  RULE_SRC="$SCRATCH_ROOT/src-$RULE_ID"
  mkdir -p "$RULE_SRC"
  # Start from the real, committed src/ tree so this stays correct once later phases
  # populate it with real code, then overlay this rule's violation fixture(s).
  if [ -d "$REPO_ROOT/src" ]; then
    cp -r "$REPO_ROOT/src/." "$RULE_SRC/"
  fi

  IFS=',' read -r -a FIXTURE_LIST <<< "$FIXTURES_CSV"
  for FIXTURE_REL in "${FIXTURE_LIST[@]}"; do
    FIXTURE_PATH="$FIXTURES_DIR/$FIXTURE_REL"
    if [ ! -f "$FIXTURE_PATH" ]; then
      echo "FAIL: $RULE_ID declares fixture '$FIXTURE_REL' but $FIXTURE_PATH does not exist." >&2
      ERRORED+=("$RULE_ID")
      continue 2
    fi

    NAMESPACE="$(grep -m1 -oP '^namespace \K[^;]+' "$FIXTURE_PATH" || true)"
    if [ -z "$NAMESPACE" ]; then
      echo "FAIL: $RULE_ID fixture '$FIXTURE_REL' declares no namespace; cannot place it." >&2
      ERRORED+=("$RULE_ID")
      continue 2
    fi

    PACKAGE_PREFIX='ReyemTech\Hubspot\'
    RELATIVE_NS="${NAMESPACE#"$PACKAGE_PREFIX"}"
    if [ "$RELATIVE_NS" = "$NAMESPACE" ]; then
      echo "FAIL: $RULE_ID fixture '$FIXTURE_REL' namespace '$NAMESPACE' is outside ReyemTech\\Hubspot\\." >&2
      ERRORED+=("$RULE_ID")
      continue 2
    fi

    RELATIVE_DIR="${RELATIVE_NS//\\//}"
    mkdir -p "$RULE_SRC/$RELATIVE_DIR"
    cp "$FIXTURE_PATH" "$RULE_SRC/$RELATIVE_DIR/$(basename "$FIXTURE_PATH")"
  done

  if [ "${#RULE_TEST_FILES[@]}" -eq 0 ]; then
    # None of the rule test files exist yet (the pre-task-2 state). No rule can
    # possibly be firing — report that plainly rather than invoking pest with zero
    # path arguments, which would silently fall back to phpunit.xml's default suite.
    echo "NOT FIRING: $RULE_ID — no rule test files exist yet." >&2
    NOT_FIRING+=("$RULE_ID")
    continue
  fi

  RUN_LOG="$SCRATCH_ROOT/run-$RULE_ID.log"
  set +e
  (
    cd "$REPO_ROOT"
    ARCH_FIRE_SCRATCH_SRC="$RULE_SRC" vendor/bin/pest \
      --bootstrap "$BOOTSTRAP" \
      --filter="${RULE_ID}:" \
      "${RULE_TEST_FILES[@]}"
  ) > "$RUN_LOG" 2>&1
  RUN_EXIT=$?
  set -e

  if [ "$RUN_EXIT" -eq 2 ] || grep -qi 'No tests found\|not found\|Fatal error\|Cannot redeclare' "$RUN_LOG"; then
    echo "ERROR: $RULE_ID — the run could not be evaluated (filter matched zero tests, a" >&2
    echo "       rule test file is missing, or the run crashed). See below:" >&2
    cat "$RUN_LOG" >&2
    ERRORED+=("$RULE_ID")
  elif [ "$RUN_EXIT" -eq 0 ]; then
    echo "NOT FIRING: $RULE_ID — the suite stayed green with this rule's fixture present." >&2
    NOT_FIRING+=("$RULE_ID")
  else
    FIRED_COUNT=$((FIRED_COUNT + 1))
  fi
done < "$MANIFEST_TSV"

echo "Architecture rule firing proof: $FIRED_COUNT/$TOTAL_COUNT rules fired."

if [ "${#NOT_FIRING[@]}" -gt 0 ] || [ "${#ERRORED[@]}" -gt 0 ]; then
  if [ "${#NOT_FIRING[@]}" -gt 0 ]; then
    echo "Rules that did NOT fire: ${NOT_FIRING[*]}" >&2
  fi
  if [ "${#ERRORED[@]}" -gt 0 ]; then
    echo "Rules that could not be verified: ${ERRORED[*]}" >&2
  fi
  exit 1
fi

exit 0
