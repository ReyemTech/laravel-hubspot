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
 * the same ambiguity reached through the one row a `NOT NULL` cannot cover. `lookup_key` is that
 * triple with the null encoded: it carries `Registry\AssociationDirection::key($label)`, which maps
 * `null` to `default:` and a label to `label:<label>` so no label can collide with the unlabelled row
 * however it is spelled. Being `NOT NULL`, the unique index bites on every row.
 *
 * A partial/filtered unique index on `is_default` was the alternative and is rejected: MySQL supports
 * neither filtered indexes nor a portable index on an expression, and `is_default` is itself nullable.
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

            // 512 four-byte characters is 2048 bytes, inside InnoDB's 3072-byte index limit with
            // room for the two object-type columns beside it.
            $table->string('lookup_key', 512);

            $table->unique(['from_object_type', 'to_object_type', 'lookup_key']);
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
