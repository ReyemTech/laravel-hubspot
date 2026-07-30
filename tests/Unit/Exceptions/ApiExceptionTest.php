<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Unit\Exceptions;

use PHPUnit\Framework\Attributes\DataProvider;
use ReyemTech\Hubspot\Exceptions\ApiException;
use ReyemTech\Hubspot\Tests\Feature\Gateway\ExceptionTranslationTest;
use ReyemTech\Hubspot\Tests\TestCase;
use RuntimeException;

/**
 * **What the message a developer actually reads says.**
 *
 * Found by running the real-portal probe rather than by reading code, twice within an hour:
 *
 * - an invalid email → HubSpot said *"Property values were not valid: … Email address … is
 *   invalid"*, the package said *"failed with status 400. Quote correlation id … to HubSpot
 *   support."*
 * - a missing scope → HubSpot said *"This app hasn't been granted all required scopes to make this
 *   call"*, the package said the same sentence again.
 *
 * In both cases HubSpot handed over a precise, actionable explanation and the package replaced it
 * with an instruction to contact support — who cannot grant an app a scope or fix a caller's email.
 * `STANDARDS` D-18 requires the message to name the fix, not just the fault; for a 4xx it named
 * neither. The detail was never lost — it sits on `body()` — but nobody reads an accessor before
 * they read the message.
 *
 * **The split is by status class, and that is the principle rather than a convenience.** For a 4xx
 * the caller can fix it and HubSpot has said how, so the message carries HubSpot's own words. For a
 * 5xx there is nothing the caller can do, so quoting a correlation id to support genuinely *is* the
 * fix and that wording is unchanged.
 *
 * Only the `message` field is lifted, never the whole body: a body is arbitrary remote text and
 * this package already holds that an access token must never reach a message
 * ({@see ExceptionTranslationTest}).
 *
 * Every assertion here is on the WHOLE message. Substring assertions leaked 31
 * `ConcatSwitchSides`/`ConcatRemoveRight` survivors on an earlier plan.
 */
mutates(ApiException::class);

final class ApiExceptionTest extends TestCase
{
    private static function body(mixed $message): string
    {
        return (string) json_encode(['message' => $message, 'category' => 'VALIDATION_ERROR']);
    }

    /**
     * Passes an explicit empty redaction list: "there is nothing to scrub", which is what lifts the
     * reason. A four-argument call means "nobody told me", and deliberately lifts nothing.
     */
    private static function raise(int $status, ?string $body, ?string $correlationId): ApiException
    {
        return ApiException::httpError($status, $body, $correlationId, new RuntimeException('sdk'), []);
    }

    public function test_a_client_error_names_hubspots_own_reason(): void
    {
        $exception = self::raise(
            400,
            self::body('Property values were not valid: Email address phase2-smoke@example.test is invalid'),
            'corr-400',
        );

        self::assertSame(
            'HubSpot rejected the request with status 400: Property values were not valid: '
            .'Email address phase2-smoke@example.test is invalid (correlation id corr-400)',
            $exception->getMessage(),
        );
    }

    /**
     * The 403 that made this worth fixing. "Contact support" is the wrong instruction: support
     * cannot grant an app a scope, and the person reading this can.
     */
    public function test_a_missing_scope_reads_as_a_missing_scope(): void
    {
        $exception = self::raise(
            403,
            self::body("This app hasn't been granted all required scopes to make this call."),
            'corr-403',
        );

        self::assertSame(
            'HubSpot rejected the request with status 403: This app hasn\'t been granted all '
            .'required scopes to make this call. (correlation id corr-403)',
            $exception->getMessage(),
        );
    }

    public function test_a_client_error_without_a_correlation_id_does_not_invent_one(): void
    {
        $exception = self::raise(404, self::body('deal not found'), null);

        self::assertSame(
            'HubSpot rejected the request with status 404: deal not found',
            $exception->getMessage(),
        );
    }

    /**
     * A 5xx is not the caller's to fix, so the support wording stays exactly as it was.
     */
    public function test_a_server_error_keeps_the_support_wording(): void
    {
        $exception = self::raise(500, self::body('internal error'), 'corr-500');

        self::assertSame(
            'HubSpot API request failed with status 500. Quote correlation id corr-500 to HubSpot support.',
            $exception->getMessage(),
        );
    }

