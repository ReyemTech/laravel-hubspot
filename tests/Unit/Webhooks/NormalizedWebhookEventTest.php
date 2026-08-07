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
}
