<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Exceptions;

use ReyemTech\Hubspot\Gateway\AssociationCategory;
use ReyemTech\Hubspot\Gateway\Contracts\AssociationTypeResolver;
use RuntimeException;

/**
 * A resolution failure -- a directed `(from, to[, label])` pair the association-type registry
 * cannot resolve to a typeId, or a type id or category that could not have come from one
 * (STANDARDS §9, design spec §9, 02-CONTEXT.md's four association rules). Extends
 * `RuntimeException`, not `LogicException`: the pair itself may be perfectly valid data -- what
 * fails is a runtime lookup against the registry's current state, the same family as
 * `ApiException`'s own failure shape.
 *
 * **Never falls back to the inverse typeId.** That silent fallback is exactly how a note<->contact
 * association gets written backwards and nobody notices for months (02-CONTEXT.md) -- the messages
 * below state the failed direction and explicitly disclaim the inverse as a substitute, rather
 * than merely omitting it.
 *
 * `directionNotResolvable()` shipped in plan 02-02, ahead of its first real caller, so the
 * Registry's seam existed before it was needed. Plan 02-05 adds the five members the Gateway's
 * labelled path actually raises. Two of them (`nonStringAssociationCategory()`,
 * `nonIntegerTypeId()`) name a `Gateway` class in their message, via `::class` rather than as a
 * hand-written literal so a rename cannot leave the message pointing at nothing. `::class` on an
 * imported name is resolved by the compiler to a plain string and never autoloads, so this adds no
 * runtime coupling from `Exceptions` back to `Gateway`; no architecture rule governs this namespace
 * either way (`tests/Arch/LayerBoundariesTest.php` constrains the six layers, not `Exceptions`).
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

    /**
     * No resolver capable of answering is bound -- the state of the package before Phase 3's
     * registry exists, raised by `Gateway\UnresolvedAssociationTypeResolver` for every request.
     *
     * Distinct from `directionNotResolvable()` on purpose: that one means "a registry looked and
     * found nothing", this one means "nothing looked". The remedies differ, and a message naming the
     * wrong one sends the reader to add a row to a table that does not exist.
     */
    public static function noResolverInstalled(string $fromType, string $toType, string $label): self
    {
        return new self(sprintf(
            'No association type resolver is installed, so the direction %s -> %s labelled "%s" cannot '
            .'be resolved to a typeId, and nothing was written. Bind %s in a service provider to an '
            .'implementation that resolves this exact direction -- the inverse %s -> %s is a different, '
            .'unrelated typeId and is never substituted automatically -- or call associate(), which uses '
            .'the unlabelled default association type and resolves no typeId at all.',
            $fromType,
            $toType,
            $label,
            AssociationTypeResolver::class,
            $toType,
            $fromType,
        ));
    }

    /**
     * A labelled write was requested with an empty label list. Steers to the unlabelled method
     * rather than quietly sending an empty spec array, which HubSpot answers with a 400 about a
     * payload the caller never knowingly built.
     */
    public static function noLabelsGiven(): self
    {
        return new self(
            'A labelled association write was requested with no labels, so there is no direction to '
            .'resolve and nothing was written. Pass at least one label, or call associate(), which uses '
            .'the unlabelled default association type and resolves no typeId at all.'
        );
    }

    /**
     * @param  list<string>  $valid
     */
    public static function unknownAssociationCategory(string $received, array $valid): self
    {
        return new self(sprintf(
            'Association category "%s" is not one the HubSpot API recognises. Use one of: %s.',
            $received,
            implode(', ', $valid),
        ));
    }

    /**
     * @param  list<string>  $valid
     */
    public static function nonStringAssociationCategory(mixed $received, array $valid): self
    {
        return new self(sprintf(
            'An association category was given as type %s. Pass one of the strings the HubSpot API '
            .'recognises -- %s -- or a %s case, which makes the invalid value unrepresentable. This is '
            .'validated here rather than by the parameter type because declare(strict_types=1) binds at '
            .'the calling file, not at this package\'s: in a file without it, 1 and true would both have '
            .'arrived as "1" and been reported as an unknown category nobody wrote.',
            get_debug_type($received),
            implode(', ', $valid),
            AssociationCategory::class,
        ));
    }

    /**
     * A type id that arrived as something other than an int -- rejected rather than cast, because
     * every plausible coercion lands on a real HubSpot type id.
     */
    public static function nonIntegerTypeId(mixed $received): self
    {
        return new self(sprintf(
            'A HubSpot association type id was given as type %s. Pass it as an int -- a value held as a '
            .'string is cast at the call site with "(int) $typeId", never coerced here. This is validated '
            .'in the value object rather than by the parameter type because declare(strict_types=1) binds '
            .'at the calling file, not at this package\'s: in a file without it, true would have arrived '
            .'as 1 -- a real type id, Contact -> Primary Company -- and 19.9 as 19, another real one, '
            .'Deal -> Line Item. Either writes an association nobody meant, and HubSpot reports no error '
            .'for it.',
            get_debug_type($received),
        ));
    }

    /**
     * A registry row's label arrived as something other than a string.
     *
     * A row is built by a portal sync and rehydrated from a cache payload -- two paths where a
     * value's provenance is invisible by the time it arrives -- so the check is here rather than on
     * the parameter type, for the reason every other member in this class states: strict types bind
     * at the calling file.
     */
    public static function nonStringLabel(mixed $received): self
    {
        return new self(sprintf('An association label was given as type %s. Pass the label as a string, exactly as the portal spells it -- a paired label carries a DIFFERENT name in each direction ("Deals" one way, "People" the other), so a label is never derived and never coerced. Pass null only for the unlabelled default type, which resolves through createDefault() and needs no type id at all.', get_debug_type($received)));
    }

    /**
     * A registry row's inverse type id arrived as something other than a positive integer.
     *
     * The inverse id is recorded for traversal and verification and is never written, so a wrong one
     * corrupts nothing directly -- it misleads a read-back check into reporting an association is
     * present when the id it searched for was never HubSpot's.
     */
    public static function invalidInverseTypeId(mixed $received): self
    {
        return new self(sprintf('An inverse association type id was given as type %s, which is not a positive integer. Record the id HubSpot issues for the OPPOSITE direction -- Contact -> Company is 279 and Company -> Contact is 280 -- as an int, or null where no inverse has been observed. HubSpot issues ids from 1 upward, so a zero or a negative is a value that was defaulted rather than observed. Null is the safe answer of the two: the inverse id is read for traversal and verification and is never written, so an absent one narrows a diagnostic while a wrong one makes it report the wrong association as found.', get_debug_type($received)));
    }

    /**
     * A registry row's default flag arrived as something other than a boolean.
     *
     * The string `'false'` is the case this exists for: a non-empty string is `true` in weak mode, so
     * a flag that round-tripped through text would flip a "not the default" row into a default one
     * with no error anywhere.
     */
    public static function nonBooleanDefaultFlag(mixed $received): self
    {
        return new self(sprintf('An association type\'s default flag was given as type %s. Pass true or false, or null where no source states which type a bare association resolves to -- null is what the seeded baseline carries, because that answer was measured against a real portal for one object-type pair only. This is checked rather than coerced because a non-empty string such as "false" is true in weak mode, which would turn a row that is not the default into one that is.', get_debug_type($received)));
    }

    /**
     * HubSpot issues type ids from 1 upward, so a zero or negative id is not an unlikely id -- it is
     * the shape a defaulted or unresolved variable takes on its way to the wire.
     */
    public static function nonPositiveTypeId(int $received): self
    {
        return new self(sprintf(
            'A HubSpot association type id of %d is not a valid id, and nothing was written. HubSpot '
            .'type ids start at 1 -- Contact -> Primary Company is 1 and Company -> Primary Contact is 2 '
            .'-- so a zero or negative id is a value that was defaulted rather than resolved. Resolve the '
            .'direction and pass the id registered for it.',
            $received,
        ));
    }
}
