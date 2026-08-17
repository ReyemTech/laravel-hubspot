<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The `local` `SignalStore` driver's event-history table (SIG-07, D-06 trail half).
 * `HUBSPOT_SIGNALS=true`.
 *
 * **This file is executable where it sits, not a `.php.stub`.** Same reasoning as the buffer
 * migration beside it: Laravel's migrator globs a registered path for `*_*.php` and never
 * discovers a `.stub`, and this package both loads the file (when `hubspot.signals.enabled` is
 * true — the SAME flag the buffer migration gates on, since both live in the same
 * `database/migrations/signals` group) and publishes it unconditionally (for teams that want to
 * own it).
 *
 * ## The unique key on `hubspot_signal_id`, and exactly what it buys
 *
 * `unsignedBigInteger('hubspot_signal_id')` carries a UNIQUE index, keyed on the `hubspot_signals`
 * row a trail entry came from. That is what makes re-appending the same source row a no-op: a
 * retried flush that attempts to append a row already recorded here hits a duplicate-key outcome
 * instead of a second row, so a retried flush redoes idempotent work rather than double-recording
 * an event (D-06, unchanged by the 2026-08-12 revision).
 *
 * **It is one-way** (D-06's own reversibility rating): this column and its unique index ship in
 * this migration, so changing either later needs a migration against installed data.
 *
 * **It is NOT what makes two overlapping flushes safe.** A unique key on the source row's own
 * identity makes a RETRY of the SAME append harmless, because a retry re-appends the same input.
 * It does nothing to order the roll-up PROPERTY WRITE between two workers computing over different
 * row sets — that is the lost-update scenario D-06's 2026-08-12 revision exists to close, and the
 * fix for it is a separate, subject-level atomic claim around calculate-and-write, living on the
 * flush path in plan 06-06, never here. A docblock claiming this table closes that gap would be the
 * exact over-generalisation the first draft of D-06 shipped and automated review caught.
 *
 * ## Every constrained column has its check at the point data enters
 *
 * `string('subject_type', 191)`, `string('subject_id', 191)` and `string('signal_name', 191)`: 191
 * is the historical MySQL-safe VARCHAR width under `utf8mb4` (191 * 4 = 764 bytes, inside the
 * legacy 767-byte index-prefix limit some supported MySQL configurations still enforce), matching
 * the identical width the `hubspot_signals` buffer migration beside this one already uses for its
 * own `subject_type`/`subject_id`/`signal_name` columns. `Signals\Stores\LocalSignalStore::append()`
 * bounds all three in BYTES against these exact widths BEFORE the INSERT and throws rather than
 * truncating (PR #71's class, applied here), and refuses a NUL byte in any of the three outright —
 * PostgreSQL rejects one in a `text`/`varchar` value regardless. `hubspot_signal_id` is refused
 * when negative before the INSERT for the identical PR #71 reason `NormalizedWebhookEvent::unsigned()`
 * already states for its own unsigned columns: MySQL's strict mode rejects a negative value in an
 * UNSIGNED column while PostgreSQL and SQLite, which have no unsigned integer type, would silently
 * accept it — so the SAME correctly-formed call would behave three different ways across this
 * package's support matrix if the check were left to the database alone.
 *
 * ## `occurred_at` is a `DATETIME`; `created_at`/`updated_at` are `timestamp()`
 *
 * The difference is whose value it is — the identical split the buffer migration's own docblock
 * states. `created_at`/`updated_at` are stamped by this package at write time, always "now", so
 * `TIMESTAMP`'s 2038 ceiling is a distant, Laravel-wide concern and not this table's. `occurred_at`
 * is copied verbatim from the `hubspot_signals` row this trail entry came from, and that value can
 * itself have arrived from OUTSIDE this process (a caller-supplied instant for a signal recorded
 * after the fact), so an out-of-range value is reachable today rather than only in 2038.
 *
 * ## `properties` is nullable, and populated only when explicitly opted in
 *
 * `hubspot.signals.trail_payload` defaults false, mirroring `hubspot.webhooks.audit_payload`'s own
 * default and its stated reason exactly: this column would carry the consumer's OWN customers'
 * behavioural data — visitor ids, ad click identifiers, referrer and page-path values, all tied to
 * an identified person by the time a row reaches this table — and this table's retention is
 * UNBOUNDED until Phase 7 ships `hubspot:signals:prune`. A package that defaulted this column
 * populated would be an opt-OUT data-retention decision made on the operator's behalf; shipping the
 * column now, nullable and unpopulated by default, is what lets an operator turn it on later with
 * no migration against installed data. `Signals\Stores\LocalSignalStore` builds this column only
 * from the properties `Hubspot::signal()` itself already recorded — nothing from `Gateway`, no raw
 * HubSpot response, ever reaches it.
 *
 * ## The two indexes, and why there is no third
 *
 * The UNIQUE index on `hubspot_signal_id` both enforces the idempotence guarantee above and
 * satisfies the only lookup `append()` itself performs. The composite `(subject_type, subject_id)`
 * index is for an operator inspecting one subject's own recorded event history — the same
 * operator-inspection role indexed columns play elsewhere in this package's migrations, and the
 * one lookup a future Phase 7 driver comparison or an ad-hoc audit query would actually run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hubspot_signal_trail', function (Blueprint $table): void {
            $table->id();

            // The `hubspot_signals` row this trail entry came from. UNIQUE, not merely indexed --
            // see the class docblock for exactly what that buys and what it does not.
            $table->unsignedBigInteger('hubspot_signal_id');

            $table->string('subject_type', 191);
            $table->string('subject_id', 191);
            $table->string('signal_name', 191);

            // Nullable, and populated only when hubspot.signals.trail_payload is true -- see the
            // class docblock. Never the raw Gateway response, only the properties Hubspot::signal()
            // itself already recorded.
            $table->json('properties')->nullable();

            // A DATETIME, not a timestamp() like the columns below it -- copied verbatim from the
            // hubspot_signals row this entry came from, and that value can itself have arrived from
            // outside this process. See the class docblock.
            $table->datetime('occurred_at');

            $table->timestamps();

            $table->unique('hubspot_signal_id');
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hubspot_signal_trail');
    }
};
