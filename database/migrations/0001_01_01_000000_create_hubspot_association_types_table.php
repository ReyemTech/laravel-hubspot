<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The association-type registry's table, and the reconciliation state that goes with it.
 *
 * **This file is executable where it sits, not a `.php.stub`.** Laravel's migrator globs a registered
 * path for `*_*.php`; it never discovers a `.stub`. The stub-only convention belongs to packages that
 * publish and never load — this package does both, so the file has to run from the package path when
 * `HUBSPOT_STORE=database` and still be publishable for teams that want to own it (Codex P1 on
 * PR #22). It is loaded only when a database store is active, which is what keeps
 * `composer require` a complete install for everybody else (STANDARDS §7).
 *
 * ## Each direction is its own row
 *
 * A paired label carries a *different name* in each direction — FOUND-03 run 2 measured `Deals`
 * forward and `People` inverse — so a schema holding one row per pair with two id columns cannot
 * represent reality and is deliberately not built. `inverse_type_id` is recorded for traversal and
 * verification (design spec §6.2) and is read by nothing on a write path;
 * `tests/Feature/Registry/DatabaseStoreNeverTheInverseTest.php` proves that from the outside.
 *
 * ## The unique key, and the null label
 *
 * The read key is `(from_object_type, to_object_type, label)`, so that is what must be unique. Two
 * rows claiming different type ids for one direction and label make the registry's own lookup
 * ambiguous and let it answer with the wrong association id (Codex P1 on PR #22 — REG-02 originally
 * said the direction was unique against `type_id`, which those two rows both satisfy).
 *
 * `label` is nullable by contract and MySQL, PostgreSQL and SQLite all permit repeated `NULL`s in a
 * unique index, so an index over `label` itself would leave the unlabelled default row duplicable —
 * the same ambiguity reached through the one row a `NOT NULL` cannot cover. `lookup_hash` is that
 * triple with the null encoded: it carries the SHA-256 digest of
 * `Registry\AssociationDirection::key($label)`, which maps `null` to `default:` and a label to
 * `label:<label>` so no label can collide with the unlabelled row however it is spelled. Being
 * `NOT NULL`, the unique index bites on every row.
 *
 * A partial/filtered unique index on `is_default` was the alternative and is rejected: MySQL supports
 * neither filtered indexes nor a portable index on an expression, and `is_default` is itself nullable.
 *
 * ## Why the indexed key is a digest rather than the readable string
 *
 * **A readable key in an indexed `varchar` is only as case-sensitive as the column's collation, and
 * MySQL's usual default is neither case- nor accent-sensitive** (Codex P1 on PR #27).
 * `utf8mb4_0900_ai_ci` — and `utf8mb4_unicode_ci` before it — folds case and accents, so
 * `…>label:Deals` and `…>label:deals` would be one value to both this index and the store's `WHERE`.
 * A row labelled `Deals` would then answer a lookup for `deals` and put a real type id on the wire for
 * a label the portal does not have, and two labels differing only by case or accent could not coexist
 * at all: the silent-wrong-id failure this package exists to prevent, arriving through a column
 * definition.
 *
 * A per-driver `COLLATE` clause was the alternative and is rejected: the collation names differ across
 * MySQL, PostgreSQL and SQLite, so it would mean driver branching in this migration that only one leg
 * of the matrix could ever execute. A lowercase hex digest has no case and no accents for any
 * collation to fold, so the column behaves identically everywhere with nothing to configure. The
 * readable columns beside it are what an operator inspects; nothing queries them.
 *
 * The other columns are safe under a case-insensitive collation and stay readable:
 * `from_object_type` and `to_object_type` are normalised to canonical lower case by
 * `Registry\HubspotObjectType` before they are ever written or queried, and `label` is never a
 * predicate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hubspot_association_types', function (Blueprint $table): void {
            $table->id();

            // 64 is comfortably above the longest canonical HubSpot object type and any
            // `p<portalId>_<name>` custom object identifier.
            $table->string('from_object_type', 64);
            $table->string('to_object_type', 64);

            $table->unsignedInteger('type_id');
            $table->string('category', 32);
            $table->string('label', 255)->nullable();
            $table->unsignedInteger('inverse_type_id')->nullable();
            $table->boolean('is_default')->nullable();

            // Fixed width, because a SHA-256 hex digest is always 64 characters. A label of any
            // length therefore indexes in the same space, and the whole three-column key stays far
            // inside InnoDB's 3072-byte index limit.
            $table->char('lookup_hash', 64);

            $table->unique(['from_object_type', 'to_object_type', 'lookup_hash']);
        });

        Schema::create('hubspot_registry_state', function (Blueprint $table): void {
            // Reconciliation state is per store, not per row, so it has no home in the seven columns
            // above -- and `max(updated_at)` cannot stand in for it: the state must survive with zero
            // rows present, and editing a row is not reconciling with a portal. Keyed by name rather
            // than being a single anonymous row so a second concern can record its own reconciliation
            // without another table.
            $table->string('name', 64)->primary();

            // A unix timestamp rather than a datetime column: it is the shape
            // `Registry\Stores\ArrayAssociationTypeStore::toArray()` already persists reconciliation
            // state in, so a portal moving between the cache and database stores does not re-sync,
            // and it carries no timezone for a driver to reinterpret on the way back out.
            $table->bigInteger('reconciled_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hubspot_registry_state');
        Schema::dropIfExists('hubspot_association_types');
    }
};
