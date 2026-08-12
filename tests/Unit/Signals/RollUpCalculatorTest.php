<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Unit\Signals;

use DateTimeImmutable;
use Illuminate\Support\Collection;
use LogicException;
use ReyemTech\Hubspot\Signals\Contracts\SignalCalculator;
use ReyemTech\Hubspot\Signals\MergeRule;
use ReyemTech\Hubspot\Signals\RollUpCalculator;
use ReyemTech\Hubspot\Tests\Support\Signals\BufferedSignal;
use ReyemTech\Hubspot\Tests\Support\Signals\IntentScore;
use ReyemTech\Hubspot\Tests\TestCase;
use UnexpectedValueException;

mutates(RollUpCalculator::class);

/**
 * A `SignalCalculator` fixture that records the exact `Collection` it was invoked with, so a test
 * can assert the invokable escape hatch (D-08) received precisely the signals `compute()` was
 * given -- not a copy, not a superset, not another subject's rows. Declared in this file rather
 * than a new `tests/Support` class: this test file is the only consumer, and 06-01's own lesson
 * (PHPUnit's file-based discovery collects only ONE *test* class per file) does not apply to a
 * plain, non-`TestCase` support class living alongside it.
 */
final class RecordingSignalCalculator implements SignalCalculator
{
    /**
     * @var Collection<int, array{
     *     id: int,
     *     signal_name: string,
     *     properties: array<string, mixed>,
     *     occurred_at: \DateTimeInterface,
     *     flushed_at: ?\DateTimeInterface,
     * }>|null
     */
    public static ?Collection $received = null;

    public function __invoke(Collection $signals): mixed
    {
        self::$received = $signals;

        return $signals->pluck('id')->implode(',');
    }
}

/**
 * SIG-04: `RollUpCalculator::compute()` is a pure function of its two arguments -- no fake, no
 * database, no config, no Testbench boot. `BufferedSignal::toArray()` builds the exact shape a real
 * `hubspot_signals` row decodes to, so every test here exercises the identical code path a
 * production `FlushSignalsJob` call does.
 *
 * `compute()` does not filter its `$signals` argument by signal name -- scoping a call to one
 * signal's rows is the CALLER's responsibility (mirroring `SignalMap::rulesFor()`'s own per-name
 * scope). "Matching signals" throughout these tests therefore means "every signal handed to this
 * particular `compute()` call."
 */
final class RollUpCalculatorTest extends TestCase
{
    public function test_valid_verbs_is_the_closed_four_verb_vocabulary_mergerule_owns(): void
    {
        self::assertSame(MergeRule::validVerbs(), RollUpCalculator::validVerbs());
        self::assertSame(['first_wins', 'last_wins', 'increment', 'sum'], RollUpCalculator::validVerbs());
    }

    public function test_first_wins_returns_the_earliest_signals_field_value(): void
    {
        $calculator = new RollUpCalculator;

        $result = $calculator->compute(
            self::toArrays([
                new BufferedSignal(1, 'pricing_page_viewed', ['source' => 'google_ads'], new DateTimeImmutable('2026-01-01 00:00:00')),
                new BufferedSignal(2, 'pricing_page_viewed', ['source' => 'direct'], new DateTimeImmutable('2026-01-02 00:00:00')),
                new BufferedSignal(3, 'pricing_page_viewed', ['source' => 'referral'], new DateTimeImmutable('2026-01-03 00:00:00')),
            ]),
            ['first_touch_source' => self::rule('first_wins:source')],
        );

        self::assertSame(['first_touch_source' => 'google_ads'], $result);
    }

