<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Webhooks;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Gateway\HubspotClientFactory;
use ReyemTech\Hubspot\Tests\TestCase;
use ReyemTech\Hubspot\Webhooks\Contracts\WebhookEventStore;
use ReyemTech\Hubspot\Webhooks\NormalizedWebhookEvent;

final class WebhookConfigurationGuardsTest extends TestCase
{
    private function event(): NormalizedWebhookEvent
    {
        return new NormalizedWebhookEvent(
            eventId: 'guard-1',
            subscriptionType: 'contact.propertyChange',
            portalId: 1,
            appId: null,
            objectId: '1',
            occurredAt: new DateTimeImmutable('2026-08-07T00:00:00+00:00'),
            attemptNumber: 0,
        );
    }

    /**
     * The message must describe the state the operator is actually in. On a default install
     * HUBSPOT_WEBHOOKS is FALSE, and the shipped text asserted the opposite — sending anyone who
     * read it to check a setting that was already correct, for a table that was never going to
     * exist until they changed a different one.
     */
    public function test_a_default_install_is_not_told_the_flag_is_true(): void
    {
        self::assertFalse(config('hubspot.webhooks.enabled'), 'shipped default');

        try {
            app(WebhookEventStore::class)->claim($this->event());
            self::fail('expected a ConfigurationException');
        } catch (ConfigurationException $e) {
            self::assertStringNotContainsString('HUBSPOT_WEBHOOKS is true', $e->getMessage());
            self::assertStringContainsString('HUBSPOT_WEBHOOKS', $e->getMessage());
            self::assertStringContainsString('php artisan migrate', $e->getMessage());
        }
    }

    /** With the flag genuinely on, the existing message stays correct. */
    public function test_an_enabled_but_unmigrated_install_is_told_the_flag_is_true(): void
    {
        config(['hubspot.webhooks.enabled' => true]);

        try {
            app(WebhookEventStore::class)->claim($this->event());
            self::fail('expected a ConfigurationException');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('HUBSPOT_WEBHOOKS is true', $e->getMessage());
            self::assertStringContainsString('php artisan migrate', $e->getMessage());
        }
    }

    /**
     * `hubspot:webhooks:sync` reconciles APP-LEVEL subscriptions, so the app id selects whose
     * subscriptions get rewritten — across every account with the app installed. A non-numeric or
     * malformed value was accepted by the null/empty check and then silently coerced by `(int)`,
     * so `"123abc"` reconciled app 123: a different, real, arbitrary app.
     *
     * @param  non-empty-string  $appId
     */
    #[DataProvider('malformedAppIds')]
    public function test_a_malformed_app_id_is_refused_rather_than_coerced(string $appId): void
    {
        $this->expectException(ConfigurationException::class);

        HubspotClientFactory::forWebhookManagement($appId, 'a-developer-key');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedAppIds(): array
    {
        return [
            'trailing letters' => ['123abc'],
            'leading letters' => ['abc123'],
            'inner space' => ['12 34'],
            'leading plus' => ['+123'],
            'negative' => ['-123'],
            'zero' => ['0'],
            'decimal' => ['123.4'],
            'leading zero' => ['0123'],
            'whitespace padded' => [' 123 '],
            'hex' => ['0x7b'],
            // All digits, no leading zero -- so the ctype_digit guard admits it -- but larger than
            // PHP_INT_MAX, where `(int)` SATURATES rather than wrapping or failing. The cast the
            // subscriptions endpoint depends on would silently address app
            // 9223372036854775807 instead, which is the same "lands somewhere plausible" failure
            // as "123abc" reaching app 123, reached by arithmetic instead of by parsing.
            'one past PHP_INT_MAX' => ['9223372036854775808'],
            'far past PHP_INT_MAX' => ['99999999999999999999'],
        ];
    }

    /**
     * The guard's real rule, stated as its own case rather than left implicit in the list above:
     * the string must survive the `(int)` cast unchanged. Every malformed shape above fails that,
     * and so does any future one -- which is what makes this stronger than enumerating spellings.
     */
    public function test_the_accepted_app_id_survives_the_integer_cast_unchanged(): void
    {
        $appId = '998877';

        self::assertSame($appId, (string) (int) $appId);

        self::assertInstanceOf(
            HubspotClientFactory::class,
            HubspotClientFactory::forWebhookManagement($appId, 'a-developer-key'),
        );
    }

    /** A canonical id still constructs — the guard must not reject what HubSpot actually issues. */
    public function test_a_canonical_numeric_app_id_is_accepted(): void
    {
        $factory = HubspotClientFactory::forWebhookManagement('998877', 'a-developer-key');

        self::assertInstanceOf(HubspotClientFactory::class, $factory);
    }
}
