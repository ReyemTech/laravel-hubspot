<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Gateway\Contracts;

use ReyemTech\Hubspot\Exceptions\ApiException;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Gateway\WebhookSubscription;

/**
 * **The package-owned subscription-management port -- list, create and update, and nothing else.**
 *
 * Backs `hubspot:webhooks:sync`'s legacy-public reconciliation (D-16, HOOK-02).
 * `Webhooks\Console\SyncWebhookSubscriptionsCommand` may not name `HubSpot\*` (R4), so every
 * operation here takes and returns {@see WebhookSubscription} package values; no `HubSpot\*` type
 * appears in this contract's signature or docblocks, and `tests/Arch/SdkSurfaceTest.php` proves it.
 *
 * ## No delete, archive, deactivate-all or replace method -- deliberately absent, not merely unused
 *
 * D-11 forbids this package from ever removing a portal subscription it did not declare: a delete
 * lands on every account that installed the HubSpot app, not one portal. The strongest form of that
 * guarantee is a contract that cannot even express the call -- a config edit or a bug can only
 * reach a capability this interface declares, and reintroducing a removal method here is a visible,
 * reviewed contract change rather than a quiet one inside an implementation
 * (`tests/Arch/LayerBoundariesTest.php` and the contract's own reflection tests both watch this).
 *
 * ## Every implementation is scoped to one app, resolved at construction
 *
 * There is no `$appId` parameter on any method here. HubSpot's subscription API is app-level, one
 * app id per request path, and this package supports reconciling exactly one app per process
 * (D-16 admits only the legacy-public model for runtime reconciliation) -- so the app id is bound
 * into the implementation at resolution time, the same way {@see WebhookGatewayContract}'s
 * implementation is bound with the configured secret, rather than threaded through every call.
 */
interface WebhookSubscriptionGatewayContract
{
    /**
     * Every subscription this app currently has, in the order HubSpot reports them.
     *
     * An empty list is a legitimate answer -- a freshly created app with no subscriptions yet --
     * and is distinguishable from a failure, which throws.
     *
     * @return list<WebhookSubscription>
     *
     * @throws ApiException if HubSpot rejected the read or was never reached
     * @throws ConfigurationException if the app id or Developer API key is missing
     */
    public function list(): array;

    /**
     * Creates one subscription. `$subscription->portalId` is ignored on the way in -- HubSpot
     * assigns it -- and the returned value carries the portal's own id.
     *
     * @throws ApiException if HubSpot rejected the write or was never reached
     * @throws ConfigurationException if the app id or Developer API key is missing
     */
    public function create(WebhookSubscription $subscription): WebhookSubscription;

    /**
     * Updates one EXISTING subscription, identified by `$subscription->portalId`, which must not
     * be null -- passing one with no portal id is a caller error, since only a subscription
     * {@see self::list()} or {@see self::create()} returned carries one. HubSpot's own update
     * endpoint accepts only the active flag -- event type and property filter are fixed at
     * creation and are never rewritten by this call, which is why a declaration that differs by
     * event type or property is a CREATE (a different identity), never an update.
     *
     * @throws ApiException if HubSpot rejected the write or was never reached
     * @throws ConfigurationException if the app id or Developer API key is missing
     */
    public function update(WebhookSubscription $subscription): WebhookSubscription;
}
