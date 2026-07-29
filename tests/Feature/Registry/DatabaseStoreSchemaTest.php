<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Registry;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReyemTech\Hubspot\Gateway\AssociationType;
use ReyemTech\Hubspot\Registry\AssociationDirection;
use ReyemTech\Hubspot\Registry\AssociationTypeRow;
use ReyemTech\Hubspot\Registry\Stores\DatabaseAssociationTypeStore;
use ReyemTech\Hubspot\Tests\Support\DatabaseStoreTestCase;

/**
 * # The schema, and the constraint the database enforces rather than the application.
 *
 * REG-02 originally said the direction was unique against `type_id`. That is wrong (Codex P1 on
 * PR #22): rows `(A, B, 10, 'buyer')` and `(A, B, 11, 'buyer')` both satisfy it, so a lookup by
 * direction and label — which is exactly how the registry resolves — becomes ambiguous and can
 * return the wrong association id. The read key is `(from, to, label)`, so that is what is unique.
 *
 * ## Why the unique index names `lookup_hash` and not `label`
 *
 * `label` is nullable by contract: `AssociationTypeRow::$label` is `?string`, and a portal sync
 * writes `null` for a HubSpot-defined type. MySQL, PostgreSQL and SQLite all permit repeated `NULL`s
 * in a unique index, so a three-column index over `label` would leave the unlabelled default row
 * duplicable — the same ambiguity by another route, reached through the one row a `NOT NULL` cannot
 * cover.
 *
 * `lookup_hash` is that triple with the null encoded and then hashed. The encoding is
 * `AssociationDirection::key($label)`, merged and tested in 03-01, which maps `null` to `default:` and
 * a label to `label:<label>` precisely so no label can collide with the unlabelled row however it is
 * spelled. The column is `NOT NULL`, so the unique index bites on every row including the default one.
 *
 * It is hashed rather than stored readably because MySQL's usual default collation is case AND accent
 * insensitive, which would have made both this index and the store's own `WHERE` insensitive to how a
 * label is spelled (Codex P1 on PR #27). A hex digest has no character a collation can fold.
 *
 * **Every insert below goes through the connection, not through the store.** A duplicate rejected by
 * `updateOrInsert()` proves only that the application is careful today; the claim is that a second
 * id for one direction and label is unrepresentable, and only the database can make that true for a
 * writer that has not been written yet.
 */
