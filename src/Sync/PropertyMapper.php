<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Sync;

use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Resolves a model's `$hubspotMap` into the property bag a HubSpot write carries (SYNC-02).
 *
 * One generic resolution shape for any property, dispatching on the MAP VALUE's own shape rather
 * than on the key or on the string's own content: a `Closure` is invoked with the model itself;
 * anything else is a string handed to `data_get($model, $path)`. `data_get()` is not hand-rolled
 * (`illuminate/collections`, declared in 04-01) and needs no dot-detecting branch of its own -- it
 * walks a single-segment "path" (a literal attribute name) through exactly the same code as a
 * multi-segment one, so the literal-attribute and dot-notation forms are the SAME code path here,
 * not two, and never throws on a missing or null relation segment.
 *
 * Every `null` result is filtered out of the returned bag, in the one place both the closure and
 * the path form funnel through. At this layer a `null` traversed relation and a deliberate clear
 * are indistinguishable, and silently clearing a live CRM property is the worse of the two
 * failures (04-CONTEXT.md T-04-11) -- so a `null` OMITS its key rather than being sent. An empty
 * string is not null and is sent verbatim: that is how a consumer deliberately blanks a property.
 *
 * Pure and single-purpose, the same shape `Registry\Stores\DatabaseAssociationTypeStore::hydrate()`/
 * `decodeBoolean()` models: no I/O, no dependency, no state -- the model and the map in, an array
 * out.
 *
 * Every resolved value is cast to a string, matching
 * `Gateway\Contracts\ObjectGatewayContract::upsert()`'s own `array<string, string>` shape -- this
 * is the one place in the whole chain that already knows a model attribute's real, possibly
 * non-string PHP type.
 */
final class PropertyMapper
{
    /**
     * @param  array<string, string|Closure>  $map  HubSpot property name => either a dot-notation
     *                                              path across the model's own attributes and
     *                                              relations, or a closure receiving the model
     * @return array<string, string>
     */
    public function map(Model $model, array $map): array
    {
        $properties = [];

        foreach ($map as $hubspotProperty => $resolver) {
            /** @var mixed $value */
            $value = $resolver instanceof Closure ? $resolver($model) : data_get($model, $resolver);

            if ($value === null) {
                continue;
            }

            $properties[$hubspotProperty] = is_string($value)
                ? $value
                : (is_scalar($value) ? (string) $value : '');
        }

        return $properties;
    }

    /**
     * The update path's map SELECTION rule, kept in the one place it lives: an empty
     * `$updateMap` means the model declares none, so the full, unnarrowed `$map` applies. `===
     * []`, not a falsy check -- the same explicit-comparison convention
     * `ModelBindings::validate()` already uses for its own "absent vs. deliberately blank"
     * distinction (`trim(...) === ''`, not `empty(...)`), for the identical reason: an identity
     * comparison reads as a decision, a truthy check reads as an inference.
     *
     * @param  array<string, string|Closure>  $map
     * @param  array<string, string|Closure>  $updateMap
     * @return array<string, string>
     */
    public function mapForUpdate(Model $model, array $map, array $updateMap): array
    {
        return $this->map($model, $updateMap === [] ? $map : $updateMap);
    }
}
