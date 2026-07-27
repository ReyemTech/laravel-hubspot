#!/usr/bin/env bash
#
# FOUND-03 -- the association-inverse empirical probe (design spec Sec.6.4).
#
# Answers, by actually writing and reading data, a question HubSpot's own documentation does not
# state: does creating an association from A to B make it automatically readable from B to A?
#
# THIS SCRIPT IS NOT RUN AS PART OF ANY TEST SUITE AND IS NEVER A MERGE-BLOCKING GATE. It is a
# documented, opt-in, secret-gated procedure -- see docs/probes/association-inverse-probe.md for
# the full write-up, the safety notes, and the results table to fill in BY HAND from this script's
# own printed output. Do not guess the answer. Do not run this against a production portal.
#
# Usage:
#   HUBSPOT_PROBE_TOKEN=your-developer-test-account-token bash scripts/probes/association-inverse-probe.sh
#
# Requires: curl, jq. Talks to the HubSpot v3 objects / v4 associations REST endpoints directly
# via curl -- not hubspot/api-client. Two reasons: the Gateway layer (the only layer permitted to
# name HubSpot\* classes, per this repository's architecture rules) does not exist until Phase 2,
# and a probe script reaching for the SDK now would either violate that boundary or force a
# one-off exception to it on day one.

set -euo pipefail

HUBSPOT_API_BASE="https://api.hubapi.com"

fail() {
  echo "ERROR: $1" >&2
  exit 1
}

require_command() {
  command -v "$1" >/dev/null 2>&1 || fail "'$1' is required but not found on PATH."
}

require_command curl
require_command jq

if [ -z "${HUBSPOT_PROBE_TOKEN:-}" ]; then
  fail "HUBSPOT_PROBE_TOKEN is not set. This is the only missing input -- see docs/probes/association-inverse-probe.md. Never a production portal token."
fi

echo "=================================================================================="
echo " FOUND-03 association-inverse probe -- developer test account ONLY, never production"
echo "=================================================================================="
echo

DEAL_ID=""
CONTACT_ID=""

cleanup() {
  echo
  echo "--- Cleaning up test records ---"
  if [ -n "$DEAL_ID" ]; then
    curl --silent --show-error --request DELETE \
      --url "${HUBSPOT_API_BASE}/crm/v3/objects/deals/${DEAL_ID}" \
      --header "Authorization: Bearer ${HUBSPOT_PROBE_TOKEN}" \
      >/dev/null || echo "  (cleanup) could not archive deal ${DEAL_ID} -- remove it by hand."
  fi
  if [ -n "$CONTACT_ID" ]; then
    curl --silent --show-error --request DELETE \
      --url "${HUBSPOT_API_BASE}/crm/v3/objects/contacts/${CONTACT_ID}" \
      --header "Authorization: Bearer ${HUBSPOT_PROBE_TOKEN}" \
      >/dev/null || echo "  (cleanup) could not archive contact ${CONTACT_ID} -- remove it by hand."
  fi
  echo "--- Cleanup attempted. Verify by hand in the developer test portal if in doubt. ---"
}
trap cleanup EXIT

echo "--- Step 1: create a disposable test contact ---"
CONTACT_RESPONSE=$(curl --silent --show-error --request POST \
  --url "${HUBSPOT_API_BASE}/crm/v3/objects/contacts" \
  --header "Authorization: Bearer ${HUBSPOT_PROBE_TOKEN}" \
  --header "Content-Type: application/json" \
  --data '{"properties":{"email":"found-03-probe@example.test","firstname":"FOUND-03","lastname":"Probe"}}')
CONTACT_ID=$(echo "$CONTACT_RESPONSE" | jq -r '.id // empty')
[ -n "$CONTACT_ID" ] || fail "Could not create test contact. Response: ${CONTACT_RESPONSE}"
echo "  Created contact ${CONTACT_ID}"

