<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Signals;

use DateTimeInterface;
use Illuminate\Support\Collection;
use LogicException;
use OverflowException;
use UnexpectedValueException;

/**
 * `(signals, rules) -> property array` -- pure, zero dependencies, no I/O, no database, no
 * `Gateway`, no config reads (SIG-04). Every merge verb is provable in a unit test with no fake
 * required: `tests/Unit/Signals/RollUpCalculatorTest.php` needs no database, no fake, and no
 * Testbench application boot.
 *
 * Computes over EVERY signal handed to it, flushed included -- `flushed_at` is never read by this
 * class at all (D-10). Callers decide which rows to pass; this class has no opinion. Passing only
 * unflushed rows would make `increment`/`sum` restart at zero on a second flush and overwrite a
 * correct HubSpot value with a partial one, turning an absolute roll-up into an accidental delta.
 *
 * `$rules` is keyed by HubSpot property name, `array<string, MergeRule>` -- `MergeRule` (06-02) is
 * the ONE parser of a merge-rule declaration, and this class interprets every resolved rule through
 * that class's accessors, never by re-parsing a declaration string (STANDARDS §6b). `compute()`
 * does NOT filter `$signals` by signal name: scoping a call to one signal's rows is the CALLER's
 * responsibility, mirroring `SignalMap::rulesFor()`'s own per-signal-name scope. Every signal handed
 * to one `compute()` call is therefore relevant to every rule in that call.
 *
 * @phpstan-type SignalRow array{
 *     id: int,
 *     signal_name: string,
 *     properties: array<string, mixed>,
 *     occurred_at: DateTimeInterface,
 *     flushed_at: ?DateTimeInterface,
 * }
 */
final class RollUpCalculator
{
    /**
     * The closed merge-verb vocabulary, delegated to {@see MergeRule::validVerbs()} -- the single
     * parser owns the vocabulary (STANDARDS §6b); this class never keeps its own copy to drift
     * against it.
     *
     * A METHOD, not a `const array`: `pest --mutate` reports a mutation on a constant declaration
     * as UNCOVERED, because a constant has no executed line for coverage to attribute a test to --
     * this is the file 06-CONTEXT.md names as where the 80% MSI floor is meant to bite hardest.
     *
     * @return list<string>
     */
    public static function validVerbs(): array
    {
        return MergeRule::validVerbs();
    }

    /**
     * @param  iterable<SignalRow>  $signals  every signal relevant to this call, flushed or not
     *                                        (D-10) -- the caller has already scoped this to
     *                                        whichever signal name(s) `$rules` was built from
     * @param  array<string, MergeRule>  $rules  HubSpot property name => the rule that computes it
     * @return array<string, string>
     */
    public function compute(iterable $signals, array $rules): array
    {
        /** @var list<SignalRow> $signals */
        $signals = is_array($signals) ? array_values($signals) : iterator_to_array($signals, false);

        if ($signals === []) {
            return [];
        }

        // The tie-break rule: two signals sharing one `occurred_at` are ordered by their own
        // `id` -- ascending here, so `first_wins` (which keeps the first match it sees) resolves
        // to the LOWEST id and `last_wins` (which keeps the last match it sees) resolves to the
        // HIGHEST id. Chosen because it is total, stable, and already stored -- no new column, no
        // clock dependence, and two ids can never tie a second time. Sorted once, up front, so
        // every verb -- and the invokable Collection -- sees a caller-order-independent sequence.
        usort($signals, static fn (array $a, array $b): int => ($a['occurred_at'] <=> $b['occurred_at']) ?: ($a['id'] <=> $b['id']));

        $result = [];

        foreach ($rules as $property => $rule) {
            $value = $this->computeOne($rule, $signals);

            // No key at all for a rule with nothing to say -- not a zero, not an empty string.
            // Writing a computed default over whatever HubSpot already holds is a wrong absolute
            // value, not a harmless one.
            if ($value !== null) {
                $result[$property] = $value;
            }
        }

        return $result;
    }

    /**
     * @param  list<SignalRow>  $signals
     */
    private function computeOne(MergeRule $rule, array $signals): ?string
    {
        return match ($rule->verb()) {
            'first_wins' => self::firstWins(self::requireField($rule), $signals),
            'last_wins' => self::lastWins(self::requireField($rule), $signals),
            'increment' => (string) count($signals),
            'sum' => self::sum(self::requireField($rule), $signals),
            'invokable' => self::invoke(self::requireCalculator($rule), $signals),
            // Unreachable through any declaration MergeRule::fromDeclaration() accepts -- a rule
            // naming "overwrite" (or any other fifth spelling) never gets this far, because
            // MergeRule itself refuses it at parse time (D-41). Reachable only if a MergeRule were
            // ever built some other way, which is exactly what the dispatch-exhaustiveness test
            // proves by doing (via reflection, bypassing the private constructor).
            default => throw new LogicException(sprintf(
                'RollUpCalculator does not support the "%s" merge verb. Supported verbs: %s, plus '
                .'an invokable class-string.',
                $rule->verb(),
                implode(', ', self::validVerbs()),
            )),
        };
    }

    private static function requireField(MergeRule $rule): string
    {
        return $rule->field() ?? throw new LogicException(sprintf(
            'RollUpCalculator cannot compute the "%s" verb without a field -- MergeRule should have '
            .'refused this declaration before it reached here.',
            $rule->verb(),
        ));
    }

