<?php

declare(strict_types=1);

/**
 * Fixture for rule R7 — never production code.
 *
 * Violates: "Signals may not depend on Sync or Webhooks — it is a peer, not a
 * consumer." This class lives in the Signals layer yet depends on Sync. Paired with
 * R7/SyncTarget.php, which supplies the concrete Sync-layer target class.
 */

namespace ReyemTech\Hubspot\Signals;

use ReyemTech\Hubspot\Sync\SyncTarget;

final class SignalsDependsOnSync
{
    public function __construct(private SyncTarget $target) {}
}
