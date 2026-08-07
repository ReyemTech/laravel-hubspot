<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Webhooks;

use DateTimeImmutable;
use InvalidArgumentException;
use ReyemTech\Hubspot\Gateway\ObjectRef;

/**
 * One HubSpot webhook item, normalized from the raw JSON HubSpot posts into package-owned values.
 * Exposes no `HubSpot\*` type — R4 forbids `Webhooks` from naming one, and this is the value every
 * generic and (in a later plan) typed event and configured handler receives, never the raw payload
 * array (D-05).
 *
 * `eventId` is deliberately an opaque `string`, not an `int`: HubSpot's JSON sends it as a number,
 * but nothing in this package ever arithmetic on it — the whole justification for
 * `declare(strict_types=1)` here (STANDARDS §4) is that an identifier that merely looks numeric is
 * safer carried as a string than coerced through one. `objectId` follows the same convention this
 * package already applies to every other HubSpot record id (see {@see ObjectRef}).
 *
 * `portalId`, `appId` and `attemptNumber` stay `int`: they are genuinely counted or compared
 * (a retry count, a portal/app identity), not looked up by value the way a record id is.
 *
 * The property-change, association and lifecycle fields are all optional and normalized loosely —
 * this plan ships the generic receipt path only (HOOK-01); dedicated typed events reading a
 * narrower, validated subset of these fields are a later plan's concern (05-CONTEXT.md D-09).
 *
 * `final readonly`; this is a plain value object with no documented extension point (STANDARDS §8).
 */
final readonly class NormalizedWebhookEvent
{
    /**
     * The width `database/migrations/webhooks/*_create_hubspot_webhook_events_table.php` bounds
     * `event_id` to. Enforced here, at normalization, rather than left to the database driver: a
     * driver that silently truncated an over-long value would alias two distinct HubSpot events
     * onto the same dedupe row (T-05-11, 05-02-PLAN.md threat register), which is exactly the
     * silent-wrong-id failure class this package exists to prevent. Rejecting it here means the
     * failure surfaces as a `400` (D-13) naming the shape problem, never as a query that quietly
     * wrote the wrong key.
     */
    public const int MAX_EVENT_ID_LENGTH = 191;

    public function __construct(
        public string $eventId,
        public string $subscriptionType,
        public int $portalId,
        public ?int $appId,
        public string $objectId,
        public DateTimeImmutable $occurredAt,
        public int $attemptNumber,
        public ?string $changeSource = null,
        public ?string $changeFlag = null,
        public ?string $propertyName = null,
        public ?string $propertyValue = null,
        public ?string $associationType = null,
        public ?string $fromObjectId = null,
        public ?string $toObjectId = null,
    ) {}

    /**
     * Normalizes one decoded JSON item from a HubSpot webhook batch.
     *
     * @param  array<string, mixed>  $item
     *
     * @throws InvalidArgumentException if a required field is missing or the wrong shape — caught
     *                                  by `Webhooks\WebhookController` and mapped to an HTTP 400
     *                                  (D-13), never left to escape as an unhandled exception.
     */
    public static function fromArray(array $item): self
    {
        return new self(
            eventId: self::requireEventId($item),
            subscriptionType: self::requireString($item, 'subscriptionType'),
            portalId: self::requireInt($item, 'portalId'),
            appId: self::optionalInt($item, 'appId'),
            objectId: self::requireIdentifier($item, 'objectId'),
            occurredAt: self::requireOccurredAt($item),
            attemptNumber: self::requireInt($item, 'attemptNumber'),
            changeSource: self::optionalString($item, 'changeSource'),
            changeFlag: self::optionalString($item, 'changeFlag'),
            propertyName: self::optionalString($item, 'propertyName'),
            propertyValue: self::optionalIdentifier($item, 'propertyValue'),
            associationType: self::optionalString($item, 'associationType'),
            fromObjectId: self::optionalIdentifier($item, 'fromObjectId'),
            toObjectId: self::optionalIdentifier($item, 'toObjectId'),
        );
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private static function requireIdentifier(array $item, string $key): string
    {
        $value = $item[$key] ?? null;

        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        throw new InvalidArgumentException(sprintf('A webhook event is missing a valid "%s".', $key));
    }

    /**
     * `eventId` specifically -- see {@see self::MAX_EVENT_ID_LENGTH}'s own docblock for why this is
     * the one identifier this class bounds the length of. `objectId` and the other opaque
     * identifiers below are not columns this package indexes on a fixed width, so they carry no
     * equivalent restriction.
     *
     * @param  array<string, mixed>  $item
     */
    private static function requireEventId(array $item): string
    {
        $eventId = self::requireIdentifier($item, 'eventId');

        if (strlen($eventId) > self::MAX_EVENT_ID_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'A webhook event\'s "eventId" is %d characters, which exceeds the %d-character '
                .'column width hubspot_webhook_events indexes it at. Rejecting it here rather than '
                .'truncating it keeps two distinct events from silently aliasing onto one dedupe row.',
                strlen($eventId),
                self::MAX_EVENT_ID_LENGTH,
            ));
        }

        return $eventId;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private static function optionalIdentifier(array $item, string $key): ?string
    {
        $value = $item[$key] ?? null;

        if (is_string($value) && $value !== '') {
            return $value;
        }

        return is_int($value) ? (string) $value : null;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private static function requireString(array $item, string $key): string
    {
        $value = $item[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException(sprintf('A webhook event is missing a valid "%s".', $key));
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private static function optionalString(array $item, string $key): ?string
    {
        $value = $item[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private static function requireInt(array $item, string $key): int
    {
        $value = $item[$key] ?? null;

        if (! is_int($value)) {
            throw new InvalidArgumentException(sprintf('A webhook event is missing a valid "%s".', $key));
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private static function optionalInt(array $item, string $key): ?int
    {
        $value = $item[$key] ?? null;

        return is_int($value) ? $value : null;
    }

    /**
     * HubSpot sends `occurredAt` as epoch milliseconds. Split rather than truncated to
     * `intdiv(...,1000)` alone, so the millisecond component this package receives is not silently
     * discarded before `DateTimeImmutable` ever sees it.
     *
     * @param  array<string, mixed>  $item
     */
    private static function requireOccurredAt(array $item): DateTimeImmutable
    {
        $value = $item['occurredAt'] ?? null;

        if (! is_int($value)) {
            throw new InvalidArgumentException('A webhook event is missing a valid "occurredAt".');
        }

        $seconds = intdiv($value, 1000);
        $milliseconds = $value - ($seconds * 1000);

        return (new DateTimeImmutable('@'.$seconds))->modify(sprintf('+%d milliseconds', $milliseconds));
    }
}
