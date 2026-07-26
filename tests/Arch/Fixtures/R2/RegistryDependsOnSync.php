<?php

declare(strict_types=1);

/**
 * Fixture for rule R2 — never production code.
 *
 * Violates: "Registry may depend only on Gateway." This class lives in the Registry
 * layer yet depends on Sync, a layer Registry may never reach into. Paired with
 * R2/SyncTarget.php, which supplies the concrete Sync-layer class being depended on
 * (arch's dependency detection requires the target class to actually resolve).
 */

namespace ReyemTech\Hubspot\Registry;

use ReyemTech\Hubspot\Sync\SyncTarget;

final class RegistryDependsOnSync
{
    public function __construct(private SyncTarget $target) {}
}
