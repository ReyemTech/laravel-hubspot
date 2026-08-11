<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Gateway;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\HandlerStack;
use HubSpot\Discovery\Discovery;
use HubSpot\Factory;
use HubSpot\RetryMiddlewareFactory;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use RuntimeException;

/**
 * The single place in the package that names `HubSpot\Factory` and `HubSpot\Discovery\Discovery`
 * (R1 — see tests/Arch/LayerBoundariesTest.php and tests/Arch/SdkSurfaceTest.php). Every other
 * file, including `src/Testing/HubspotFake.php` and `src/ServiceProvider.php`, must go through
 * this class rather than naming an SDK type itself.
 *
 * Dependency-policy note — read before "fixing" the missing `guzzlehttp/guzzle` require:
 * `guzzlehttp/guzzle` is deliberately NOT declared in this package's own composer.json and must
 * never be added — production requires are frozen at seven (STANDARDS §2, D-02) and
 * tests/Ci/ComposerManifestTest.php fails the build on an eighth. Guzzle is already a hard
 * `require` of `hubspot/api-client` itself (installed transitively, confirmed in
 * 02-RESEARCH.md), and `HubSpot\Factory::createWithAccessToken()`'s injection seam is typed
 * directly on `GuzzleHttp\ClientInterface` — wrapping the SDK without naming that type is not
 * possible. Naming it here is therefore not a new dependency; it is unwrapping one that already
 * exists two layers down the dependency tree.
 *
 * `fromConfig()` is the deliberate production transport (02-02): it validates the token before
 * constructing anything, sets an explicit request and connect timeout, and — when enabled —
 * attaches the SDK's own `RetryMiddlewareFactory` for HTTP 429 and 5xx (T-02-03, threat
 * register). `forTransport()` is untouched on purpose: the fake it builds for `Hubspot::fake()`
 * must never inherit retries, or a queued 429 in a test would be silently absorbed and
 * `assertRequestCount()` would stop being exact.
 */
final class HubspotClientFactory
{
    private function __construct(private readonly Discovery $discovery) {}

    /**
     * Builds the production transport from package config. Throws before any client is
     * constructed if the token is missing or empty — a missing token must never surface as an
     * unauthenticated request that looks like a permissions problem (T-02-08, threat register).
     *
     * The three transport parameters default to exactly what `config/hubspot.php` documents as
     * `hubspot.transport.timeout` / `connect_timeout` / `retries` (Codex review, PR #14 P2): this
     * method is `public` and not `@internal`, so the previously valid single-argument call
     * `fromConfig($token)` must keep working rather than throwing `ArgumentCountError`. Defaults
     * intentionally do NOT reproduce the pre-02-02 behaviour of an unbounded Guzzle timeout and no
     * retry middleware — that behaviour is exactly the outage risk (T-02-03/T-02-07, threat
     * register) this plan was written to close, and a compatibility fix must not resurrect it.
     */
    public static function fromConfig(
        ?string $accessToken,
        float $timeout = 10.0,
        float $connectTimeout = 5.0,
        bool $retriesEnabled = true,
    ): self {
        if ($accessToken === null || $accessToken === '') {
            throw ConfigurationException::missingToken();
        }

        $stack = HandlerStack::create();

        if ($retriesEnabled) {
            $stack->push(self::guzzleMiddleware(RetryMiddlewareFactory::createRateLimitMiddleware(...)), 'rate_limit_retry');
            $stack->push(self::guzzleMiddleware(RetryMiddlewareFactory::createInternalErrorsMiddleware(...)), 'internal_errors_retry');
        }

        $client = new Client([
            'handler' => $stack,
            'timeout' => $timeout,
            'connect_timeout' => $connectTimeout,
        ]);

        return new self(Factory::createWithAccessToken($accessToken, $client));
    }

    /**
     * Builds a transport wired to a caller-supplied Guzzle client — the seam
     * `Testing\HubspotFake` uses to inject a `MockHandler`-backed client with zero real HTTP.
     */
    public static function forTransport(ClientInterface $client, string $accessToken = 'fake-token'): self
    {
        return new self(Factory::createWithAccessToken($accessToken, $client));
    }

