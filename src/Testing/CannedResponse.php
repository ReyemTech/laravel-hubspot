<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Testing;

/**
 * A canned response for one object type, built via `Hubspot::response()` and passed into
 * `Hubspot::fake(['deals' => ...])`. Holds the exact response body `HubspotFake` JSON-encodes
 * and the HTTP status HubSpot would have returned — success bodies and HubSpot-shaped error
 * bodies alike, so the same value object drives both the happy path and (plan 02-01 task 2) the
 * failure-translation tests.
 */
final readonly class CannedResponse
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        public array $body,
        public int $status = 200,
    ) {}
}
