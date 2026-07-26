#!/usr/bin/env bash
#
# Governance gate for reyemtech/laravel-hubspot.
#
# Fails the build when a required repository-governance file is missing, or is
# present but has lost the specific content the standards require of it. Reports
# every failing file/field individually rather than a single generic failure, so
# a contributor (or a future edit to this script) can see exactly what regressed.
#
# See STANDARDS.md §10 (Security) and §12b (Repository governance).
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

errors=()

# --- SECURITY.md: must exist and must publish a private disclosure address ---
if [ ! -f "SECURITY.md" ]; then
    errors+=("SECURITY.md: missing")
else
    if ! grep -qE '[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}' "SECURITY.md"; then
        errors+=("SECURITY.md: no private disclosure email address found")
    fi
fi

# --- Dependabot: must exist ---
if [ ! -f ".github/dependabot.yml" ]; then
    errors+=("Dependabot config: missing (.github/dependabot.yml)")
fi

# --- CODEOWNERS: must exist ---
if [ ! -f ".github/CODEOWNERS" ]; then
    errors+=("CODEOWNERS: missing (.github/CODEOWNERS)")
fi

if [ "${#errors[@]}" -gt 0 ]; then
    echo "Repository governance check FAILED:" >&2
    for e in "${errors[@]}"; do
        echo "  - ${e}" >&2
    done
    exit 1
fi

echo "Repository governance check passed."
exit 0
