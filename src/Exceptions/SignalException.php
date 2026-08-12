<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Exceptions;

use RuntimeException;

/**
 * The fifth member of the shared hierarchy (SIG-05, spec §11) -- `Hubspot::identify()`'s two
 * refusals: D-09's asymmetric rebind-to-a-different-subject refusal, and D-02's blank-`id_property`
 * refusal.
 *
 * `RuntimeException`, not `LogicException`, for `AssociationTypeException`'s own stated reason: a
 * rebinding conflict is a runtime data conflict discovered at call time against buffered
 * `hubspot_signals` rows -- the visitor id and the subject are each perfectly valid data on their
 * own, and what fails is a runtime lookup against the buffer's current state, not a caller or
 * config mistake detectable before any I/O. A blank `id_property` value is the identical shape:
 * the `hubspot.models` binding itself is well-formed, and what fails is the runtime VALUE the
 * bound model happens to carry -- `identify()` issues no HTTP, so both checks are cheap to run
 * before any write, and the alternative surfaces hours later in a worker log detached from its
 * cause (D-02).
 *
 * `final`, with a private constructor and only static named factories -- exactly like every other
 * member of this hierarchy ({@see HubspotException} is the marker interface all five implement).
 *
 * **Neither factory accepts a buffered `properties` payload or a property value.** An exception
 * message is an operator-facing channel, and the buffer holds the consumer's own customers'
 * behavioural data -- the messages below name the identifiers an operator needs to resolve a
 * conflict, and nothing about what that person did.
 */
final class SignalException extends RuntimeException implements HubspotException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    /**
     * `$visitorId` already carries a subject, and `identify()` was called again for a DIFFERENT
     * one (SIG-05). D-09 makes this refusal deliberately asymmetric: many visitor ids may bind to
     * ONE subject -- that is what lets roll-ups compute across a person's own devices and pick the
     * genuinely earliest touch -- but one visitor id may never bind to two subjects, because that
     * would attribute one person's behaviour onto another person's HubSpot record.
     *
     * The fix names the application's own responsibility (D9): visitor-id issuance belongs to the
     * application, so a second person who reached this state needs a FRESH visitor id, not a
     * second binding on this one.
     */
    public static function visitorAlreadyBoundToDifferentSubject(
        string $visitorId,
        string $boundSubjectType,
        string $boundSubjectId,
        string $attemptedSubjectType,
        string $attemptedSubjectId,
    ): self {
        return new self(sprintf(
            'Visitor id "%s" is already bound to %s #%s, so it cannot also be bound to %s #%s -- '
            .'one visitor id may bind to only one subject, though one subject may bind to many '
            .'visitor ids (the same person on several devices). Issue a fresh visitor id for the '
            .'second person instead: visitor-id issuance is the application\'s own responsibility, '
            .'not this package\'s.',
            $visitorId,
            $boundSubjectType,
            $boundSubjectId,
            $attemptedSubjectType,
            $attemptedSubjectId,
        ));
    }

    /**
     * The subject's `id_property` value is missing, null, empty or whitespace-only (D-02). Thrown
     * in `identify()`'s OWN caller stack, before any write -- `identify()` issues no HTTP, so this
     * check is free, and the alternative surfaces hours later in a worker log detached from its
     * cause.
     */
    public static function missingIdPropertyValue(string $subjectType, string $subjectId, string $idProperty): self
    {
        return new self(sprintf(
            '%s #%s has no usable value for "%s", the id_property hubspot.models declares for '
            .'it, so Hubspot::identify() cannot bind it to any visitor id. Populate "%s" on this '
            .'subject before calling identify(), or correct config(\'hubspot.models\')[\'%s\']'
            .'[\'id_property\'] if a different property should be used.',
            $subjectType,
            $subjectId,
            $idProperty,
            $idProperty,
            $subjectType,
        ));
    }
}
