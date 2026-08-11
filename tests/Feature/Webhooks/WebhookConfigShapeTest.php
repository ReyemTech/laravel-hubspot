<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Webhooks;

use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Gateway\Contracts\WebhookSubscriptionGatewayContract;
use ReyemTech\Hubspot\Tests\Support\CommandOutput;
use ReyemTech\Hubspot\Tests\Support\Webhooks\FakeWebhookSubscriptionGateway;
use ReyemTech\Hubspot\Tests\TestCase;
use ReyemTech\Hubspot\Webhooks\HandlerMap;
use ReyemTech\Hubspot\Webhooks\SubscriptionDeclarations;
use Symfony\Component\Console\Command\Command;

/**
 * **Every `hubspot.webhooks.*` key is checked for SHAPE, not merely for presence — and this file
 * is what keeps that true for keys added later.**
 *
 * Six review rounds on PR #71 found the same defect class six times, one key at a time: a value
 * read straight out of config, checked for null-or-empty, and then used. `(int) ''` is `0` and
 * silently meant "prune everything"; a scalar where a list belonged was coerced to `[]` and
 * misreported as "empty"; a scalar where the handler map belonged reached `new HandlerMap()` as a
 * raw `TypeError`; `webhook` passed as a delivery URL; `'deal.creation '` passed as an event type
 * and was forwarded to HubSpot unchanged.
 *
 * Fixing those one at a time is what produced six rounds. `test_every_shipped_webhook_config_key_has_a_decided_shape()`
 * below is the part that ends it: it reads the keys the package actually ships and fails if one of
 * them is not named here, so a new key cannot be added without someone deciding, in writing, what
 * a malformed value of it does.
 *
 * ## The rule these cases share
 *
 * **A configured identifier or URL means exactly what it says. Surrounding whitespace is refused,
 * never trimmed away.** That follows `ConfigurationException::malformedWebhookAppId()`'s existing
 * precedent — "It is not guessed at or coerced" — and it keeps the package from quietly acting on
 * a value that differs from the one written in the config file.
 */
final class WebhookConfigShapeTest extends TestCase
{
    /**
     * Every key the shipped config declares under `webhooks`, each mapped to where its shape is
     * decided. A key with no shape beyond its cast says so explicitly; that is a decision too, and
     * writing it down is the point.
     *
     * @return array<string, string>
     */
    private static function decidedShapes(): array
    {
        return [
            'enforce' => 'bool cast in config; both values are meaningful, so no shape to reject.',
            'secret' => 'Gateway\WebhookGateway rejects null/empty via missingWebhookSecret().',
            'enabled' => 'bool cast in config; both values are meaningful.',
            'retention_days' => 'PruneWebhookEventsCommand rejects < 1 via invalidWebhookRetentionDays().',
            'audit_payload' => 'bool cast in config; both values are meaningful.',
            'claim_lease' => 'DatabaseWebhookEventStore rejects < 1 via invalidWebhookClaimLease().',
            'handlers' => 'ServiceProvider rejects a non-array via invalidWebhookHandlerMap(); HandlerMap::validate() rejects each entry.',
            'app_model' => 'Webhooks\AppModel::resolve() rejects anything outside the three cases.',
            'app_id' => 'Gateway\HubspotClientFactory rejects a non-canonical id via malformedWebhookAppId().',
            'developer_api_key' => 'Gateway\HubspotClientFactory rejects null/empty via missingWebhookManagementCredentials().',
            'target_url' => 'SyncWebhookSubscriptionsCommand rejects absent, padded and non-absolute URLs.',
            'subscriptions' => 'Webhooks\SubscriptionDeclarations rejects a non-array, and every malformed or padded entry.',
        ];
    }

    /**
     * The guard. Reads the keys this package actually ships — `config('hubspot.webhooks')` is the
     * merged shipped file, not a copy — and fails when one of them is not accounted for above.
     *
     * A new key therefore cannot be added without deciding what a malformed value of it does. That
     * decision may legitimately be "the cast covers it", but it has to be made and written down
     * rather than discovered by a reviewer one round later.
     */
    public function test_every_shipped_webhook_config_key_has_a_decided_shape(): void
    {
        /** @var array<string, mixed> $webhooks */
        $webhooks = config('hubspot.webhooks');

        $shipped = array_keys($webhooks);
        sort($shipped);

        $decided = array_keys(self::decidedShapes());
        sort($decided);

        self::assertSame(
            $decided,
            $shipped,
            'A hubspot.webhooks.* key was added or removed without deciding its shape. Add it to '
            .'decidedShapes() with a note naming where a malformed value is rejected.',
        );
    }

