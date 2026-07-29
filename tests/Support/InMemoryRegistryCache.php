<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Support;

use ReyemTech\Hubspot\Registry\Contracts\RegistryCache;

/**
 * The registry cache port, backed by a plain array.
 *
 * Lets the cache-backed store be exercised with no Laravel cache driver and no I/O, and — more
 * usefully — lets a test prove that a read MISS wrote nothing, by counting writes. A store that
 * persisted a baseline read-through would look identical from the outside without that count.
 */
final class InMemoryRegistryCache implements RegistryCache
{
    /**
     * @var array<string, array<array-key, mixed>>
     */
    private array $entries = [];

    public int $writes = 0;

    public int $reads = 0;

    public function read(string $key): ?array
    {
        $this->reads++;

        return $this->entries[$key] ?? null;
    }

    public function write(string $key, array $payload): void
    {
        $this->writes++;
        $this->entries[$key] = $payload;
    }
}