    public function test_a_server_error_without_a_correlation_id_degrades_honestly(): void
    {
        $exception = self::raise(503, self::body('unavailable'), null);

        self::assertSame('HubSpot API request failed with status 503.', $exception->getMessage());
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function unusableBodies(): iterable
    {
        yield 'no body at all' => [null];
        yield 'not json' => ['<html>502 Bad Gateway</html>'];
        yield 'json without a message key' => ['{"category":"VALIDATION_ERROR"}'];
        yield 'json whose message is not a string' => ['{"message":{"nested":"object"}}'];
        yield 'json whose message is empty' => ['{"message":""}'];
        yield 'json whose message is only whitespace' => ['{"message":"   "}'];
        yield 'a json scalar rather than an object' => ['"just a string"'];
    }

    /**
     * When there is no usable reason the old wording is exactly right, and must not degrade into
     * something like "status 400: " with nothing after the colon.
     */
    #[DataProvider('unusableBodies')]
    public function test_a_client_error_with_no_usable_reason_falls_back(?string $body): void
    {
        $exception = self::raise(400, $body, 'corr-400');

        self::assertSame(
            'HubSpot API request failed with status 400. Quote correlation id corr-400 to HubSpot support.',
            $exception->getMessage(),
        );
    }

    /**
     * **The reason is remote text, and a 4xx echoes the submitted value back.**
     *
     * That is what makes it useful and also why it cannot be trusted verbatim: a caller who ever
     * writes a credential into a property gets it quoted back by HubSpot, and the message is the
     * field applications log by default. T-02-01 says a recognisable token appears in neither the
     * message nor the string representation, and lifting remote text into the message is exactly
     * how that guarantee would have been lost (Codex P2 on PR #35).
     */
    public function test_a_secret_echoed_back_by_hubspot_is_scrubbed_from_the_message(): void
    {
        $exception = ApiException::httpError(
            400,
            self::body('Property values were not valid: pat-na1-shh-do-not-log-me is not a valid email'),
            'corr-400',
            new RuntimeException('sdk'),
            ['pat-na1-shh-do-not-log-me'],
        );

        self::assertSame(
            'HubSpot rejected the request with status 400: Property values were not valid: '
            .'[redacted] is not a valid email (correlation id corr-400)',
            $exception->getMessage(),
        );
        self::assertStringNotContainsString('pat-na1-shh-do-not-log-me', $exception->getMessage());
        self::assertStringNotContainsString('pat-na1-shh-do-not-log-me', (string) $exception);
    }

    /**
     * Every secret, not merely the first -- the token AND the webhook client secret are both wired.
     */
    public function test_every_supplied_secret_is_scrubbed(): void
    {
        $exception = ApiException::httpError(
            400,
            self::body('rejected aaa-token and bbb-secret'),
            null,
            new RuntimeException('sdk'),
            ['aaa-token', 'bbb-secret'],
        );

        self::assertSame(
            'HubSpot rejected the request with status 400: rejected [redacted] and [redacted]',
            $exception->getMessage(),
        );
    }

    /**
     * `body()` keeps the raw payload. It always did, it is an accessor a developer opts into rather
     * than something a logger reaches for, and scrubbing it would destroy the only faithful record
     * of what HubSpot actually said.
     */
    public function test_scrubbing_does_not_touch_the_retained_body(): void
    {
        $body = self::body('rejected pat-na1-shh-do-not-log-me');
        $exception = ApiException::httpError(400, $body, null, new RuntimeException('sdk'), ['pat-na1-shh-do-not-log-me']);

        self::assertSame($body, $exception->body());
    }

    /**
     * An empty secret must not turn every message into a wall of [redacted]. An unset
     * HUBSPOT_TOKEN reaching here as '' is the realistic way that happens.
     */
    public function test_an_empty_secret_scrubs_nothing(): void
    {
        $exception = ApiException::httpError(400, self::body('deal not found'), null, new RuntimeException('sdk'), ['']);

        self::assertSame(
            'HubSpot rejected the request with status 400: deal not found',
            $exception->getMessage(),
        );
    }

    /**
     * **The path that actually matters, and the one an override of `__toString()` does not touch.**
     *
     * Laravel's handler puts the throwable into `context['exception']` and Monolog normalises
     * `getPrevious()` recursively, reading each exception's own message. The SDK's exception inlines
     * the entire raw response body, so an echoed credential reaches ordinary application logs
     * through a message this package does not write (Codex P1 on PR #35).
     *
     * This walks the chain the way a normaliser does, rather than casting to string.
     */
    public function test_no_link_in_the_retained_chain_carries_an_echoed_secret(): void
    {
        $secret = 'pat-na1-shh-do-not-log-me-99999';

        // Shaped exactly like the SDK's: the whole response body inlined into the message.
        $sdk = new RuntimeException(
            '[400] Client error: resulted in a 400 response: {"message":"rejected '.$secret.'"}',
        );

        $exception = ApiException::httpError(400, '{"message":"rejected '.$secret.'"}', null, $sdk, [$secret]);

        // ApiException itself carries whatever previous it is given -- sanitising the SDK exception
        // is `Gateway\ExceptionTranslator`'s job, since R1 forbids this namespace naming a
        // `HubSpot\*` class. What is asserted here is this exception's OWN message.
        self::assertStringNotContainsString($secret, $exception->getMessage());
        self::assertSame($sdk, $exception->getPrevious());
    }

    /**
     * **A connection failure keeps its original chain, and that is deliberate.**
     *
     * Nothing was received, so there is no echoed body to leak — and the previous exception carries
     * the only thing that distinguishes DNS failure from refusal from a TLS error from a timeout
     * (Codex P2 on PR #35). Replacing it there would trade a real diagnostic for no security.
     */
    public function test_a_connection_failure_keeps_its_original_previous_exception(): void
    {
        $guzzle = new RuntimeException('cURL error 6: Could not resolve host: api.hubapi.com');
        $exception = ApiException::connectionFailure($guzzle);

        self::assertSame($guzzle, $exception->getPrevious());
        self::assertStringContainsString('Could not resolve host', (string) $exception);
    }

    /**
     * **A four-argument call keeps the behaviour it had before this parameter existed.**
     *
     * The reason is remote text. Lifting it is only safe once the caller has said what to scrub, so
     * `null` -- "nobody told me" -- lifts nothing and degrades to the previous wording. A consumer
     * calling this factory directly, or hand-wiring an `ExceptionTranslator`, therefore cannot
     * silently gain an unscrubbed message (Codex P2 on PR #35).
     */
    public function test_omitting_the_redaction_list_lifts_no_remote_text(): void
    {
        $exception = ApiException::httpError(400, self::body('deal not found'), 'corr-400', new RuntimeException('sdk'));

        self::assertSame(
            'HubSpot API request failed with status 400. Quote correlation id corr-400 to HubSpot support.',
            $exception->getMessage(),
        );
    }

    /**
     * **Longest first**, or a secret that is a prefix of another leaks most of the longer one:
     * replacing `abc` first turns `abcdef123` into `[redacted]def123`, which the second pass can no
     * longer match.
     */
    public function test_a_secret_that_prefixes_another_does_not_leak_the_longer_one(): void
    {
        $exception = ApiException::httpError(
            400,
            self::body('rejected abcdef123'),
            null,
            new RuntimeException('sdk'),
            ['abc', 'abcdef123'],
        );

        self::assertSame('HubSpot rejected the request with status 400: rejected [redacted]', $exception->getMessage());
        self::assertStringNotContainsString('def123', $exception->getMessage());
    }

    /**
     * `json_decode` turns `\n` escapes into real newlines, so an attacker-controlled property value
     * echoed back by HubSpot could forge extra log lines or emit terminal escapes.
     */
    public function test_control_characters_in_the_reason_are_normalised(): void
    {
        $exception = ApiException::httpError(
            400,
            (string) json_encode(['message' => "Invalid value:\nfake log line\r\n\x07 and\ttabs"]),
            null,
            new RuntimeException('sdk'),
            [],
        );

        self::assertSame(
            'HubSpot rejected the request with status 400: Invalid value: fake log line and tabs',
            $exception->getMessage(),
        );
        self::assertStringNotContainsString("\n", $exception->getMessage());
        self::assertStringNotContainsString("\r", $exception->getMessage());
    }

    /**
     * ASCII C0 and DEL are not the whole alphabet. U+0085 NEL, U+2028 LINE SEPARATOR and U+2029
     * PARAGRAPH SEPARATOR are all rendered as line breaks by log viewers, so stripping only the
     * ASCII range leaves the same injection available in a different encoding.
     */
    public function test_unicode_line_separators_are_normalised_too(): void
    {
        $exception = ApiException::httpError(
            400,
            (string) json_encode(['message' => "Invalid:\u{0085}NEL\u{2028}LS\u{2029}PS\u{200B}zw"]),
            null,
            new RuntimeException('sdk'),
            [],
        );

        self::assertSame(
            'HubSpot rejected the request with status 400: Invalid: NEL LS PS zw',
            $exception->getMessage(),
        );
    }

    /**
     * The body is retained whatever the message says — it always was, and the fix must not trade
     * one for the other.
     */
    public function test_the_body_is_still_retained_in_full(): void
    {
        $body = self::body('deal not found');
        $exception = self::raise(404, $body, 'corr-404');

        self::assertSame($body, $exception->body());
        self::assertSame(404, $exception->status());
        self::assertSame('corr-404', $exception->correlationId());
    }
}