    public function test_first_wins_is_never_overwritten_once_set(): void
    {
        $calculator = new RollUpCalculator;

        $result = $calculator->compute(
            self::toArrays([
                new BufferedSignal(1, 'pricing_page_viewed', ['source' => 'google_ads'], new DateTimeImmutable('2026-01-01 00:00:00')),
                new BufferedSignal(2, 'pricing_page_viewed', ['source' => 'direct'], new DateTimeImmutable('2026-01-02 00:00:00')),
                new BufferedSignal(3, 'pricing_page_viewed', ['source' => 'referral'], new DateTimeImmutable('2026-01-03 00:00:00')),
                new BufferedSignal(4, 'pricing_page_viewed', ['source' => 'email'], new DateTimeImmutable('2026-01-04 00:00:00')),
            ]),
            ['first_touch_source' => self::rule('first_wins:source')],
        );

        self::assertSame(['first_touch_source' => 'google_ads'], $result);
    }

    public function test_last_wins_returns_the_latest_signals_field_value(): void
    {
        $calculator = new RollUpCalculator;

        $result = $calculator->compute(
            self::toArrays([
                new BufferedSignal(1, 'pricing_page_viewed', ['source' => 'google_ads'], new DateTimeImmutable('2026-01-01 00:00:00')),
                new BufferedSignal(2, 'pricing_page_viewed', ['source' => 'direct'], new DateTimeImmutable('2026-01-02 00:00:00')),
                new BufferedSignal(3, 'pricing_page_viewed', ['source' => 'referral'], new DateTimeImmutable('2026-01-03 00:00:00')),
            ]),
            ['most_recent_source' => self::rule('last_wins:source')],
        );

        self::assertSame(['most_recent_source' => 'referral'], $result);
    }

    public function test_increment_counts_the_signals_handed_to_it(): void
    {
        $calculator = new RollUpCalculator;

        $signals = array_map(
            static fn (int $i): array => (new BufferedSignal(
                $i,
                'pricing_page_viewed',
                [],
                new DateTimeImmutable(sprintf('2026-01-0%d 00:00:00', $i)),
            ))->toArray(),
            range(1, 5),
        );

        $result = $calculator->compute($signals, ['pricing_page_views' => self::rule('increment')]);

        self::assertSame(['pricing_page_views' => '5'], $result);
    }

    public function test_sum_totals_the_named_field_across_signals(): void
    {
        $calculator = new RollUpCalculator;

        $result = $calculator->compute(
            self::toArrays([
                new BufferedSignal(1, 'purchase', ['amount' => '10'], new DateTimeImmutable('2026-01-01')),
                new BufferedSignal(2, 'purchase', ['amount' => '2.5'], new DateTimeImmutable('2026-01-02')),
                new BufferedSignal(3, 'purchase', ['amount' => '0'], new DateTimeImmutable('2026-01-03')),
            ]),
            ['lifetime_value' => self::rule('sum:amount')],
        );

        self::assertSame(['lifetime_value' => '12.5'], $result);
    }

    public function test_sum_over_a_non_numeric_field_value_throws(): void
    {
        $calculator = new RollUpCalculator;

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('is not numeric');

        $calculator->compute(
            self::toArrays([
                new BufferedSignal(1, 'purchase', ['amount' => 'not-a-number'], new DateTimeImmutable('2026-01-01')),
            ]),
            ['lifetime_value' => self::rule('sum:amount')],
        );
    }

    public function test_an_invokable_class_string_receives_a_collection_of_exactly_the_given_signals(): void
    {
        $calculator = new RollUpCalculator;

        $result = $calculator->compute(
            self::toArrays([
                new BufferedSignal(5, 'pricing_page_viewed', [], new DateTimeImmutable('2026-01-01')),
                new BufferedSignal(9, 'pricing_page_viewed', [], new DateTimeImmutable('2026-01-02')),
            ]),
            ['ids_seen' => self::rule(RecordingSignalCalculator::class)],
        );

        self::assertInstanceOf(Collection::class, RecordingSignalCalculator::$received);
        self::assertSame([5, 9], RecordingSignalCalculator::$received->pluck('id')->all());
        self::assertSame(['ids_seen' => '5,9'], $result);
    }

