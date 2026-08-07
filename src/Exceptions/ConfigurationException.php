<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Exceptions;

use LogicException;
use ReyemTech\Hubspot\Webhooks\Contracts\WebhookHandler;

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

    /**
     * A `hubspot.models` binding declares no `id_property` (D-12). Thrown by
     * `Sync\ModelBindings::validate()` from `ServiceProvider::boot()`, before any observer is
     * attached -- a binding with no `id_property` cannot converge a retried write onto the
     * correct record (D-11), and the failure mode of guessing one is a silent upsert onto the
     * wrong CRM field, which is this package's own recurring silent-wrong-id threat class.
     */
    public static function missingIdProperty(string $modelClass): self
    {
        return new self(sprintf(
            '%s is bound in hubspot.models but has no "id_property" set. Add the HubSpot '
            .'property this model upserts on, for example \'id_property\' => \'email\' for a '
            .'model bound to the "contacts" object. Without it, an upsert has no property to '
            .'converge on and this package refuses to guess one.',
            $modelClass,
        ));
    }

    /**
     * A binding's `id_property` is not a key its own model's `$hubspotMap` produces. Distinct
     * from `missingIdProperty()`: that is a config shape rejected before anything runs; this is
     * only detectable once a sync is attempted, because it depends on the model's own map, which
     * `ServiceProvider::boot()` cannot see. Thrown by `Sync\SyncHubspotObjectJob::handle()`.
     */
    public static function idPropertyNotMapped(string $modelClass, string $idProperty): self
    {
        return new self(sprintf(
            '%s is bound to HubSpot with id_property "%s", but its $hubspotMap does not produce '
            .'that key. Add an entry to $hubspotMap that maps "%s" to one of the model\'s own '
            .'attributes, so the upsert has a value to converge on.',
            $modelClass,
            $idProperty,
            $idProperty,
        ));
    }

    public static function duplicateBatchIdentifier(string $modelClass, string $idProperty, string $value): self
    {
        return new self(sprintf(
            '%s has multiple unlinked models with the same %s. A HubSpot batch upsert response cannot '
            .'establish which local model owns that identifier, so this package refuses to guess.',
            $modelClass,
            $idProperty,
        ));
    }

    public static function duplicateBatchLinkedHubspotId(string $modelClass, string $objectType): self
    {
        return new self(sprintf(
            '%s has multiple linked models for the same HubSpot %s record. A batch update cannot '
            .'safely correlate its response, so correct the duplicate hubspot_object_links rows before retrying.',
            $modelClass,
            $objectType,
        ));
    }

    /**
     * A model applies `Sync\SyncsToHubspot` but has no entry in `hubspot.models` -- D-12's
     * inverse. Thrown by `Sync\ModelBindings::for()`, the single resolution point every Sync
     * collaborator that needs a model's binding reaches (`SyncsToHubspot::hubspotLink()` and every
     * scope built on it, `HubspotObserver`, `SyncHubspotObjectJob`): a genuinely unbound model
     * fails here, naming the fix, rather than guessing which HubSpot object type it belongs to.
     */
    public static function unboundSyncModel(string $modelClass): self
    {
        return new self(sprintf(
            '%s uses ReyemTech\Hubspot\Sync\SyncsToHubspot but has no entry in hubspot.models. '
            .'Add one naming the HubSpot object it syncs to and the property it upserts on, for '
            .'example \'%s\' => [\'object\' => \'contacts\', \'id_property\' => \'email\']. This '
            .'package never guesses which object type an unbound model belongs to.',
            $modelClass,
            $modelClass,
        ));
    }

    /**
     * `hubspot.auto_sync.hard_delete` is set to a value the package does not recognise (SYNC-04).
     * Thrown by `Sync\DeletePolicy::resolve()`, from inside whichever delete event consulted it.
     *
     * There is no safe fallback and the message says which way each supported value falls, because
     * both fallbacks are wrong in opposite directions: defaulting a typo to `allow` issues
     * irreversible archives nobody asked for, and defaulting it to `guard` silently stops mirroring
     * deletes an operator believed were mirrored (T-04-26).
     *
     * @param  list<string>  $validValues
     */
    public static function unknownHardDeletePolicy(string $given, array $validValues): self
    {
        return new self(sprintf(
            'hubspot.auto_sync.hard_delete is set to "%s", which is not a supported delete '
            .'policy. Supported values are: %s. "guard" and "warn" both SKIP the archive and '
            .'differ only in log level; only "allow" archives in HubSpot, which cannot be undone '
            .'through the API.',
            $given,
            implode(', ', $validValues),
        ));
    }

    /**
     * `hubspot.auto_sync.on_restore` is set to a value the package does not recognise (SYNC-04).
     * Thrown by `Sync\DeletePolicy::resolve()` on the `restored` event and on no other.
     *
     * The message names the consequence of each supported value rather than only listing them: the
     * two do not differ in loudness, they differ in whether the local row keeps pointing at the CRM
     * history it already has.
     *
     * @param  list<string>  $validValues
     */
    public static function unknownRestorePolicy(string $given, array $validValues): self
    {
        return new self(sprintf(
            'hubspot.auto_sync.on_restore is set to "%s", which is not a supported restore '
            .'policy. Supported values are: %s. HubSpot has no unarchive endpoint, so "flag" '
            .'keeps the stored hubspot_id and marks it stale. "recreate" is not implemented in '
            .'this release: creating a replacement has to be ordered after the earlier archive '
            .'confirms completion, and this package cannot yet guarantee that ordering.',
            $given,
            implode(', ', $validValues),
        ));
    }

    /**
     * `Sync\DeletePolicy::resolve()` was handed an event it does not model. Reachable only from a
     * direct call -- `Sync\HubspotObserver` passes one of four literals -- which is exactly why it
     * throws: `DeletePolicy` is public API of a released package, and a delete-policy resolver that
     * answered a question it did not understand would answer it with either an irreversible archive
     * or a silently dropped mirror.
     *
     * @param  list<string>  $validValues
     */
    public static function unknownDeleteEvent(string $given, array $validValues): self
    {
        return new self(sprintf(
            'ReyemTech\Hubspot\Sync\DeletePolicy cannot resolve the "%s" Eloquent event. It models '
            .'these and only these: %s. Each one answers a different row of the delete-policy '
            .'table, and this resolver never guesses a row it was not given.',
            $given,
            implode(', ', $validValues),
        ));
    }

    /**
     * `hubspot.webhooks.secret` (env `HUBSPOT_CLIENT_SECRET`) is missing or empty while
     * `Gateway\WebhookGateway::verify()` is being asked to check a signature (HOOK-01, T-05-01).
     * Thrown before `HubSpot\Utils\Signature::isValid()` is ever called: handing that SDK call a
     * null or empty secret would silently coerce it into an HMAC key of nothing, which is the
     * opposite of the fail-closed default D-20 requires.
     */
    public static function missingWebhookSecret(): self
    {
        return new self(
            'HUBSPOT_CLIENT_SECRET is not set. Set it to the client secret of the HubSpot app '
            .'that sends this webhook -- verification fails closed until it is.',
        );
    }

    /**
     * `HUBSPOT_WEBHOOKS=true` is set but the `hubspot_webhook_events` table this package owns has
     * never been created. Raised by `Webhooks\Stores\DatabaseWebhookEventStore` in place of the
     * driver's own `SQLSTATE[42S02]`, mirroring `missingRegistryTable()`'s shape exactly: name the
     * table, name `php artisan migrate`, and pre-empt the publish question, because every other
     * Laravel package that ships a migration expects `vendor:publish` first and this one does not.
     */
    public static function missingWebhookEventsTable(): self
    {
        return new self(
            'HUBSPOT_WEBHOOKS is true but the "hubspot_webhook_events" table does not exist. Run '
            .'`php artisan migrate` to create it. Nothing needs publishing first: this package '
            .'loads its own migrations whenever HUBSPOT_WEBHOOKS=true.',
        );
    }

    /**
     * A `hubspot.webhooks.handlers` entry is not a class-string naming a class that exists and
     * implements {@see WebhookHandler} (D-07). Thrown by `Webhooks\HandlerMap::validate()`, called
     * from `ProcessWebhookEventJob::handle()` before the durable claim from 05-02 is taken -- a
     * configuration typo must not burn a claim, and must not emit half an item's events before
     * failing.
     *
     * Three distinct causes share this one factory, and the message names whichever one actually
     * happened rather than a single generic sentence: a non-string value, a class name that does not
     * exist, and a class that exists but does not implement the interface are three different fixes.
     */
    public static function invalidWebhookHandler(mixed $value, string $eventKey): self
    {
        if (! is_string($value)) {
            return new self(sprintf(
                'hubspot.webhooks.handlers["%s"] contains a %s, which is not a class name string. '
                .'Each entry must be a string naming a class, or a list of such strings, that '
                .'implements %s.',
                $eventKey,
                get_debug_type($value),
                WebhookHandler::class,
            ));
        }

        if (! class_exists($value)) {
            return new self(sprintf(
                'hubspot.webhooks.handlers["%s"] names "%s", which is not a class that exists. '
                .'Correct the class name in config/hubspot.php, or remove the entry.',
                $eventKey,
                $value,
            ));
        }

        return new self(sprintf(
            'hubspot.webhooks.handlers["%s"] names "%s", which does not implement %s. Add '
            .'"implements %s" to the class, or remove it from config/hubspot.php.',
            $eventKey,
            $value,
            WebhookHandler::class,
            WebhookHandler::class,
        ));
    }
}
