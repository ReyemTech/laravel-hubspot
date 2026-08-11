<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Webhooks;

use ReyemTech\Hubspot\Exceptions\ConfigurationException;

/**
 * **The HubSpot app type `hubspot.webhooks.app_model` names (D-16).**
 *
 * A backed enum, not a validated string: an enum case makes an invalid value unrepresentable past
 * `resolve()`, so `Webhooks\Console\SyncWebhookSubscriptionsCommand` and any future consumer never
 * re-check or re-trust a raw string -- the same reasoning `Gateway\AssociationCategory` already
 * applies to the association category.
 *
 * There is no default case and `resolve()` accepts no fallback: guessing one would either issue
 * remote writes for a consumer who wanted a legacy-private or project-based component export, or
 * silently decline to reconcile for one who wanted the legacy-public API path.
 */
enum AppModel: string
{
    case LegacyPublic = 'legacy_public';
    case LegacyPrivate = 'legacy_private';
    case Project = 'project';

    /**
     * Resolves `hubspot.webhooks.app_model`'s raw config value, or throws naming the three
     * accepted values. Called from `SyncWebhookSubscriptionsCommand::handle()`, never while the
     * application boots (D-12) -- a consumer who never runs `hubspot:webhooks:sync` never pays for
     * an unset or misspelled value.
     */
    public static function resolve(mixed $value): self
    {
        if (! is_string($value) || self::tryFrom($value) === null) {
            throw ConfigurationException::unknownWebhookAppModel(
                is_string($value) ? $value : get_debug_type($value),
                self::values(),
            );
        }

        return self::from($value);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