    public function test_the_ready_made_invokable_fixture_also_resolves_through_compute(): void
    {
        $calculator = new RollUpCalculator;

        $result = $calculator->compute(
            self::toArrays([
                new BufferedSignal(1, 'pricing_page_viewed', [], new DateTimeImmutable('2026-01-01')),
                new BufferedSignal(2, 'pricing_page_viewed', [], new DateTimeImmutable('2026-01-02')),
            ]),
            ['intent_score' => self::rule(IntentScore::class)],
        );

        self::assertSame(['intent_score' => '20'], $result);
    }

    public function test_the_dispatch_covers_exactly_the_four_verbs_plus_the_invokable_case(): void
    {
        $calculator = new RollUpCalculator;
        $signal = (new BufferedSignal(1, 'pricing_page_viewed', ['field' => '5'], new DateTimeImmutable('2026-01-01')))->toArray();

        self::assertSame(['p' => '5'], $calculator->compute([$signal], ['p' => self::rule('first_wins:field')]));
        self::assertSame(['p' => '5'], $calculator->compute([$signal], ['p' => self::rule('last_wins:field')]));
        self::assertSame(['p' => '1'], $calculator->compute([$signal], ['p' => self::rule('increment')]));
        self::assertSame(['p' => '5'], $calculator->compute([$signal], ['p' => self::rule('sum:field')]));
        self::assertSame(['p' => '10'], $calculator->compute([$signal], ['p' => self::rule(IntentScore::class)]));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('does not support the "overwrite" merge verb');

        $calculator->compute([$signal], ['p' => self::bogusRule('overwrite')]);
    }

    public function test_flushed_and_unflushed_rows_produce_identical_increment_and_sum_values(): void
    {
        $calculator = new RollUpCalculator;

        $build = static function (bool $someFlushed): array {
            $signals = [];

            foreach (range(1, 5) as $i) {
                $signals[] = new BufferedSignal(
                    $i,
                    'purchase',
                    ['amount' => (string) $i],
                    new DateTimeImmutable(sprintf('2026-01-0%d', $i)),
                    ($someFlushed && $i <= 3) ? new DateTimeImmutable('2026-02-01') : null,
                );
            }

            return self::toArrays($signals);
        };

        $rules = ['count' => self::rule('increment'), 'total' => self::rule('sum:amount')];

        $withSomeFlushed = $calculator->compute($build(true), $rules);
        $withNoneFlushed = $calculator->compute($build(false), $rules);

        self::assertSame($withSomeFlushed, $withNoneFlushed);
        self::assertSame(['count' => '5', 'total' => '15'], $withSomeFlushed);
    }

    public function test_computing_twice_after_marking_every_row_flushed_returns_identical_values(): void
    {
        $calculator = new RollUpCalculator;

        $signals = array_map(
            static fn (int $i): BufferedSignal => new BufferedSignal(
                $i,
                'purchase',
                ['amount' => (string) ($i * 2)],
                new DateTimeImmutable(sprintf('2026-01-0%d', $i)),
            ),
            range(1, 4),
        );

        $rules = ['count' => self::rule('increment'), 'total' => self::rule('sum:amount')];

        $first = $calculator->compute(self::toArrays($signals), $rules);

        // Simulate the flush between the two calls: identical rows, every one now carrying a
        // flushed_at. The maths must not move.
        $flushed = array_map(
            static fn (BufferedSignal $s): BufferedSignal => new BufferedSignal(
                $s->id,
                $s->signalName,
                $s->properties,
                $s->occurredAt,
                new DateTimeImmutable('2026-02-01'),
            ),
            $signals,
        );

        $second = $calculator->compute(self::toArrays($flushed), $rules);

        self::assertSame($first, $second);
    }

