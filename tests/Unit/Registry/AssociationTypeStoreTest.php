<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Unit\Registry;

use Closure;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionNamedType;
use ReyemTech\Hubspot\Gateway\AssociationType;
use ReyemTech\Hubspot\Registry\AssociationDirection;
use ReyemTech\Hubspot\Registry\AssociationTypeRow;
use ReyemTech\Hubspot\Registry\Contracts\AssociationTypeStore;
use ReyemTech\Hubspot\Registry\Stores\ArrayAssociationTypeStore;
use ReyemTech\Hubspot\Registry\Stores\CacheAssociationTypeStore;
use ReyemTech\Hubspot\Tests\Support\InMemoryRegistryCache;
use ReyemTech\Hubspot\Tests\TestCase;

/**
 * **The store seam, exercised through both implementations at once.**
 *
 * Every test below runs against the array store and the cache store, because a seam whose two
 * implementations disagree is not a seam. 03-02's database store implements the same four operations
 * and joins this provider; 03-03's sync and doctors then add no store code at all, which is the test
 * of whether this contract was defined completely (Codex P1 on PR #22 was raised about exactly the
 * opposite outcome).
 *
 * The four operations, and who needs each:
 *
 * 1. `resolve(direction, label)` — the read `AssociationTypeRegistry` performs on every labelled
 *    write.
 * 2. `upsert(row)` — what `hubspot:associations:sync` writes, keyed on direction AND label so a
 *    re-run updates a row rather than adding a second one for the same key.
 * 3. `all()` — what `hubspot:associations:doctor` counts and lists.
 * 4. `reconciledAt()` / `markReconciled()` — whether this store has ever been synced, and when.
 *
 * Operations 2 to 4 have no consumer until 03-03. They are tested here anyway: an untested contract
 * method is a guess about what 03-03 will need.
 */
mutates(
    ArrayAssociationTypeStore::class,
    CacheAssociationTypeStore::class,
);

final class AssociationTypeStoreTest extends TestCase
{
    /**
     * @return array<string, array{Closure(): AssociationTypeStore}>
     */
    public static function storeProvider(): array
    {
        return [
            'the array store' => [static fn (): AssociationTypeStore => new ArrayAssociationTypeStore],
            'the cache store' => [static fn (): AssociationTypeStore => new CacheAssociationTypeStore(new InMemoryRegistryCache)],
        ];
    }

    private static function row(
        string $fromType,
        string $toType,
        string $label,
        int $typeId,
        ?int $inverseTypeId = null,
    ): AssociationTypeRow {
        return new AssociationTypeRow(
            direction: AssociationDirection::of(from: $fromType, to: $toType),
            type: new AssociationType(typeId: $typeId, category: 'USER_DEFINED'),
            label: $label,
            inverseTypeId: $inverseTypeId,
            isDefault: null,
        );
    }

    /**
     * @param  Closure(): AssociationTypeStore  $make
     */
    #[DataProvider('storeProvider')]
    public function test_it_reads_through_to_the_seeded_baseline_on_a_miss(Closure $make): void
    {
        $row = $make()->resolve(AssociationDirection::of(from: 'contacts', to: 'companies'), 'Contact to company');

        self::assertInstanceOf(AssociationTypeRow::class, $row);
        self::assertSame(279, $row->type->typeId);
    }

    /**
     * @param  Closure(): AssociationTypeStore  $make
     */
    #[DataProvider('storeProvider')]
    public function test_a_direction_neither_stored_nor_seeded_answers_nothing(Closure $make): void
    {
        self::assertNull(
            $make()->resolve(AssociationDirection::of(from: 'tickets', to: 'companies'), 'Escalated to'),
        );
    }

    /**
     * **The store-level form of the never-the-inverse guarantee.** A store holding one direction
     * answers nothing for the other, under the same label, with that direction's id one lookup away.
     *
     * @param  Closure(): AssociationTypeStore  $make
     */
    #[DataProvider('storeProvider')]
    public function test_a_stored_direction_answers_nothing_for_its_inverse(Closure $make): void
    {
        $store = $make();
        $store->upsert(self::row('tickets', 'companies', 'Escalated to', 4242, 4243));

        self::assertNull(
            $store->resolve(AssociationDirection::of(from: 'companies', to: 'tickets'), 'Escalated to'),
        );
    }

