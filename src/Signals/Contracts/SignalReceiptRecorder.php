<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Signals\Contracts;

use DateTimeInterface;

/**
 * The THIRD instance of the inverted arrow `Sync\SyncStateContract` and
 * `Webhooks\Contracts\WebhookReceiptRecorder` already carry, for the
 * identical reason: `Signals` may not depend on `ReyemTech\Hubspot\Testing` (R5), so the layer
 * that needs the capability -- recording that a signal was buffered or a subject's roll-up was
 * flushed, for `Hubspot::assertSignalRecorded()` / `assertSignalFlushed()` /
 * `assertPropertyRolledUp()` to read -- declares the port and the composition root
 * (`HubspotManager`) implements it. `ServiceProvider::register()` binds the two together, on the
 * line beside the existing `SyncStateContract` and `WebhookReceiptRecorder` bindings.
 *
 * Named in prose rather than with an `{@see}` tag on purpose, mirroring `RequestLog`'s own
 * documented reason: Pint's `fully_qualified_strict_types` fixer turns such a tag into a real
 * `use` statement, and `Sync\SyncStateContract` / `Webhooks\Contracts\WebhookReceiptRecorder`
 * are exactly the two imports R5 and R7 forbid this file from naming.
 */
interface SignalReceiptRecorder
{
    /**
     * Records that a signal was buffered -- called from `SignalRecorder::record()` after the
     * INSERT succeeds. A receipt records that work FINISHED, never that it merely started, the
     * identical rule `WebhookReceiptRecorder`'s own docblock states.
     *
     * @param  array<string, mixed>  $properties
     */
    public function recordSignalBuffered(string $visitorId, string $signalName, array $properties, DateTimeInterface $occurredAt): void;

    /**
     * Records that a subject's roll-up was flushed -- called from `FlushSignalsJob` only after
     * that subject's write is confirmed AND its trail appended, never merely attempted.
     *
     * @param  array<string, mixed>  $properties
     */
    public function recordSignalFlushed(string $subjectType, string $subjectId, array $properties): void;
}
