<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Webhooks;

use ReyemTech\Hubspot\Gateway\Contracts\WebhookSubscriptionGatewayContract;
use ReyemTech\Hubspot\Gateway\WebhookSubscription;

/**
 * **Validated, rendered manual setup guidance for a legacy private app (D-16, HOOK-02).**
 *
 * HubSpot exposes no subscription-management API for a legacy private app --
 * {@see WebhookSubscriptionGatewayContract} backs the classic
 * `/webhooks/v3/{appId}/subscriptions` API only, and legacy private apps cannot call it at all.
 * This class is guidance BY NECESSITY, not preference:
 * `Webhooks\Console\SyncWebhookSubscriptionsCommand`'s `legacy_private` branch never resolves the
 * subscription gateway -- not defensively, not for a capability probe, because there is nothing to
 * probe for.
 *
 * A pure transform with no console dependency of its own -- takes the validated declaration list
 * and the configured target URL as plain values and returns an ordered list of lines, so its whole
 * output is unit-assertable without an Artisan call and the command stays a thin adapter that
 * prints what this class hands it.
 *
 * No template file: the guidance is a handful of lines, and a template would add a
 * publish/discovery question this class does not need to answer.
 *
 * ## Every line is honest about what did NOT happen
 *
 * No line here is phrased as though a remote change occurred -- no "synced", no "applied", no
 * "configured". The closing line states plainly that nothing was changed in HubSpot, so rendered
 * guidance can never be mistaken for an applied change (T-05-24, this plan's must-have
 * prohibition). It also never prints a credential value: it names `HUBSPOT_CLIENT_SECRET` as the
 * env var to set and reads no secret from anywhere, so there is no value in scope to leak.
 *
 * `final` by default (STANDARDS §8); no documented extension point.
 */
final class ManualSetupInstructions
{
    /**
     * @param  list<WebhookSubscription>  $declarations
     * @return list<string>
     */
    public static function render(array $declarations, string $targetUrl): array
    {
        $lines = [
            'HubSpot exposes no subscription-management API for a legacy private app, so this '
                .'package cannot reconcile subscriptions automatically. Complete these steps in '
                .'HubSpot: Settings -> Integrations -> Private Apps -> your app -> Webhooks tab.',
            '', // Visual spacing, not content -- see tests/Support/CommandOutput.php's own docblock.
            sprintf('Set the target URL to: %s', $targetUrl),
            'Set HUBSPOT_CLIENT_SECRET in your .env to the client secret shown on the Auth tab '
                .'of this app.',
            'Add these subscriptions, one per line, under "Subscriptions":',
        ];

        foreach ($declarations as $declaration) {
            $lines[] = self::describe($declaration);
        }

        $lines[] = ''; // Visual spacing, not content -- same rationale as the blank entry above.
        $lines[] = 'Nothing was changed in HubSpot. The steps above are yours to perform.';

        return $lines;
    }

    private static function describe(WebhookSubscription $subscription): string
    {
        return $subscription->propertyName === null
            ? sprintf('  - %s', $subscription->eventType)
            : sprintf('  - %s (property: %s)', $subscription->eventType, $subscription->propertyName);
    }
}
