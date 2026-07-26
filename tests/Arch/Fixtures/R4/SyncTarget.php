<?php

declare(strict_types=1);

/**
 * Fixture for rule R4 — never production code.
 *
 * Target class for R4/WebhooksDependsOnSync.php: a trivial Sync-layer class that
 * exists purely so the violating import in the paired fixture resolves to a real class.
 */

namespace ReyemTech\Hubspot\Sync;

final class SyncTarget {}
