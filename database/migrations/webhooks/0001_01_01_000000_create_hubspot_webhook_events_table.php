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
 * ## `event_id` is bound to a fixed width, and validated before it ever reaches this table
 *
 * `string('event_id', 191)`: 191 is the historical MySQL-safe VARCHAR width for a UNIQUE index
 * under `utf8mb4` (191 * 4 = 764 bytes, inside the legacy 767-byte index-prefix limit some
 * supported MySQL configurations still enforce), matching the width `subscription_type` below also
 * uses. `Webhooks\NormalizedWebhookEvent::MAX_EVENT_ID_LENGTH` enforces the identical ceiling at
 * normalization time and REJECTS an over-long id rather than truncating it (T-05-11, threat
 * register): a truncated value could silently alias two distinct HubSpot events onto the same
 * dedupe row, which is exactly the silent-wrong-id failure class this package exists to prevent.
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

            $table->string('event_id', 191);
            $table->string('subscription_type', 191);
            $table->unsignedBigInteger('portal_id');
            $table->string('object_id')->nullable();
            $table->timestamp('occurred_at')->nullable();

            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('handled_at')->nullable();

            // Opt-in only -- see the class docblock. Never the raw request body, the signature
            // header, or the client secret.
            $table->json('payload')->nullable();

            $table->timestamps();

            $table->unique('event_id');
            $table->index('handled_at');
            $table->index(['subscription_type', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hubspot_webhook_events');
    }
};
