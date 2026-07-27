<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Exceptions;

use LogicException;

/**
 * A caller mistake detectable before any I/O -- a missing or malformed package configuration
 * value (STANDARDS §9, design spec §9). Extends `LogicException`, not `RuntimeException`: the
 * failure is always fixable by correcting config or the environment before retrying, never by
 * retrying the same request unchanged -- unlike `ApiException`, whose family genuinely is a
 * runtime, request-shaped failure.
 */
final class ConfigurationException extends LogicException implements HubspotException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    /**
     * `hubspot.token` (env `HUBSPOT_TOKEN`) is missing or empty. Thrown by
     * `Gateway\HubspotClientFactory::fromConfig()` before any client is constructed (T-02-08,
     * threat register) -- a missing token must never surface as an unauthenticated request that
     * looks like a permissions problem.
     */
    public static function missingToken(): self
    {
        return new self(
            'HUBSPOT_TOKEN is not set. Create a HubSpot Private App access token (HubSpot '
            .'account settings -> Integrations -> Private Apps) and set HUBSPOT_TOKEN in your '
            .'.env file before making any Gateway call.',
        );
    }

    /**
     * `hubspot.store` (env `HUBSPOT_STORE`) is set to a value the package does not recognise.
     *
     * @param  list<string>  $validValues
     */
    public static function unknownStore(string $given, array $validValues): self
    {
        return new self(sprintf(
            'HUBSPOT_STORE is set to "%s", which is not a supported store. Set it to one of: %s.',
            $given,
            implode(', ', $validValues),
        ));
    }
}
