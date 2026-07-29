<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Registry\Stores;

use DateTimeImmutable;
use ReyemTech\Hubspot\Registry\AssociationDirection;
use ReyemTech\Hubspot\Registry\AssociationTypeRow;
use ReyemTech\Hubspot\Registry\Contracts\AssociationTypeStore;
use ReyemTech\Hubspot\Registry\Contracts\RegistryCache;

/**
 * The default store: reconciled rows kept in the application cache, over the seeded baseline.
 *
 * Default because of STANDARDS §7's zero-migration install — a bare `composer require` has to work
 * with no publish step and no `migrate`, and a cache is the only durable place a package may assume.
 * `HUBSPOT_STORE=database` (03-02) is the opt-in alternative for portals that want the registry in a
 * table they can inspect.
 *
 * It composes an {@see ArrayAssociationTypeStore} rather than reimplementing the lookup, so the
 * keying, the precedence and the baseline read-through exist in exactly one place (STANDARDS §6b).
 * Everything this class adds is persistence.
 *
 * ## It never writes a guess
 *
 * A read — a hit, a miss, or a baseline read-through — writes nothing. Persisting a read-through
 * would freeze a copy of a map that lives in code, so a later correction to the baseline would never
 * reach a portal that had already resolved that direction once.
 * `tests/Unit/Registry/AssociationTypeStoreTest.php` asserts the write count is zero across every
 * read path.
 *
 * ## It is loaded once and written on change
 *
 * The cache payload is read on first use and held for the life of the instance, because the store is
 * a container singleton and re-reading per lookup would put a cache round trip on every labelled
 * association write. A mutation writes through immediately, so the next process sees it.
 */
final class CacheAssociationTypeStore implements AssociationTypeStore
{
    /**
     * The one cache key the whole registry lives under. Namespaced by the package so it cannot
     * collide with a consumer's own cache entries.
     */
    public const CACHE_KEY = 'reyemtech-hubspot:association-types';

    private ?ArrayAssociationTypeStore $rows = null;

    public function __construct(private readonly RegistryCache $cache) {}

    public function resolve(AssociationDirection $direction, string $label): ?AssociationTypeRow
    {
        return $this->rows()->resolve($direction, $label);
    }

    public function upsert(AssociationTypeRow $row): void
    {
        $rows = $this->rows();
        $rows->upsert($row);

        $this->persist($rows);
    }

    /**
     * @return list<AssociationTypeRow>
     */
    public function all(): array
    {
        return $this->rows()->all();
    }

    public function reconciledAt(): ?DateTimeImmutable
    {
        return $this->rows()->reconciledAt();
    }

    public function markReconciled(DateTimeImmutable $at): void
    {
        $rows = $this->rows();
        $rows->markReconciled($at);

        $this->persist($rows);
    }

    private function rows(): ArrayAssociationTypeStore
    {
        return $this->rows ??= ArrayAssociationTypeStore::fromArray($this->cache->read(self::CACHE_KEY) ?? []);
    }

    private function persist(ArrayAssociationTypeStore $rows): void
    {
        $this->cache->write(self::CACHE_KEY, $rows->toArray());
    }
}
