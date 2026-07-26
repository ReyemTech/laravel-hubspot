<?php

declare(strict_types=1);

/**
 * Fixture for rule R3 — never production code.
 *
 * Target class for R3/SyncDependsOnWebhooks.php: a trivial Webhooks-layer class that
 * exists purely so the violating import in the paired fixture resolves to a real class.
 */

namespace ReyemTech\Hubspot\Webhooks;

final class WebhooksTarget {}