    /**
     * @param  Closure(): AssociationTypeStore  $make
     */
    #[DataProvider('storeProvider')]
    public function test_an_upserted_row_resolves_for_its_own_direction_and_label(Closure $make): void
    {
        $store = $make();
        $store->upsert(self::row('tickets', 'companies', 'Escalated to', 4242, 4243));

        $row = $store->resolve(AssociationDirection::of(from: 'tickets', to: 'companies'), 'Escalated to');

        self::assertInstanceOf(AssociationTypeRow::class, $row);
        self::assertSame(4242, $row->type->typeId);
        self::assertSame(4243, $row->inverseTypeId);
    }

    /**
     * Re-running a reconciliation updates the row rather than adding a second one for the same key.
     * Two rows for one `(direction, label)` is exactly the ambiguity REQUIREMENTS.md's REG-02
     * correction of 2026-07-28 was raised about: the lookup could then return either.
     *
     * @param  Closure(): AssociationTypeStore  $make
     */
    #[DataProvider('storeProvider')]
    public function test_upserting_the_same_direction_and_label_updates_rather_than_duplicating(Closure $make): void
    {
        $store = $make();
        $store->upsert(self::row('tickets', 'companies', 'Escalated to', 4242));
        $before = count($store->all());

        $store->upsert(self::row('tickets', 'companies', 'Escalated to', 5150));

        self::assertCount($before, $store->all());

        $row = $store->resolve(AssociationDirection::of(from: 'tickets', to: 'companies'), 'Escalated to');

        self::assertInstanceOf(AssociationTypeRow::class, $row);
        self::assertSame(5150, $row->type->typeId, 'The second reconciliation must win, not the first.');
    }

    /**
     * A portal's own answer overrides the seeded one for the same direction and label. This is what
     * makes the package-canonical baseline names safe: a portal that happens to use one of them for a
     * user-defined label owns that key after a sync.
     *
     * @param  Closure(): AssociationTypeStore  $make
     */
    #[DataProvider('storeProvider')]
    public function test_a_reconciled_row_overrides_the_seeded_one_for_the_same_key(Closure $make): void
    {
        $store = $make();
        $store->upsert(self::row('contacts', 'companies', 'Contact to company', 9001, 9002));

        $row = $store->resolve(AssociationDirection::of(from: 'contacts', to: 'companies'), 'Contact to company');

        self::assertInstanceOf(AssociationTypeRow::class, $row);
        self::assertSame(9001, $row->type->typeId);
    }

    /**
     * @param  Closure(): AssociationTypeStore  $make
     */
    #[DataProvider('storeProvider')]
    public function test_all_enumerates_the_seeded_rows_plus_whatever_was_reconciled(Closure $make): void
    {
        $store = $make();
        $seeded = count($store->all());

        self::assertGreaterThan(0, $seeded, 'A store with no reconciliation still answers for the seeded baseline.');

        $store->upsert(self::row('tickets', 'companies', 'Escalated to', 4242));

        self::assertCount($seeded + 1, $store->all());
    }

    /**
     * @param  Closure(): AssociationTypeStore  $make
     */
    #[DataProvider('storeProvider')]
    public function test_a_store_that_has_never_been_synced_says_so(Closure $make): void
    {
        self::assertNull($make()->reconciledAt());
    }

    /**
     * The clock is the caller's, never the store's: a store that read `now()` itself could not be
     * asserted deterministically, and a doctor reporting "last synced" wants the time the sync
     * finished rather than the time it was asked.
     *
     * @param  Closure(): AssociationTypeStore  $make
     */
    #[DataProvider('storeProvider')]
    public function test_marking_a_store_reconciled_records_the_moment_the_caller_gave(Closure $make): void
    {
        $store = $make();
        $at = new DateTimeImmutable('2026-07-29T09:15:00+00:00');

        $store->markReconciled($at);

        $recorded = $store->reconciledAt();

        self::assertInstanceOf(DateTimeImmutable::class, $recorded);
        self::assertSame($at->getTimestamp(), $recorded->getTimestamp());
    }

    /**
     * The cache store's own reason to exist: what a sync wrote is still there for the next process,
     * without a database. Asserted by building a second store over the same cache rather than by
     * inspecting the payload, so the format stays an implementation detail.
     */
    public function test_the_cache_store_persists_reconciled_rows_and_metadata_across_instances(): void
    {
        $cache = new InMemoryRegistryCache;
        $at = new DateTimeImmutable('2026-07-29T09:15:00+00:00');

        $first = new CacheAssociationTypeStore($cache);
        $first->upsert(self::row('tickets', 'companies', 'Escalated to', 4242, 4243));
        $first->markReconciled($at);

        $second = new CacheAssociationTypeStore($cache);
        $row = $second->resolve(AssociationDirection::of(from: 'tickets', to: 'companies'), 'Escalated to');

        self::assertInstanceOf(AssociationTypeRow::class, $row);
        self::assertSame(4242, $row->type->typeId);
        self::assertSame(4243, $row->inverseTypeId);
        self::assertSame($at->getTimestamp(), $second->reconciledAt()?->getTimestamp());
    }

