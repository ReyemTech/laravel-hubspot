<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The package-owned mapping from a local Eloquent model to the HubSpot record it syncs to (D-13).
 *
 * **This file is executable where it sits, not a `.php.stub`.** Same reasoning as the
 * association-types migration beside it: Laravel's migrator globs a registered path for `*_*.php`
 * and never discovers a `.stub`, and this package both loads the file (when a `models` binding
 * exists) and publishes it (for teams that want to own it). Its own subdirectory —
 * `database/migrations/sync/` — is what lets `ServiceProvider::migrationGroups()` gate it as a
 * SECOND, independent group rather than folding it into the registry's group, which gates on a
 * completely different config key (`hubspot.store`, not `hubspot.models`). The shared
 * `0001_01_01_000000_` prefix is repeated rather than a real timestamp so ordering never depends
 * on when the file was authored.
 *
 * ## Why `model_id` is a `string`, not `morphs()`'s `unsignedBigInteger` (D-18)
 *
 * `$table->morphs('model')` assumes every bound model has an autoincrement integer primary key.
 * This column stores the local half of a real CRM link for whatever model an application binds,
 * and this package supports autoincrement, UUID and ULID primary keys uniformly elsewhere — a
 * `morphs()` column would work today and force a breaking migration the first time a consumer
 * binds a UUID- or ULID-keyed model, which is exactly the kind of "worked in practice, still the
 * wrong call" defect this package's own STANDARDS exist to catch before release. A plain `string`
 * accepts all three key shapes with nothing to migrate later.
 *
 * ## Why there is only ONE composite index, not two
 *
 * D-18 also names a composite `['model_type', 'model_id']` index. The table's correctness
 * constraint is the UNIQUE index on `(model_type, model_id, object_type)` below — one link row per
 * model instance per object type, which is what lets three distinct local models bind to
 * `contacts` simultaneously without colliding. That unique index's LEFTMOST PREFIX is exactly
 * `(model_type, model_id)`, and every engine this package's support matrix covers (MySQL,
 * PostgreSQL, SQLite) satisfies a leftmost-prefix lookup from a composite index without a second,
 * standalone one. A second index over the same two leading columns would cost write amplification
 * on every insert for no read benefit it does not already have — deliberate, not an omission a
 * future reader should "fix" back in.
 *
 * ## Why there is no `lookup_hash` collation workaround here
 *
 * The association-types migration beside this one carries a `lookup_hash` column specifically
 * because `label` is free-text, user-supplied and compared case-insensitively for MEANING under
 * MySQL's default collation. Neither indexed column here has that problem: `model_type` is a
 * fully-qualified PHP class name the package itself reads via `get_class()`, and `model_id` is a
 * primary-key value the package itself reads via `getKey()` — both are package- and
 * PHP-controlled, never free text a human typed with an accent or a case a MySQL collation might
 * fold. There is no "same value, different case, meant to be a different row" ambiguity for a
 * class name or a numeric/UUID primary key string, so the digest workaround is deliberately not
 * repeated here.
 *
 * ## Why `hubspot_id` is never nulled, only flagged
 *
 * `is_stale` and `stale_at` exist because SYNC-04's restore path needs the previously-synced
 * HubSpot id to stay re-linkable after a soft-deleted record is restored (D-17): the row is
 * flagged stale rather than deleted, and `hubspot_id` itself is never cleared. HubSpot has no
 * unarchive endpoint, so the only way to recover a link to an archived record is to keep pointing
 * at the id that still, in fact, identifies it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hubspot_object_links', function (Blueprint $table): void {
            $table->id();

            $table->string('model_type');
            $table->string('model_id');

            // 64 is comfortably above the longest canonical HubSpot object type and any
            // `p<portalId>_<name>` custom object identifier -- the same width the association-types
            // migration uses for the identical value shape.
            $table->string('object_type', 64);

            $table->string('hubspot_id');

            $table->timestamp('synced_at')->nullable();
            $table->boolean('is_stale')->default(false);
            $table->timestamp('stale_at')->nullable();

            $table->timestamps();

            // One link row per model instance per object type -- see the class docblock for why
            // this single composite index also satisfies D-18's named ['model_type', 'model_id']
            // index via its leftmost prefix, and why a second index is not added.
            $table->unique(['model_type', 'model_id', 'object_type']);

            // The reverse lookup: given a HubSpot id and object type, which local model does it
            // belong to (the shape a webhook handler needs).
            $table->index(['object_type', 'hubspot_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hubspot_object_links');
    }
};
