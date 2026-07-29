<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Registry;

use Illuminate\Contracts\Cache\Repository;
use ReyemTech\Hubspot\IlluminateRegistryCache;
use ReyemTech\Hubspot\Tests\TestCase;

/**
 * The framework-facing half of the registry cache port.
 *
 * It lives at the composition root rather than under `src/Registry/` because architecture rule R2
 * lets `Registry` depend on `Gateway` and the package exceptions only — a store naming
 * `Illuminate\Contracts\Cache\Repository` fails the build, correctly, since keeping the framework out
 * of the registry is what makes the registry testable with no cache driver and no container. This
 * test is therefore a Feature test (it needs a real cache repository) where every other registry test
 * is a Unit one that needs nothing.
 */
mutates(IlluminateRegistryCache::class);

final class IlluminateRegistryCacheTest extends TestCase
{
    public function test_a_key_that_was_never_written_reads_as_absent(): void
    {
        $cache = new IlluminateRegistryCache(app(Repository::class));

        self::assertNull($cache->read('reyemtech-hubspot:never-written'));
    }

    public function test_a_written_payload_reads_back_unchanged(): void
    {
        $cache = new IlluminateRegistryCache(app(Repository::class));
        $payload = ['rows' => [['from' => 'contacts', 'to' => 'companies']], 'reconciled_at' => 1_753_000_000];

        $cache->write('reyemtech-hubspot:round-trip', $payload);

        self::assertSame($payload, $cache->read('reyemtech-hubspot:round-trip'));
    }

    /**
     * A cache is shared infrastructure a consumer can write to. A value of the wrong shape under this
     * package's key is treated as absent — which sends the store back to the seeded baseline, exactly
     * as a cold cache would — rather than raising a `TypeError` from inside an association write.
     */
    public function test_a_value_of_the_wrong_shape_reads_as_absent_rather_than_failing(): void
    {
        $repository = app(Repository::class);
        $repository->forever('reyemtech-hubspot:wrong-shape', 'a bare string nobody in this package wrote');

        $cache = new IlluminateRegistryCache($repository);

        self::assertNull($cache->read('reyemtech-hubspot:wrong-shape'));
    }

    /**
     * Written with no expiry, deliberately: a reconciled registry is valid until the next
     * reconciliation replaces it, and an entry that quietly expired would send the package back to
     * the seeded baseline while a doctor still reported the portal as synced.
     *
     * Asserted by travelling past any plausible TTL rather than by spying on which repository method
     * was called. A spy would have to reimplement or extend a framework class whose signatures differ
     * across the supported Laravel majors, and it would assert the call rather than the property the
     * call exists to produce.
     */
    public function test_the_payload_survives_longer_than_any_plausible_expiry(): void
    {
        $cache = new IlluminateRegistryCache(app(Repository::class));

        $cache->write('reyemtech-hubspot:no-expiry', ['rows' => []]);

        $this->travel(10)->years();

        self::assertSame(
            ['rows' => []],
            $cache->read('reyemtech-hubspot:no-expiry'),
            'The reconciled registry expired. A store that silently reverts to the seeded baseline while '
            .'a diagnostic still reports the portal as synced is worse than one that was never synced.',
        );
    }
}
