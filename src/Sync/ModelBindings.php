<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Sync;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Registry\HubspotObjectType;
use RuntimeException;

/**
 * Reads and validates `config('hubspot.models')` -- the single source of truth every other Sync
 * collaborator resolves a model's binding from.
 *
 * Read fresh from config on every call, exactly the way `ServiceProvider`'s store `match` reads
 * `config('hubspot.store')` at resolution time rather than caching a decision: `HubspotObserver`
 * and `SyncHubspotObjectJob` both resolve a binding by `get_class($model)` AT CALL TIME (never in
 * a constructor -- see `HubspotObserver`'s own docblock for why `Model::observe()` makes that a
 * silent-data-loss bug rather than a style choice), so this service must answer the same way.
 */
final class ModelBindings
{
    public function __construct(private readonly ConfigRepository $config) {}

    /**
     * Every configured binding, keyed by model class.
     *
     * @return array<class-string, ModelBinding>
     */
    public function all(): array
    {
        /** @var array<class-string, array{object?: mixed, id_property?: mixed}> $configured */
        $configured = $this->config->get('hubspot.models', []);

        $bindings = [];

        foreach ($configured as $modelClass => $binding) {
            $objectType = HubspotObjectType::normalise($binding['object'] ?? null)->value;

            /** @var mixed $idProperty */
            $idProperty = $binding['id_property'] ?? null;

            $bindings[$modelClass] = new ModelBinding(
                modelClass: $modelClass,
                objectType: $objectType,
                // Never guessed -- an absent or non-string id_property becomes the empty string
                // here, which is what makes it representable as a non-nullable ModelBinding, and
                // validate() below is what refuses to let it survive past boot.
                idProperty: is_string($idProperty) ? $idProperty : '',
            );
        }

        return $bindings;
    }

    public function isBound(string $modelClass): bool
    {
        return array_key_exists($modelClass, $this->all());
    }

    /**
     * The binding for one model class.
     *
     * Only ever called for a class `ServiceProvider::boot()` has already registered the observer
     * for, so a miss here is an internal invariant violation, not a user-facing config mistake --
     * `ConfigurationException::unboundSyncModel()` (04-04) is the directed error for a genuinely
     * user-reachable miss, e.g. calling a trait method on a model nobody bound.
     */
    public function for(string $modelClass): ModelBinding
    {
        return $this->all()[$modelClass] ?? throw new RuntimeException(sprintf(
            'No HubSpot binding is registered for %s. This should be unreachable: the observer '
            .'is only attached to classes ServiceProvider::boot() already found in '
            .'hubspot.models.',
            $modelClass,
        ));
    }

    /**
     * Throws for the first binding missing `id_property` (D-12). Called once, from
     * `ServiceProvider::boot()`, before any observer is attached -- a hard failure on a config
     * shape consumers will have written, never a guessed default.
     */
    public function validate(): void
    {
        foreach ($this->all() as $binding) {
            if ($binding->idProperty === '') {
                throw ConfigurationException::missingIdProperty($binding->modelClass);
            }
        }
    }
}
