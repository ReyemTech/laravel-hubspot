<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Sync;

use Illuminate\Database\Eloquent\Model;

/**
 * Resolves a model's `$hubspotMap` into the property bag a HubSpot write carries.
 *
 * Pure and single-purpose, the same shape
 * `Registry\Stores\DatabaseAssociationTypeStore::decodeBoolean()`/`hydrate()` models: no I/O, no
 * dependency, one job. This plan resolves the LITERAL-attribute form only -- `$hubspotMap`'s
 * dot-notation (`'stage.hubspot_id'`) and closure forms are 04-03's, and this signature does not
 * change when they arrive: both forms resolve to a value keyed by the same HubSpot property name,
 * so `map()` stays `(Model, array): array` regardless of which form each entry takes.
 *
 * Every resolved value is cast to a string, matching
 * `Gateway\Contracts\ObjectGatewayContract::upsert()`'s own `array<string, string>` shape -- a
 * HubSpot property is always a string on the wire, and this is the one place in the whole chain
 * that already knows a model attribute's real, possibly non-string PHP type.
 */
final class PropertyMapper
{
    /**
     * @param  array<string, mixed>  $map  HubSpot property name => the model's own attribute name
     *                                     (the literal form this plan resolves) -- typed `mixed`
     *                                     on the value rather than `string` because `$hubspotMap`
     *                                     itself carries that wider type across every form 04-03
     *                                     adds, and this signature is the one that does not change
     *                                     when they arrive
     * @return array<string, string>
     */
    public function map(Model $model, array $map): array
    {
        $properties = [];

        foreach ($map as $hubspotProperty => $attribute) {
            if (! is_string($attribute)) {
                // 04-03's dot-notation and closure forms are not yet resolvable here.
                continue;
            }

            /** @var mixed $value */
            $value = $model->getAttribute($attribute);

            if (is_string($value)) {
                $properties[$hubspotProperty] = $value;
            } elseif (is_scalar($value)) {
                $properties[$hubspotProperty] = (string) $value;
            } else {
                $properties[$hubspotProperty] = '';
            }
        }

        return $properties;
    }
}
