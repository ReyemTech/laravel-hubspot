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
    /**
     * `$retriesInternalErrors` records how this transport was built, and exists so a caller can ask
     * the ONE question that matters for a non-idempotent write: does this client repeat a request
     * it cannot prove was unprocessed? A 429 is safe to repeat -- the request was rate-limited, not
     * processed -- but a 5xx or a timeout may follow a write that already landed, and repeating a
     * CREATE there produces a duplicate object (Codex, PR #49).
     *
     * A bool, never the construction inputs. STANDARDS Sec.12 is explicit that a container
     * singleton must not retain credentials for a debug dumper to reveal, so this records the
     * SHAPE of the transport and nothing about how it authenticates.
     */
    private function __construct(
        private readonly Discovery $discovery,
        private readonly bool $retriesInternalErrors = false,
    ) {}

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
        bool $retryInternalErrors = true,
    ): self {
        if ($accessToken === null || $accessToken === '') {
            throw ConfigurationException::missingToken();
        }

        $stack = HandlerStack::create();

        if ($retriesEnabled) {
            $stack->push(self::guzzleMiddleware(RetryMiddlewareFactory::createRateLimitMiddleware(...)), 'rate_limit_retry');
        }

        // Separately switchable from the rate-limit retry above, because the two are not equally
        // safe. A 429 says the request was REFUSED, so repeating it cannot duplicate anything. A
        // 5xx or a timeout says nothing at all about whether the write landed, so repeating a
        // non-idempotent create there can produce a second object -- which is why
        // `Sync\RecreateHubspotObjectJob` reaches HubSpot through a transport built with this off
        // (Codex, PR #49). Every other caller keeps both, unchanged.
        $retriesInternalErrors = $retriesEnabled && $retryInternalErrors;

        if ($retriesInternalErrors) {
            $stack->push(self::guzzleMiddleware(RetryMiddlewareFactory::createInternalErrorsMiddleware(...)), 'internal_errors_retry');
        }

        $client = new Client([
            'handler' => $stack,
            'timeout' => $timeout,
            'connect_timeout' => $connectTimeout,
        ]);

        return new self(Factory::createWithAccessToken($accessToken, $client), $retriesInternalErrors);
    }

    /**
     * Whether this transport repeats a request that failed for a reason which does not prove the
     * request was unprocessed. See the constructor for why that is the only distinction a caller
     * needs, and `ServiceProvider` for the one binding that acts on it.
     */
    public function retriesInternalErrors(): bool
    {
        return $this->retriesInternalErrors;
    }

    /**
     * Builds a transport wired to a caller-supplied Guzzle client — the seam
     * `Testing\HubspotFake` uses to inject a `MockHandler`-backed client with zero real HTTP.
     */
    public static function forTransport(ClientInterface $client, string $accessToken = 'fake-token'): self
    {
        return new self(Factory::createWithAccessToken($accessToken, $client));
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
