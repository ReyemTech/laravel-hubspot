<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records that THIS PACKAGE issued an archive for a link row (SYNC-04).
 *
 * ## Why a second migration rather than a column on the first
 *
 * `0001_01_01_000000_create_hubspot_object_links_table.php` shipped in 0.5.0, so consumers have
 * already run it. Editing a migration somebody has run changes nothing in their database and makes
 * the schema depend on when they installed — the failure this package would then carry forever.
 * The `0001_01_01_000001_` prefix continues the sibling's convention of a fixed prefix rather than
 * a real timestamp, so ordering never depends on when the file was authored.
 *
 * ## What the column is FOR, and why the gate could not answer it
 *
 * Every decision this package makes after a delete needs to know one thing: was the HubSpot record
 * actually archived? Three separate defects came from answering that with the gate's CURRENT value
 * (Codex, PR #49), and all three share one shape — `hubspot.auto_sync.on` can change between the
 * delete and whatever asks about it later:
 *
 * 1. A purge skipped its archive because the model was already trashed, on the assumption that the
 *    soft delete had archived it. If `deleted` was gated off then, nothing had, and a live HubSpot
 *    record was left with no local row behind it.
 * 2. A restore flagged a link stale, or under `recreate` DELETED a perfectly valid link and created
 *    a duplicate object, for a soft delete that was never mirrored.
 * 3. An edit to a soft-deleted model was discarded as "the delete path owns this record" when the
 *    delete path had never touched it, and the CRM stayed stale.
 *
 * `trashed()` proves a soft delete happened. It never proves that soft delete's archive passed the
 * gate. This column is that proof, and nothing else in the schema could stand in for it: `is_stale`
 * means something different and is cleared by an ordinary re-sync, and `synced_at` is when the
 * record last received properties.
 *
 * Nullable, and null for every row that already exists — which is the correct reading of a link
 * written before this column did: this package has no evidence it archived them, and the whole
 * point of the column is to stop inferring evidence it does not have.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hubspot_object_links', function (Blueprint $table): void {
            // After synced_at, beside the other two timestamps that describe what has happened to
            // this link rather than what it points at.
            $table->timestamp('archived_at')->nullable()->after('synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('hubspot_object_links', function (Blueprint $table): void {
            $table->dropColumn('archived_at');
        });
    }
};
