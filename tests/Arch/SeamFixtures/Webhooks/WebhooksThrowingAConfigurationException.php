<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Webhooks;

use ReyemTech\Hubspot\Exceptions\ConfigurationException;

/**
 * A permanent, committed proof that `Webhooks` may throw the package's exception hierarchy — required
 * to PASS R4. See `Registry/RegistryResolverThrowingOnAMiss.php` for why this directory exists.
 *
 * `ConfigurationException` is the member Phase 5's webhook layer reaches for first: signature
 * verification fails closed, and "the credential this needs is not configured" is a configuration
 * fault rather than an API one. The three layer fixtures here each throw a *different* member of the
 * hierarchy, so together they prove the whole namespace is permitted.
 */
final class WebhooksThrowingAConfigurationException
{
    public function assertVerifiable(bool $credentialPresent): void
    {
        if (! $credentialPresent) {
            throw ConfigurationException::missingToken();
        }
    }
}
