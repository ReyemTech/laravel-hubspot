<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Gateway;

use HubSpot\Utils\Signature;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Gateway\Contracts\WebhookGatewayContract;

/**
 * The only class in this package permitted to name `HubSpot\Utils\Signature` (R1). Wraps
 * `Signature::isValid()` — the SDK's own HMAC comparison via `hash_equals()` — and returns a plain
 * boolean, so `Webhooks` never touches the SDK or the secret directly.
 *
 * Constructed with the configured secret rather than reading `config()` itself, mirroring
 * {@see ExceptionTranslator}'s own on-demand credential boundary: the secret is handed to a single
 * call at the moment verification happens, never retained on a shared, inspectable property.
 *
 * `final` by default (STANDARDS §8); consumers depend on {@see WebhookGatewayContract}.
 */
final class WebhookGateway implements WebhookGatewayContract
{
    public function __construct(private readonly ?string $secret) {}

    public function verify(
        string $method,
        string $uri,
        string $rawBody,
        string $signatureVersion,
        string $signature,
        ?string $timestamp,
    ): bool {
        if ($this->secret === null || $this->secret === '') {
            throw ConfigurationException::missingWebhookSecret();
        }

        return Signature::isValid([
            'signature' => $signature,
            'secret' => $this->secret,
            'requestBody' => $rawBody,
            'httpUri' => $uri,
            'httpMethod' => $method,
            'signatureVersion' => $signatureVersion,
            'timestamp' => $timestamp ?? '',
        ]);
    }
}
