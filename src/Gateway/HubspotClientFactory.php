<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Gateway;

use GuzzleHttp\ClientInterface;
use HubSpot\Discovery\Discovery;
use HubSpot\Factory;

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
 * For this task the production path (`fromConfig()`) takes the token straight from config with
 * no validation — plan 02-02 adds the missing-token `ConfigurationException`, the timeout and
 * the retry middleware (T-02-07, threat register).
 */
final class HubspotClientFactory
{
    private function __construct(private readonly Discovery $discovery) {}

    /**
     * Builds the production transport from package config.
     */
    public static function fromConfig(?string $accessToken): self
    {
        return new self(Factory::createWithAccessToken((string) $accessToken));
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
}
