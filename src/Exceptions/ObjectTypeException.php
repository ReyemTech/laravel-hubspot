<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Exceptions;

use InvalidArgumentException;

/**
 * A caller mistake detectable before any I/O -- an unknown or unmappable HubSpot object type
 * (STANDARDS §9, design spec §9). Extends `InvalidArgumentException` (itself a `LogicException`):
 * an object-type string is a plain argument the caller controls, and HubSpot's own API performs
 * zero server-side validation on it either (02-RESEARCH.md Pitfall 6) -- this is always a
 * client-side data problem, detectable before any request is sent.
 *
 * This is the member every fault a `Gateway\ObjectRef` or `Gateway\AssociationPair` can raise
 * before any I/O resolves to — a blank object type, a blank object id, a non-string on either side,
 * or a pair of one record with itself (plan 02-04). One member for all of them, deliberately:
 *
 * - The hierarchy is fixed at four members (STANDARDS §9) and
 *   `tests/Feature/Gateway/ExceptionHierarchyTest.php` fails the build on a fifth, so a dedicated
 *   `ObjectRefException` is not available.
 * - `AssociationTypeException` — the other candidate for the self-pair case — documents itself as a
 *   RUNTIME registry-lookup failure over data that may be perfectly valid. None of these three are
 *   that: they are invalid arguments, detectable with no lookup and no request, which is exactly the
 *   `InvalidArgumentException` lineage this class already carries.
 * - Reaching for a plain SPL exception for one of them would mean a consumer's single
 *   `catch (HubspotException)` block caught a blank object type and missed a blank object id — or, in
 *   the non-string case, saw a raw `TypeError` it could never catch at all.
 *
 * `unmappable()` is still unused in Phase 2: object-type normalisation and mapping are explicitly
 * the Registry layer's job (02-CONTEXT.md's Phase Boundary), which does not exist until Phase 3. It
 * ships ahead of its first caller so that seam exists before the Registry needs it, exactly as
 * `AssociationTypeException::directionNotResolvable()` ships ahead of plan 02-05.
 */
final class ObjectTypeException extends InvalidArgumentException implements HubspotException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    /**
     * The given object type has no known mapping.
     */
    public static function unmappable(string $objectType): self
    {
        return new self(sprintf(
            'Object type "%s" has no known mapping. Confirm the spelling matches a HubSpot '
            .'object type (for example "contacts" or "deals") or your custom object\'s '
            .'fully-qualified type (for example "p12345_my_object"), then map the local model '
            .'that should sync to it before retrying.',
            $objectType,
        ));
    }

    /**
     * An object reference was built with no object type at all.
     *
     * Single unbroken string literals in this and the two constructors below, rather than
     * concatenated fragments: `pest --mutate` generates a concat mutant per `.` operator, and a
     * message assembled from four fragments buys four mutants whose only observable difference is
     * word order in prose (the same reasoning as `ApiException::partialBatchFailure()`).
     */
    public static function blankObjectType(): self
    {
        return new self('A HubSpot object reference was built with a blank object type. Pass the object type the record belongs to — for example "contacts", "deals", or a custom object\'s fully-qualified type "p12345_my_object". HubSpot validates this value server-side not at all, so a blank one is encoded straight into the request path instead of being rejected.');
    }

    /**
     * An object reference was built with no record id at all.
     */
    public static function blankObjectId(string $objectType): self
    {
        return new self(sprintf('A HubSpot object reference of type "%s" was built with a blank object id. Pass the record id HubSpot assigned, as a string. Object ids are strings that look like integers, which is the specific reason this package declares strict types: a coerced or blank id addresses a different record rather than failing.', $objectType));
    }

    /**
     * An object reference was built with something that is not a string on one of its two sides.
     *
     * Checked explicitly rather than left to the parameter's own type declaration, because
     * `declare(strict_types=1)` binds at the file making the CALL, not at the file declaring the
     * constructor. A consumer file without it — the normal case in a Laravel application — has `0`
     * coerced to `"0"`, `true` to `"1"` and a large float to `"1.2345678901235E+19"` before the
     * constructor body runs, which is the silent-equivalence problem `ObjectRef`'s docblock condemns,
     * arriving through the one door strict types cannot close.
     *
     * `$side` is prose ("object type" or "object id") so the message names which of the two
     * arguments was wrong without the caller counting positions. Both begin with a vowel sound, so
     * the message's article is a fixed "an" rather than something computed — asserted in the test,
     * because a message that names the fix (D-18) should be able to manage its own grammar.
     */
    public static function nonStringObjectReference(string $side, mixed $received): self
    {
        return new self(sprintf('A HubSpot object reference was built with an %s of type %s. Pass it as a string — an id held as an integer is cast at the call site with "(string) $id", never coerced. This is validated here rather than by the parameter type because declare(strict_types=1) binds at the calling file, not at this package\'s: in a file without it, 0 would have arrived as "0" and true as "1", addressing a record nobody meant.', $side, get_debug_type($received)));
    }

    /**
     * A directed association pair was built with the same record on both sides.
     */
    public static function selfAssociation(string $objectType, string $id): self
    {
        return new self(sprintf('An association pair was built from record "%s" of object type "%s" on both sides. HubSpot cannot associate a record with itself: pass two different records, and check which side of the pair was built from the wrong variable.', $id, $objectType));
    }
}
