<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Registry;

use ReyemTech\Hubspot\Exceptions\AssociationTypeException;
use ReyemTech\Hubspot\Gateway\AssociationCategory;
use ReyemTech\Hubspot\Gateway\AssociationType;

/**
 * One row of the association-type registry, in the shape design spec §6.2 gives it: the direction,
 * the type, the label it answers under, the inverse id recorded for traversal, and whether it is the
 * default.
 *
 * ## `inverse_type_id` is recorded and never written
 *
 * FOUND-03 measured that HubSpot maintains the opposite direction itself — one write, both directions
 * readable — so this column stays what design spec §6.2 says it is: recorded for traversal and
 * verification, **never used for writes**. Nothing on a write path reads it, and
 * `tests/Feature/Registry/LabelledWriteThroughRegistryTest.php` proves that from the outside by
 * seeding an inverse id that matches no type id and asserting it never appears in a recorded request.
 *
 * ## Why every scalar is `mixed` and checked
 *
 * `declare(strict_types=1)` binds at the file making the CALL, never at this one. A row is built by a
 * portal sync and rehydrated from a cache payload — two paths where a value's provenance is invisible
 * by the time it arrives — so a promoted `?bool $isDefault` would have turned the string `'false'`
 * into `true`, and a promoted `?int $inverseTypeId` would have turned `19.9` into `19`, a real type
 * id (Deal -> Line Item). Each is rejected rather than cast, following the merged precedent in
 * `Gateway\ObjectRef` and `Gateway\AssociationType`.
 *
 * The type id and the category are not re-checked here: they arrive already validated inside an
 * `AssociationType`, which rejects a non-int, a zero-or-negative id and an unknown category. Checking
 * them again would be a second place to be wrong (STANDARDS §6b).
 *
 * No narrowing `@param` docblocks sit over the natives — PHPStan at level max would fold the checks
 * into tautologies, and this repository forbids a baseline (STANDARDS §3).
 */
final readonly class AssociationTypeRow
{
    public ?string $label;

    public ?int $inverseTypeId;

    public ?bool $isDefault;

    public function __construct(
        public AssociationDirection $direction,
        public AssociationType $type,
        mixed $label,
        mixed $inverseTypeId,
        mixed $isDefault,
    ) {
        if ($label !== null && ! is_string($label)) {
            throw AssociationTypeException::nonStringLabel($label);
        }

        if ($inverseTypeId !== null && (! is_int($inverseTypeId) || $inverseTypeId < 1)) {
            throw AssociationTypeException::invalidInverseTypeId($inverseTypeId);
        }

        if ($isDefault !== null && ! is_bool($isDefault)) {
            throw AssociationTypeException::nonBooleanDefaultFlag($isDefault);
        }

        $this->label = $label;
        $this->inverseTypeId = $inverseTypeId;
        $this->isDefault = $isDefault;
    }

    /**
     * The storage key this row answers under: its own direction and its own label, never a
     * normalisation of the two that could be reached from the opposite direction.
     */
    public function key(): string
    {
        return $this->direction->key($this->label);
    }

    /**
     * The row as plain data, in the column names design spec §6.2 uses, for a cache payload or a
     * database row.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'from' => $this->direction->from->value,
            'to' => $this->direction->to->value,
            'type_id' => $this->type->typeId,
            'category' => $this->type->category->value,
            'label' => $this->label,
            'inverse_type_id' => $this->inverseTypeId,
            'is_default' => $this->isDefault,
        ];
    }

    /**
     * Rehydrates a row from plain data, through the same validating door a fresh row comes through.
     *
     * A corrupt or truncated payload therefore surfaces as this package's own exception naming the
     * fault, never as a `TypeError` from a property assignment a consumer cannot catch (STANDARDS §9).
     *
     * @param  array<array-key, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            direction: AssociationDirection::of(
                from: $payload['from'] ?? null,
                to: $payload['to'] ?? null,
            ),
            type: new AssociationType(
                typeId: $payload['type_id'] ?? null,
                category: AssociationCategory::fromValue($payload['category'] ?? null),
            ),
            label: $payload['label'] ?? null,
            inverseTypeId: $payload['inverse_type_id'] ?? null,
            isDefault: $payload['is_default'] ?? null,
        );
    }
}
