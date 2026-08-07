<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Gateway;

use HubSpot\Utils\Signature;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Gateway\Contracts\WebhookGatewayContract;
use UnexpectedValueException;

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

        try {
            return Signature::isValid([
                'signature' => $signature,
                'secret' => $this->secret,
                'requestBody' => $rawBody,
                'httpUri' => $uri,
                'httpMethod' => $method,
                'signatureVersion' => $signatureVersion,
                'timestamp' => $timestamp ?? '',
            ]);
        } catch (UnexpectedValueException $exception) {
            // `$signatureVersion` comes from a header an UNAUTHENTICATED caller sets, and the SDK
            // throws on any value it does not recognise. Uncaught, that let a hostile request pick
            // a 500 over the documented 401 -- and let a raw SDK exception cross the Gateway
            // boundary, which this layer exists to prevent.
            //
            // Answered as `false`, not rethrown as a package exception: a signature that cannot be
            // checked has not been proven genuine, which is exactly what an invalid signature is.
            // Failing CLOSED here keeps the controller's one rule -- unverified means 401 -- rather
            // than adding a second failure mode for the same question.
            return false;
        }
    }
}
