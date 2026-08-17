<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Signals;

use Closure;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Signals\Contracts\SignalCalculator;

/**
 * The ONE parser of a `hubspot.signals.map` property declaration (SIG-03, D-08, D-41).
 * `SignalMap::validate()` validates every declaration through {@see self::fromDeclaration()}, and
 * `RollUpCalculator` (06-04) interprets every resolved rule through this same class's accessors --
 * one implementation of the vocabulary, per STANDARDS §6b.
 *
 * The closed vocabulary is exactly four verbs, plus an invokable class-string (D-08 -- see
 * `Signals\Contracts\SignalCalculator`'s own docblock for why a closure is refused). There is no
 * `overwrite` verb: an earlier draft of the spec listed `overwrite` and `last_wins` as separate
 * verbs and they are the same operation (SIG-03 note).
 *
 * ## Grammar
 *
 * `verb[:field][|reconcile]` -- split on `|` once, then on `:` once, so a field itself containing a
 * colon (`first_wins:utm:source`) is a malformed FIELD rather than a nested verb.
 *
 * | Verb | Field | `\|reconcile` |
 * |---|---|---|
 * | `first_wins` | required | accepted |
 * | `last_wins` | required | accepted |
 * | `sum` | required | rejected |
 * | `increment` | rejected | rejected |
 *
 * Comparison against {@see self::validVerbs()} is exact BYTE equality -- no case folding, no
 * trimming, no Unicode normalisation. `'INCREMENT'`, `'Increment'` and `' increment'` are each a
 * different, unknown value.
 *
 * ## Disambiguating a bad verb from a bad class-string
 *
 * An operator-supplied string is dual-purpose: it is either a verb declaration or a class-string
 * naming a {@see SignalCalculator}. `class_exists()` resolves the calculator branch outright when
 * the class is real. When it is NOT, this parser has to decide which mistake was made: a typo in a
 * verb ("overwrite" -- SIG-03's own worked example of the removed verb), or a typo in a namespaced
 * class reference. PHP verb syntax uses `:` and `|` and never `\`, and every calculator this
 * package or a real consumer resolves through `::class` is namespaced, so the presence of a
 * backslash is what decides it: `App\Signals\NotYetWritten` reports as an invalid calculator,
 * `overwrite` reports as an unknown merge verb. This cannot misclassify a genuinely valid
 * four-verb declaration -- none of the four verbs, nor their field or modifier syntax, ever
 * contains a backslash.
 */
final readonly class MergeRule
{
    /**
     * The marker {@see self::verb()} returns for a resolved invokable class-string, distinct from
     * every real merge verb -- SIG-03's Test 7 asserts against this literal.
     */
    private const string INVOKABLE_VERB = 'invokable';

    private function __construct(
        private string $verb,
        private ?string $field,
        private bool $reconciles,
        private ?string $calculator,
    ) {}

    /**
     * The closed merge-verb vocabulary, exactly four members, in this order.
     *
     * A METHOD, not a `const array`: `pest --mutate` reports a mutation on a constant declaration
     * as UNCOVERED, because a constant has no executed line for coverage to attribute a test to --
     * and dropping a verb from this list is a real defect (06-CONTEXT.md, `ServiceProvider::
     * supportedStores()` is the established precedent this mirrors).
     *
     * @return list<string>
     */
    public static function validVerbs(): array
    {
        return ['first_wins', 'last_wins', 'increment', 'sum'];
    }

    /**
     * Parses one `hubspot.signals.map[$signalName]['properties'][$property]` declaration.
     *
     * @throws ConfigurationException if the declaration is not a valid four-verb merge rule and
     *                                not a valid invokable class-string
     */
    public static function fromDeclaration(string $property, mixed $declaration, string $signalName): self
    {
        if ($declaration instanceof Closure || ! is_string($declaration) || $declaration === '') {
            throw ConfigurationException::invalidSignalCalculator($declaration, $signalName, $property);
        }

        if (class_exists($declaration)) {
            if (! is_a($declaration, SignalCalculator::class, true)) {
                throw ConfigurationException::invalidSignalCalculator($declaration, $signalName, $property);
            }

            return new self(
                verb: self::INVOKABLE_VERB,
                field: null,
                reconciles: false,
                calculator: $declaration,
            );
        }

        return self::parseVerbDeclaration($declaration, $signalName, $property);
    }

    public function verb(): string
    {
        return $this->verb;
    }

    public function field(): ?string
    {
        return $this->field;
    }

    /**
     * @return class-string<SignalCalculator>|null
     */
    public function calculator(): ?string
    {
        /** @var class-string<SignalCalculator>|null */
        return $this->calculator;
    }

    public function reconciles(): bool
    {
        return $this->reconciles;
    }

    private static function parseVerbDeclaration(string $declaration, string $signalName, string $property): self
    {
        $modifierParts = explode('|', $declaration, 2);
        $verbAndField = $modifierParts[0];
        $modifier = $modifierParts[1] ?? null;

        if ($modifier !== null && $modifier !== 'reconcile') {
            throw self::unknownVerb($declaration, $signalName, $property);
        }

        $reconciles = $modifier === 'reconcile';

        $fieldParts = explode(':', $verbAndField, 2);
        $verb = $fieldParts[0];
        $field = $fieldParts[1] ?? null;

        if (! in_array($verb, self::validVerbs(), true)) {
            // A namespaced-looking string that resolved to no real class above is an attempted
            // class-string with a typo, not a verb typo -- the two failures need different fixes
            // (see the class docblock's disambiguation note).
            if (str_contains($declaration, '\\')) {
                throw ConfigurationException::invalidSignalCalculator($declaration, $signalName, $property);
            }

            throw self::unknownVerb($declaration, $signalName, $property);
        }

        $field = $field === '' ? null : $field;

        match ($verb) {
            'increment' => self::assertNoFieldOrModifier($field, $reconciles, $declaration, $signalName, $property),
            'sum' => self::assertFieldRequiredNoModifier($field, $reconciles, $declaration, $signalName, $property),
            default => self::assertFieldRequired($field, $declaration, $signalName, $property),
        };

        return new self($verb, $field, $reconciles, null);
    }

    private static function assertNoFieldOrModifier(
        ?string $field,
        bool $reconciles,
        string $declaration,
        string $signalName,
        string $property,
    ): void {
        if ($field !== null || $reconciles) {
            throw self::unknownVerb($declaration, $signalName, $property);
        }
    }

    private static function assertFieldRequiredNoModifier(
        ?string $field,
        bool $reconciles,
        string $declaration,
        string $signalName,
        string $property,
    ): void {
        if ($field === null || $reconciles) {
            throw self::unknownVerb($declaration, $signalName, $property);
        }
    }

    private static function assertFieldRequired(
        ?string $field,
        string $declaration,
        string $signalName,
        string $property,
    ): void {
        if ($field === null) {
            throw self::unknownVerb($declaration, $signalName, $property);
        }
    }

    private static function unknownVerb(string $given, string $signalName, string $property): ConfigurationException
    {
        return ConfigurationException::unknownSignalMergeVerb($given, $signalName, $property, self::validVerbs());
    }
}
