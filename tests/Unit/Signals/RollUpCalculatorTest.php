<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Unit\Signals;

use LogicException;
use ReyemTech\Hubspot\Signals\RollUpCalculator;
use ReyemTech\Hubspot\Tests\TestCase;

mutates(RollUpCalculator::class);

/**
 * SIG-04: a pure function, no dependencies at all -- no fake, no database, no config, provable in
 * a plain unit test. Only the `increment` verb ships in this task (06-04 extends the vocabulary
 * without changing this class's shape).
 */
final class RollUpCalculatorTest extends TestCase
{
    public function test_valid_verbs_is_the_closed_one_member_vocabulary_this_task_ships(): void
    {
        self::assertSame(['increment'], RollUpCalculator::validVerbs());
    }

    public function test_increment_counts_the_signals_matching_its_rule(): void
    {
        $calculator = new RollUpCalculator;

        $result = $calculator->compute(
            [
                ['signal_name' => 'pricing_page_viewed'],
                ['signal_name' => 'pricing_page_viewed'],
                ['signal_name' => 'demo_requested'],
            ],
            ['pricing_page_views' => ['signal' => 'pricing_page_viewed', 'verb' => 'increment']],
        );

        self::assertSame(['pricing_page_views' => '2'], $result);
    }

    /**
     * D-10: `flushed_at` is never an input to the maths. This class has no opinion on which rows a
     * caller hands it -- it counts every row it is given, flushed or not.
     */
    public function test_it_counts_over_every_signal_it_is_given_regardless_of_flushed_state(): void
    {
        $calculator = new RollUpCalculator;

        $result = $calculator->compute(
            (function (): iterable {
                yield ['signal_name' => 'pricing_page_viewed'];
                yield ['signal_name' => 'pricing_page_viewed'];
            })(),
            ['pricing_page_views' => ['signal' => 'pricing_page_viewed', 'verb' => 'increment']],
        );

        self::assertSame(['pricing_page_views' => '2'], $result);
    }

    public function test_a_rule_with_no_matching_signals_computes_zero(): void
    {
        $calculator = new RollUpCalculator;

        $result = $calculator->compute(
            [['signal_name' => 'demo_requested']],
            ['pricing_page_views' => ['signal' => 'pricing_page_viewed', 'verb' => 'increment']],
        );

        self::assertSame(['pricing_page_views' => '0'], $result);
    }

    public function test_an_unsupported_verb_throws_naming_the_verb_and_the_supported_list(): void
    {
        $calculator = new RollUpCalculator;

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'RollUpCalculator does not support the "sum" merge verb yet. Supported verbs: increment.',
        );

        $calculator->compute(
            [['signal_name' => 'pricing_page_viewed']],
            ['pricing_page_views' => ['signal' => 'pricing_page_viewed', 'verb' => 'sum']],
        );
    }
}
