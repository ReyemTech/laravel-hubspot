<?php

declare(strict_types=1);

/**
 * Fixture for rule R7 — never production code.
 *
 * Target class for R7/SignalsDependsOnSync.php: a trivial Sync-layer class that
 * exists purely so the violating import in the paired fixture resolves to a real class.
 */

namespace ReyemTech\Hubspot\Sync;

final class SyncTarget {}
