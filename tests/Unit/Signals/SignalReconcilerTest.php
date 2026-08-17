<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Unit\Signals;

use ReyemTech\Hubspot\Signals\SignalReconciler;
use ReyemTech\Hubspot\Tests\TestCase;

mutates(SignalReconciler::class);

/**
 * `SignalReconciler::withPersistedProperties()` in isolation (P1, PR #82 review): no database, no
 * fake, no Testbench boot -- a pure function of the rows it is handed and the buffer's own computed
 * properties, mirroring `RollUpCalculatorTest`'s identical unit-level shape.
 *
 * `FlushReconcileTest`'s own integration-level tests already prove the END-TO-END durability this
 * method exists for (a retry after a failed write still writes the read's value); this file closes
 * the mutation-coverage gap those tests cannot reach with a single-row fixture -- several of the
 * mutants below (`continue` vs `break`, `break` vs `continue`) are only distinguishable with TWO
 * rows, since a one-row loop behaves identically either way.
 */
final class SignalReconcilerTest extends TestCase
{
    public function test_an_unreconciled_subject_returns_the_buffer_untouched_even_if_a_row_carries_a_stray_value(): void
    {
        // reconciled_at is null -- alreadyReconciled() is false -- so nothing below the early
        // return should ever run, even though this row's own reconciled_properties carries a value
        // (a state reconcileChunk() never actually produces, but the guard is what proves the
        // early return, not reconcileChunk()'s own discipline).
        $rows = [self::row(reconciledAt: null, reconciledProperties: ['first_touch_source' => 'stray-value'])];

        $result = SignalReconciler::withPersistedProperties($rows, ['first_touch_source' => 'buffer-value']);

        self::assertSame(['first_touch_source' => 'buffer-value'], $result);
    }

    public function test_a_null_reconciled_properties_row_is_skipped_in_favour_of_a_later_row_that_carries_one(): void
    {
        $rows = [
            self::row(reconciledAt: '2026-08-12 12:00:00', reconciledProperties: null),
            self::row(reconciledAt: '2026-08-12 12:00:00', reconciledProperties: ['first_touch_source' => 'portal-value']),
        ];

        $result = SignalReconciler::withPersistedProperties($rows, ['first_touch_source' => 'buffer-value']);

        self::assertSame(['first_touch_source' => 'portal-value'], $result);
    }

    public function test_a_non_array_decoded_row_is_skipped_in_favour_of_a_later_row_that_decodes(): void
    {
        $rows = [
            self::row(reconciledAt: '2026-08-12 12:00:00', reconciledProperties: 'garbage'),
            self::row(reconciledAt: '2026-08-12 12:00:00', reconciledProperties: ['first_touch_source' => 'portal-value']),
        ];

        $result = SignalReconciler::withPersistedProperties($rows, ['first_touch_source' => 'buffer-value']);

        self::assertSame(['first_touch_source' => 'portal-value'], $result);
    }

    public function test_a_non_string_persisted_value_is_never_merged(): void
    {
        // json_decode() can hand back any scalar for a property value -- this package only ever
        // writes strings (reconcileChunk()'s own $portalValue is always a string), so a non-string
        // is data this package did not write, treated exactly like nothing being persisted.
        $rows = [self::row(reconciledAt: '2026-08-12 12:00:00', reconciledProperties: ['first_touch_source' => 42])];

        $result = SignalReconciler::withPersistedProperties($rows, ['first_touch_source' => 'buffer-value']);

        self::assertSame(['first_touch_source' => 'buffer-value'], $result);
    }

    public function test_an_empty_string_persisted_value_is_never_merged(): void
    {
        // Mirrors reconcileChunk()'s own "non-empty wins" precedence for the live read -- an empty
        // string means the portal held nothing FOR THAT READ, so the buffer's own value still wins.
        $rows = [self::row(reconciledAt: '2026-08-12 12:00:00', reconciledProperties: ['first_touch_source' => ''])];

        $result = SignalReconciler::withPersistedProperties($rows, ['first_touch_source' => 'buffer-value']);

        self::assertSame(['first_touch_source' => 'buffer-value'], $result);
    }

    public function test_only_the_first_rows_persisted_properties_are_read_a_second_rows_are_never_consulted(): void
    {
        // Every row for a subject is written together in the SAME update() call
        // (reconcileChunk()'s own docblock) -- reading only the first is a deliberate stop, not an
        // accidental one. Two DIFFERENT keys prove it: if the loop kept going, the second row's
        // "b" would ALSO be merged; the first row's own break stops it before that happens.
        $rows = [
            self::row(reconciledAt: '2026-08-12 12:00:00', reconciledProperties: ['a' => 'first-row-value']),
            self::row(reconciledAt: '2026-08-12 12:00:00', reconciledProperties: ['b' => 'second-row-value']),
        ];

        $result = SignalReconciler::withPersistedProperties($rows, ['a' => 'buffer-a', 'b' => 'buffer-b']);

        self::assertSame(['a' => 'first-row-value', 'b' => 'buffer-b'], $result);
    }

    /**
     * @param  array<string, mixed>|string|null  $reconciledProperties
     * @return object{id: int, signal_name: string, properties: ?string, occurred_at: string, flushed_at: ?string, reconciled_at: ?string, reconciled_properties: ?string}
     */
    private static function row(?string $reconciledAt, array|string|null $reconciledProperties): object
    {
        return (object) [
            'id' => 1,
            'signal_name' => 'pricing_page_viewed',
            'properties' => null,
            'occurred_at' => '2026-08-12 12:00:00',
            'flushed_at' => null,
            'reconciled_at' => $reconciledAt,
            'reconciled_properties' => $reconciledProperties === null
                ? null
                : json_encode($reconciledProperties, JSON_THROW_ON_ERROR),
        ];
    }
}