    /**
     * An upsert on its own must persist. Kept separate from the test above, which also marks the
     * store reconciled: that second write would carry the row to the cache regardless, so a store
     * that forgot to persist on upsert would still pass it.
     */
    public function test_the_cache_store_persists_a_row_upserted_with_no_reconciliation_marker(): void
    {
        $cache = new InMemoryRegistryCache;

        (new CacheAssociationTypeStore($cache))->upsert(self::row('tickets', 'companies', 'Escalated to', 4242));

        $row = (new CacheAssociationTypeStore($cache))
            ->resolve(AssociationDirection::of(from: 'tickets', to: 'companies'), 'Escalated to');

        self::assertInstanceOf(AssociationTypeRow::class, $row);
        self::assertSame(4242, $row->type->typeId);
    }

    /**
     * **A row another process reconciled is visible to a store that has already answered** (Codex P2
     * on PR #24).
     *
     * The store is a container singleton, and `hubspot:associations:sync` runs in a different process
     * from the queue worker that writes associations. A store that loaded the cache payload once and
     * held it for the life of the process would leave that worker resolving stale type ids — and
     * missing new labels entirely — until somebody restarted it, which is a silent wrong-id failure
     * with an operational cause rather than a code one.
     *
     * The cost is one cache read per lookup. A labelled association write is an HTTP round trip to
     * HubSpot; a cache read alongside it is noise, and correctness is not a thing to trade for it.
     */
    public function test_the_cache_store_sees_what_another_process_reconciled_after_it_first_answered(): void
    {
        $cache = new InMemoryRegistryCache;
        $worker = new CacheAssociationTypeStore($cache);

        // The long-running process answers once, which is what populates any cached payload.
        self::assertNull($worker->resolve(AssociationDirection::of(from: 'tickets', to: 'companies'), 'Escalated to'));

        // Meanwhile, `hubspot:associations:sync` reconciles the portal in its own process.
        $sync = new CacheAssociationTypeStore($cache);
        $sync->upsert(self::row('tickets', 'companies', 'Escalated to', 4242));
        $sync->markReconciled(new DateTimeImmutable('2026-07-29T09:15:00+00:00'));

        $row = $worker->resolve(AssociationDirection::of(from: 'tickets', to: 'companies'), 'Escalated to');

        self::assertInstanceOf(AssociationTypeRow::class, $row);
        self::assertSame(4242, $row->type->typeId, 'The worker kept answering from a payload it had cached.');
        self::assertNotNull($worker->reconciledAt(), 'The worker still believes the portal was never synced.');
    }

    /**
     * The same staleness, one step worse: a type id that CHANGED. A worker holding the old id keeps
     * writing a real, valid, wrong association — which HubSpot accepts without complaint.
     */
    public function test_the_cache_store_sees_a_type_id_another_process_changed(): void
    {
        $cache = new InMemoryRegistryCache;

        (new CacheAssociationTypeStore($cache))->upsert(self::row('tickets', 'companies', 'Escalated to', 4242));

        $worker = new CacheAssociationTypeStore($cache);
        $worker->resolve(AssociationDirection::of(from: 'tickets', to: 'companies'), 'Escalated to');

        (new CacheAssociationTypeStore($cache))->upsert(self::row('tickets', 'companies', 'Escalated to', 5150));

        $row = $worker->resolve(AssociationDirection::of(from: 'tickets', to: 'companies'), 'Escalated to');

        self::assertInstanceOf(AssociationTypeRow::class, $row);
        self::assertSame(5150, $row->type->typeId);
    }

    /**
     * `all()` and the persistence payload are both LISTS. A store that returned its internal keyed
     * array would json_encode to an object rather than an array, which is a shape change for every
     * consumer of the payload — the database store of 03-02 included.
     *
     * @param  Closure(): AssociationTypeStore  $make
     */
    #[DataProvider('storeProvider')]
    public function test_the_enumeration_is_a_list_rather_than_a_keyed_array(Closure $make): void
    {
        $store = $make();
        $store->upsert(self::row('tickets', 'companies', 'Escalated to', 4242));

        $all = $store->all();

        self::assertSame(
            range(0, count($all) - 1),
            array_keys($all),
            'all() must return a list. A keyed array json_encodes to an object rather than an array.',
        );
    }

