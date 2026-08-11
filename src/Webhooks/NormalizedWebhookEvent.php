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

    /**
     * The width the same migration bounds `subscription_type` to, and {@see self::MAX_OBJECT_ID_LENGTH}
     * the width it bounds `object_id` to (Laravel's default `string()` length).
     *
     * **Every value this class hands to a constrained column is checked here, not only `eventId`.**
     * `eventId` was for a while the only one, on the narrower argument above about aliasing. The
     * general rule is a separate and simpler one: with an asynchronous queue, `WebhookController`
     * answers `204` before any worker attempts the INSERT, so a value the column cannot hold is
     * not a failed request -- it is an ACKNOWLEDGED delivery that no longer exists, discovered
     * only in a worker log after HubSpot has stopped retrying. Validating at normalization turns
     * every one of them back into the documented `400` (D-13) the sender can act on.
     */
    public const int MAX_SUBSCRIPTION_TYPE_LENGTH = 191;

    public const int MAX_OBJECT_ID_LENGTH = 255;

    /**
     * The range `occurred_at` can hold, in the epoch milliseconds HubSpot sends:
     * `1000-01-01 00:00:00` through `9999-12-31 23:59:59` UTC, MySQL's `DATETIME` limits and the
     * narrowest of the three drivers this package supports.
     *
     * **`occurred_at` is a `DATETIME` and not a `timestamp()` like its neighbours, and the reason
     * is whose value it is.** `claimed_at`, `handled_at` and `timestamps()` are stamped by this
     * package at write time, so they are always "now" and `TIMESTAMP`'s 2038 ceiling is a distant,
     * Laravel-wide concern rather than this table's. `occurred_at` is the one column here whose
     * value arrives from OUTSIDE, in a signed payload nobody local chose -- so an out-of-range
     * instant is reachable today, not in 2038. Under `TIMESTAMP` a delivery dated past 2038 was
     * answered `204` and then lost when the worker's INSERT failed.
     *
     * Bounding it at 2038 instead would have been the same defect wearing a different hat: a 2039
     * event is WELL FORMED, and a package that refuses it is broken in 2039 rather than merely
     * lossy. Widening the column and bounding both ends at what the column genuinely holds refuses
     * nothing a real HubSpot event could carry, while still keeping an unstorable value from being
     * acknowledged.
     */
    private const int MIN_OCCURRED_AT_MILLISECONDS = -30610224000000;

    private const int MAX_OCCURRED_AT_MILLISECONDS = 253402300799999;

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
        /**
         * HubSpot's own `subscriptionId` -- retained because it is part of the delivery identity
         * {@see self::deliveryIdentity()} keys on, not for display.
         *
         * Nullable, and last, so adding it broke no existing construction: every call site names
         * its arguments. Null is a real state rather than a placeholder -- an item that carries no
         * subscriptionId hashes as a distinct identity from one that does, instead of colliding
         * with it.
         */
        public ?int $subscriptionId = null,
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
            eventId: self::bounded(self::requireIdentifier($item, 'eventId'), 'eventId', self::MAX_EVENT_ID_LENGTH),
            subscriptionType: self::bounded(
                self::requireString($item, 'subscriptionType'),
                'subscriptionType',
                self::MAX_SUBSCRIPTION_TYPE_LENGTH,
            ),
            portalId: self::requireUnsignedInt($item, 'portalId'),
            appId: self::optionalInt($item, 'appId'),
            objectId: self::bounded(
                self::requireIdentifier($item, 'objectId'),
                'objectId',
                self::MAX_OBJECT_ID_LENGTH,
            ),
            occurredAt: self::requireOccurredAt($item),
            attemptNumber: self::requireInt($item, 'attemptNumber'),
            changeSource: self::optionalString($item, 'changeSource'),
            changeFlag: self::optionalString($item, 'changeFlag'),
            propertyName: self::optionalString($item, 'propertyName'),
            propertyValue: self::optionalIdentifier($item, 'propertyValue'),
            associationType: self::optionalString($item, 'associationType'),
            fromObjectId: self::optionalIdentifier($item, 'fromObjectId'),
            toObjectId: self::optionalIdentifier($item, 'toObjectId'),
            subscriptionId: self::optionalUnsignedInt($item, 'subscriptionId'),
        );
    }

    /**
     * **The delivery this item is, as one indexable value.**
     *
     * HubSpot's Webhooks v3 API guide says of `eventId`: *"This value is not guaranteed to be
     * unique."* (checked 2026-08-11). It also says HubSpot *"does not guarantee that you'll only
     * get a single notification for an event"*. The second sentence is why D-01 needs an identity;
     * the first is why that identity cannot be `eventId` -- two distinct events sharing one would
     * collide, and the later one would be discarded as a redelivery of the earlier.
     *
     * Six fields, and each earns its place: `portalId` separates accounts, `subscriptionId`
     * separates subscriptions within an account, and `objectId` and `occurredAt` separate events
     * that share an id within one subscription.
     *
     * `subscriptionType` is here because `subscriptionId` is OPTIONAL -- `fromArray()` reads it
     * with `optionalInt()`, so an item may arrive without one, and the field meant to separate
     * subscriptions would then contribute nothing. `subscriptionType` is required on every item, so
     * it separates them even when the id is absent. An identity is only as strong as its weakest
     * input, and the weakest input here is the one that can be missing. `attemptNumber` is deliberately EXCLUDED -- it is the
     * one field a genuine redelivery changes, so including it would make every retry a new
     * delivery and leave HOOK-01 guaranteeing nothing.
     *
     * Hashed rather than stored as a composite index, following
     * `hubspot_object_links.lookup_hash`: it yields a fixed-width value of `0-9a-f` that no
     * collation on any driver can fold together, and it sidesteps the MySQL index-width limit that
     * already forced `event_id` to 191 characters. SHA-256 for collision resistance, not secrecy --
     * `DatabaseAssociationTypeStore::lookupHash()` records the same reasoning.
     *
     * `\0` separates the parts, and no two different field combinations can concatenate into the
     * same string -- `a|b` and `a` + `|b` would otherwise hash alike.
     *
     * **That holds because {@see self::bounded()} REFUSES the byte, not because JSON cannot carry
     * it.** An earlier version of this docblock asserted it could not occur in any of these
     * fields; JSON permits ` ` inside a string and `json_decode` returns a NUL byte, so the
     * separator was ambiguous for exactly as long as the claim went unenforced -- `subscriptionType`
     * `a\0b` with `eventId` `c` hashed identically to `a` with `b\0c`. An invariant a separator
     * depends on has to be checked somewhere, and the check is what the claim rests on.
     *
     * `occurredAt` is rendered with millisecond precision, the precision HubSpot sends.
     */
    public function deliveryIdentity(): string
    {
        return hash('sha256', implode("\0", [
            (string) $this->portalId,
            $this->subscriptionId === null ? '' : (string) $this->subscriptionId,
            $this->subscriptionType,
            $this->eventId,
            $this->objectId,
            $this->occurredAt->format('U.v'),
        ]));
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
     * Refuses a value wider than the `hubspot_webhook_events` column that stores it.
     *
     * Measured in BYTES (`strlen`, not `mb_strlen`) because the constraint being honoured is the
     * byte-width MySQL applies to a `VARCHAR` index prefix, not a count of user-perceived
     * characters -- an over-long multibyte value must be refused on the same terms the column
     * refuses it.
     *
     * Rejecting beats truncating for every field, not just `eventId`: a truncated value is written
     * successfully and wrong, which is the failure mode this package exists to prevent.
     */
    private static function bounded(string $value, string $key, int $max): string
    {
        // Refused for TWO independent reasons, either of which alone would justify it.
        //
        // `deliveryIdentity()` joins these same fields with `\0` and relies on the byte not
        // occurring inside one: `subscriptionType` `a\0b` with `eventId` `c` concatenates to the
        // identical sequence as `a` with `b\0c`, so two distinct signed deliveries would hash
        // alike and the second would be discarded as a redelivery. That separator's claim is TRUE
        // because of this check, not independently of it.
        //
        // And PostgreSQL refuses a NUL byte in a `text`/`varchar` value outright, so an accepted
        // one is the same acknowledged-then-lost failure every bound in this class exists to stop.
        if (str_contains($value, "\0")) {
            throw new InvalidArgumentException(sprintf(
                'A webhook event\'s "%s" contains a NUL byte. It is refused because the delivery '
                .'identity joins these fields on that byte, so allowing it would let two distinct '
                .'deliveries hash alike and silently drop the second as a redelivery.',
                $key,
            ));
        }

        if (strlen($value) > $max) {
            throw new InvalidArgumentException(sprintf(
                'A webhook event\'s "%s" is %d bytes, which exceeds the %d-byte column width '
                .'hubspot_webhook_events stores it at. Rejecting it here rather than truncating it '
                .'keeps a value that cannot be stored from being acknowledged as if it had been.',
                $key,
                strlen($value),
                $max,
            ));
        }

        return $value;
    }

    /**
     * `portal_id` and `subscription_id` are UNSIGNED columns.
     *
     * A negative value is rejected by MySQL in strict mode and accepted by PostgreSQL and SQLite,
     * which have no unsigned integer and take the column as a signed `bigint`. So leaving this
     * unchecked would not merely risk one lost delivery -- it would let the SAME correctly signed
     * payload behave differently on three drivers this package supports.
     *
     * @param  array<string, mixed>  $item
     */
    private static function requireUnsignedInt(array $item, string $key): int
    {
        return self::unsigned(self::requireInt($item, $key), $key);
    }

    /**
     * The optional counterpart. An ABSENT value stays a valid `null`; only a value that is present
     * and negative is refused, so bounding the field does not make it required.
     *
     * @param  array<string, mixed>  $item
     */
    private static function optionalUnsignedInt(array $item, string $key): ?int
    {
        $value = self::optionalInt($item, $key);

        return $value === null ? null : self::unsigned($value, $key);
    }

    private static function unsigned(int $value, string $key): int
    {
        if ($value < 0) {
            throw new InvalidArgumentException(sprintf(
                'A webhook event\'s "%s" is %d, and hubspot_webhook_events stores it in an '
                .'unsigned column. A negative value is refused by MySQL and silently accepted by '
                .'PostgreSQL and SQLite, so it is refused here on every driver alike.',
                $key,
                $value,
            ));
        }

        return $value;
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

        if ($value < self::MIN_OCCURRED_AT_MILLISECONDS || $value > self::MAX_OCCURRED_AT_MILLISECONDS) {
            throw new InvalidArgumentException(sprintf(
                'A webhook event\'s "occurredAt" is %d, which is outside the range '
                .'hubspot_webhook_events can store it in -- 1000-01-01 through 9999-12-31. It is '
                .'refused here rather than acknowledged and then lost by the worker\'s insert.',
                $value,
            ));
        }

        $seconds = intdiv($value, 1000);
        $milliseconds = $value - ($seconds * 1000);

        return (new DateTimeImmutable('@'.$seconds))->modify(sprintf('+%d milliseconds', $milliseconds));
    }
}
