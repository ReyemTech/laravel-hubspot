<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Webhooks;

use ReyemTech\Hubspot\Webhooks\Events\ContactPropertyChanged;
use ReyemTech\Hubspot\Webhooks\Events\ObjectAssociationChanged;
use ReyemTech\Hubspot\Webhooks\Events\ObjectLifecycleChanged;
use ReyemTech\Hubspot\Webhooks\Events\ObjectPropertyChanged;

/**
 * The closed, package-owned table from a HubSpot subscription type to the typed Laravel event class
 * it resolves to (D-06, D-09). "Closed" is load-bearing: `subscriptionType` is attacker-influenced
 * input (it arrives inside a signed but otherwise untrusted payload item), so this table never
 * constructs a class name FROM that string -- it only ever looks one up in a fixed map it owns, and
 * an unrecognized type resolves nothing at all rather than a fallback the payload could steer
 * (T-05-13, threat register).
 *
 * ## Most-specific-first
 *
 * `contact.propertyChange` has its own row, resolved ahead of the `*.propertyChange` family row
 * every other object type's property change falls back to -- ROADMAP.md's own success criterion 4
 * names {@see ContactPropertyChanged} by class, and D-09 keeps every other family on the shared
 * fallback class until a dedicated one is needed. Exactly one class is ever resolved per item.
 */
final class TypedEventMap
{
    /**
     * Resolves the typed event class for one item's subscription type, or `null` when the type is
     * unrecognized -- the generic-only path D-06 guarantees every item reaches regardless.
     *
     * @return class-string|null
     */
    public function resolve(string $subscriptionType): ?string
    {
        $table = $this->table();

        if (isset($table[$subscriptionType])) {
            return $table[$subscriptionType];
        }

        return $table[self::familyOf($subscriptionType)] ?? null;
    }

    /**
     * A method rather than a class constant -- the same trade `ServiceProvider::supportedStores()`
     * and `consoleCommands()` make and explain in their own docblocks: `pest --mutate` reports a
     * mutated CONSTANT declaration as UNCOVERED, since a constant is not an executed line coverage
     * can attribute a test to. Dropping a row from this table is a real defect
     * `TypedEventRoutingTest` catches.
     *
     * @return array<string, class-string>
     */
    private function table(): array
    {
        return [
            'contact.propertyChange' => ContactPropertyChanged::class,
            '*.propertyChange' => ObjectPropertyChanged::class,
            '*.creation' => ObjectLifecycleChanged::class,
            '*.deletion' => ObjectLifecycleChanged::class,
            '*.associationChange' => ObjectAssociationChanged::class,
        ];
    }

    /**
     * The family row a subscription type falls back to when no exact entry names it: the portion
     * after the object type, with the wildcard prefix this table's own family rows use --
     * `"deal.propertyChange"` yields `"*.propertyChange"`.
     */
    private static function familyOf(string $subscriptionType): string
    {
        $separator = strrpos($subscriptionType, '.');

        if ($separator === false) {
            return $subscriptionType;
        }

        return '*'.substr($subscriptionType, $separator);
    }
}
