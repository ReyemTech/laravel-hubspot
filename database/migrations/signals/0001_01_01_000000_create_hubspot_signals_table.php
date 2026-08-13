<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The durable behavioural-signal buffer SIG-01 requires. `HUBSPOT_SIGNALS=true`.
 *
 * **This file is executable where it sits, not a `.php.stub`.** Same reasoning as the webhooks
 * migration beside it: Laravel's migrator globs a registered path for `*_*.php` and never
 * discovers a `.stub`, and this package both loads the file (when `hubspot.signals.enabled` is
 * true) and publishes it unconditionally (for teams that want to own it). Its own subdirectory --
 * `database/migrations/signals/` -- is what lets `ServiceProvider::migrationGroups()` gate it as a
 * FOURTH, independent group, on a config key none of the other three groups read.
 *
 * ## A cache-backed buffer was explicitly rejected
 *
 * Cache is evictable by definition, the `li_fat_id` case needs 90 days, and losing the buffer
 * loses the attribution the feature exists to protect. Better explicitly off than silently lossy.
 *
 * ## Every constrained column has its check at the point data enters
 *
 * `string('visitor_id', 191)` and `string('signal_name', 191)`: 191 is the historical MySQL-safe
 * VARCHAR width under `utf8mb4` (191 * 4 = 764 bytes, inside the legacy 767-byte index-prefix
 * limit some supported MySQL configurations still enforce), matching the width the webhooks
 * migration beside this one already uses for `event_id` and `subscription_type`.
 * `Signals\SignalRecorder::record()` bounds both values in BYTES against these exact widths
 * BEFORE the INSERT and throws rather than truncating -- a truncated `visitor_id` could silently
 * alias two distinct visitors onto the same buffer identity (PR #71's class, applied here).
 * `subject_type` and `subject_id` share the 191 width for the same MySQL-index reason, even though
 * neither is validated at the point data enters this table: both are package-controlled --
 * `subject_type` is always an Eloquent model's own `::class` string and `subject_id` its own
 * primary key cast to a string -- not application-supplied free text.
 *
 * ## `reconciled_properties` -- the read's VALUE, not only that it happened (PR #82 review)
 *
 * `reconciled_at` alone made "at most one read per subject, ever" durable, but not what the read
 * RETURNED: that lived only in the in-memory `$group` `SignalReconciler::reconcile()` built, so a
 * write that failed or a job that threw after the read (queue retry, worker death) recomputed the
 * next attempt from the buffer alone (D-40) and overwrote the portal value permanently -- the exact
 * loss `reconcile` exists to prevent. `reconciled_properties` is written in the SAME `update()` call
 * as `reconciled_at`, holding exactly what that read confirmed (only the non-empty values, keyed by
 * HubSpot property) -- see `Signals\SignalReconciler::reconcileChunk()`. A later flush (retry or
 * otherwise) merges it back in ahead of a fresh buffer recompute (`Signals\FlushSignalsJob::buildGroups()`),
 * so the read's result survives exactly as long as the read's own gate does.
 *
 * ## `occurred_at` is a `DATETIME`; `flushed_at` and `reconciled_at` are `timestamp()`
 *
 * The difference is whose value it is, exactly as the webhooks migration's own docblock states for
 * its identical column split: `flushed_at` and `reconciled_at` are stamped by this package at
 * write time -- always "now", so `TIMESTAMP`'s 2038 ceiling is a distant, Laravel-wide concern and
 * not this table's. `occurred_at` is the one column whose value can arrive from OUTSIDE this
 * process -- a caller-supplied instant for a signal recorded after the fact -- so an out-of-range
 * value is reachable today rather than only in 2038. `occurred_at` is NOT nullable: every signal
 * has an instant it occurred at, defaulted by `SignalRecorder` to "now" when the caller supplies
 * none, so there is never a buffered row with nothing to roll up a `first_wins`/`last_wins` tie
 * against.
 *
 * ## `subject_type` and `subject_id` stay NULL until `identify()` -- SIG-02's zero-HTTP proof
 *
 * A row this package writes from `Hubspot::signal()` alone always has both columns NULL.
 * `Signals\FlushSignalsJob` only ever reads rows carrying a subject, so an anonymous row can never
 * reach HubSpot -- the accepted consequence D-01 names ("a flush can create a contact") is
 * reachable only for people the application explicitly identified.
 *
 * ## The three indexes, and why there is no fourth
 *
 * `visitor_id` alone is what `Signals\IdentityResolver::identify()` backfills against --
 * `UPDATE ... WHERE visitor_id = ? AND subject_type IS NULL`. The composite
 * `(subject_type, subject_id, flushed_at)` index is what `Signals\FlushSignalsJob` reads a
 * subject's rows through, `flushed_at` trailing so the same index also serves the eventual pruning
 * query that filters on it (Phase 7, out of scope here). `signal_name` is indexed alone for an
 * operator inspecting a given signal's volume across visitors -- the same operator-inspection role
 * indexed columns play elsewhere in this package's migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hubspot_signals', function (Blueprint $table): void {
            $table->id();

            $table->string('visitor_id', 191);
            $table->string('subject_type', 191)->nullable();
            $table->string('subject_id', 191)->nullable();
            $table->string('signal_name', 191);
            $table->json('properties')->nullable();

            // A DATETIME, not a timestamp() -- see the class docblock. This is the one column
            // here whose value can arrive from a caller-supplied instant rather than "now".
            $table->datetime('occurred_at');

            $table->timestamp('flushed_at')->nullable();
            $table->timestamp('reconciled_at')->nullable();

            // What the reconcile read returned, not only that it happened -- see the class
            // docblock's "reconciled_properties" section. Written in the SAME update() call as
            // reconciled_at, so the two are never durable independently of each other.
            $table->json('reconciled_properties')->nullable();

            $table->timestamps();

            $table->index('visitor_id');
            $table->index(['subject_type', 'subject_id', 'flushed_at']);
            $table->index('signal_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hubspot_signals');
    }
};
