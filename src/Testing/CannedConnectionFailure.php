<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Testing;

use GuzzleHttp\Exception\ConnectException;
use Psr\Http\Message\RequestInterface;

/**
 * A marker canned "response" installed via `Hubspot::connectionFailure()` that instructs
 * `HubspotFake` to simulate a Guzzle connection-level failure (DNS/timeout/refused) for the
 * configured object type, instead of returning any HTTP response at all (02-RESEARCH.md
 * Pitfall 2 — `getResponseObject()` degrades to null on this path, unlike a genuine HTTP error).
 */
final class CannedConnectionFailure
{
    public function toException(RequestInterface $request): ConnectException
    {
        return new ConnectException(
            'cURL error: Could not resolve host (simulated by Hubspot::fake()).',
            $request,
        );
    }
}
