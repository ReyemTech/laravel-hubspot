<?php

declare(strict_types=1);

/**
 * Fixture for rule R2 — never production code.
 *
 * Target class for R2/RegistryDependsOnSync.php: a trivial Sync-layer class that
 * exists purely so the violating import in the paired fixture resolves to a real class.
 */

namespace ReyemTech\Hubspot\Sync;

final class SyncTarget {}
