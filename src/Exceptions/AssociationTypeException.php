<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Exceptions;

use RuntimeException;

/**
 * A resolution failure -- a directed `(from, to[, label])` pair the association-type registry
 * cannot resolve to a typeId (STANDARDS §9, design spec §9, 02-CONTEXT.md's four association
 * rules). Extends `RuntimeException`, not `LogicException`: the pair itself may be perfectly
 * valid data -- what fails is a runtime lookup against the registry's current state, the same
 * family as `ApiException`'s own failure shape.
 *
 * **Never falls back to the inverse typeId.** That silent fallback is exactly how a note<->contact
 * association gets written backwards and nobody notices for months (02-CONTEXT.md) -- the message
 * below states the failed direction and explicitly disclaims the inverse as a substitute, rather
 * than merely omitting it.
 *
 * Not yet thrown anywhere in Phase 2: the association-type registry does not exist until Phase 3.
 * `directionNotResolvable()` ships now, ahead of its first real caller in plan 02-05, so the
 * Registry's seam exists before that plan needs it (02-02-PLAN.md's scope note).
 */
final class AssociationTypeException extends RuntimeException implements HubspotException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    /**
     * No association type is registered for this direction (and, if given, this label).
     */
    public static function directionNotResolvable(string $fromType, string $toType, ?string $label = null): self
    {
        $direction = sprintf('%s -> %s', $fromType, $toType);
        $inverse = sprintf('%s -> %s', $toType, $fromType);

        $labelled = $label !== null
            ? sprintf(' labelled "%s"', $label)
            : '';

        return new self(sprintf(
            'No association type is registered for the direction %s%s. Register this direction '
            .'-- the inverse %s is a different, unrelated typeId and is never substituted '
            .'automatically -- before associating these object types.',
            $direction,
            $labelled,
            $inverse,
        ));
    }
}
