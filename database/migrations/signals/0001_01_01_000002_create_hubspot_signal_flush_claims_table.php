<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The subject-level flush claim `Signals\FlushClaims` reads and writes (D-06, revised
 * 2026-08-12). `HUBSPOT_SIGNALS=true`.
 *
 * **This file is executable where it sits, not a `.php.stub`.** Same reasoning as the buffer and
 * trail migrations beside it: Laravel's migrator globs a registered path for `*_*.php` and never
 * discovers a `.stub`, and this package both loads the file (when `hubspot.signals.enabled` is
 * true -- the SAME flag the buffer and trail migrations gate on, since all three live in the same
 * `database/migrations/signals` group) and publishes it unconditionally (for teams that want to
 * own it).
 *
 * ## `UNIQUE (subject_type, subject_id)` IS the mutual-exclusion primitive
 *
 * This is the checkpoint's own settled decision (option-a), and the reason it is correct rather
 * than merely convenient: a claim COLUMN on `hubspot_signals` cannot express per-subject
 * exclusion, because a row inserted mid-flush is always unclaimed, so a second worker's own
 * conditional UPDATE would always affect at least one row and wrongly believe it won the claim.
 * Per-row predicates can never give per-SUBJECT mutual exclusion on their own -- the claim has to
 * be keyed on the subject's identity, which is exactly what this table's unique index is. A
 * second INSERT for a subject already claimed either succeeds or fails atomically, decided by the
 * database itself, never by this package reading a row and then deciding.
 *
 * **It is one-way** (D-06's own reversibility rating, inherited by the checkpoint that settled
 * this table's shape): the claim storage ships in this migration, so changing the mechanism later
 * needs a migration against installed data, and installs will have been writing under whatever
 * exclusion semantics it gives.
 *
 * ## Every constrained column has its check at the point data enters
 *
 * `string('subject_type', 191)` and `string('subject_id', 191)` match the identical 191-byte width
 * the `hubspot_signals` buffer and `hubspot_signal_trail` migrations beside this one already use
 * for their own `subject_type`/`subject_id` columns -- the historical MySQL-safe VARCHAR width
 * under `utf8mb4` for a column carrying a UNIQUE index. Both are package-controlled (an Eloquent
 * model's own `::class` string and its primary key cast to a string), not application-supplied
 * free text, the same reasoning the buffer migration's own docblock states for its identical pair.
 * `claim_token` is bounded at the same 191-byte width for consistency, though nothing in this
 * package validates it at the point of entry -- `FlushSignalsJob` generates it internally
 * (`Illuminate\Support\Str::uuid()` or the underlying queue job's own id), never from
 * application-supplied input.
 *
 * ## `claimed_at` is a `timestamp()`, not a `datetime()`
 *
 * Unlike `hubspot_signals.occurred_at` or `hubspot_signal_trail.occurred_at`, this column's value
 * never arrives from outside this process -- `FlushClaims` stamps it at write time, always "now"
 * (or, on release, a fixed backdated instant it also owns). `TIMESTAMP`'s 2038 ceiling is
 * therefore a distant, Laravel-wide concern rather than this table's own, the identical reasoning
 * the buffer and trail migrations' docblocks already give for their own package-stamped columns.
 *
 * ## `attempts`, and released rows are pruning's concern, not this migration's
 *
 * `attempts` starts at 1 on the first claim and increments on every reclaim -- an operator-visible
 * signal that a worker held this subject's claim more than once, mirroring
 * `hubspot_webhook_events.attempts`' identical role. `FlushClaims::release()` backdates
 * `claimed_at` rather than deleting the row, so a released claim's row persists until Phase 7's
 * `hubspot:signals:prune` (T-06-43, accepted in the threat register) -- recorded here rather than
 * left to be discovered as an unbounded table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hubspot_signal_flush_claims', function (Blueprint $table): void {
            $table->id();

            $table->string('subject_type', 191);
            $table->string('subject_id', 191);
            $table->string('claim_token', 191);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('claimed_at');

            $table->timestamps();

            $table->unique(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hubspot_signal_flush_claims');
    }
};
