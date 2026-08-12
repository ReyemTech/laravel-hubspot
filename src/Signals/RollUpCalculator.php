<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Signals;

use LogicException;

/**
 * `(signals, rules) -> property array` -- pure, zero dependencies, no I/O, no database, no
 * `Gateway`, no config reads (SIG-04). Every merge verb is meant to be provable in a unit test
 * with no fake required; this task ships only `increment`, which plan 06-04 extends without
 * changing this class's shape.
 *
 * Computes over EVERY signal handed to it, flushed included -- `flushed_at` is never an input to
 * the maths (D-10). Callers decide which rows to pass; this class has no opinion. Passing only
 * unflushed rows would make `increment` restart at zero on a second flush and overwrite a correct
 * HubSpot value with a partial one, turning an absolute roll-up into an accidental delta.
 */
final class RollUpCalculator
{
    /**
     * The closed merge-verb vocabulary this task supports.
     *
     * A METHOD, not a `const array`: `pest --mutate` reports a mutation on a constant declaration
     * as UNCOVERED, because a constant has no executed line for coverage to attribute a test to --
     * this is the file 06-CONTEXT.md names as where the 80% MSI floor is meant to bite hardest.
     *
     * @return list<string>
     */
    public static function validVerbs(): array
    {
        return ['increment'];
    }

    /**
     * @param  iterable<array{signal_name: string}>  $signals  every one of the subject's buffered
     *                                                         rows, flushed or not (D-10)
     * @param  array<string, array{signal: string, verb: string}>  $rules  HubSpot property =>
     *                                                                     {signal name, merge verb}
     * @return array<string, string>
     */
    public function compute(iterable $signals, array $rules): array
    {
        $signals = is_array($signals) ? $signals : iterator_to_array($signals);

        $result = [];

        foreach ($rules as $property => $rule) {
            $matching = array_values(array_filter(
                $signals,
                static fn (array $signal): bool => $signal['signal_name'] === $rule['signal'],
            ));

            $result[$property] = match ($rule['verb']) {
                'increment' => (string) count($matching),
                default => throw new LogicException(sprintf(
                    'RollUpCalculator does not support the "%s" merge verb yet. Supported verbs: '
                    .'%s.',
                    $rule['verb'],
                    implode(', ', self::validVerbs()),
                )),
            };
        }

        return $result;
    }
}
