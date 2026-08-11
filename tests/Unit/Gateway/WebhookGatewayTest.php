<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Unit\Gateway;

use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Gateway\WebhookGateway;
use ReyemTech\Hubspot\Tests\TestCase;

/**
 * The fail-closed guard T-05-01 names: a missing or empty configured secret must never reach
 * `HubSpot\Utils\Signature::isValid()`, which would otherwise silently HMAC against an empty key
 * rather than refusing to answer at all. Exercised directly against {@see WebhookGateway} rather
 * than through the HTTP feature tests, since none of those configure a webhook route with no
 * secret set -- doing so would leave the tracer/failure suites unable to sign anything.
 */
final class WebhookGatewayTest extends TestCase
{
    public function test_it_throws_a_configuration_exception_when_no_secret_is_configured(): void
    {
        $gateway = new WebhookGateway(null);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('HUBSPOT_CLIENT_SECRET is not set');

        $gateway->verify('POST', 'http://localhost/hubspot/webhook', '[]', 'v3', 'ignored', '0');
    }

    public function test_it_throws_a_configuration_exception_when_the_secret_is_an_empty_string(): void
    {
        $gateway = new WebhookGateway('');

        $this->expectException(ConfigurationException::class);

        $gateway->verify('POST', 'http://localhost/hubspot/webhook', '[]', 'v3', 'ignored', '0');
    }

    public function test_a_correctly_computed_v3_signature_verifies(): void
    {
        $secret = 'unit-test-secret';
        $method = 'POST';
        $uri = 'http://localhost/hubspot/webhook';
        $body = '[]';
        $timestamp = (string) ((int) round(microtime(true) * 1000));

        $signature = base64_encode(hash_hmac('sha256', $method.$uri.$body.$timestamp, $secret, true));

        $gateway = new WebhookGateway($secret);

        self::assertTrue($gateway->verify($method, $uri, $body, 'v3', $signature, $timestamp));
    }

    public function test_a_wrong_signature_does_not_verify(): void
    {
        $gateway = new WebhookGateway('unit-test-secret');

        self::assertFalse($gateway->verify(
            'POST',
            'http://localhost/hubspot/webhook',
            '[]',
            'v3',
            base64_encode('not-the-right-signature'),
            (string) ((int) round(microtime(true) * 1000)),
        ));
    }
}
