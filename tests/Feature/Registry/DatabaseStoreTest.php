<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Registry;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use ReyemTech\Hubspot\Gateway\AssociationType;
use ReyemTech\Hubspot\Registry\AssociationDirection;
use ReyemTech\Hubspot\Registry\AssociationTypeRow;
use ReyemTech\Hubspot\Registry\Contracts\AssociationTypeStore;
use ReyemTech\Hubspot\Registry\Stores\ArrayAssociationTypeStore;
use ReyemTech\Hubspot\Registry\Stores\DatabaseAssociationTypeStore;
use ReyemTech\Hubspot\Tests\Support\DatabaseStoreTestCase;

/**
 * # The database store answers the same four operations as the two stores 03-01 shipped.
 *
 * Substitutability is this plan's claim, so it is proved rather than asserted: every guarantee the
 * array and cache stores are held to is re-run here against a table, and the two implementations are
 * asked the same questions side by side and required to give the same answers.
 *
 * The store contract itself is untouched. `resolve`, `upsert`, `all`, `reconciledAt` and
 * `markReconciled` were defined and tested in 03-01 against two implementations; a third
 * implementation needing a sixth operation would have meant the seam was defined wrongly, and it did
 * not.
 */
mutates(DatabaseAssociationTypeStore::class);

final class DatabaseStoreTest extends DatabaseStoreTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
    }

    private function store(): AssociationTypeStore
    {
        return app(AssociationTypeStore::class);
    }

    private static function row(
        string $from,
        string $to,
        int $typeId,
        ?string $label,
        ?int $inverseTypeId = null,
        ?bool $isDefault = null,
    ): AssociationTypeRow {
        return new AssociationTypeRow(
            direction: AssociationDirection::of(from: $from, to: $to),
            type: new AssociationType(typeId: $typeId, category: 'USER_DEFINED'),
            label: $label,
            inverseTypeId: $inverseTypeId,
            isDefault: $isDefault,
        );
    }

    /**
     * Selecting the store is the whole of the switch: nothing in the registry changes.
     */
    public function test_hubspot_store_database_binds_the_database_store(): void
    {
        self::assertInstanceOf(DatabaseAssociationTypeStore::class, $this->store());
    }

    public function test_an_upserted_row_survives_a_round_trip_through_the_table(): void
    {
        $store = $this->store();
        $direction = AssociationDirection::of(from: 'tickets', to: 'companies');

        $store->upsert(self::row('tickets', 'companies', 4242, 'Escalated to', 4243, false));

        $resolved = $store->resolve($direction, 'Escalated to');

        self::assertNotNull($resolved);
        self::assertSame(4242, $resolved->type->typeId);
        self::assertSame('USER_DEFINED', $resolved->type->category->value);
        self::assertSame('Escalated to', $resolved->label);
        self::assertSame(4243, $resolved->inverseTypeId);
        self::assertFalse($resolved->isDefault);
    }

    /**
     * `is_default` is the one column a driver hands back in a shape `AssociationTypeRow` refuses: a
     * boolean is stored as `0`/`1` and read back as an `int`. All three of its states are asserted,
     * because a decoder that answered `true` for everything would satisfy a `false`-only test.
     */
    #[DataProvider('defaultFlagProvider')]
    public function test_the_default_flag_survives_a_round_trip_in_all_three_states(?bool $isDefault): void
    {
        $store = $this->store();

        $store->upsert(self::row('tickets', 'companies', 4242, 'Escalated to', null, $isDefault));

        $resolved = $store->resolve(AssociationDirection::of(from: 'tickets', to: 'companies'), 'Escalated to');

        self::assertNotNull($resolved);
        self::assertSame($isDefault, $resolved->isDefault);
    }

    /**
     * @return array<string, array{?bool}>
     */
    public static function defaultFlagProvider(): array
    {
        return [
            'the default type for the pair' => [true],
            'a type that is not the default' => [false],
            'not known, which is what every seeded row carries' => [null],
        ];
    }

    public function test_a_null_valued_row_round_trips_with_its_nulls_intact(): void
    {
        $store = $this->store();
        $direction = AssociationDirection::of(from: 'tickets', to: 'companies');

        $store->upsert(self::row('tickets', 'companies', 4242, 'Escalated to'));

        $resolved = $store->resolve($direction, 'Escalated to');

        self::assertNotNull($resolved);
        self::assertNull($resolved->inverseTypeId);
        self::assertNull($resolved->isDefault);
    }

    /**
     * The baseline read-through is same-key only, exactly as in `ArrayAssociationTypeStore`: a
     * labelled write resolves offline before any portal has been synced, and before any row exists
     * in the table at all.
     */
    public function test_a_miss_reads_through_to_the_seeded_baseline_for_the_same_key(): void
    {
        $resolved = $this->store()->resolve(
            AssociationDirection::of(from: 'contacts', to: 'companies'),
            'Contact to company',
        );

        self::assertNotNull($resolved);
        self::assertSame(279, $resolved->type->typeId);
        self::assertSame(0, DB::table(DatabaseAssociationTypeStore::TABLE)->count());
    }

    /**
     * **The rule this phase must not break, restated against a table that holds both directions as
     * rows.** The opposite direction's row is one query away, and the requested direction still
     * misses.
     */
    public function test_the_opposite_direction_never_answers_for_the_requested_one(): void
    {
        $store = $this->store();

        $store->upsert(self::row('companies', 'tickets', 4243, 'Escalated to'));

        self::assertNull($store->resolve(
            AssociationDirection::of(from: 'tickets', to: 'companies'),
            'Escalated to',
        ));
    }

    /**
     * A different label on the same direction is a different row, not a near miss to fall back on.
     */
    public function test_a_different_label_on_the_same_direction_does_not_answer(): void
    {
        $store = $this->store();

        $store->upsert(self::row('tickets', 'companies', 4242, 'Escalated to'));

        self::assertNull($store->resolve(
            AssociationDirection::of(from: 'tickets', to: 'companies'),
            'Escalated from',
        ));
    }

    public function test_a_reconciled_row_overrides_the_seeded_one_for_the_same_key(): void
    {
        $store = $this->store();
        $direction = AssociationDirection::of(from: 'contacts', to: 'companies');

        $store->upsert(self::row('contacts', 'companies', 999, 'Contact to company'));

        $resolved = $store->resolve($direction, 'Contact to company');

        self::assertNotNull($resolved);
        self::assertSame(999, $resolved->type->typeId);

        $ids = array_map(
            static fn (AssociationTypeRow $row): int => $row->type->typeId,
            array_values(array_filter(
                $store->all(),
                static fn (AssociationTypeRow $row): bool => $row->key() === $direction->key('Contact to company'),
            )),
        );

        self::assertSame([999], $ids, 'all() must carry the reconciled row once, not both rows.');
    }

    public function test_all_carries_the_seeded_baseline_plus_the_rows_the_table_holds(): void
    {
        $store = $this->store();
        $seeded = count((new ArrayAssociationTypeStore)->all());

        $store->upsert(self::row('tickets', 'companies', 4242, 'Escalated to'));

        self::assertCount($seeded + 1, $store->all());
    }

    /**
     * The contract's return type is `list<AssociationTypeRow>`. Rows are collected into a map keyed
     * by direction and label to apply the reconciled-over-seeded precedence, so the keys have to be
     * dropped again on the way out — a caller iterating with an index, or `json_encode`ing the
     * result, sees an object rather than an array otherwise.
     */
    public function test_all_returns_a_list_rather_than_a_map_keyed_by_direction_and_label(): void
    {
        $store = $this->store();

        $store->upsert(self::row('tickets', 'companies', 4242, 'Escalated to'));

        $rows = $store->all();

        // The keys, rather than `array_is_list()`: PHPStan folds that into a tautology against the
        // declared `list<AssociationTypeRow>` return type and the assertion stops asserting.
        self::assertSame(
            range(0, count($rows) - 1),
            array_keys($rows),
            'all() leaked the direction-and-label keys it collects rows under.',
        );
    }

    public function test_reconciled_at_is_null_until_marked_and_then_survives_a_round_trip(): void
    {
        $store = $this->store();

        self::assertNull($store->reconciledAt());

        $at = new DateTimeImmutable('2026-07-29 11:22:33', new DateTimeZone('UTC'));
        $store->markReconciled($at);

        self::assertSame($at->getTimestamp(), $store->reconciledAt()?->getTimestamp());
    }

    /**
     * Marking twice records the second moment, rather than adding a second state row that a later
     * read might pick either of.
     */
    public function test_marking_reconciled_twice_records_the_later_moment(): void
    {
        $store = $this->store();

        $store->markReconciled(new DateTimeImmutable('@1000000000'));
        $store->markReconciled(new DateTimeImmutable('@2000000000'));

        self::assertSame(2000000000, $store->reconciledAt()?->getTimestamp());
        self::assertSame(1, DB::table(DatabaseAssociationTypeStore::STATE_TABLE)->count());
    }

    /**
     * A read — a hit, a miss, or a baseline read-through — writes nothing. The cache store is held to
     * the same guarantee, for the same reason: persisting a read-through would freeze a copy of a map
     * that lives in code.
     */
    public function test_no_read_path_writes_a_row(): void
    {
        $store = $this->store();

        $store->resolve(AssociationDirection::of(from: 'contacts', to: 'companies'), 'Contact to company');
        $store->resolve(AssociationDirection::of(from: 'tickets', to: 'companies'), 'Escalated to');
        $store->all();
        $store->reconciledAt();

        self::assertSame(0, DB::table(DatabaseAssociationTypeStore::TABLE)->count());
        self::assertSame(0, DB::table(DatabaseAssociationTypeStore::STATE_TABLE)->count());
    }

    /**
     * A second upsert for the same direction and label updates the row rather than adding a second
     * one — the property the unique key exists to make unrepresentable, asserted here from the
     * application's side. `DatabaseStoreSchemaTest` asserts the database refuses it too, which is the
     * assertion that still holds when a future writer bypasses this method.
     *
     * @param  array{string, string, ?string}  $key
     */
    #[DataProvider('upsertKeyProvider')]
    public function test_upserting_the_same_direction_and_label_updates_one_row(array $key): void
    {
        $store = $this->store();
        [$from, $to, $label] = $key;

        $store->upsert(self::row($from, $to, 4242, $label));
        $store->upsert(self::row($from, $to, 4244, $label));

        self::assertSame(1, DB::table(DatabaseAssociationTypeStore::TABLE)->count());

        if ($label !== null) {
            self::assertSame(
                4244,
                $store->resolve(AssociationDirection::of(from: $from, to: $to), $label)?->type->typeId,
            );
        }
    }

    /**
     * @return array<string, array{array{string, string, ?string}}>
     */
    public static function upsertKeyProvider(): array
    {
        return [
            'a labelled row' => [['tickets', 'companies', 'Escalated to']],
            'the unlabelled default row' => [['tickets', 'companies', null]],
        ];
    }
}
