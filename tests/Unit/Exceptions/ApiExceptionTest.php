<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Unit\Exceptions;

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

    private static function raise(int $status, ?string $body, ?string $correlationId): ApiException
    {
        return ApiException::httpError($status, $body, $correlationId, new RuntimeException('sdk'));
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
     *
     * @dataProvider unusableBodies
     */
    public function test_a_client_error_with_no_usable_reason_falls_back(?string $body): void
    {
        $exception = self::raise(400, $body, 'corr-400');

        self::assertSame(
            'HubSpot API request failed with status 400. Quote correlation id corr-400 to HubSpot support.',
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
