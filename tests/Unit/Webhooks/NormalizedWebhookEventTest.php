<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Unit\Webhooks;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReyemTech\Hubspot\Tests\TestCase;
use ReyemTech\Hubspot\Webhooks\NormalizedWebhookEvent;

/**
 * `NormalizedWebhookEvent::fromArray()` is the whole of the shape this package hands to every
 * generic event and (in a later plan) typed event and configured handler -- it exposes no SDK
 * type and no raw payload array (D-05). Covered directly here, complementing the HTTP-level
 * coverage in `tests/Feature/Webhooks/*`, which exercises it only through fixture items shaped
 * with integer ids.
 */
final class NormalizedWebhookEventTest extends TestCase
{
    /**
     * @return array{eventId: int, subscriptionId: int, portalId: int, appId: int, occurredAt: int, subscriptionType: string, attemptNumber: int, objectId: int, changeSource: string, changeFlag: string}
     */
    private static function rawItem(): array
    {
        return [
            'eventId' => 1,
            'subscriptionId' => 12345,
            'portalId' => 62515,
            'appId' => 54321,
            'occurredAt' => 1564113600000,
            'subscriptionType' => 'contact.creation',
            'attemptNumber' => 0,
            'objectId' => 123,
            'changeSource' => 'CRM',
            'changeFlag' => 'NEW',
        ];
    }

    public function test_it_carries_a_string_typed_event_id_and_object_id_when_hubspot_sends_them_as_ints(): void
    {
        $event = NormalizedWebhookEvent::fromArray(self::rawItem());

        self::assertSame('1', $event->eventId);
        self::assertSame('123', $event->objectId);
    }

    /**
     * HubSpot's JSON always sends `eventId`/`objectId` as numbers, but this normalizer accepts a
     * string just as validly -- the opaque-string contract does not depend on which shape arrived.
     */
    public function test_it_accepts_an_already_string_typed_event_id_and_object_id(): void
    {
        $item = self::rawItem();
        $item['eventId'] = 'evt_abc123';
        $item['objectId'] = 'obj_xyz789';

        $event = NormalizedWebhookEvent::fromArray($item);

        self::assertSame('evt_abc123', $event->eventId);
        self::assertSame('obj_xyz789', $event->objectId);
    }

    public function test_it_carries_a_string_typed_optional_association_field_when_already_a_string(): void
    {
        $item = self::rawItem();
        $item['fromObjectId'] = 'obj_from';
        $item['toObjectId'] = 'obj_to';

        $event = NormalizedWebhookEvent::fromArray($item);

        self::assertSame('obj_from', $event->fromObjectId);
        self::assertSame('obj_to', $event->toObjectId);
    }

    public function test_optional_association_fields_default_to_null_when_absent(): void
    {
        $event = NormalizedWebhookEvent::fromArray(self::rawItem());

        self::assertNull($event->fromObjectId);
        self::assertNull($event->toObjectId);
        self::assertNull($event->propertyName);
        self::assertNull($event->propertyValue);
        self::assertNull($event->associationType);
    }

