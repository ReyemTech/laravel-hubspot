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
 * ## It reads the cache on every operation, and that is deliberate
 *
 * The store is a container singleton, and `hubspot:associations:sync` runs in a **different process**
 * from the queue worker that writes associations. An instance that loaded the payload once and held
 * it for the life of the process would leave a long-running worker missing every newly reconciled
 * label, and resolving stale type ids for every changed one, until somebody restarted it — a silent
 * wrong-id failure with an operational cause rather than a code one (Codex P2, PR #24).
 *
 * The cost is one cache read per lookup. A labelled association write is an HTTP round trip to
 * HubSpot; a cache read alongside it is noise, and correctness is not a thing to trade for it.
 */
final class CacheAssociationTypeStore implements AssociationTypeStore
{
    /**
     * The one cache key the whole registry lives under. Namespaced by the package so it cannot
     * collide with a consumer's own cache entries.
     */
    public const CACHE_KEY = 'reyemtech-hubspot:association-types';

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
        return ArrayAssociationTypeStore::fromArray($this->cache->read(self::CACHE_KEY) ?? []);
    }

    private function persist(ArrayAssociationTypeStore $rows): void
    {
        $this->cache->write(self::CACHE_KEY, $rows->toArray());
    }
}
