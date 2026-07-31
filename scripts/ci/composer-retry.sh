#!/usr/bin/env bash
#
# Runs a composer command, retrying with exponential backoff.
#
# `composer.lock` is gitignored, deliberately: a library's lock never constrains its consumers,
# and the CI matrix tests the declared range's boundaries instead, which is the stronger check.
# The cost of that decision is that EVERY job in this repository resolves its dependency set from
# packagist on every run -- there is no lock to install from and no offline path. A packagist
# outage is therefore not one flaky job, it is every job at once: on 2026-07-31 a run of PR #44
# lost eight checks to a single HTTP 502 on `symfony/clock.json`, none of which had reached any
# of this project's own code.
#
# Retrying is the proportionate answer. It does not paper over a real failure -- an unsatisfiable
# constraint fails on the last attempt exactly as it failed on the first, only later. What it
# removes is the case where a transient upstream 5xx is indistinguishable, in the checks UI, from
# a defect in the diff, which is a bad thing for a repository whose merge rule is "green or it
# does not merge".
#
# Deliberately NOT narrowed to network-shaped error text: matching composer's failure messages
# would have to be kept in step with composer's wording, and being wrong in that direction means
# not retrying an outage -- the exact failure this exists to prevent. Retrying everything costs a
# slow failure on a genuine one, which is the cheaper mistake.
#
# Usage:  bash scripts/ci/composer-retry.sh install --prefer-dist --no-interaction
#         bash scripts/ci/composer-retry.sh update --with vendor/pkg:1.* --prefer-stable
#         bash scripts/ci/composer-retry.sh --self-test
#
# Env:    COMPOSER_BIN                 binary to invoke (default: composer; the self-test's seam)
#         COMPOSER_RETRY_ATTEMPTS      total attempts, including the first (default: 4)
#         COMPOSER_RETRY_BASE_DELAY    seconds before the first retry, doubling (default: 10)

set -uo pipefail

COMPOSER_BIN="${COMPOSER_BIN:-composer}"
COMPOSER_RETRY_ATTEMPTS="${COMPOSER_RETRY_ATTEMPTS:-4}"
COMPOSER_RETRY_BASE_DELAY="${COMPOSER_RETRY_BASE_DELAY:-10}"

composer_with_retries() {
    local delay="$COMPOSER_RETRY_BASE_DELAY"
    local attempt

    for (( attempt = 1; attempt <= COMPOSER_RETRY_ATTEMPTS; attempt++ )); do
        if "$COMPOSER_BIN" "$@"; then
            if (( attempt > 1 )); then
                echo "::notice title=composer recovered::'${COMPOSER_BIN} $*' succeeded on attempt ${attempt}/${COMPOSER_RETRY_ATTEMPTS}."
            fi

            return 0
        fi

        if (( attempt == COMPOSER_RETRY_ATTEMPTS )); then
            break
        fi

        echo "::warning title=composer retry::'${COMPOSER_BIN} $*' failed on attempt ${attempt}/${COMPOSER_RETRY_ATTEMPTS}; retrying in ${delay}s."
        sleep "$delay"
        delay=$(( delay * 2 ))
    done

    echo "::error title=composer failed::'${COMPOSER_BIN} $*' failed ${COMPOSER_RETRY_ATTEMPTS} times. This is a real failure, not a retryable one."

    return 1
}

# Proves the loop is not a no-op in either direction: that a command failing fewer times than the
# attempt budget still succeeds overall, and that one failing every time still fails overall. A
# retry wrapper that silently always-succeeded or never-retried would look identical to this one
# in a green run, which is exactly the kind of gate this repository refuses to take on trust.
self_test() {
    local workdir status
    workdir="$(mktemp -d)"
    # shellcheck disable=SC2064
    trap "rm -rf '${workdir}'" EXIT

    cat > "${workdir}/flaky" <<'FAKE'
#!/usr/bin/env bash
count_file="${FAKE_COUNT_FILE}"
count=$(( $(cat "${count_file}" 2>/dev/null || echo 0) + 1 ))
echo "${count}" > "${count_file}"
if (( count < 3 )); then
    echo "simulated transient failure ${count}"
    exit 1
fi
echo "simulated success on attempt ${count}"
FAKE

    cat > "${workdir}/broken" <<'FAKE'
#!/usr/bin/env bash
echo "simulated permanent failure"
exit 1
FAKE

    chmod +x "${workdir}/flaky" "${workdir}/broken"

    export FAKE_COUNT_FILE="${workdir}/count"
    export COMPOSER_RETRY_ATTEMPTS=4
    export COMPOSER_RETRY_BASE_DELAY=0

    COMPOSER_BIN="${workdir}/flaky" composer_with_retries install > /dev/null
    status=$?

    if (( status != 0 )); then
        echo "SELF-TEST FAILED: a command that succeeds on attempt 3 of 4 must succeed overall (got exit ${status})."
        return 1
    fi

    if [[ "$(cat "${FAKE_COUNT_FILE}")" != "3" ]]; then
        echo "SELF-TEST FAILED: expected exactly 3 invocations, got $(cat "${FAKE_COUNT_FILE}"). The loop is not retrying."
        return 1
    fi

    COMPOSER_BIN="${workdir}/broken" composer_with_retries install > /dev/null
    status=$?

    if (( status == 0 )); then
        echo "SELF-TEST FAILED: a command that never succeeds must fail overall. The wrapper is swallowing failures."
        return 1
    fi

    echo "composer-retry.sh self-test passed: retries a transient failure, and still fails a permanent one."

    return 0
}

if [[ "${1:-}" == "--self-test" ]]; then
    self_test
    exit $?
fi

if (( $# == 0 )); then
    echo "::error title=composer-retry::No composer command given. Usage: bash scripts/ci/composer-retry.sh install --prefer-dist --no-interaction"
    exit 2
fi

composer_with_retries "$@"
exit $?
