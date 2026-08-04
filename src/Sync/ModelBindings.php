<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Sync;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\SoftDeletes;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Registry\Contracts\BoundModelReporter;
use ReyemTech\Hubspot\Registry\HubspotObjectType;

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
final class ModelBindings implements BoundModelReporter
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
     * The deletion facts each configured model contributes to `hubspot:doctor`.
     *
     * Registry owns the small reporting contract; Sync implements it because it owns both the
     * binding configuration and DeletePolicy. The command therefore receives already-resolved data
     * and does not recreate either decision across the R2 boundary.
     *
     * @return list<array{
     *     modelClass: string,
     *     objectType: string,
     *     idProperty: string,
     *     usesSoftDeletes: bool,
     *     deletePolicy: string
     * }>
     */
    public function boundModelReports(): array
    {
        $reports = [];

        foreach ($this->all() as $binding) {
            $usesSoftDeletes = in_array(SoftDeletes::class, class_uses_recursive($binding->modelClass), true);

            $reports[] = [
                'modelClass' => $binding->modelClass,
                'objectType' => $binding->objectType,
                'idProperty' => $binding->idProperty,
                'usesSoftDeletes' => $usesSoftDeletes,
                'deletePolicy' => DeletePolicy::resolve(
                    $usesSoftDeletes,
                    $usesSoftDeletes ? 'trashed' : 'deleted',
                    $this->policyValue('hard_delete', 'guard'),
                    $this->policyValue('on_restore', 'flag'),
                ),
            ];
        }

        return $reports;
    }

    /**
     * The binding for one model class.
     *
     * The single resolution point every Sync collaborator that needs a model's binding reaches --
     * `SyncsToHubspot::hubspotLink()` (and every scope built on it), `HubspotObserver` and
     * `SyncHubspotObjectJob` all resolve through this one method. Throws
     * {@see ConfigurationException::unboundSyncModel()} on a miss (04-04): a model that applies
     * the trait without a `hubspot.models` entry is D-12's inverse -- the fix is named, never
     * guessed, and never a fallback object type.
     */
    public function for(string $modelClass): ModelBinding
    {
        return $this->all()[$modelClass] ?? throw ConfigurationException::unboundSyncModel($modelClass);
    }

    /**
     * Throws for the first binding missing `id_property` (D-12). Called once, from
     * `ServiceProvider::boot()`, before any observer is attached -- a hard failure on a config
     * shape consumers will have written, never a guessed default.
     *
     * The trimmed value is compared, not the raw one (Codex, PR #39): `'id_property' => '   '` is
     * neither absent nor the literal empty string, so an `=== ''` check alone lets it boot clean.
     * The failure it defers is exactly the one D-12 exists to prevent -- `PropertyMapper::map()`
     * casts every resolved value to a string and `SyncHubspotObjectJob::handle()` reads
     * `$properties[$binding->idProperty]`, so a whitespace key that resolves to nothing throws
     * `idPropertyNotMapped()` instead, on a worker, long after the config that caused it was
     * written.
     */
    public function validate(): void
    {
        foreach ($this->all() as $binding) {
            if (trim($binding->idProperty) === '') {
                throw ConfigurationException::missingIdProperty($binding->modelClass);
            }
        }
    }

    /**
     * Preserve DeletePolicy's directed configuration error instead of leaking a TypeError.
     */
    private function policyValue(string $key, string $default): string
    {
        /** @var mixed $value */
        $value = $this->config->get('hubspot.auto_sync.'.$key, $default);

        return is_string($value) ? $value : get_debug_type($value);
    }
}
