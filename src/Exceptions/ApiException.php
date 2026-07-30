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
     * error payload deserialized cleanly) correlation id are all real.
     *
     * The message names the fix, not just the fault (D-18), and what the fix *is* depends on who
     * can perform it. A **4xx** is the caller's, and HubSpot has just said how, so its own words
     * lead. A **5xx** is not, so quoting a correlation id to support is the fix rather than a
     * deflection — and that wording is unchanged from before this split existed.
     *
     * @param  list<string>  $redact  secrets this package holds, scrubbed from the reason before it
     *                                reaches the message. Supplied by `Gateway\ExceptionTranslator`,
     *                                which `ServiceProvider` binds with the configured token and
     *                                webhook client secret.
     */
    public static function httpError(int $status, ?string $body, ?string $correlationId, Throwable $previous, array $redact = []): self
    {
        $reason = $status >= 400 && $status < 500 ? self::reasonFrom($body, $redact) : null;

        if ($reason !== null) {
            // A 4xx is the CALLER's to fix and HubSpot has just said how, so its words lead. The
            // correlation id still follows for the rare case where the explanation looks wrong.
            $message = sprintf('HubSpot rejected the request with status %d: %s', $status, $reason)
                .($correlationId !== null ? sprintf(' (correlation id %s)', $correlationId) : '');
        } elseif ($correlationId !== null) {
            // A 5xx, or a 4xx whose body said nothing usable. Nothing here is the caller's to fix,
            // so quoting a correlation id to support IS the fix rather than a deflection.
            $message = sprintf('HubSpot API request failed with status %d. Quote correlation id %s to HubSpot support.', $status, $correlationId);
        } else {
            $message = sprintf('HubSpot API request failed with status %d.', $status);
        }

        return new self($message, $status, $body, $correlationId, $previous);
    }

    /**
     * HubSpot's own explanation, when it gave one that can be read.
     *
     * **Only the `message` field, never the whole body.** A body is arbitrary remote text of
     * unbounded shape, and this package holds that an access token must never reach an exception
     * message; lifting one named string keeps that promise cheap to verify, where echoing the body
     * would make it a matter of hoping HubSpot never reflects one back.
     *
     * Everything unusable returns null so the caller falls back to the support wording rather than
     * emitting `status 400: ` with nothing after the colon — a body may be HTML from a proxy, JSON
     * without a `message`, a bare scalar, or a `message` that is not a string at all.
     *
     * ## And it is scrubbed before it is returned
     *
     * A 4xx validation response **echoes the submitted value back** — that is what makes it useful,
     * and it is also why remote text cannot be trusted into a message. If a caller ever writes a
     * credential into a property, HubSpot rejects it and quotes it, and the message is the one
     * field applications log by default (Codex P2, PR #35). T-02-01 states the guarantee this
     * protects: a recognisable token appears in neither the message nor the string representation.
     *
     * `body()` is deliberately left untouched. It always carried the raw payload, it is an accessor
     * a developer opts into rather than something a logger reaches for, and scrubbing it would
     * destroy the one faithful record of what HubSpot actually said.
     *
     * @param  list<string>  $redact
     */
    private static function reasonFrom(?string $body, array $redact = []): ?string
    {
        if ($body === null) {
            return null;
        }

        $decoded = json_decode($body, true);

        if (! is_array($decoded) || ! isset($decoded['message']) || ! is_string($decoded['message'])) {
            return null;
        }

        $reason = trim($decoded['message']);

        foreach ($redact as $secret) {
            // Guarded: `str_replace` with an empty needle is a no-op, but an unset `HUBSPOT_TOKEN`
            // arriving here as '' and silently matching nothing is worth being explicit about.
            if ($secret !== '') {
                $reason = str_replace($secret, '[redacted]', $reason);
            }
        }

        return $reason === '' ? null : $reason;
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
    public static function partialBatchFailure(int $errorCount, ?string $firstErrorMessage): self
    {
        // A 207 with no itemised errors is a real partial write whose failed records HubSpot did
        // not name — the caller cannot retry them selectively, so the message has to say so rather
        // than report "0 record(s) were rejected", which reads like nothing went wrong.
        // Both branches are single unbroken string literals rather than wrapped concatenations:
        // pest --mutate generates a concat mutant per `.` operator, and a message assembled from
        // six fragments buys six mutants whose only observable difference is word order in prose.
        $detail = $firstErrorMessage === null
            ? 'HubSpot itemised no errors, so which records it rejected cannot be read off the response. Call recordsDespitePartialFailure() for the records it did confirm and reconcile the rest against HubSpot directly.'
            : sprintf('%d record(s) were rejected. First error: %s. Call recordsDespitePartialFailure() and errors() to handle the partial outcome deliberately — each error names the rejected records so they can be retried.', $errorCount, $firstErrorMessage);

        return new self(
            'HubSpot wrote only part of this batch: '.$detail,
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