    /**
     * @return class-string<Contracts\SignalCalculator>
     */
    private static function requireCalculator(MergeRule $rule): string
    {
        return $rule->calculator() ?? throw new LogicException(
            'RollUpCalculator reached the invokable branch for a rule carrying no calculator '
            .'class-string -- MergeRule should have refused this declaration before it reached '
            .'here.',
        );
    }

    /**
     * @param  list<SignalRow>  $signals
     */
    private static function firstWins(string $field, array $signals): ?string
    {
        $earliestValue = null;
        $earliestAt = null;

        foreach ($signals as $signal) {
            $value = self::scalarFieldValue($signal['properties'], $field);

            if ($value === null) {
                continue;
            }

            if ($earliestAt === null || $signal['occurred_at'] < $earliestAt) {
                $earliestValue = $value;
                $earliestAt = $signal['occurred_at'];
            }
        }

        return $earliestValue;
    }

    /**
     * @param  list<SignalRow>  $signals
     */
    private static function lastWins(string $field, array $signals): ?string
    {
        $latestValue = null;
        $latestAt = null;

        foreach ($signals as $signal) {
            $value = self::scalarFieldValue($signal['properties'], $field);

            if ($value === null) {
                continue;
            }

            if ($latestAt === null || $signal['occurred_at'] >= $latestAt) {
                $latestValue = $value;
                $latestAt = $signal['occurred_at'];
            }
        }

        return $latestValue;
    }

    /**
     * @param  list<SignalRow>  $signals
     */
    private static function sum(string $field, array $signals): ?string
    {
        $total = 0.0;
        $found = false;

        foreach ($signals as $signal) {
            if (! array_key_exists($field, $signal['properties']) || $signal['properties'][$field] === null) {
                continue;
            }

            $value = $signal['properties'][$field];

            if (! is_numeric($value)) {
                throw new UnexpectedValueException(sprintf(
                    'RollUpCalculator cannot sum property "%s": the value %s is not numeric. Every '
                    .'signal that carries this field must carry it as a number or a numeric '
                    .'string.',
                    $field,
                    var_export($value, true),
                ));
            }

            $found = true;
            $total += (float) $value;
        }

        if (! $found) {
            return null;
        }

        // 9_007_199_254_740_992.0 is 2**53, the largest integer a 64-bit float represents
        // exactly -- written as a literal, not a named constant, so `pest --mutate` can attribute
        // a covering test (Test 21) to a mutation of this comparison; a `const` declaration has no
        // executed line for coverage to attribute one to. Beyond it, both the numeric-string-to-
        // float cast above and the addition itself can silently round -- refused here rather than
        // writing a silently-wrong absolute value to HubSpot.
        if (! is_finite($total) || abs($total) >= 9_007_199_254_740_992.0) {
            throw new OverflowException(sprintf(
                'RollUpCalculator cannot sum property "%s": the total %s cannot be represented as a '
                .'64-bit float without losing precision (it exceeds 2**53). Summing it here would '
                .'write a silently-rounded absolute value to HubSpot.',
                $field,
                $total,
            ));
        }

        return self::formatDecimal($total);
    }

    /**
     * PHP's default float-to-string cast switches to scientific notation once a value exceeds
     * `precision` (14 significant digits, the ini default) -- `(string) 1.0E+15 === "1.0E+15"`.
     * `sprintf('%F', ...)`, unlike `(string)`, never does that regardless of magnitude, which is
     * why every branch of this method builds the string through it rather than a bare cast.
     *
     * Six decimal places is this class's own chosen precision floor for a fractional total, not
     * something the merge-rule grammar or HubSpot's API dictates -- trailing zeros (and a trailing
     * decimal point, if nothing survives after them) are trimmed by walking in from the right,
     * which stops the moment it meets the decimal point rather than eating into the integer part's
     * own trailing zeros (`"1000000000000000.000000"` -> `"1000000000000000."` -> stops -- never
     * `"1"`).
     */
    private static function formatDecimal(float $total): string
    {
        return rtrim(rtrim(sprintf('%.6F', $total), '0'), '.');
    }

    /**
     * @param  class-string<Contracts\SignalCalculator>  $calculatorClass
     * @param  list<SignalRow>  $signals
     */
    private static function invoke(string $calculatorClass, array $signals): string
    {
        $calculator = new $calculatorClass;

        $returned = $calculator(new Collection($signals));

        return is_scalar($returned) ? (string) $returned : throw new UnexpectedValueException(sprintf(
            '%s::__invoke() returned a %s, which cannot be written to HubSpot as a property value. '
            .'An invokable signal calculator must return a scalar.',
            $calculatorClass,
            get_debug_type($returned),
        ));
    }

    /**
     * A present, non-null, scalar field value, stringified -- or null if the field is absent,
     * explicitly null, or a shape (array/object) this class refuses to silently stringify as
     * `"Array"`.
     *
     * @param  array<string, mixed>  $properties
     */
    private static function scalarFieldValue(array $properties, string $field): ?string
    {
        if (! array_key_exists($field, $properties)) {
            return null;
        }

        $value = $properties[$field];

        return is_scalar($value) ? (string) $value : null;
    }
}
