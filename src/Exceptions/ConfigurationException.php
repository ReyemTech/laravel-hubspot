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
    public static function missingWebhookEventsTable(bool $featureEnabled = true): self
    {
        // Two states, two messages, because the fix differs and a wrong diagnosis costs more than
        // no diagnosis. This used to assert "HUBSPOT_WEBHOOKS is true" unconditionally -- which on
        // a default install was simply false, and sent the reader to verify a setting that was
        // already correct while the actual cause (the flag being OFF) went unmentioned.
        if (! $featureEnabled) {
            return new self(
                'Receiving webhooks requires HUBSPOT_WEBHOOKS=true, and it is currently false. The '
                .'"hubspot_webhook_events" table is how a redelivered eventId is handled exactly '
                .'once, so receipt cannot run without it. Set HUBSPOT_WEBHOOKS=true and run `php '
                .'artisan migrate` (+ `php artisan config:cache` if you cache config). Nothing '
                .'needs publishing first: this package loads its own migrations whenever '
                .'HUBSPOT_WEBHOOKS=true.',
            );
        }

        return new self(
            'HUBSPOT_WEBHOOKS is true but the "hubspot_webhook_events" table does not exist. Run '
            .'`php artisan migrate` to create it. Nothing needs publishing first: this package '
            .'loads its own migrations whenever HUBSPOT_WEBHOOKS=true.',
        );
    }

    /**
     * `hubspot.webhooks.retention_days` resolved to zero or less. Raised by
     * `Webhooks\Console\PruneWebhookEventsCommand::handle()` BEFORE the cutoff is computed, because
     * the cutoff is what does the damage: at zero days it is the present moment and at a negative
     * value it is in the future, so a prune deletes every handled row rather than the ones past
     * retention.
     *
     * Those rows are the dedupe history, not merely an audit trail — HOOK-01's promise that a
     * redelivered `eventId` is a no-op is exactly what they carry, so wiping them turns the next
     * HubSpot redelivery of any previously-handled event into a second run of every handler.
     *
     * The message names the `(int)` cast explicitly. `config/hubspot.php` writes
     * `(int) env('HUBSPOT_WEBHOOK_RETENTION_DAYS', 30)`, and `(int) ''` is `0`, so the likeliest
     * way to arrive here is a key copied into `.env` and left blank — which looks nothing like a
     * number the operator chose.
     */
    public static function invalidWebhookRetentionDays(int $retentionDays): self
    {
        return new self(sprintf(
            'HUBSPOT_WEBHOOK_RETENTION_DAYS must be a whole number of days of at least 1, but '
            .'resolved to %d. Nothing was pruned: a retention of zero or less puts the cutoff at '
            .'or after the present moment, so this command would delete every handled record '
            .'rather than the ones past retention -- and those records are what make a HubSpot '
            .'redelivery a no-op. Note that a blank or non-numeric value becomes 0.',
            $retentionDays,
        ));
    }

    /**
     * `hubspot.webhooks.claim_lease` resolved to zero or less. Raised by
     * `Webhooks\Stores\DatabaseWebhookEventStore`'s constructor, so a store that cannot honour
     * exactly-once never hands out a claim at all.
     *
     * The lease deadline is `now() - claim_lease`. At zero that deadline is the present moment,
     * and the comparison is not even a tie: persisted timestamps carry second precision while
     * `Carbon::now()` carries microseconds, so a claim taken moments ago already reads as expired
     * and a concurrent worker or a HubSpot redelivery reclaims an event still in flight. A negative
     * value puts the deadline in the future and makes every claim reclaimable outright.
     *
     * Same `(int)` cast caveat as {@see self::invalidWebhookRetentionDays()}.
     */
    public static function invalidWebhookClaimLease(int $claimLeaseSeconds): self
    {
        return new self(sprintf(
            'hubspot.webhooks.claim_lease must be a whole number of seconds of at least 1, but is '
            .'%d. A lease of zero or less makes a claim taken moments ago read as already expired, '
            .'so a redelivery or a second worker would reclaim an event still being handled and '
            .'run every handler for it twice. Note that a blank or non-numeric value becomes 0.',
            $claimLeaseSeconds,
        ));
    }

    /**
     * `hubspot.webhooks.app_id` is set but is not a canonical positive integer. Raised by
     * `Gateway\HubspotClientFactory::forWebhookManagement()` -- named as a PATH, not as a
     * `{@see}`, because pint's fully_qualified_strict_types rule rewrites a docblock class
     * reference into a real `use` statement and this layer must not import Gateway (the same
     * reason `Sync\SyncGate` records for its own docblock) -- before any
     * client exists, because the value is later cast with `(int)` to address the subscriptions
     * endpoint and that cast is lossy: `"123abc"` becomes `123`, an app that exists and is not
     * yours. Reconciliation is app-level, so the blast radius is every account with that app
     * installed (T-05-17).
     */
    public static function malformedWebhookAppId(string $appId): self
    {
        return new self(sprintf(
            'HUBSPOT_WEBHOOK_APP_ID must be a HubSpot app id -- digits only, no leading zero -- '
            .'but is "%s". It is not guessed at or coerced: a value like "123abc" would otherwise '
            .'become app 123 and reconcile THAT app\'s subscriptions, for every account it is '
            .'installed on. Find the numeric id on the app\'s "Auth" tab in your HubSpot developer '
            .'account.',
            $appId,
        ));
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

    /**
     * `hubspot.webhooks.app_id` or `hubspot.webhooks.developer_api_key` is missing or empty while
     * `Gateway\HubspotClientFactory::forWebhookManagement()` is building the management client
     * `hubspot:webhooks:sync` needs (D-16, HOOK-02, T-05-17). Thrown before any client is
     * constructed, for the same reason `missingToken()` is: a missing credential must never surface
     * as an unauthenticated request that looks like a permissions problem.
     *
     * Named as a THIRD, distinct credential class throughout, because this package already has two:
     * `hubspot.token` (the CRM access token) and `hubspot.webhooks.secret` (the inbound signature
     * secret). A Developer API key authenticates neither of those calls, and neither of those
     * credentials authenticates this one.
     */
    public static function missingWebhookManagementCredentials(): self
    {
        return new self(
            'HUBSPOT_WEBHOOK_APP_ID and HUBSPOT_DEVELOPER_API_KEY must both be set to run '
            .'hubspot:webhooks:sync. Find the app ID on the app\'s "Auth" tab in your HubSpot '
            .'developer account, and create a Developer API key from your HubSpot account '
            .'(Settings -> Integrations -> Private Apps is NOT it -- look for "Get HubSpot API '
            .'key" / the legacy Developer API key page for your developer account). This is a '
            .'THIRD credential, distinct from HUBSPOT_TOKEN (the CRM access token) and '
            .'HUBSPOT_CLIENT_SECRET (the webhook signature secret) -- a HubSpot Service Key is '
            .'never accepted here, only a Developer API key.',
        );
    }

    /**
     * `hubspot.webhooks.app_model` is unset or holds a value `Webhooks\AppModel` does not
     * recognise (D-16). Thrown by `Webhooks\AppModel::resolve()`, reached from
     * `Webhooks\Console\SyncWebhookSubscriptionsCommand::handle()` -- never while the application
     * boots, since a consumer who never runs the sync command should never pay for this key.
     *
     * Deliberately no default: guessing one would either issue remote writes for a consumer who
     * wanted a legacy-private or project-based component export, or silently refuse to reconcile
     * for one who wanted the legacy-public API path -- the same reasoning `unknownStore()` already
     * applies to `hubspot.store`.
     *
     * @param  list<string>  $validValues
     */
    public static function unknownWebhookAppModel(string $given, array $validValues): self
    {
        return new self(sprintf(
            'hubspot.webhooks.app_model is set to "%s", which is not a supported app model. '
            .'Supported values are: %s. There is no default -- set it explicitly to the HubSpot '
            .'app type this application actually is.',
            $given,
            implode(', ', $validValues),
        ));
    }

    /**
     * An entry in `hubspot.webhooks.subscriptions` is not an array, names no `event_type`, or names
     * a `property_name` that is not a string (D-10, D-12). Thrown by
     * `Webhooks\SubscriptionDeclarations::all()`, reached only when the command reads declarations
     * -- never while the application boots, so a consumer who never runs `hubspot:webhooks:sync`
     * never pays for a malformed entry they have not touched yet.
     */
    public static function invalidWebhookSubscription(mixed $entry): self
    {
        return new self(sprintf(
            'hubspot.webhooks.subscriptions contains an invalid entry: %s. Each entry must be an '
            .'array naming "event_type" (a string, for example "contact.propertyChange") and, '
            .'only for a *.propertyChange event type, "property_name" (the internal HubSpot '
            .'property name to filter on). Given: %s.',
            get_debug_type($entry),
            var_export($entry, true),
        ));
    }

    /**
     * Two entries in `hubspot.webhooks.subscriptions` share the same identity -- the same event
     * type and, when present, the same property name (D-10). Thrown by
     * `Webhooks\SubscriptionDeclarations::all()`. Two declarations that would resolve to the same
     * portal subscription make the command's own matching ambiguous: which one is the desired
     * state, and which one is the duplicate the operator meant to delete?
     */
    public static function duplicateWebhookSubscription(string $eventType, ?string $propertyName): self
    {
        return new self(sprintf(
            'hubspot.webhooks.subscriptions declares "%s"%s more than once. Remove the duplicate '
            .'entry -- two declarations that resolve to the same portal subscription make the '
            .'sync command\'s own matching ambiguous.',
            $eventType,
            $propertyName === null ? '' : sprintf(' with property "%s"', $propertyName),
        ));
    }

    /**
     * `hubspot.webhooks.target_url` (env `HUBSPOT_WEBHOOK_TARGET_URL`) is missing or empty while
     * either non-API app-model path is about to render an artefact that embeds it (D-16, HOOK-02).
     * Thrown by `Webhooks\Console\SyncWebhookSubscriptionsCommand` before rendering either
     * `Webhooks\ManualSetupInstructions` or `Webhooks\ProjectWebhookComponent` -- never while the
     * application boots, since a consumer who never runs `hubspot:webhooks:sync` never pays for it.
     *
     * The message says why a WRONG value is dangerous, not merely that a missing one is broken:
     * HubSpot signs the URI it actually calls, so a target URL that does not match where
     * `Route::hubspotWebhook()` is mounted produces rejected deliveries that look exactly like a
     * credential problem rather than a configuration one -- the same failure mode
     * `missingWebhookSecret()` exists to name before it is mistaken for something else.
     */
    public static function missingWebhookTargetUrl(): self
    {
        return new self(
            'HUBSPOT_WEBHOOK_TARGET_URL is not set. Set it to the absolute URL where '
            .'Route::hubspotWebhook() is mounted -- the exact URL HubSpot will call. A wrong '
            .'value here is dangerous, not merely broken: HubSpot signs the URI it calls, so a '
            .'mismatch produces rejected deliveries that look like a credential problem rather '
            .'than a configuration one.',
        );
    }
}
