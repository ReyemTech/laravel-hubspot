<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Gateway\Contracts;

use ReyemTech\Hubspot\Exceptions\ConfigurationException;

/**
 * **Whether a raw inbound HTTP request carries a valid HubSpot webhook signature.**
 *
 * `Webhooks` may not name `HubSpot\*` (R4), so signature verification is a Gateway capability
 * handing back a plain boolean; the SDK's own `HubSpot\Utils\Signature::isValid()` and its HMAC
 * comparison never cross this boundary (STANDARDS §10, D-21: this package does not hand-roll HMAC).
 *
 * ## Every argument is a raw, unprocessed value — that is the whole point
 *
 * `$uri` MUST be the byte-for-byte request URI HubSpot signed, including its exact, unsorted query
 * string ordering — built from the request's externally visible scheme/host plus the untouched
 * `REQUEST_URI` server value, never `$request->fullUrl()` (Symfony sorts query parameters; HubSpot
 * signs the raw URI verbatim — AGENTS.md, PROJECT.md "Protocol — webhook signature"). `$rawBody`
 * MUST be the exact bytes HubSpot sent, never a re-encoded or re-serialized reconstruction of a
 * decoded payload — HubSpot's v2/v3 algorithms hash the raw request body. Signing over anything
 * else always fails a genuinely valid request and would make signature verification look broken
 * instead of the caller.
 *
 * A missing or empty webhook secret is a package configuration fault, not a caller's failed
 * signature — the implementation throws {@see ConfigurationException} rather than silently
 * returning `false` or handing the SDK an empty HMAC key.
 */
interface WebhookGatewayContract
{
    /**
     * @throws ConfigurationException if no webhook secret is configured
     */
    public function verify(
        string $method,
        string $uri,
        string $rawBody,
        string $signatureVersion,
        string $signature,
        ?string $timestamp,
    ): bool;
}