echo
echo "--- Step 2: create a disposable test deal ---"
echo "  NOTE: 'dealstage' below may need to match your developer test portal's default pipeline"
echo "  stage id -- adjust if this call fails with an invalid dealstage error."
DEAL_RESPONSE=$(curl --silent --show-error --request POST \
  --url "${HUBSPOT_API_BASE}/crm/v3/objects/deals" \
  --header "Authorization: Bearer ${HUBSPOT_PROBE_TOKEN}" \
  --header "Content-Type: application/json" \
  --data '{"properties":{"dealname":"FOUND-03 probe deal","pipeline":"default","dealstage":"appointmentscheduled"}}')
DEAL_ID=$(echo "$DEAL_RESPONSE" | jq -r '.id // empty')
[ -n "$DEAL_ID" ] || fail "Could not create test deal. Response: ${DEAL_RESPONSE}"
echo "  Created deal ${DEAL_ID}"

echo
echo "--- Step 3: look up the real deals -> contacts association type (never hardcoded) ---"
LABELS_RESPONSE=$(curl --silent --show-error --request GET \
  --url "${HUBSPOT_API_BASE}/crm/v4/associations/deals/contacts/labels" \
  --header "Authorization: Bearer ${HUBSPOT_PROBE_TOKEN}")
echo "  Available deals -> contacts association types:"
echo "$LABELS_RESPONSE" | jq '.'

TYPE_ID=$(echo "$LABELS_RESPONSE" | jq -r '.results[0].typeId // empty')
CATEGORY=$(echo "$LABELS_RESPONSE" | jq -r '.results[0].category // empty')
[ -n "$TYPE_ID" ] && [ -n "$CATEGORY" ] || fail "Could not determine an association typeId/category from the portal. Response: ${LABELS_RESPONSE}"
echo "  Using typeId=${TYPE_ID}, category=${CATEGORY} for the write below."

echo
echo "--- Step 4: create the labelled deals -> contacts association ---"
ASSOCIATE_RESPONSE=$(curl --silent --show-error --request PUT \
  --url "${HUBSPOT_API_BASE}/crm/v4/objects/deals/${DEAL_ID}/associations/contacts/${CONTACT_ID}" \
  --header "Authorization: Bearer ${HUBSPOT_PROBE_TOKEN}" \
  --header "Content-Type: application/json" \
  --data "[{\"associationCategory\":\"${CATEGORY}\",\"associationTypeId\":${TYPE_ID}}]")
echo "$ASSOCIATE_RESPONSE" | jq '.'

echo
echo "--- Step 5: read back FROM THE WRITTEN DIRECTION (deals -> contacts) ---"
FORWARD_READ=$(curl --silent --show-error --request GET \
  --url "${HUBSPOT_API_BASE}/crm/v4/objects/deals/${DEAL_ID}/associations/contacts" \
  --header "Authorization: Bearer ${HUBSPOT_PROBE_TOKEN}")
echo "$FORWARD_READ" | jq '.'

echo
echo "--- Step 6: read back FROM THE INVERSE DIRECTION (contacts -> deals) -- THIS IS THE ANSWER ---"
INVERSE_READ=$(curl --silent --show-error --request GET \
  --url "${HUBSPOT_API_BASE}/crm/v4/objects/contacts/${CONTACT_ID}/associations/deals" \
  --header "Authorization: Bearer ${HUBSPOT_PROBE_TOKEN}")
echo "$INVERSE_READ" | jq '.'

echo
echo "=================================================================================="
echo " RESULT -- transcribe this block into docs/probes/association-inverse-probe.md by hand"
echo "=================================================================================="
echo "  Deal:               ${DEAL_ID}"
echo "  Contact:            ${CONTACT_ID}"
echo "  typeId used:        ${TYPE_ID} (category: ${CATEGORY})"
echo "  Forward read count: $(echo "$FORWARD_READ" | jq '.results | length')"
echo "  Inverse read count: $(echo "$INVERSE_READ" | jq '.results | length')"
echo
echo "  If the inverse read count above is greater than zero, the inverse association materialised"
echo "  automatically. If it is zero, it did not -- inverse_type_id becomes write-critical."
echo "  Do not summarize this away: record the raw JSON above in the results table too."
echo "=================================================================================="