    public function test_the_persistence_payload_holds_its_rows_as_a_list(): void
    {
        $store = new ArrayAssociationTypeStore;
        $store->upsert(self::row('tickets', 'companies', 'Escalated to', 4242));
        $store->upsert(self::row('leads', 'meetings', 'Booked', 5150));

        $rows = $store->toArray()['rows'];

        self::assertIsArray($rows);
        self::assertTrue(array_is_list($rows), 'The persisted rows must be a list, not a keyed map.');
        self::assertCount(2, $rows);
    }

    /**
     * **The cache store never writes a guess.** A read — hit, miss, or baseline read-through — leaves
     * the cache exactly as it found it. A store that persisted its baseline read-through would look
     * identical from every other test in this file, and would then keep answering from a stale copy
     * of a map that had since been corrected in code.
     */
    public function test_reading_the_cache_store_writes_nothing(): void
    {
        $cache = new InMemoryRegistryCache;
        $store = new CacheAssociationTypeStore($cache);

        $store->resolve(AssociationDirection::of(from: 'contacts', to: 'companies'), 'Contact to company');
        $store->resolve(AssociationDirection::of(from: 'tickets', to: 'companies'), 'Escalated to');
        $store->all();
        $store->reconciledAt();

        self::assertSame(0, $cache->writes, 'A read path wrote to the cache.');
    }

    /**
     * A cache is shared infrastructure a consumer can write to, and this package's key holds a
     * structure rather than a scalar. A payload of the wrong shape must leave the store behaving
     * exactly like a cold one — every seeded direction still resolves, nothing claims to have been
     * reconciled — rather than raising a `TypeError` from inside a lookup on an association write.
     */
    public function test_a_cache_payload_of_the_wrong_shape_leaves_a_cold_store_rather_than_a_broken_one(): void
    {
        $cache = new InMemoryRegistryCache;
        $cache->write(CacheAssociationTypeStore::CACHE_KEY, [
            'rows' => 'not a list of rows at all',
            'reconciled_at' => 'not a timestamp',
        ]);

        $store = new CacheAssociationTypeStore($cache);

        self::assertNull($store->reconciledAt());
        self::assertCount(count((new ArrayAssociationTypeStore)->all()), $store->all());

        $row = $store->resolve(AssociationDirection::of(from: 'notes', to: 'contacts'), 'Note to contact');

        self::assertInstanceOf(AssociationTypeRow::class, $row);
        self::assertSame(202, $row->type->typeId);
    }

    /**
     * The same guard one level down: a payload whose `rows` IS a list, but whose entries are not
     * rows. Kept separate from the test above because the two exercise different branches — a whole
     * payload of the wrong shape, and one bad entry inside a well-shaped one.
     */
    public function test_a_cache_payload_whose_row_entries_are_not_rows_is_ignored_entry_by_entry(): void
    {
        $cache = new InMemoryRegistryCache;
        $cache->write(CacheAssociationTypeStore::CACHE_KEY, [
            'rows' => [
                'not a row',
                self::row('tickets', 'companies', 'Escalated to', 4242)->toArray(),
            ],
        ]);

        $store = new CacheAssociationTypeStore($cache);
        $row = $store->resolve(AssociationDirection::of(from: 'tickets', to: 'companies'), 'Escalated to');

        self::assertInstanceOf(AssociationTypeRow::class, $row);
        self::assertSame(4242, $row->type->typeId);
    }

    /**
     * **The signature guard.** The contract must offer no way to ask "what are the types for these
     * two objects" without an order — that signature is how the inverse gets picked. Every method
     * therefore takes a direction or a row, never two object types.
     */
    public function test_the_contract_cannot_express_an_unordered_lookup(): void
    {
        $reflection = new ReflectionClass(AssociationTypeStore::class);

        self::assertTrue($reflection->isInterface());

        foreach ($reflection->getMethods() as $method) {
            $objectTypeParameters = 0;

            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();

                if ($type instanceof ReflectionNamedType && $type->getName() === 'string') {
                    self::assertSame(
                        'label',
                        $parameter->getName(),
                        "AssociationTypeStore::{$method->getName()}() takes a bare string that is not a label. "
                        .'An object type passed as a loose string is one transposition away from an unordered pair.',
                    );
                }

                if ($type instanceof ReflectionNamedType && $type->getName() === AssociationDirection::class) {
                    $objectTypeParameters++;
                }
            }

            self::assertLessThanOrEqual(
                1,
                $objectTypeParameters,
                "AssociationTypeStore::{$method->getName()}() takes two directions, which is two ends without one order.",
            );
        }
    }
}
