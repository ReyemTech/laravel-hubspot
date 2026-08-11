<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use ReyemTech\Hubspot\Webhooks\WebhookEventClaim;

/**
 * The opt-in, false-by-default durable claim/complete/prune record (HOOK-01's durable half,
 * HOOK-03's audit table). `HUBSPOT_WEBHOOKS=true`.
 *
 * **This file is executable where it sits, not a `.php.stub`.** Same reasoning as the association-
 * types and object-links migrations beside it: Laravel's migrator globs a registered path for
 * `*_*.php` and never discovers a `.stub`, and this package both loads the file (when
 * `hubspot.webhooks.enabled` is true) and publishes it unconditionally (for teams that want to own
 * it). Its own subdirectory -- `database/migrations/webhooks/` -- is what lets
 * `ServiceProvider::migrationGroups()` gate it as a THIRD, independent group, on a config key none
 * of the other two groups read.
 *
 * ## One table, not two (05-02-PLAN.md Task 1 decision)
 *
 * The audit trail HOOK-03 asks for and the durable claim D-01/D-03 requires are the SAME row rather
 * than two tables joined together: an operator inspecting one record sees both why an event was
 * skipped and whether it was ever handled, and there is exactly one migration and one retention
 * policy to keep in step against the one-way-door cost D-02 names.
 *
 * ## Every constraint below is validated before a value ever reaches this table
 *
 * `string('event_id', 191)`: 191 is the historical MySQL-safe VARCHAR width for a UNIQUE index
 * under `utf8mb4` (191 * 4 = 764 bytes, inside the legacy 767-byte index-prefix limit some
 * supported MySQL configurations still enforce), matching the width `subscription_type` below also
 * uses. `Webhooks\NormalizedWebhookEvent::MAX_EVENT_ID_LENGTH` enforces the identical ceiling at
 * normalization time and REJECTS an over-long id rather than truncating it (T-05-11, threat
 * register): a truncated value could silently alias two distinct HubSpot events onto the same
 * dedupe row, which is exactly the silent-wrong-id failure class this package exists to prevent.
 *
 * **`event_id` is not the only one, and the general rule is not about aliasing.** `WebhookController`
 * answers `204` before any worker attempts the INSERT below, so a value this table cannot hold is
 * not a failed request -- it is an ACKNOWLEDGED delivery that no longer exists, found in a worker
 * log after HubSpot has stopped retrying. So `NormalizedWebhookEvent` bounds every field that a
 * constraint here applies to: `subscription_type` and `object_id` to their widths
 * (`MAX_SUBSCRIPTION_TYPE_LENGTH`, `MAX_OBJECT_ID_LENGTH`), `portal_id` and `subscription_id` to
 * the non-negative range their UNSIGNED declaration allows, and `occurred_at` to the span its
 * `DATETIME` holds. Adding a constrained column here means adding its check there. A NUL byte is
 * refused in the three string fields for the same reason, PostgreSQL rejecting one outright.
 *
 * ## `occurred_at` is a `DATETIME`; every other instant here is a `timestamp()`
 *
 * The difference is whose value it is. `claimed_at`, `handled_at` and `timestamps()` are stamped
 * by this package at write time -- always "now", so `TIMESTAMP`'s 2038 ceiling is a distant,
 * Laravel-wide concern and not this table's. `occurred_at` is the one column whose value arrives
 * from OUTSIDE, in a signed payload nobody local chose, so an out-of-range instant is reachable
 * today: under `TIMESTAMP` a delivery dated past 2038 was answered `204` and then lost when the
 * worker's INSERT failed. Bounding normalization at 2038 instead would have been the same defect
 * wearing a different hat -- a 2039 event is well formed, and refusing it breaks the package in
 * 2039 rather than merely losing an event. This deviates from 05-02-PLAN.md Task 1, which named
 * `timestamp('occurred_at')`; the migration is in no released tag and the column is written but
 * never read back by package code, so the change costs nothing.
 *
 * ## The claim lease, not a bare status column
 *
 * `claimed_at` and `handled_at` are two nullable timestamps rather than one status enum, because the
 * FSM this table backs
 * ({@see WebhookEventClaim}) needs to answer "is this claim still
 * inside its lease window" as a comparison against `hubspot.webhooks.claim_lease`
 * (`Webhooks\Stores\DatabaseWebhookEventStore::resolveExistingClaim()`), which a status column alone
 * cannot express. `attempts` is incremented only when a claim is genuinely reclaimed after its
 * lease expired -- it is an operator-visible signal that a worker died holding this event at least
 * once, not a retry counter for ordinary redelivery (a redelivery of an already-handled event never
 * touches this column at all).
 *
 * ## `payload` is nullable, and empty unless explicitly opted in
 *
 * `hubspot.webhooks.audit_payload` defaults false (T-05-07, threat register): the normalized item
 * carries the consumer's OWN customers' personal data, so persisting it is an opt-in decision an
 * operator makes, never a default this package chooses on their behalf. The raw request body, the
 * signature header and the configured client secret are never columns on this table at all --
 * `Webhooks\Stores\DatabaseWebhookEventStore::payloadFor()` builds this column only from
 * `NormalizedWebhookEvent`'s own package-owned fields, so there is nothing here that could carry
 * any of the three even if a future change made the payload column the default.
 *
 * ## The two indexes, and why there is no third
 *
 * `handled_at` is indexed alone because `hubspot:webhooks:prune` filters on it directly
 * (`WHERE handled_at IS NOT NULL AND handled_at < ?`) against a table that, left unpruned, is the
 * one place this package writes one row per inbound webhook item forever (D-04, T-05-08). The
 * composite `(subscription_type, occurred_at)` index is for an operator inspecting recent activity
 * by event family -- the same operator-inspection role `(subscription_type, occurred_at)`-shaped
 * indexes play elsewhere in this package's migrations. `event_id` needs no separate index beyond its
 * own UNIQUE constraint, which both enforces D-01's one-row-per-event invariant and satisfies every
 * lookup `claim()` and `complete()` perform.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hubspot_webhook_events', function (Blueprint $table): void {
            $table->id();

            // The delivery identity the claim is keyed on -- see
            // NormalizedWebhookEvent::deliveryIdentity(). A fixed 64 hex characters, so the unique
            // index below has none of the width trouble that bounds `event_id` to 191.
            $table->char('delivery_hash', 64);

            $table->string('event_id', 191);
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->string('subscription_type', 191);
            $table->unsignedBigInteger('portal_id');
            $table->string('object_id')->nullable();
            // A DATETIME, not a timestamp() like the columns below it -- see the class docblock.
            // This is the one column here whose value arrives from outside in a signed payload,
            // so TIMESTAMP's 2038 ceiling was reachable today rather than in 2038.
            $table->datetime('occurred_at')->nullable();

            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('handled_at')->nullable();

            // Opt-in only -- see the class docblock. Never the raw request body, the signature
            // header, or the client secret.
            $table->json('payload')->nullable();

            $table->timestamps();

            // UNIQUE on the delivery identity, not on `event_id`. HubSpot documents that
            // "This value is not guaranteed to be unique" (Webhooks v3 API guide, checked
            // 2026-08-11), so a unique index on it alone collapsed two distinct events into one
            // and discarded the second as a redelivery. `event_id` keeps a plain index: it is what
            // an operator searches by, and what every log line and error message names.
            $table->unique('delivery_hash');
            $table->index('event_id');
            $table->index('handled_at');
            $table->index(['subscription_type', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hubspot_webhook_events');
    }
};
