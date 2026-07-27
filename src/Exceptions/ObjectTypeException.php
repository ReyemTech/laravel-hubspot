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
 * Not yet thrown anywhere in Phase 2: object-type normalisation and mapping are explicitly the
 * Registry layer's job (02-CONTEXT.md's Phase Boundary), which does not exist until Phase 3. The
 * named constructor below ships now so that seam exists before the Registry needs it, exactly as
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
}
