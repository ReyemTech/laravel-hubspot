<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Sync;

/**
 * One entry of `config('hubspot.models')`, resolved and validated.
 *
 * All three properties are non-nullable by construction, deliberately. A binding that could carry
 * a null (or absent) `$idProperty` past this point would push D-12's failure downstream to the
 * moment a sync actually runs, on a worker, long after the config that caused it was written --
 * `ModelBindings::validate()` is what refuses to construct one otherwise.
 */
final readonly class ModelBinding
{
    public function __construct(
        public string $modelClass,
        public string $objectType,
        public string $idProperty,
    ) {}
}