    public function test_occurred_at_is_a_millisecond_precise_date_time_immutable(): void
    {
        $event = NormalizedWebhookEvent::fromArray(self::rawItem());

        self::assertInstanceOf(DateTimeImmutable::class, $event->occurredAt);
        self::assertSame(1564113600, $event->occurredAt->getTimestamp());
        self::assertSame('000', $event->occurredAt->format('v'));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function requiredKeys(): iterable
    {
        yield 'subscriptionType' => ['subscriptionType'];
        yield 'portalId' => ['portalId'];
        yield 'attemptNumber' => ['attemptNumber'];
        yield 'occurredAt' => ['occurredAt'];
        yield 'eventId' => ['eventId'];
        yield 'objectId' => ['objectId'];
    }

    #[DataProvider('requiredKeys')]
    public function test_it_rejects_an_item_missing_a_required_key(string $key): void
    {
        $item = self::rawItem();
        unset($item[$key]);

        $this->expectException(InvalidArgumentException::class);

        NormalizedWebhookEvent::fromArray($item);
    }

    public function test_it_rejects_an_occurred_at_that_is_not_an_integer(): void
    {
        $item = self::rawItem();
        $item['occurredAt'] = 'not-a-timestamp';

        $this->expectException(InvalidArgumentException::class);

        NormalizedWebhookEvent::fromArray($item);
    }

    /**
     * T-05-11 (05-02-PLAN.md threat register): a truncated `eventId` would silently alias two
     * distinct events onto the same `hubspot_webhook_events` dedupe row, so an over-long id is
     * rejected here rather than truncated to fit the column.
     */
    public function test_it_accepts_an_event_id_at_exactly_the_column_width(): void
    {
        $item = self::rawItem();
        $item['eventId'] = str_repeat('a', NormalizedWebhookEvent::MAX_EVENT_ID_LENGTH);

        $event = NormalizedWebhookEvent::fromArray($item);

        self::assertSame(NormalizedWebhookEvent::MAX_EVENT_ID_LENGTH, strlen($event->eventId));
    }

    public function test_it_rejects_an_event_id_exceeding_the_column_width(): void
    {
        $item = self::rawItem();
        $item['eventId'] = str_repeat('a', NormalizedWebhookEvent::MAX_EVENT_ID_LENGTH + 1);

        $this->expectException(InvalidArgumentException::class);

        NormalizedWebhookEvent::fromArray($item);
    }

    /**
     * `eventId` was for a while the ONLY field bounded to what `hubspot_webhook_events` can hold,
     * and the four cases below are its siblings: every other normalized value the store's INSERT
     * writes to a width- or range-constrained column. A signed item carrying one of them was
     * accepted here, acknowledged `204`, and only rejected later by the worker's insert -- after
     * HubSpot had stopped retrying. Each is asserted at the boundary AND one past it, so a bound
     * that is off by one fails a test rather than passing both.
     */
    public function test_it_accepts_a_subscription_type_at_exactly_the_column_width(): void
    {
        $item = self::rawItem();
        $item['subscriptionType'] = str_repeat('a', NormalizedWebhookEvent::MAX_SUBSCRIPTION_TYPE_LENGTH);

        $event = NormalizedWebhookEvent::fromArray($item);

        self::assertSame(NormalizedWebhookEvent::MAX_SUBSCRIPTION_TYPE_LENGTH, strlen($event->subscriptionType));
    }

    public function test_it_rejects_a_subscription_type_exceeding_the_column_width(): void
    {
        $item = self::rawItem();
        $item['subscriptionType'] = str_repeat('a', NormalizedWebhookEvent::MAX_SUBSCRIPTION_TYPE_LENGTH + 1);

        $this->expectException(InvalidArgumentException::class);

        NormalizedWebhookEvent::fromArray($item);
    }

    public function test_it_accepts_an_object_id_at_exactly_the_column_width(): void
    {
        $item = self::rawItem();
        $item['objectId'] = str_repeat('9', NormalizedWebhookEvent::MAX_OBJECT_ID_LENGTH);

        $event = NormalizedWebhookEvent::fromArray($item);

        self::assertSame(NormalizedWebhookEvent::MAX_OBJECT_ID_LENGTH, strlen($event->objectId));
    }

    public function test_it_rejects_an_object_id_exceeding_the_column_width(): void
    {
        $item = self::rawItem();
        $item['objectId'] = str_repeat('9', NormalizedWebhookEvent::MAX_OBJECT_ID_LENGTH + 1);

        $this->expectException(InvalidArgumentException::class);

        NormalizedWebhookEvent::fromArray($item);
    }

    /**
     * `portal_id` and `subscription_id` are UNSIGNED columns. A negative value is rejected by
     * MySQL in strict mode and accepted by PostgreSQL and SQLite, which map the column to a signed
     * `bigint` -- so leaving it unvalidated does not merely risk a lost delivery on one driver, it
     * makes the SAME signed payload behave differently on three supported drivers.
     */
    public function test_it_rejects_a_negative_portal_id(): void
    {
        $item = self::rawItem();
        $item['portalId'] = -1;

        $this->expectException(InvalidArgumentException::class);

        NormalizedWebhookEvent::fromArray($item);
    }

    public function test_it_accepts_a_zero_portal_id(): void
    {
        $item = self::rawItem();
        $item['portalId'] = 0;

        self::assertSame(0, NormalizedWebhookEvent::fromArray($item)->portalId);
    }

    public function test_it_rejects_a_negative_subscription_id(): void
    {
        $item = self::rawItem();
        $item['subscriptionId'] = -1;

        $this->expectException(InvalidArgumentException::class);

        NormalizedWebhookEvent::fromArray($item);
    }

    /**
     * An ABSENT `subscriptionId` stays a valid null -- the unsigned check bounds the value when one
     * is present and must not turn the optional field into a required one.
     */
    public function test_it_still_accepts_an_absent_subscription_id(): void
    {
        $item = self::rawItem();
        unset($item['subscriptionId']);

        self::assertNull(NormalizedWebhookEvent::fromArray($item)->subscriptionId);
    }

    /**
     * **The case the column type exists to serve.** `occurred_at` was a MySQL `TIMESTAMP`, whose
     * range ends at `2038-01-19`, while normalization accepted any future instant -- so a signed
     * delivery dated past 2038 was answered `204` and then lost when the worker's INSERT failed.
     * The column is a `DATETIME` for this test's sake; the assertion is that a well-formed future
     * event is ACCEPTED, which is the half a normalization-only fix would have got backwards.
     */
    public function test_it_accepts_an_occurred_at_beyond_the_range_a_mysql_timestamp_could_hold(): void
    {
        $item = self::rawItem();
        $item['occurredAt'] = 2177452800000;

        $event = NormalizedWebhookEvent::fromArray($item);

        self::assertSame('2039-01-01', $event->occurredAt->format('Y-m-d'));
    }

    /**
     * The epoch itself, and instants before it, are ordinary storable values under `DATETIME` --
     * they were refused only while the column was a `TIMESTAMP` that began in 1970. Kept as an
     * explicit case so re-narrowing the column cannot pass silently.
     */
    public function test_it_accepts_the_epoch_and_instants_before_it(): void
    {
        $item = self::rawItem();
        $item['occurredAt'] = 0;

        self::assertSame('1970-01-01 00:00:00', NormalizedWebhookEvent::fromArray($item)->occurredAt->format('Y-m-d H:i:s'));

        $item['occurredAt'] = -86400000;

        self::assertSame('1969-12-31', NormalizedWebhookEvent::fromArray($item)->occurredAt->format('Y-m-d'));
    }

    /**
     * Both ends are bounded at what `DATETIME` genuinely holds -- `1000-01-01 00:00:00` through
     * `9999-12-31 23:59:59`. Nothing a real HubSpot event could carry lies outside that, so this
     * refuses no well-formed value while still keeping an unstorable one from being acknowledged.
     * Asserted at each boundary AND one millisecond past it, so an off-by-one fails a test.
     */
    public function test_it_accepts_an_occurred_at_at_each_end_of_the_storable_range(): void
    {
        $item = self::rawItem();
        $item['occurredAt'] = -30610224000000;

        self::assertSame('1000-01-01 00:00:00', NormalizedWebhookEvent::fromArray($item)->occurredAt->format('Y-m-d H:i:s'));

        $item['occurredAt'] = 253402300799999;

        self::assertSame('9999-12-31 23:59:59', NormalizedWebhookEvent::fromArray($item)->occurredAt->format('Y-m-d H:i:s'));
    }

    public function test_it_rejects_an_occurred_at_one_millisecond_below_the_storable_range(): void
    {
        $item = self::rawItem();
        $item['occurredAt'] = -30610224000001;

        $this->expectException(InvalidArgumentException::class);

        NormalizedWebhookEvent::fromArray($item);
    }

    public function test_it_rejects_an_occurred_at_one_millisecond_above_the_storable_range(): void
    {
        $item = self::rawItem();
        $item['occurredAt'] = 253402300800000;

        $this->expectException(InvalidArgumentException::class);

        NormalizedWebhookEvent::fromArray($item);
    }

    /**
     * `deliveryIdentity()` joins its parts with `\0` and its docblock justified that by saying the
     * byte "cannot occur in any of them" -- but JSON permits ` ` inside a string, `json_decode`
     * returns it as a NUL byte, and nothing refused it. The separator was therefore ambiguous:
     * `subscriptionType` `a\0b` with `eventId` `c` concatenates to the identical byte sequence as
     * `a` with `b\0c`, so two DISTINCT signed deliveries hash alike and the second is discarded as
     * a redelivery -- the exact silent-collision failure D-01 keys on an identity to avoid.
     *
     * It is also the same acknowledged-then-lost class as the widths above: PostgreSQL refuses a
     * NUL byte in a `text`/`varchar` value outright, so the worker's INSERT fails after the `204`.
     *
     * Both halves are closed by refusing the byte, which is what makes the docblock's claim true
     * rather than assumed.
     */
    public function test_it_refuses_a_nul_byte_in_each_field_the_delivery_identity_joins(): void
    {
        foreach (['eventId', 'subscriptionType', 'objectId'] as $key) {
            $item = self::rawItem();
            $item[$key] = "a\0b";

            try {
                NormalizedWebhookEvent::fromArray($item);
                self::fail(sprintf('A NUL byte in "%s" was accepted.', $key));
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString($key, $exception->getMessage());
            }
        }
    }

    /**
     * The concrete collision, asserted as the pair it is: were either shape still accepted, these
     * two distinct deliveries would share one `deliveryIdentity()`.
     */
    public function test_the_two_shapes_that_would_share_a_delivery_identity_are_both_refused(): void
    {
        $split = self::rawItem();
        $split['subscriptionType'] = "a\0b";
        $split['eventId'] = 'c';

        $shifted = self::rawItem();
        $shifted['subscriptionType'] = 'a';
        $shifted['eventId'] = "b\0c";

        $refused = 0;

        foreach ([$split, $shifted] as $item) {
            try {
                NormalizedWebhookEvent::fromArray($item);
            } catch (InvalidArgumentException) {
                $refused++;
            }
        }

        self::assertSame(2, $refused);
    }

    /**
     * **Pinned to the migration's literal widths, deliberately not to the constants themselves.**
     *
     * Every boundary test above spends the constant, so it moves WITH the constant: raise
     * `MAX_SUBSCRIPTION_TYPE_LENGTH` to 200 without touching
     * `create_hubspot_webhook_events_table.php` and all of them still pass while every 192-byte
     * value is acknowledged and then lost. This is the one assertion that fails on that drift, and
     * it is why the numbers here are written out rather than referenced.
     */
    public function test_the_declared_bounds_match_the_column_widths_the_migration_creates(): void
    {
        self::assertSame(191, NormalizedWebhookEvent::MAX_EVENT_ID_LENGTH);
        self::assertSame(191, NormalizedWebhookEvent::MAX_SUBSCRIPTION_TYPE_LENGTH);
        self::assertSame(255, NormalizedWebhookEvent::MAX_OBJECT_ID_LENGTH);
    }

    /**
     * The rejections above are answered to the sender as a `400` (D-13), so the message IS the
     * diagnosis (STANDARDS Sec.9) rather than incidental prose. Each assertion below spans a
     * boundary between the concatenated fragments, so a message silently losing one of them fails
     * here instead of shipping a 400 that names no cause.
     */
    public function test_an_over_long_value_is_refused_with_a_message_naming_the_field_and_both_widths(): void
    {
        $item = self::rawItem();
        $item['subscriptionType'] = str_repeat('a', 192);

        try {
            NormalizedWebhookEvent::fromArray($item);
            self::fail('An over-long subscriptionType was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('"subscriptionType" is 192 bytes', $exception->getMessage());
            self::assertStringContainsString('191-byte column width hubspot_webhook_events', $exception->getMessage());
            self::assertStringContainsString('truncating it keeps a value', $exception->getMessage());
        }
    }

    public function test_a_negative_unsigned_value_is_refused_with_a_message_naming_the_driver_split(): void
    {
        $item = self::rawItem();
        $item['portalId'] = -7;

        try {
            NormalizedWebhookEvent::fromArray($item);
            self::fail('A negative portalId was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('"portalId" is -7', $exception->getMessage());
            self::assertStringContainsString('stores it in an unsigned column', $exception->getMessage());
            self::assertStringContainsString('PostgreSQL and SQLite', $exception->getMessage());
        }
    }

    public function test_an_unstorable_occurred_at_is_refused_with_a_message_naming_the_range(): void
    {
        $item = self::rawItem();
        $item['occurredAt'] = 253402300800000;

        try {
            NormalizedWebhookEvent::fromArray($item);
            self::fail('An out-of-range occurredAt was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('"occurredAt" is 253402300800000', $exception->getMessage());
            self::assertStringContainsString('outside the range hubspot_webhook_events', $exception->getMessage());
            self::assertStringContainsString('1000-01-01 through 9999-12-31', $exception->getMessage());
        }
    }
}
