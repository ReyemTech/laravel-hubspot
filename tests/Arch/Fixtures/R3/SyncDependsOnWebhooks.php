<?php

declare(strict_types=1);

/**
 * Fixture for rule R3 — never production code.
 *
 * Violates: "Sync may depend only on Registry and Gateway." This class lives in the
 * Sync layer yet depends on Webhooks, a peer layer Sync may never reach into. Paired
 * with R3/WebhooksTarget.php, which supplies the concrete Webhooks-layer target class.
 */

namespace ReyemTech\Hubspot\Sync;

use ReyemTech\Hubspot\Webhooks\WebhooksTarget;

final class SyncDependsOnWebhooks
{
    public function __construct(private WebhooksTarget $target) {}
}