    public function test_a_signal_missing_the_named_field_is_skipped_not_treated_as_empty(): void
    {
        $calculator = new RollUpCalculator;

        $result = $calculator->compute(
            self::toArrays([
                new BufferedSignal(1, 'pricing_page_viewed', [], new DateTimeImmutable('2026-01-01')),
                new BufferedSignal(2, 'pricing_page_viewed', ['source' => 'direct'], new DateTimeImmutable('2026-01-02')),
            ]),
            [
                'first_touch_source' => self::rule('first_wins:source'),
                'most_recent_source' => self::rule('last_wins:source'),
            ],
        );

        self::assertSame(['first_touch_source' => 'direct', 'most_recent_source' => 'direct'], $result);
    }

    public function test_sum_skips_a_signal_missing_the_named_field_rather_than_treating_it_as_zero(): void
    {
        $calculator = new RollUpCalculator;

        $result = $calculator->compute(
            self::toArrays([
                new BufferedSignal(1, 'purchase', [], new DateTimeImmutable('2026-01-01')),
                new BufferedSignal(2, 'purchase', ['amount' => '5'], new DateTimeImmutable('2026-01-02')),
            ]),
            ['lifetime_value' => self::rule('sum:amount')],
        );

        self::assertSame(['lifetime_value' => '5'], $result);
    }

    public function test_increment_counts_signals_regardless_of_their_properties(): void
    {
        $calculator = new RollUpCalculator;

        $result = $calculator->compute(
            self::toArrays([
                new BufferedSignal(1, 'pricing_page_viewed', [], new DateTimeImmutable('2026-01-01')),
                new BufferedSignal(2, 'pricing_page_viewed', ['source' => 'direct', 'extra' => 'noise'], new DateTimeImmutable('2026-01-02')),
            ]),
            ['pricing_page_views' => self::rule('increment')],
        );

        self::assertSame(['pricing_page_views' => '2'], $result);
    }

    public function test_one_pass_computes_all_four_properties_declared_for_one_signal(): void
    {
        $calculator = new RollUpCalculator;

        $result = $calculator->compute(
            self::toArrays([
                new BufferedSignal(1, 'purchase', ['source' => 'google_ads', 'amount' => '10'], new DateTimeImmutable('2026-01-01')),
                new BufferedSignal(2, 'purchase', ['source' => 'direct', 'amount' => '5'], new DateTimeImmutable('2026-01-02')),
            ]),
            [
                'first_touch_source' => self::rule('first_wins:source'),
                'most_recent_source' => self::rule('last_wins:source'),
                'purchase_count' => self::rule('increment'),
                'lifetime_value' => self::rule('sum:amount'),
            ],
        );

        self::assertSame([
            'first_touch_source' => 'google_ads',
            'most_recent_source' => 'direct',
            'purchase_count' => '2',
            'lifetime_value' => '15',
        ], $result);
    }

    /**
     * @param  list<BufferedSignal>  $signals
     * @return list<array{
     *     id: int,
     *     signal_name: string,
     *     properties: array<string, mixed>,
     *     occurred_at: DateTimeImmutable,
     *     flushed_at: ?DateTimeImmutable,
     * }>
     */
    private static function toArrays(array $signals): array
    {
        return array_map(static fn (BufferedSignal $signal): array => $signal->toArray(), $signals);
    }

    private static function rule(string $declaration): MergeRule
    {
        return MergeRule::fromDeclaration('property', $declaration, 'signal');
    }

    /**
     * Bypasses `MergeRule`'s private constructor to build a rule carrying a verb spelling
     * `MergeRule::fromDeclaration()` would never let through -- the only way to prove
     * `RollUpCalculator`'s own dispatch refuses a fifth spelling rather than silently reaching a
     * branch for it (Test 7 / D-41).
     */
    private static function bogusRule(string $verb): MergeRule
    {
        $reflection = new \ReflectionClass(MergeRule::class);
        $rule = $reflection->newInstanceWithoutConstructor();

        $reflection->getProperty('verb')->setValue($rule, $verb);
        $reflection->getProperty('field')->setValue($rule, null);
        $reflection->getProperty('reconciles')->setValue($rule, false);
        $reflection->getProperty('calculator')->setValue($rule, null);

        return $rule;
    }
}
