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
     * MySQL's `TIMESTAMP` range begins at `1970-01-01 00:00:01` UTC, so an `occurredAt` at or
     * before the epoch is out of range and strict mode rejects it --
     * `DatabaseWebhookEventStore::RECLAIMABLE_AT` already pays for that boundary being known.
     * Rejected here rather than at the insert for the same reason as every case above.
     *
     * Only the LOWER bound is enforced. A time past 2038 is a WELL-FORMED value this column
     * happens not to reach; refusing it would itself be the defect, and the remedy is the column
     * type every `timestamp()` in this package shares, not a normalization rule on this one field.
     */
    public function test_it_rejects_an_occurred_at_at_or_before_the_earliest_storable_instant(): void
    {
        $item = self::rawItem();
        $item['occurredAt'] = 0;

        $this->expectException(InvalidArgumentException::class);

        NormalizedWebhookEvent::fromArray($item);
    }

    public function test_it_rejects_a_negative_occurred_at(): void
    {
        $item = self::rawItem();
        $item['occurredAt'] = -1000;

        $this->expectException(InvalidArgumentException::class);

        NormalizedWebhookEvent::fromArray($item);
    }

    /**
     * 999ms rounds down to `1970-01-01 00:00:00`, the instant MySQL's `TIMESTAMP` excludes, so the
     * boundary is 1000 and not 999. Asserted from BELOW as well as above because a bound checked
     * only from one side is satisfied by an off-by-one on the other.
     */
    public function test_it_rejects_an_occurred_at_one_millisecond_below_the_earliest_storable_instant(): void
    {
        $item = self::rawItem();
        $item['occurredAt'] = 999;

        $this->expectException(InvalidArgumentException::class);

        NormalizedWebhookEvent::fromArray($item);
    }

    public function test_it_accepts_an_occurred_at_at_exactly_the_earliest_storable_instant(): void
    {
        $item = self::rawItem();
        $item['occurredAt'] = 1000;

        $event = NormalizedWebhookEvent::fromArray($item);

        self::assertSame('1970-01-01 00:00:01', $event->occurredAt->format('Y-m-d H:i:s'));
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

    public function test_a_pre_epoch_occurred_at_is_refused_with_a_message_naming_the_timestamp_range(): void
    {
        $item = self::rawItem();
        $item['occurredAt'] = 0;

        try {
            NormalizedWebhookEvent::fromArray($item);
            self::fail('A pre-epoch occurredAt was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('"occurredAt" is 0, which is at or before', $exception->getMessage());
            self::assertStringContainsString('timestamp column whose range begins one', $exception->getMessage());
            self::assertStringContainsString('second later', $exception->getMessage());
        }
    }
}