    /**
     * Builds the management transport `Webhooks\Console\SyncWebhookSubscriptionsCommand` needs
     * (D-16, HOOK-02) — authenticated with a Developer API key, a THIRD credential class distinct
     * from `fromConfig()`'s access token and from `hubspot.webhooks.secret` (the inbound signature
     * secret). Takes both credentials as plain nullable strings, exactly as `fromConfig()` takes
     * its token, so nothing outside `Gateway` ever names an SDK type.
     *
     * `$appId` is validated for presence here rather than merely passed through: a call that ran
     * with an app id but no key, or a key but no app id, would still fail — just later, and as an
     * SDK-level 401/404 that names neither credential. Failing here names both, before any client
     * is built (T-05-17, threat register). The app id itself is not consumed by this method — it
     * is a path parameter on every `SubscriptionsApi` call, not part of the SDK's own auth config —
     * so `Gateway\WebhookSubscriptionGateway` holds it separately, resolved from the same config
     * keys at the same moment.
     *
     * `HubSpot\Factory::createWithDeveloperApiKey()` — confirmed against the pinned 14.1.0 — is the
     * only entry point that authenticates a client with a Developer API key (a `hapikey` query
     * parameter, the scheme `SubscriptionsApi`'s generated request builders read via
     * `Configuration::getApiKeyWithPrefix('hapikey')`). Never a Service Key, which
     * `hubspot.token`/`fromConfig()` carries and which this method never reads.
     *
     * Deliberately does NOT attach the retry middleware or an explicit request/connect timeout the
     * way `fromConfig()` does: those exist to protect a queued worker's transport, and
     * `hubspot:webhooks:sync` is a hand-run admin command, never queued work.
     */
    public static function forWebhookManagement(?string $appId, ?string $developerApiKey): self
    {
        if ($appId === null || $appId === '' || $developerApiKey === null || $developerApiKey === '') {
            throw ConfigurationException::missingWebhookManagementCredentials();
        }

        // A canonical positive integer, checked as a STRING before anything casts it. The caller
        // ends up doing `(int) $appId` to reach the subscriptions endpoint, and PHP's cast is
        // lossy in exactly the way that matters here: `(int) "123abc"` is 123, a different app
        // that really exists. Since `hubspot:webhooks:sync` reconciles APP-LEVEL subscriptions,
        // that silently rewrites subscriptions for every account the wrong app is installed on --
        // so a typo has to fail loudly rather than land somewhere plausible (T-05-17).
        //
        // `ctype_digit` alone would admit "0" and "0123"; neither is an app id HubSpot issues, and
        // both indicate a mis-set variable rather than a value worth guessing at.
        //
        // The round-trip is the rule the other two clauses are only spellings of: the string must
        // survive `(int)` unchanged. Digits alone are not enough, because the cast SATURATES past
        // PHP_INT_MAX rather than wrapping or failing -- "9223372036854775808" is all digits with
        // no leading zero, and reaches the subscriptions endpoint as 9223372036854775807. That is
        // the identical "lands somewhere plausible" failure as "123abc" reaching app 123, arrived
        // at by arithmetic instead of by parsing, and it deserves the same loud refusal (T-05-17).
        if (! ctype_digit($appId) || $appId[0] === '0' || (string) (int) $appId !== $appId) {
            throw ConfigurationException::malformedWebhookAppId($appId);
        }

        return new self(Factory::createWithDeveloperApiKey($developerApiKey));
    }

    public function discovery(): Discovery
    {
        return $this->discovery;
    }

    /**
     * `RetryMiddlewareFactory::createRateLimitMiddleware()`/`createInternalErrorsMiddleware()`
     * declare no native return type (confirmed reading `lib/RetryMiddlewareFactory.php`), so
     * PHPStan infers `mixed` from the call site rather than the `callable(callable): callable`
     * shape `HandlerStack::push()` requires. `is_callable()` narrowing (not a cast, not a
     * suppression comment) proves the real shape at both boundaries — the factory call and the
     * middleware it returns — and this closure's own declared signature is what PHPStan actually
     * checks `push()` against, since a closure's param/return types are read directly rather than
     * inferred from an untyped vendor method.
     *
     * @param  callable(): mixed  $middlewareFactory
     */
    private static function guzzleMiddleware(callable $middlewareFactory): callable
    {
        $middleware = $middlewareFactory();

        if (! is_callable($middleware)) {
            throw new RuntimeException('HubSpot\RetryMiddlewareFactory did not return a callable middleware.');
        }

        return static function (callable $handler) use ($middleware): callable {
            $wrapped = $middleware($handler);

            if (! is_callable($wrapped)) {
                throw new RuntimeException('HubSpot\RetryMiddlewareFactory produced a non-callable request handler.');
            }

            return $wrapped;
        };
    }
}
