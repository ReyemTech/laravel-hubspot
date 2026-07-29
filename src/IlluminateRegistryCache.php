<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot;

use Illuminate\Contracts\Cache\Repository;
use ReyemTech\Hubspot\Registry\Contracts\RegistryCache;

/**
 * The registry cache port, backed by the application's own cache.
 *
 * **It lives here, at the composition root, and not under `src/Registry/`, and that is load-bearing
 * rather than filing.** Architecture rule R2 says `Registry` may depend only on `Gateway` and the
 * package exceptions, so a store naming `Illuminate\Contracts\Cache\Repository` fails the build --
 * correctly, since keeping the framework out of the registry is what makes the registry testable with
 * no cache driver, no container and no I/O. The port is declared in `Registry\Contracts`, the
 * framework-facing half is here, next to the `ServiceProvider` that wires the two together, and the
 * registry never names this class.
 *
 * A consumer who keeps registry state somewhere else -- Redis under their own key, a config file, a
 * shared filesystem -- implements `Registry\Contracts\RegistryCache` and rebinds it. Two methods, no
 * framework.
 */
final class IlluminateRegistryCache implements RegistryCache
{
    public function __construct(private readonly Repository $cache) {}

    /**
     * @return array<array-key, mixed>|null
     */
    public function read(string $key): ?array
    {
        $payload = $this->cache->get($key);

        // Anything that is not an array is treated as absent rather than as a fault. A cache is
        // shared infrastructure a consumer can write to, and a value of the wrong shape under this
        // key means the package falls back to the seeded baseline -- which resolves the cited
        // directions and throws for the rest, the same as a cold cache.
        return is_array($payload) ? $payload : null;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public function write(string $key, array $payload): void
    {
        // Forever, deliberately. A reconciled registry is valid until the next reconciliation
        // replaces it, and an entry that quietly expired would send the package back to the seeded
        // baseline while `hubspot:associations:doctor` still reported the portal as synced.
        $this->cache->forever($key, $payload);
    }
}
