<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Gateway;

use ReyemTech\Hubspot\Exceptions\AssociationTypeException;

/**
 * A resolved association type: the directional type id HubSpot will be sent, plus the category that
 * says who defined it. This is what an {@see Contracts\AssociationTypeResolver} returns, and the
 * only value in this package from which a labelled write's payload is built.
 *
 * **A type id is the one value here that cannot be checked by looking at it.** `279` and `280` are
 * both valid, both plausible, and both accepted by HubSpot without complaint — the only thing that
 * tells them apart is the direction they were registered for. This class therefore carries no
 * direction of its own and offers no way to derive one: it is the answer to a question that was
 * already asked directionally, and an `inverse()` method here would be an invitation to answer a
 * different question than the one asked (02-CONTEXT.md rule 4 — the inverse is stored, never
 * assumed).
 *
 * **Validated at construction, and not by its parameter types.** `declare(strict_types=1)` binds at
 * the file making the CALL, never at the file declaring the constructor, so this package declaring
 * it everywhere (STANDARDS §4) buys nothing for a consumer's own file — and a typical Laravel
 * application file does not declare it. A promoted `int $typeId` would therefore have coerced before
 * the constructor body ran, and the coerced values are not harmless:
 *
 * | passed from a weak-mode file | would have arrived as | which is |
 * |---|---|---|
 * | `true` | `1` | Contact -> Primary Company, a real type id |
 * | `19.9` | `19` | Deal -> Line Item, a real type id |
 * | `'279'` | `279` | Contact -> Company, silently accepted |
 * | `false` | `0` | not an id at all, and sent anyway |
 *
 * The first two are the dangerous ones: nothing fails loudly, the write succeeds, and the records
 * are associated under a type nobody chose. Both parameters are therefore native `mixed`, checked
 * before assignment to declared properties. A wrong-typed value is **rejected, never cast** — the
 * message tells the caller to write `(int) $typeId` at their own call site, where the value's
 * provenance is visible.
 *
 * No narrowing `@param` docblock sits over the natives: PHPStan at level max would read the checks
 * as tautologies, and this repository forbids a baseline and permits a per-line suppression only
 * with a written reason (STANDARDS §3).
 *
 * Package-owned and SDK-free — it crosses the Gateway boundary inbound from `Registry`, which may
 * not name a `HubSpot\*` class (R1). `tests/Arch/SdkSurfaceTest.php` proves it stays that way.
 */
final readonly class AssociationType
{
    // Declared rather than promoted so the constructor can reject a wrong-typed value before
    // anything is assigned. Both are readonly by virtue of the readonly class.
    // `tests/Unit/Gateway/AssociationTypeTest.php` pins the parameters' `mixed`-ness and the
    // properties' types by reflection, because collapsing this into two promoted native parameters
    // reads like a tidy-up and would restore the coercion above.
    public int $typeId;

    public AssociationCategory $category;

    public function __construct(mixed $typeId, mixed $category)
    {
        if (! is_int($typeId)) {
            throw AssociationTypeException::nonIntegerTypeId($typeId);
        }

        // Ordered after the type check and before the category's, so the report names the fault
        // closest to the caller's mistake. HubSpot issues ids from 1 upward, so 0 and negatives are
        // not "unlikely ids" — they are the shape a defaulted or unresolved variable takes.
        if ($typeId < 1) {
            throw AssociationTypeException::nonPositiveTypeId($typeId);
        }

        $this->typeId = $typeId;
        $this->category = AssociationCategory::fromValue($category);
    }
}
