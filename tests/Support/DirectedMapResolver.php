<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Support;

use ReyemTech\Hubspot\Exceptions\AssociationTypeException;
use ReyemTech\Hubspot\Gateway\AssociationPair;
use ReyemTech\Hubspot\Gateway\AssociationType;
use ReyemTech\Hubspot\Gateway\Contracts\AssociationTypeResolver;

/**
 * A test resolver holding an explicitly **directed** map: every entry is keyed by the from type, the
 * to type and the label, in that order, and a lookup that misses throws rather than answering.
 *
 * The whole value of this double is what it refuses to do. It never consults the reversed key, never
 * falls back to it, and holds no way to derive one — so a test can register **only** the opposite
 * direction and prove that the gateway still throws instead of writing. A resolver double that
 * normalised the pair, or that stored `(a, b)` and `(b, a)` under one key, would make
 * `NeverTheInverseTest` unable to express its own negative case, and the suite would report success
 * for a package that writes associations backwards.
 *
 * Lives under `tests/Support/` rather than in a test file so `NeverTheInverseTest`,
 * `LabelledAssociationTest` and (from plan 02-06) the `assertAssociated` tests all drive the gateway
 * through one resolver double, not three subtly different ones. This directory is deliberately NOT a
 * registered testsuite in `phpunit.xml.dist` — it holds no tests, and `failOnWarning` turns a
 * declared-but-empty testsuite into a build failure (see `tests/Ci/PhpunitTestsuitesTest.php`).
 */
final class DirectedMapResolver implements AssociationTypeResolver
{
    /**
     * @var array<string, AssociationType>
     */
    private array $map;

    /**
     * @param  array<string, AssociationType>  $map  keyed by {@see self::key()} — `fromType>toType>label`
     */
    public function __construct(array $map)
    {
        $this->map = $map;
    }

    /**
     * Registers one direction under one label. Named parameters throughout, because a helper whose
     * signature is `(string, string, string, int)` is exactly the shape that lets a test register the
     * inverse by accident and then pass for the wrong reason.
     */
    public static function knowing(string $fromType, string $toType, string $label, int $typeId, string $category = 'USER_DEFINED'): self
    {
        return new self([
            self::key($fromType, $toType, $label) => new AssociationType(typeId: $typeId, category: $category),
        ]);
    }

    public function alsoKnowing(string $fromType, string $toType, string $label, int $typeId, string $category = 'USER_DEFINED'): self
    {
        return new self([
            ...$this->map,
            self::key($fromType, $toType, $label) => new AssociationType(typeId: $typeId, category: $category),
        ]);
    }

    public function resolve(AssociationPair $pair, string $label): AssociationType
    {
        // No `?? $this->map[reversed key]`, and no way to write one: the reversed key is never
        // computed anywhere in this class. That absence is the point of the double.
        return $this->map[self::key($pair->from->objectType, $pair->to->objectType, $label)]
            ?? throw AssociationTypeException::directionNotResolvable(
                $pair->from->objectType,
                $pair->to->objectType,
                $label,
            );
    }

    private static function key(string $fromType, string $toType, string $label): string
    {
        return sprintf('%s>%s>%s', $fromType, $toType, $label);
    }
}
