<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Registry\Stores;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Registry\AssociationDirection;
use ReyemTech\Hubspot\Registry\AssociationTypeRow;
use ReyemTech\Hubspot\Registry\BaselineAssociationTypes;
use ReyemTech\Hubspot\Registry\Contracts\AssociationTypeStore;
use Throwable;

/**
 * The opt-in store: reconciled rows in a table, over the seeded baseline. `HUBSPOT_STORE=database`.
 *
 * The default is {@see CacheAssociationTypeStore}, because STANDARDS §7's zero-migration install means
 * a bare `composer require` must work with no publish step and no `migrate`. This is what a portal
 * chooses when it wants the registry somewhere it can inspect, join against and back up. Selecting it
 * is the whole of the switch — the registry is unchanged, and the four operations below are the same
 * four the array and cache stores answer.
 *
 * ## The keying, and why it is not `(from, to, label)` in three columns
 *
 * Every read is keyed on `lookup_key`, which holds {@see AssociationDirection::key()} — the identical
 * string the other two stores key on. It is carried in its own `NOT NULL` column because `label` is
 * nullable and every supported database permits repeated `NULL`s in a unique index, which would leave
 * the unlabelled default row duplicable. The migration's own docblock has the full reasoning.
 *
 * Reusing the merged key rather than assembling a second encoding here is the point: the encoding
 * lives in one place (STANDARDS §6b), and
 * `tests/Feature/Registry/DatabaseStoreSchemaTest.php::test_the_persisted_lookup_key_is_the_key_the_other_stores_use`
 * asserts the column really holds it, so the two cannot drift.
 *
 * **No reversed key is computed anywhere in this class**, and no query names both directions. The
 * table holds both directions of a pair as two rows, so this is the one file in the package where a
 * miss on the requested direction has the other direction's row sitting in arm's reach; there is
 * deliberately nothing for a `??` or an `orWhere` to reach for.
 *
 * ## A missing table names the fix
 *
 * `HUBSPOT_STORE=database` without `php artisan migrate` is the most likely first encounter with this
 * store, and `SQLSTATE[42S02]` teaches the reader nothing about this package (STANDARDS §9). Every
 * operation runs through {@see self::guarded()}, which asks the schema whether the table is genuinely
 * absent before saying so — a refused connection or a hand-edited schema keeps its own exception,
 * because a directed error pointing at the wrong fix is worse than an undirected one.
 */
final class DatabaseAssociationTypeStore implements AssociationTypeStore
{
    public const TABLE = 'hubspot_association_types';

    /**
     * Reconciliation state is per store rather than per row, so it cannot live in the row table.
     */
    public const STATE_TABLE = 'hubspot_registry_state';

    /**
     * This store's row in the state table. Named rather than anonymous so REG-03's second consumer
     * can record its own reconciliation beside it without another table.
     */
    private const STATE_NAME = 'association_types';

    public function __construct(private readonly Connection $connection) {}

    public function resolve(AssociationDirection $direction, string $label): ?AssociationTypeRow
    {
        $record = $this->guarded(self::TABLE, fn (): mixed => $this->rows()
            ->where('from_object_type', $direction->from->value)
            ->where('to_object_type', $direction->to->value)
            ->where('lookup_key', $direction->key($label))
            ->first());

        // Same key only: the same direction and the same label, never a second lookup.
        if (! is_object($record)) {
            return BaselineAssociationTypes::resolve($direction, $label);
        }

        return self::hydrate($record);
    }

    public function upsert(AssociationTypeRow $row): void
    {
        $this->guarded(self::TABLE, function () use ($row): void {
            $this->rows()->updateOrInsert(
                [
                    'from_object_type' => $row->direction->from->value,
                    'to_object_type' => $row->direction->to->value,
                    'lookup_key' => $row->key(),
                ],
                [
                    'type_id' => $row->type->typeId,
                    'category' => $row->type->category->value,
                    'label' => $row->label,
                    'inverse_type_id' => $row->inverseTypeId,
                    'is_default' => $row->isDefault,
                ],
            );
        });
    }

