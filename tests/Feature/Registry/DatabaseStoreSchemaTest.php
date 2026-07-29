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
 * ## Why the unique index names `lookup_key` and not `label`
 *
 * `label` is nullable by contract: `AssociationTypeRow::$label` is `?string`, and a portal sync
 * writes `null` for a HubSpot-defined type. MySQL, PostgreSQL and SQLite all permit repeated `NULL`s
 * in a unique index, so a three-column index over `label` would leave the unlabelled default row
 * duplicable — the same ambiguity by another route, reached through the one row a `NOT NULL` cannot
 * cover.
 *
 * `lookup_key` is that triple with the null encoded: it holds `AssociationDirection::key($label)`,
 * merged and tested in 03-01, which already maps `null` to `default:` and a label to `label:<label>`
 * precisely so no label can collide with the unlabelled row however it is spelled. The column is
 * `NOT NULL`, so the unique index bites on every row including the default one, and it is the
 * identical string the array and cache stores key on — substitutability by construction rather than
 * by coincidence.
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
     * The seven columns design spec §6.2 names, plus the encoded lookup key the unique index needs.
     */
    public function test_the_table_carries_the_seven_columns_and_the_lookup_key(): void
    {
        foreach ([
            'from_object_type',
            'to_object_type',
            'type_id',
            'category',
            'label',
            'inverse_type_id',
            'is_default',
            'lookup_key',
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
     * **The persisted key is the merged one, not a second encoding written here.**
     *
     * If the store computed its own `default:` / `label:` prefixes, this repository would hold the
     * encoding in two places and the copy in the wrong place would be the one nobody updated. This
     * asserts from the outside that the string in the column is the string
     * `AssociationDirection::key()` produces — so the database store keys on exactly what the array
     * and cache stores key on.
     */
    #[DataProvider('lookupKeyProvider')]
    public function test_the_persisted_lookup_key_is_the_key_the_other_stores_use(?string $label): void
    {
        $store = app(DatabaseAssociationTypeStore::class);
        $direction = AssociationDirection::of(from: 'tickets', to: 'companies');

        $store->upsert(new AssociationTypeRow(
            direction: $direction,
            type: new AssociationType(typeId: 4242, category: 'USER_DEFINED'),
            label: $label,
            inverseTypeId: null,
            isDefault: null,
        ));

        self::assertSame(
            $direction->key($label),
            DB::table(DatabaseAssociationTypeStore::TABLE)->value('lookup_key'),
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
            'lookup_key' => AssociationDirection::of(from: 'tickets', to: 'companies')->key($label),
        ];
    }
}
