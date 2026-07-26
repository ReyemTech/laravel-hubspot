<?php

declare(strict_types=1);

/**
 * Fixture for rule R10 — never production code.
 *
 * Violates: "Config keys holding tokens or secrets never appear in log calls."
 * (STANDARDS §10, D-19). Passes the `hubspot.token` config value directly into a
 * Log facade call, exactly the leak-into-a-consumer's-log-aggregator this rule exists
 * to prevent.
 */

namespace ReyemTech\Hubspot\Registry;

use Illuminate\Support\Facades\Log;

final class LogsTheAccessToken
{
    public function connect(): void
    {
        Log::info('Connecting to HubSpot', ['token' => config('hubspot.token')]);
    }
}