final class DatabaseStoreSchemaTest extends DatabaseStoreTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
    }

    /**
     * The seven columns design spec §6.2 names, plus the hashed lookup key the unique index needs.
     */
    public function test_the_table_carries_the_seven_columns_and_the_lookup_hash(): void
    {
        foreach ([
            'from_object_type',
            'to_object_type',
            'type_id',
            'category',
            'label',
            'inverse_type_id',
            'is_default',
            'lookup_hash',
        ] as $column) {
            self::assertTrue(
                Schema::hasColumn(DatabaseAssociationTypeStore::TABLE, $column),
                "The registry table is missing the column {$column}.",
            );
        }
    }

    /**
     * Reconciliation state is per store, not per row, so it cannot live in the seven columns — and
     * `max(updated_at)` cannot stand in for it either: the state has to survive with zero rows
     * present, and a row edit is not a reconciliation.
     */
    public function test_the_reconciliation_state_table_exists_alongside_it(): void
    {
        self::assertTrue(Schema::hasTable(DatabaseAssociationTypeStore::STATE_TABLE));
        self::assertTrue(Schema::hasColumn(DatabaseAssociationTypeStore::STATE_TABLE, 'name'));
        self::assertTrue(Schema::hasColumn(DatabaseAssociationTypeStore::STATE_TABLE, 'reconciled_at'));
    }

    /**
     * **The database refuses the second id, not the application.**
     */
    #[DataProvider('duplicateLabellingProvider')]
    public function test_two_type_ids_for_one_direction_and_label_are_rejected_by_the_database(
        ?string $label,
    ): void {
        $direction = AssociationDirection::of(from: 'tickets', to: 'companies');

        DB::table(DatabaseAssociationTypeStore::TABLE)->insert(self::record($label, 4242));

        try {
            DB::table(DatabaseAssociationTypeStore::TABLE)->insert(self::record($label, 4244));

            self::fail(sprintf(
                'The database accepted a second type id for %s under %s, so a lookup by direction and '
                .'label is ambiguous and can answer with the wrong association id.',
                $direction->describe(),
                $label === null ? 'the default (unlabelled) row' : "the label \"{$label}\"",
            ));
        } catch (PDOException) {
            self::assertSame(
                1,
                DB::table(DatabaseAssociationTypeStore::TABLE)->count(),
                'The rejected insert must leave the first row alone and add nothing.',
            );
        }
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function duplicateLabellingProvider(): array
    {
        return [
            'a labelled row' => ['Escalated to'],
            'the unlabelled default row, where most databases permit repeated NULLs' => [null],
        ];
    }

    /**
     * The same direction under two DIFFERENT labels is two legitimate rows. Without this, the test
     * above would also pass against a unique index on `(from, to)` alone, which would make a portal's
     * second label unstorable.
     */
    public function test_two_labels_on_one_direction_are_two_rows(): void
    {
        DB::table(DatabaseAssociationTypeStore::TABLE)->insert(self::record('Escalated to', 4242));
        DB::table(DatabaseAssociationTypeStore::TABLE)->insert(self::record('Escalated from', 4244));

        self::assertSame(2, DB::table(DatabaseAssociationTypeStore::TABLE)->count());
    }

    /**
     * The unlabelled default row and a labelled row on the same direction do not collide, which is
     * the property the `default:` / `label:` prefixes exist for.
     */
    public function test_the_default_row_and_a_labelled_row_coexist_on_one_direction(): void
    {
        DB::table(DatabaseAssociationTypeStore::TABLE)->insert(self::record(null, 4242));
        DB::table(DatabaseAssociationTypeStore::TABLE)->insert(self::record('Escalated to', 4244));

        self::assertSame(2, DB::table(DatabaseAssociationTypeStore::TABLE)->count());
    }

    /**
     * **The persisted key is a hash of the merged one, not a second encoding written here.**
     *
     * If the store computed its own `default:` / `label:` prefixes, this repository would hold the
     * encoding in two places and the copy in the wrong place would be the one nobody updated. This
     * asserts from the outside that the column holds the digest of the string
     * `AssociationDirection::key()` produces — so the database store keys on exactly what the array
     * and cache stores key on, one derivation later.
     */
    #[DataProvider('lookupKeyProvider')]
    public function test_the_persisted_lookup_hash_is_the_digest_of_the_key_the_other_stores_use(
        ?string $label,
    ): void {
        $store = app(DatabaseAssociationTypeStore::class);
        $direction = AssociationDirection::of(from: 'tickets', to: 'companies');

        $store->upsert(self::rowFor($direction, $label));

        self::assertSame(
            hash('sha256', $direction->key($label)),
            DB::table(DatabaseAssociationTypeStore::TABLE)->value('lookup_hash'),
        );
    }

    /**
     * **The stored key has no case and no accents to be insensitive to** (Codex P1 on PR #27).
     *
     * MySQL's usual default collation — `utf8mb4_0900_ai_ci`, and `utf8mb4_unicode_ci` before it — is
     * case AND accent insensitive. A readable `contacts>companies>label:Deals` in a `varchar` column
     * would therefore have made both the unique index and the store's own `WHERE` insensitive to how
     * a label is spelled: a row labelled `Deals` would answer a lookup for `deals`, putting a real
     * type id on the wire for a label the portal does not have, and two labels differing only by case
     * or accent could not coexist at all. That is the silent-wrong-id failure this package exists to
     * prevent, arriving through a column definition.
     *
     * A hex digest has no character a collation can fold, so the column behaves identically under
     * every collation on every driver — which is what this asserts, rather than asserting a
     * collation name that only one driver understands.
     */
    #[DataProvider('lookupKeyProvider')]
    public function test_the_stored_key_is_collation_proof(?string $label): void
    {
        $store = app(DatabaseAssociationTypeStore::class);
        $direction = AssociationDirection::of(from: 'Tickets', to: 'Companies');

        $store->upsert(self::rowFor($direction, $label));

        $stored = DB::table(DatabaseAssociationTypeStore::TABLE)->value('lookup_hash');

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/',
            is_string($stored) ? $stored : '',
            'The stored key must carry no character a case- or accent-insensitive collation could fold.',
        );

        if ($label !== null) {
            self::assertStringNotContainsString(
                $label,
                is_string($stored) ? $stored : '',
                'A readable label in the indexed column is what makes the index collation-sensitive.',
            );
        }
    }

    /**
     * Two labels that differ only in case are two rows, and each resolves to its own type id.
     *
     * This passes under SQLite whichever way the column is defined, because SQLite's default `TEXT`
     * collation is `BINARY`. It is committed anyway: it is the behaviour the fix exists to give MySQL,
     * and the CI matrix is not the only place this package runs.
     */
    public function test_two_labels_differing_only_in_case_are_two_rows(): void
    {
        $store = app(DatabaseAssociationTypeStore::class);
        $direction = AssociationDirection::of(from: 'tickets', to: 'companies');

        $store->upsert(self::rowFor($direction, 'Escalated To', 4242));
        $store->upsert(self::rowFor($direction, 'escalated to', 4244));

        self::assertSame(2, DB::table(DatabaseAssociationTypeStore::TABLE)->count());
        self::assertSame(4242, $store->resolve($direction, 'Escalated To')?->type->typeId);
        self::assertSame(4244, $store->resolve($direction, 'escalated to')?->type->typeId);
    }

    private static function rowFor(
        AssociationDirection $direction,
        ?string $label,
        int $typeId = 4242,
    ): AssociationTypeRow {
        return new AssociationTypeRow(
            direction: $direction,
            type: new AssociationType(typeId: $typeId, category: 'USER_DEFINED'),
            label: $label,
            inverseTypeId: null,
            isDefault: null,
        );
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function lookupKeyProvider(): array
    {
        return [
            'a labelled row' => ['Escalated to'],
            'the unlabelled default row' => [null],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function record(?string $label, int $typeId): array
    {
        return [
            'from_object_type' => 'tickets',
            'to_object_type' => 'companies',
            'type_id' => $typeId,
            'category' => 'USER_DEFINED',
            'label' => $label,
            'inverse_type_id' => null,
            'is_default' => null,
            'lookup_hash' => hash('sha256', AssociationDirection::of(from: 'tickets', to: 'companies')->key($label)),
        ];
    }
}
