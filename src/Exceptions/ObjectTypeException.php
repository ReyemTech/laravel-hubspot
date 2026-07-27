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
 * before any I/O resolves to — a blank object type, a blank object id, or a pair of one record with
 * itself (plan 02-04). One member for all three, deliberately:
 *
 * - The hierarchy is fixed at four members (STANDARDS §9) and
 *   `tests/Feature/Gateway/ExceptionHierarchyTest.php` fails the build on a fifth, so a dedicated
 *   `ObjectRefException` is not available.
 * - `AssociationTypeException` — the other candidate for the self-pair case — documents itself as a
 *   RUNTIME registry-lookup failure over data that may be perfectly valid. None of these three are
 *   that: they are invalid arguments, detectable with no lookup and no request, which is exactly the
 *   `InvalidArgumentException` lineage this class already carries.
 * - Reaching for a plain SPL exception for one of the three would mean a consumer's single
 *   `catch (HubspotException)` block caught a blank object type and missed a blank object id.
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
     * A directed association pair was built with the same record on both sides.
     */
    public static function selfAssociation(string $objectType, string $id): self
    {
        return new self(sprintf('An association pair was built from record "%s" of object type "%s" on both sides. HubSpot cannot associate a record with itself: pass two different records, and check which side of the pair was built from the wrong variable.', $id, $objectType));
    }
}
