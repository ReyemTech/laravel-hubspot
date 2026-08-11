<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Webhooks;

use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Gateway\WebhookSubscription;

/**
 * **The exportable project webhook component for a current, project-based HubSpot app (D-16, HOOK-02).**
 *
 * A project-based app declares its webhook subscriptions as a config artefact deployed WITH the
 * project -- `src/app/webhooks/<name>-hsmeta.json` inside the app's project folder -- rather than
 * through a runtime management API a project-based app does not expose (D-16). This class renders
 * that exact artefact and nothing else: it issues no HubSpot request, and
 * `Webhooks\Console\SyncWebhookSubscriptionsCommand`'s `project` branch never resolves the
 * subscription gateway on this path either.
 *
 * ## Verified against live documentation, not recalled
 *
 * Field names and shape verified against
 * https://developers.hubspot.com/docs/apps/developer-platform/add-features/configure-webhooks
 * (checked 2026-08-06; the page itself states "Last modified on March 29, 2026"). Declared
 * subscriptions map onto the documented `legacyCrmObjects` array -- the classic
 * `subscriptionType`/`propertyName`/`active` shape `hubspot.webhooks.subscriptions` already uses,
 * the same shape the `legacy_public` runtime path (`Gateway\WebhookSubscriptionGateway`)
 * reconciles against the classic `/webhooks/v3/{appId}/subscriptions` API. Two sibling arrays the
 * documented schema also defines -- `crmObjects` (the newer `object.*` event family) and
 * `hubEvents` (`contact.privacyDeletion`, `conversation.*`) -- are rendered empty:
 * `Webhooks\SubscriptionDeclarations` has no config shape that produces either, and emitting an
 * empty array is honest about that rather than omitting a key the documented schema shows present.
 *
 * ## Returns data, encodes separately
 *
 * {@see self::render()} returns a plain PHP array so the structure is assertable field by field
 * (05-PATTERNS.md), never by string comparison against a formatted document. {@see self::encode()}
 * is the only place this class turns that into text.
 *
 * `final` by default (STANDARDS §8); no documented extension point.
 */
final class ProjectWebhookComponent
{
    /**
     * The upper threshold of concurrent HTTP requests HubSpot will make to `targetUrl` --
     * HubSpot's own documented example value, and a reasonable default for a package with no
     * config key asking for a different one.
     *
     * A method rather than a class constant: `pest --mutate` reports a mutation on a constant
     * declaration as UNCOVERED, because a constant has no executed line for coverage to attribute
     * a test to -- the same reasoning `ServiceProvider::supportedStores()` already applies.
     */
    private static function maxConcurrentRequests(): int
    {
        return 10;
    }

    /**
     * @param  list<WebhookSubscription>  $declarations
     * @return array<string, mixed>
     */
    public static function render(array $declarations, string $targetUrl): array
    {
        foreach ($declarations as $declaration) {
            $section = self::sectionFor($declaration->eventType);

            // Refused, never filed under the one section this class populates. A component whose
            // subscription sits in the wrong section still DEPLOYS -- the operator learns nothing
            // until deliveries never arrive -- so the artefact declines to render instead.
            if ($section !== 'legacyCrmObjects') {
                throw ConfigurationException::projectComponentCannotExpressSubscription(
                    $declaration->eventType,
                    $section,
                );
            }
        }

        return [
            'uid' => 'webhooks',
            'type' => 'webhooks',
            'config' => [
                'settings' => [
                    'targetUrl' => $targetUrl,
                    'maxConcurrentRequests' => self::maxConcurrentRequests(),
                ],
                'subscriptions' => [
                    'crmObjects' => [],
                    'legacyCrmObjects' => array_map(self::toLegacyCrmObject(...), $declarations),
                    'hubEvents' => [],
                ],
            ],
        ];
    }

    /**
     * Which of the documented component sections an event type belongs to.
     *
     * The three families come from this class's own verified reading of HubSpot's schema (see the
     * class docblock): `object.*` is the newer CRM-object family carried in `crmObjects`, and
     * `contact.privacyDeletion` and `conversation.*` are carried in `hubEvents`. Everything else is
     * a classic subscription type and belongs in `legacyCrmObjects`, the section this class
     * renders.
     *
     * A prefix/exact table rather than a regex, and it answers with the SECTION rather than a
     * boolean, so the refusal can name where the declaration actually belongs instead of only
     * saying no.
     */
    private static function sectionFor(string $eventType): string
    {
        if (str_starts_with($eventType, 'object.')) {
            return 'crmObjects';
        }

        if ($eventType === 'contact.privacyDeletion' || str_starts_with($eventType, 'conversation.')) {
            return 'hubEvents';
        }

        return 'legacyCrmObjects';
    }

    /**
     * @param  array<string, mixed>  $component
     *
     * @throws \JsonException
     */
    public static function encode(array $component): string
    {
        return json_encode($component, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private static function toLegacyCrmObject(WebhookSubscription $declaration): array
    {
        $entry = ['subscriptionType' => $declaration->eventType];

        if ($declaration->propertyName !== null) {
            $entry['propertyName'] = $declaration->propertyName;
        }

        $entry['active'] = $declaration->active;

        return $entry;
    }
}
