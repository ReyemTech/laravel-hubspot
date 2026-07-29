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
        // Names Service Keys, matching config/hubspot.php's own comment. HubSpot classifies
        // Private Apps as legacy, and an error that sends the reader to a different settings page
        // than the documentation costs them a trip to a deprecated screen before they discover the
        // mismatch. The legacy path is still acknowledged in one clause, because tokens already in
        // production keep working and a message implying otherwise would send people to rotate a
        // credential that is fine.
        return new self(
            'HUBSPOT_TOKEN is not set. Create a HubSpot Service Key (HubSpot account settings '
            .'-> Integrations -> Service Keys) and set HUBSPOT_TOKEN in your .env file before '
            .'making any Gateway call. A legacy Private App token also works.',
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

    /**
     * `HUBSPOT_STORE=database` is selected but a table this package owns has never been created.
     * Raised by `Registry\Stores\DatabaseAssociationTypeStore` in place of the driver's own
     * `SQLSTATE[42S02]`, which names neither this package nor the command that fixes it (STANDARDS
     * §9).
     *
     * The second sentence pre-empts the question the first one raises. Every other Laravel package
     * that ships a migration expects `vendor:publish` first, so a reader told to run `migrate` will
     * reasonably go looking for the publish step; this package loads its own migrations when the
     * database store is active, and saying so is what stops them.
     */
    public static function missingRegistryTable(string $table): self
    {
        return new self(sprintf(
            'HUBSPOT_STORE is set to "database" but the "%s" table does not exist. Run '
            .'`php artisan migrate` to create it. Nothing needs publishing first: this package '
            .'loads its own migrations whenever HUBSPOT_STORE=database.',
            $table,
        ));
    }
}
