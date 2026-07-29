<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Registry\Contracts;

/**
 * **The package-owned cache port the cache-backed store persists through.**
 *
 * Declared here, and deliberately not typed against `Illuminate\Contracts\Cache\Repository`, because
 * architecture rule R2 says `Registry` may depend only on `Gateway` and the package exceptions. A
 * store naming an Illuminate contract fails that rule — correctly, since the layer boundary is what
 * keeps the registry testable with no framework and no I/O.
 *
 * The Illuminate-backed implementation therefore lives at the composition root
 * (`ReyemTech\Hubspot\IlluminateRegistryCache`), which is where the `ServiceProvider` that wires it
 * lives too, and the registry never names it. A consumer who keeps registry state somewhere else
 * implements this interface and rebinds it — two methods, no framework.
 *
 * The payload is a plain array rather than a package value object on purpose: what a store persists
 * is its own business, and a port that knew about rows would have to change every time a store's
 * storage format did.
 */
interface RegistryCache
{
    /**
     * The payload stored under this key, or null if there is none.
     *
     * @return array<array-key, mixed>|null
     */
    public function read(string $key): ?array;

    /**
     * Stores a payload under this key, with no expiry: a reconciled registry is valid until the next
     * reconciliation replaces it, and an entry that quietly expired would send the package back to
     * the seeded baseline while a doctor still reported the portal as synced.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public function write(string $key, array $payload): void;
}
