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
 * ## Why the unique index carries `lookup_hash` instead of the raw `model_type` (Codex, PR #39)
 *
 * **This section previously argued the opposite, and that argument no longer holds.** It said
 * `model_type` was safe to index raw because "the package itself reads it via `get_class()`" and
 * is therefore package- and PHP-controlled, the same reasoning that keeps `model_id` and
 * `object_type` raw below. That was true when this migration was first written and stopped being
 * true one commit later, in this same PR: the write path (`SyncHubspotObjectJob::handle()`) moved
 * from `get_class()` to `getMorphClass()`, because `morphOne()`'s own read query compares against
 * `getMorphClass()` and a `get_class()` write is invisible to it under a configured
 * `Relation::morphMap()`. `getMorphClass()` returns a USER-DEFINED alias — whatever string an
 * application author chose when declaring the morph map — so `model_type` is no longer a value
 * this package controls the shape of.
 *
 * That is the identical defect Codex raised as a **P1 on PR #27** for `hubspot_association_types`'
 * `label` column: MySQL's usual default collation (`utf8mb4_0900_ai_ci`, and `utf8mb4_unicode_ci`
 * before it) is case- and accent-insensitive, so two morph aliases differing only by case —
 * `Lead` and `lead` — would compare EQUAL to both this unique index and any `WHERE model_type = ?`
 * clause. Two distinct local models bound under those two aliases would then collide: one sync
 * could silently overwrite the other's link row, or a read could resolve the wrong one — the
 * silent-wrong-id failure this package exists to prevent, arriving through a column definition
 * rather than an API call.
 *
 * The fix mirrors the association-types precedent exactly: `lookup_hash` carries the SHA-256
 * digest of the raw `model_type` value, is `NOT NULL`, and the unique index is built over the
 * digest instead of the raw discriminator. A hex digest has no character any collation can fold,
 * so it behaves identically on every driver in this package's support matrix with nothing to
 * configure per driver. `model_type` itself is kept, readable, for an operator inspecting the
 * table — the same role `label` plays beside `lookup_hash` on the association-types table — but,
 * like `label`, it is never a predicate: both the job's write and the trait's read key on
 * `lookup_hash` (`Sync\HubspotObjectLink::lookupHashFor()`).
 *
 * `model_id` and `object_type` are NOT hashed, and stay raw, indexed columns: `model_id` is a
 * primary-key value the package itself reads via `getKey()`, and `object_type` is normalised to
 * canonical lower case by `Registry\HubspotObjectType` before it is ever written or queried —
 * both are still package- and PHP-controlled values with no "same value, spelled differently"
 * ambiguity for a collation to introduce.
 *
 * ## Why there is only ONE composite index, not two
 *
 * D-18 names a composite `['model_type', 'model_id']` index, satisfied here through
 * `lookup_hash` rather than `model_type` directly, for the reason above. The table's correctness
 * constraint is the UNIQUE index on `(lookup_hash, model_id, object_type)` below — one link row
 * per model instance per object type, which is what lets three distinct local models bind to
 * `contacts` simultaneously without colliding. That unique index's LEFTMOST PREFIX is exactly
 * `(lookup_hash, model_id)`, and every engine this package's support matrix covers (MySQL,
 * PostgreSQL, SQLite) satisfies a leftmost-prefix lookup from a composite index without a second,
 * standalone one. A second index over the same two leading columns would cost write amplification
 * on every insert for no read benefit it does not already have — deliberate, not an omission a
 * future reader should "fix" back in.
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

            // Fixed width, because a SHA-256 hex digest is always 64 characters. See the class
            // docblock for why the digest, rather than model_type itself, is what the unique
            // index and every query key on.
            $table->char('lookup_hash', 64);

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
            // this single composite index carries lookup_hash rather than model_type, and why a
            // second index over the same leading columns is not added.
            $table->unique(['lookup_hash', 'model_id', 'object_type']);

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
