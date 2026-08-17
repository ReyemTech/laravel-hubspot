<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Unit\Signals;

use Closure;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Signals\MergeRule;
use ReyemTech\Hubspot\Tests\Support\Signals\IntentScore;
use ReyemTech\Hubspot\Tests\Support\Signals\NotACalculator;
use ReyemTech\Hubspot\Tests\TestCase;

mutates(MergeRule::class);

/**
 * SIG-03: the closed four-verb vocabulary, plus D-08's invokable class-string escape hatch. Every
 * parse and every refusal is proven here with no config booted -- `MergeRule::fromDeclaration()` is
 * a pure parser over one property's raw declaration.
 */
final class MergeRuleTest extends TestCase
{
    public function test_valid_verbs_is_the_closed_four_member_vocabulary_in_order(): void
    {
        $verbs = MergeRule::validVerbs();

        self::assertSame(['first_wins', 'last_wins', 'increment', 'sum'], $verbs);
        self::assertContains('first_wins', $verbs);
        self::assertContains('last_wins', $verbs);
        self::assertContains('increment', $verbs);
        self::assertContains('sum', $verbs);
    }

    public function test_overwrite_is_rejected_as_a_fifth_verb(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/overwrite/');

        MergeRule::fromDeclaration('source', 'overwrite', 'pricing_page_viewed');
    }

    public function test_first_wins_with_a_field_parses_verb_and_field(): void
    {
        $rule = MergeRule::fromDeclaration('source', 'first_wins:source', 'pricing_page_viewed');

        self::assertSame('first_wins', $rule->verb());
        self::assertSame('source', $rule->field());
        self::assertFalse($rule->reconciles());
        self::assertNull($rule->calculator());
    }

    public function test_increment_parses_with_a_null_field(): void
    {
        $rule = MergeRule::fromDeclaration('pricing_page_views', 'increment', 'pricing_page_viewed');

        self::assertSame('increment', $rule->verb());
        self::assertNull($rule->field());
    }

    public function test_increment_with_a_field_throws(): void
    {
        $this->expectException(ConfigurationException::class);

        MergeRule::fromDeclaration('pricing_page_views', 'increment:anything', 'pricing_page_viewed');
    }

    public function test_sum_with_a_field_parses_verb_and_field(): void
    {
        $rule = MergeRule::fromDeclaration('value', 'sum:value', 'demo_requested');

        self::assertSame('sum', $rule->verb());
        self::assertSame('value', $rule->field());
    }

    public function test_bare_sum_with_no_field_throws(): void
    {
        $this->expectException(ConfigurationException::class);

        MergeRule::fromDeclaration('value', 'sum', 'demo_requested');
    }

    public function test_first_wins_with_the_reconcile_modifier_parses_reconciles_true(): void
    {
        $rule = MergeRule::fromDeclaration('source', 'first_wins:source|reconcile', 'pricing_page_viewed');

        self::assertSame('first_wins', $rule->verb());
        self::assertSame('source', $rule->field());
        self::assertTrue($rule->reconciles());
    }

    public function test_increment_with_the_reconcile_modifier_throws(): void
    {
        $this->expectException(ConfigurationException::class);

        MergeRule::fromDeclaration('pricing_page_views', 'increment|reconcile', 'pricing_page_viewed');
    }

    public function test_a_modifier_other_than_reconcile_throws(): void
    {
        $this->expectException(ConfigurationException::class);

        MergeRule::fromDeclaration('source', 'first_wins:source|garbage', 'pricing_page_viewed');
    }

    public function test_bare_first_wins_with_no_field_throws(): void
    {
        $this->expectException(ConfigurationException::class);

        MergeRule::fromDeclaration('source', 'first_wins', 'pricing_page_viewed');
    }

    public function test_bare_last_wins_with_no_field_throws(): void
    {
        $this->expectException(ConfigurationException::class);

        MergeRule::fromDeclaration('source', 'last_wins', 'pricing_page_viewed');
    }

    public function test_a_calculator_class_string_parses_to_calculator_and_the_invokable_marker(): void
    {
        $rule = MergeRule::fromDeclaration('intent_score', IntentScore::class, 'demo_requested');

        self::assertSame(IntentScore::class, $rule->calculator());
        self::assertSame('invokable', $rule->verb());
        self::assertNull($rule->field());
        self::assertFalse($rule->reconciles());
    }

    public function test_a_nonexistent_namespaced_class_string_throws_naming_the_class(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/NoSuchCalculator/');

        MergeRule::fromDeclaration(
            'intent_score',
            'ReyemTech\Hubspot\Tests\Support\Signals\NoSuchCalculator',
            'demo_requested',
        );
    }

    public function test_a_class_that_exists_but_does_not_implement_signal_calculator_throws(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/SignalCalculator/');

        MergeRule::fromDeclaration('intent_score', NotACalculator::class, 'demo_requested');
    }

    public function test_a_closure_throws_naming_config_cache_and_the_class_string_alternative(): void
    {
        try {
            MergeRule::fromDeclaration(
                'intent_score',
                static fn (): int => 1,
                'demo_requested',
            );

            self::fail('Expected a ConfigurationException for a closure declaration.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('config:cache', $exception->getMessage());
            self::assertStringContainsString('class-string', $exception->getMessage());
        }
    }

    public function test_uppercase_increment_throws(): void
    {
        $this->expectException(ConfigurationException::class);

        MergeRule::fromDeclaration('pricing_page_views', 'INCREMENT', 'pricing_page_viewed');
    }

    public function test_capitalised_increment_throws(): void
    {
        $this->expectException(ConfigurationException::class);

        MergeRule::fromDeclaration('pricing_page_views', 'Increment', 'pricing_page_viewed');
    }

    public function test_leading_space_increment_throws(): void
    {
        $this->expectException(ConfigurationException::class);

        MergeRule::fromDeclaration('pricing_page_views', ' increment', 'pricing_page_viewed');
    }

    public function test_an_empty_string_declaration_throws_naming_the_property(): void
    {
        try {
            MergeRule::fromDeclaration('pricing_page_views', '', 'pricing_page_viewed');

            self::fail('Expected a ConfigurationException for an empty-string declaration.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('pricing_page_views', $exception->getMessage());
        }
    }

    public function test_a_null_declaration_throws_naming_the_property(): void
    {
        try {
            MergeRule::fromDeclaration('pricing_page_views', null, 'pricing_page_viewed');

            self::fail('Expected a ConfigurationException for a null declaration.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('pricing_page_views', $exception->getMessage());
        }
    }

    public function test_a_field_may_itself_contain_a_colon(): void
    {
        $rule = MergeRule::fromDeclaration('source', 'first_wins:utm:source', 'pricing_page_viewed');

        self::assertSame('first_wins', $rule->verb());
        self::assertSame('utm:source', $rule->field());
    }

    public function test_the_closure_check_runs_before_any_string_handling(): void
    {
        $declaration = static function (): int {
            return 1;
        };

        self::assertInstanceOf(Closure::class, $declaration);

        $this->expectException(ConfigurationException::class);

        MergeRule::fromDeclaration('intent_score', $declaration, 'demo_requested');
    }
}