    /**
     * A scalar or null where the handler MAP belongs reached `new HandlerMap($handlers)` as a raw
     * `TypeError`. Receipt has already answered 204 by then, so every event on that deployment
     * failed in the worker with a PHP type error instead of the directed configuration message
     * this package exists to give.
     */
    #[DataProvider('malformedHandlerMaps')]
    public function test_a_non_array_handler_map_raises_a_directed_error(mixed $handlers): void
    {
        config(['hubspot.webhooks.handlers' => $handlers]);

        $this->expectException(ConfigurationException::class);

        app(HandlerMap::class);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function malformedHandlerMaps(): array
    {
        return [
            'a bare class-string where the map belongs' => ['App\\Webhooks\\SyncContact'],
            'an integer' => [42],
            'a bool' => [true],
        ];
    }

    /** An empty map is legitimate -- wiring no handlers is a normal configuration. */
    public function test_an_empty_handler_map_is_accepted(): void
    {
        config(['hubspot.webhooks.handlers' => []]);

        self::assertInstanceOf(HandlerMap::class, app(HandlerMap::class));
    }

    /**
     * `target_url` is embedded in both non-API artefacts and is the address HubSpot delivers to. A
     * non-absolute or padded value produces a component HubSpot cannot deliver to, and the command
     * exits zero while producing it.
     */
    #[DataProvider('malformedTargetUrls')]
    public function test_a_target_url_that_is_not_an_absolute_http_url_fails_the_command(string $targetUrl): void
    {
        config([
            'hubspot.webhooks.app_model' => 'project',
            'hubspot.webhooks.target_url' => $targetUrl,
            'hubspot.webhooks.subscriptions' => [['event_type' => 'deal.creation']],
        ]);

        app()->instance(
            WebhookSubscriptionGatewayContract::class,
            new FakeWebhookSubscriptionGateway,
        );

        $exitCode = Artisan::call('hubspot:webhooks:sync');
        $joined = implode("\n", CommandOutput::linesOf(Artisan::output()));

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('HUBSPOT_WEBHOOK_TARGET_URL', $joined);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedTargetUrls(): array
    {
        return [
            'relative path' => ['webhook'],
            'rooted path with no host' => ['/hubspot/webhook'],
            'a non-http scheme' => ['javascript:alert(1)'],
            'ftp' => ['ftp://example.com/hook'],
            'padded with a trailing space' => ['https://app.example.com/hubspot/webhook '],
            'padded with a leading space' => [' https://app.example.com/hubspot/webhook'],
            'scheme only' => ['https://'],
        ];
    }

    /** Both http and https absolute URLs still work -- the guard rejects malformed, not merely unusual. */
    public function test_an_absolute_http_target_url_is_accepted(): void
    {
        config([
            'hubspot.webhooks.app_model' => 'project',
            'hubspot.webhooks.target_url' => 'http://localhost:8000/hubspot/webhook',
            'hubspot.webhooks.subscriptions' => [['event_type' => 'deal.creation']],
        ]);

        app()->instance(
            WebhookSubscriptionGatewayContract::class,
            new FakeWebhookSubscriptionGateway,
        );

        self::assertSame(Command::SUCCESS, Artisan::call('hubspot:webhooks:sync'));
    }

    /**
     * A padded identifier is not the identifier that was written. It reaches HubSpot unchanged on
     * the legacy-public path, and is written into a deployable artefact on the project path -- and
     * because `WebhookSubscription::identity()` keys on these values, a padded copy of a declared
     * type reads as a DIFFERENT subscription rather than as the duplicate it is.
     *
     * @param  array<string, string>  $entry
     */
    #[DataProvider('paddedDeclarations')]
    public function test_a_padded_subscription_identifier_is_refused(array $entry): void
    {
        config(['hubspot.webhooks.subscriptions' => [$entry]]);

        $this->expectException(ConfigurationException::class);

        app(SubscriptionDeclarations::class)->all();
    }

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function paddedDeclarations(): array
    {
        return [
            'trailing space on the event type' => [['event_type' => 'deal.creation ']],
            'leading space on the event type' => [['event_type' => ' deal.creation']],
            'newline on the event type' => [['event_type' => "deal.creation\n"]],
            'trailing space on the property name' => [
                ['event_type' => 'contact.propertyChange', 'property_name' => 'email '],
            ],
            'leading space on the property name' => [
                ['event_type' => 'contact.propertyChange', 'property_name' => ' email'],
            ],
        ];
    }

    /** The unpadded equivalents still resolve, so the guard rejects padding and nothing else. */
    public function test_unpadded_declarations_are_still_accepted(): void
    {
        config(['hubspot.webhooks.subscriptions' => [
            ['event_type' => 'deal.creation'],
            ['event_type' => 'contact.propertyChange', 'property_name' => 'email'],
        ]]);

        self::assertCount(2, app(SubscriptionDeclarations::class)->all());
    }
}