    /**
     * @return list<AssociationTypeRow>
     */
    public function all(): array
    {
        $effective = [];

        foreach (BaselineAssociationTypes::rows() as $row) {
            $effective[$row->key()] = $row;
        }

        /** @var list<object> $records */
        $records = $this->guarded(self::TABLE, fn (): array => array_values($this->rows()->get()->all()));

        // Second, so a reconciled row overrides the seeded one for the same key -- the same
        // precedence resolve() applies, expressed once more here rather than inferred.
        foreach ($records as $record) {
            $row = self::hydrate($record);
            $effective[$row->key()] = $row;
        }

        return array_values($effective);
    }

    public function reconciledAt(): ?DateTimeImmutable
    {
        $value = $this->guarded(self::STATE_TABLE, fn (): mixed => $this->state()
            ->where('name', self::STATE_NAME)
            ->value('reconciled_at'));

        if (! is_numeric($value)) {
            return null;
        }

        return (new DateTimeImmutable('@'.(int) $value))->setTimezone(new DateTimeZone('UTC'));
    }

    public function markReconciled(DateTimeImmutable $at): void
    {
        $this->guarded(self::STATE_TABLE, function () use ($at): void {
            $this->state()->updateOrInsert(
                ['name' => self::STATE_NAME],
                ['reconciled_at' => $at->getTimestamp()],
            );
        });
    }

    private function rows(): Builder
    {
        return $this->connection->table(self::TABLE);
    }

    private function state(): Builder
    {
        return $this->connection->table(self::STATE_TABLE);
    }

    /**
     * Runs one operation, translating "the table this package owns has never been created" into a
     * message naming the command that creates it, and leaving every other database failure alone.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $operation
     * @return TReturn
     *
     * @throws ConfigurationException if the table is genuinely absent
     * @throws Throwable the driver's own failure otherwise, unchanged and undiagnosed by this
     *                   package, because relabelling a refused connection as a missing table sends
     *                   the reader to a command they have already run
     */
    private function guarded(string $table, callable $operation): mixed
    {
        try {
            return $operation();
        } catch (QueryException $exception) {
            if ($this->connection->getSchemaBuilder()->hasTable($table)) {
                throw $exception;
            }

            throw ConfigurationException::missingRegistryTable($table);
        }
    }

    /**
     * Decodes one record into the validating value object.
     *
     * The two casts are the storage boundary doing its own job, not the coercion `AssociationTypeRow`
     * refuses: a driver is free to hand an integer column back as a numeric string (any connection
     * with emulated prepares does), and a boolean column back as `0`/`1`. Anything that is neither is
     * passed through untouched so it still meets the value object's validation and throws there,
     * rather than being silently turned into a real-looking type id.
     */
    private static function hydrate(object $record): AssociationTypeRow
    {
        /** @var array<string, mixed> $columns */
        $columns = (array) $record;

        return AssociationTypeRow::fromArray([
            'from' => $columns['from_object_type'] ?? null,
            'to' => $columns['to_object_type'] ?? null,
            'type_id' => self::decodeInteger($columns['type_id'] ?? null),
            'category' => $columns['category'] ?? null,
            'label' => $columns['label'] ?? null,
            'inverse_type_id' => self::decodeInteger($columns['inverse_type_id'] ?? null),
            'is_default' => self::decodeBoolean($columns['is_default'] ?? null),
        ]);
    }

    private static function decodeInteger(mixed $value): mixed
    {
        if (is_string($value) && $value === (string) (int) $value) {
            return (int) $value;
        }

        return $value;
    }

    private static function decodeBoolean(mixed $value): mixed
    {
        if (is_int($value) || is_string($value)) {
            return (bool) (int) $value;
        }

        return $value;
    }
}
