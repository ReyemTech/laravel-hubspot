<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Wraps every SDK-thrown exception this package can receive (STANDARDS §9). A raw
 * `HubSpot\Client\...\ApiException` must never reach userland — this is what a consumer
 * actually catches, which is what lets the SDK be swapped without breaking their `catch` block.
 *
 * Carries only the HTTP status, the raw response body and HubSpot's own correlation id — never
 * the access token or any request header (T-02-01, threat register). All three are nullable
 * where the SDK legitimately supplies nothing: a connection failure never reaches HubSpot at
 * all, so there is no status, no body and no correlation id to report, and the message says so
 * honestly rather than reporting a misleading status.
 */
final class ApiException extends RuntimeException implements HubspotException
{
    private function __construct(
        string $message,
        private readonly ?int $status,
        private readonly ?string $body,
        private readonly ?string $correlationId,
        ?Throwable $previous,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * A genuine HTTP error response reached the process — status, body and (when HubSpot's own
     * error payload deserialized cleanly) correlation id are all real. The message names the
     * fix, not just the fault (D-18): it quotes both so a reader can hand them to HubSpot
     * support without digging through logs.
     */
    public static function httpError(int $status, ?string $body, ?string $correlationId, Throwable $previous): self
    {
        $message = $correlationId !== null
            ? sprintf('HubSpot API request failed with status %d. Quote correlation id %s to HubSpot support.', $status, $correlationId)
            : sprintf('HubSpot API request failed with status %d.', $status);

        return new self($message, $status, $body, $correlationId, $previous);
    }

    /**
     * No HTTP response was ever received — a Guzzle connection-level failure (DNS, timeout,
     * connection refused). Status is reported as 0 rather than a misleading real-looking code,
     * and body/correlationId are honestly null: HubSpot never saw the request.
     */
    public static function connectionFailure(Throwable $previous): self
    {
        return new self(
            'No response was received from HubSpot — the request never reached the API '
            .'(connection failure). Check network connectivity and retry.',
            0,
            null,
            null,
            $previous,
        );
    }

    /**
     * HubSpot answered a batch request with 207: some records were written and some were not.
     * Reported as an exception rather than a return value because it is raised from the one
     * accessor that promises a fully written batch — see `Gateway\BatchResult::records()`. The
     * message names the fix (D-18): it points at the accessor that does hand back the survivors,
     * so a caller who genuinely wants the partial outcome knows where to go rather than reaching
     * for a `catch` that swallows the failure.
     */
    public static function partialBatchFailure(int $errorCount, string $firstErrorMessage): self
    {
        return new self(
            sprintf(
                'HubSpot wrote only part of this batch: %d record(s) were rejected. First error: %s. '
                .'Call recordsDespitePartialFailure() and errors() to handle the partial outcome '
                .'deliberately — each error names the rejected records so they can be retried.',
                $errorCount,
                $firstErrorMessage,
            ),
            207,
            null,
            null,
            null,
        );
    }

    public function status(): ?int
    {
        return $this->status;
    }

    public function body(): ?string
    {
        return $this->body;
    }

    public function correlationId(): ?string
    {
        return $this->correlationId;
    }
}
