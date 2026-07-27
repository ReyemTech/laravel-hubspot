<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Sync;

use ReyemTech\Hubspot\Exceptions\ObjectTypeException;

/**
 * A permanent, committed proof that `Sync` may throw the package's exception hierarchy — required to
 * PASS R3, not to break it. See `Registry/RegistryResolverThrowingOnAMiss.php` for why this directory
 * exists and how it differs from `tests/Arch/Fixtures/`.
 *
 * `ObjectTypeException::unmappable()` is the exception Phase 4's sync raises first: STANDARDS §9 lists
 * it as covering "unknown or unmappable object type", which is precisely an Eloquent model with no
 * HubSpot object type configured. A different member of the hierarchy is used in each of the three
 * layer fixtures here, so between them they prove the whole **namespace** is permitted rather than one
 * convenient class.
 */
final class SyncThrowingAnObjectTypeException
{
    public function objectTypeFor(string $model): string
    {
        if ($model === '') {
            throw ObjectTypeException::unmappable($model);
        }

        return $model;
    }
}
