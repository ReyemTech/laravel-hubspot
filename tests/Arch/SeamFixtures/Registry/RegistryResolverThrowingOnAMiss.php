<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Registry;

use ReyemTech\Hubspot\Exceptions\AssociationTypeException;
use ReyemTech\Hubspot\Gateway\AssociationPair;
use ReyemTech\Hubspot\Gateway\AssociationType;
use ReyemTech\Hubspot\Gateway\Contracts\AssociationTypeResolver;

/**
 * **A permanent, committed proof that Phase 3 can implement the resolver seam. Not a violation
 * fixture — this one is required to PASS.**
 *
 * `tests/Arch/Fixtures/` holds one deliberately rule-breaking class per architecture rule, played back
 * by `scripts/ci/verify-arch-rules-fire.sh` to prove each rule can go red. This directory is the
 * mirror image: classes that are the *intended* shape of a later phase's code, played back by
 * `tests/Arch/ResolverSeamTest.php` to prove the boundaries permit them. A rule that cannot fire is
 * vacuous; a rule that forbids the design it exists to protect is worse, because it is discovered only
 * when the phase that needs it starts.
 *
 * This is the shape REG-02 ships: a `Registry` class implementing the Gateway-side
 * {@see AssociationTypeResolver}, returning a Gateway-side {@see AssociationType}, and **throwing the
 * package's own `AssociationTypeException` when the requested direction is not in its map**. The throw
 * is not optional — the contract's return type is non-nullable, STANDARDS §9 requires consumers to
 * catch package types, and 02-CONTEXT.md rule 3 forbids answering a miss with anything else. Plan
 * 02-05 verified the interface half of that with a throwaway class and found the exception half
 * failing R2 (`Expecting 'ReyemTech\Hubspot\Registry' to only use 'ReyemTech\Hubspot\Gateway'.
 * However, it also uses 'ReyemTech\Hubspot\Exceptions\AssociationTypeException'.`), which is what the
 * widened allow-lists fix. Committing the fixture rather than reproducing it by hand is what keeps the
 * fix from silently regressing.
 *
 * It deliberately holds a map, rather than throwing unconditionally like
 * `Gateway\UnresolvedAssociationTypeResolver`: a resolver with no map has no miss to express, and the
 * miss is the case the seam exists for. The map is keyed strictly by `(from, to, label)` and no
 * reversed key is computed anywhere in this class, so it cannot answer for the inverse direction even
 * by accident.
 *
 * Never autoloaded: `tests/` is PSR-4 mapped to `ReyemTech\Hubspot\Tests\`, and this file declares
 * `ReyemTech\Hubspot\Registry`. It is only ever read by the seam test, which copies it into a scratch
 * `src/` tree under a temporary directory.
 */
final class RegistryResolverThrowingOnAMiss implements AssociationTypeResolver
{
    /**
     * @param  array<string, AssociationType>  $map  keyed `fromType>toType>label`
     */
    public function __construct(private readonly array $map) {}

    public function resolve(AssociationPair $pair, string $label): AssociationType
    {
        $key = sprintf('%s>%s>%s', $pair->from->objectType, $pair->to->objectType, $label);

        return $this->map[$key] ?? throw AssociationTypeException::directionNotResolvable(
            $pair->from->objectType,
            $pair->to->objectType,
            $label,
        );
    }
}
