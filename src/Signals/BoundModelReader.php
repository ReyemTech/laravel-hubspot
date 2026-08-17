<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Signals;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Registry\HubspotObjectType;

/**
 * Reads `config('hubspot.models')` -- the same config key `Sync\ModelBindings` reads -- without
 * depending on the `Sync` class that resolves it (D-01). `Signals` may depend only on `Registry`,
 * `Gateway`, `Exceptions` and the framework (R5), and may never depend on `Sync` (R7); reading a
 * config KEY is not a dependency on a `Sync` CLASS, which is exactly what keeps both rules green.
 *
 * Read fresh from config on every call, exactly the way `Sync\ModelBindings` reads it: this class
 * is bound as a singleton purely because it holds no transport `Hubspot::fake()` would ever need
 * to invalidate, never because its answer is cached.
 */
final class BoundModelReader
{
    public function __construct(private readonly ConfigRepository $config) {}

    /**
     * Every configured binding, keyed by model class.
     *
     * @return array<class-string, BoundSignalSubject>
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

            $bindings[$modelClass] = new BoundSignalSubject(
                objectType: $objectType,
                idProperty: is_string($idProperty) ? $idProperty : '',
            );
        }

        return $bindings;
    }

    /**
     * The binding for one model class.
     *
     * The single resolution point every Signals collaborator that needs a subject's binding
     * reaches -- `IdentityResolver::identify()` and `FlushSignalsJob::handle()` both resolve
     * through this one method. Throws {@see ConfigurationException::unboundSignalSubject()} on a
     * miss: a subject with no `hubspot.models` entry is never guessed at.
     */
    public function for(string $modelClass): BoundSignalSubject
    {
        return $this->all()[$modelClass] ?? throw ConfigurationException::unboundSignalSubject($modelClass);
    }

    /**
     * Whether any configured binding names the given, already-normalised object type. Plan 06-02
     * consumes this for D-03's "a signal's declared object must be claimed by some bound model"
     * check; it needs no runtime subject, only the config map.
     */
    public function claimsObjectType(string $normalisedObjectType): bool
    {
        foreach ($this->all() as $binding) {
            if ($binding->objectType === $normalisedObjectType) {
                return true;
            }
        }

        return false;
    }
}
