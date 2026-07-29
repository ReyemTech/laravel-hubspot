<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Registry\Stores;

use DateTimeImmutable;
use DateTimeZone;
use ReyemTech\Hubspot\Registry\AssociationDirection;
use ReyemTech\Hubspot\Registry\AssociationTypeRow;
use ReyemTech\Hubspot\Registry\BaselineAssociationTypes;
use ReyemTech\Hubspot\Registry\Contracts\AssociationTypeStore;

/**
 * The in-memory association-type store: rows for this process, over the seeded baseline.
 *
 * It is the canonical implementation of the seam — {@see CacheAssociationTypeStore} composes one of
 * these and adds persistence, and 03-02's database store answers the same four operations against a
 * table. Selectable as `HUBSPOT_STORE=array`, which is what a test suite or a queue worker with no
 * shared cache wants: everything resolves from the baseline, and anything a sync writes lives for
 * the life of the process.
 *
 * **The baseline read-through is same-key only.** A miss consults
 * `BaselineAssociationTypes::resolve()` for the identical direction and label, and there is no second
 * lookup anywhere in this class — no reversed key is computed, so there is nothing for a `??` to
 * reach for even by accident.
 */
final class ArrayAssociationTypeStore implements AssociationTypeStore
{
    /**
     * Reconciled rows, keyed by direction and label. Never seeded rows: the baseline lives in code and
     * copying it in here would put the map in two places, and the copy in the wrong place would be the
     * one nobody updates.
     *
     * @var array<string, AssociationTypeRow>
     */
    private array $rows = [];

    private ?DateTimeImmutable $reconciledAt = null;

    public function resolve(AssociationDirection $direction, string $label): ?AssociationTypeRow
    {
        return $this->rows[$direction->key($label)]
            ?? BaselineAssociationTypes::resolve($direction, $label);
    }

    public function upsert(AssociationTypeRow $row): void
    {
        $this->rows[$row->key()] = $row;
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

        // Second, so a reconciled row overrides the seeded one for the same key -- the same
        // precedence resolve() applies, expressed once more here rather than inferred.
        foreach ($this->rows as $key => $row) {
            $effective[$key] = $row;
        }

        return array_values($effective);
    }

    public function reconciledAt(): ?DateTimeImmutable
    {
        return $this->reconciledAt;
    }

    public function markReconciled(DateTimeImmutable $at): void
    {
        $this->reconciledAt = $at;
    }

    /**
     * This store's reconciled state as plain data, for a cache payload. Seeded rows are deliberately
     * absent: persisting them would freeze a copy of a map that lives in code, and a later correction
     * to the baseline would never reach a portal that had already been synced.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'reconciled_at' => $this->reconciledAt?->getTimestamp(),
            'rows' => array_values(array_map(
                static fn (AssociationTypeRow $row): array => $row->toArray(),
                $this->rows,
            )),
        ];
    }

    /**
     * Rebuilds a store from the plain data `toArray()` produced.
     *
     * Every row goes back through `AssociationTypeRow::fromArray()`, which validates it, so a corrupt
     * or partially-written payload raises this package's own exception rather than a `TypeError` from
     * a property assignment nobody can catch.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $store = new self;

        $rows = $payload['rows'] ?? [];

        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $store->upsert(AssociationTypeRow::fromArray($row));
                }
            }
        }

        $reconciledAt = $payload['reconciled_at'] ?? null;

        if (is_int($reconciledAt)) {
            $store->markReconciled(
                (new DateTimeImmutable('@'.$reconciledAt))->setTimezone(new DateTimeZone('UTC')),
            );
        }

        return $store;
    }
}
