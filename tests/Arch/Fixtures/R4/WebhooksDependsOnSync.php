<?php

declare(strict_types=1);

/**
 * Fixture for rule R4 — never production code.
 *
 * Violates: "Webhooks may depend only on Registry and Gateway." This class lives in
 * the Webhooks layer yet depends on Sync, a peer layer Webhooks may never reach into.
 * Paired with R4/SyncTarget.php, which supplies the concrete Sync-layer target class.
 */

namespace ReyemTech\Hubspot\Webhooks;

use ReyemTech\Hubspot\Sync\SyncTarget;

final class WebhooksDependsOnSync
{
    public function __construct(private SyncTarget $target) {}
}
