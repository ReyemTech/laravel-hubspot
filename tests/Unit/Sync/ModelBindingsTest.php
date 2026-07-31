<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Unit\Sync;

use Illuminate\Config\Repository as ConfigRepository;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Sync\ModelBinding;
use ReyemTech\Hubspot\Sync\ModelBindings;
use ReyemTech\Hubspot\Tests\Support\Sync\SyncedLead;
use ReyemTech\Hubspot\Tests\TestCase;

/**
 * `ModelBindings` read and resolved directly against a plain config array, with no application
 * boot required -- `isBound()` and `for()` are otherwise only exercised indirectly, through
 * `ServiceProvider::boot()` and `HubspotObserver`/`SyncHubspotObjectJob`, in every other Sync test.
 *
 * Not listed in 04-02-PLAN.md's `files_modified` -- added under deviation Rule 2, for the same
 * reason `PropertyMapperTest` was: `ModelBindings` is a `phase_artifacts`-owned deliverable of this
 * plan, and `isBound()` had no other test reaching it.
 */
mutates(ModelBindings::class);

final class ModelBindingsTest extends TestCase
{
    private function bindings(): ModelBindings
    {
        return new ModelBindings(new ConfigRepository([
            'hubspot' => [
                'models' => [
                    SyncedLead::class => ['object' => 'contacts', 'id_property' => 'email'],
                ],
            ],
        ]));
    }

    public function test_a_bound_model_class_is_reported_as_bound(): void
    {
        self::assertTrue($this->bindings()->isBound(SyncedLead::class));
    }

    public function test_an_unbound_model_class_is_reported_as_not_bound(): void
    {
        self::assertFalse($this->bindings()->isBound(self::class));
    }

    public function test_all_resolves_the_configured_binding_keyed_by_model_class(): void
    {
        $all = $this->bindings()->all();

        self::assertArrayHasKey(SyncedLead::class, $all);
        self::assertInstanceOf(ModelBinding::class, $all[SyncedLead::class]);
        self::assertSame('contacts', $all[SyncedLead::class]->objectType);
        self::assertSame('email', $all[SyncedLead::class]->idProperty);
    }

    public function test_for_resolves_the_same_binding_all_carries(): void
    {
        $binding = $this->bindings()->for(SyncedLead::class);

        self::assertSame(SyncedLead::class, $binding->modelClass);
        self::assertSame('contacts', $binding->objectType);
        self::assertSame('email', $binding->idProperty);
    }

    /**
     * The full message, not a substring: `expectExceptionMessage()` only checks a `str_contains()`
     * match, which would still pass with the sprintf's own trailing text mutated away or
     * rearranged. Caught here the same way this codebase asserts every other directed error --
     * verbatim, against the factory's own real output rather than a copied literal.
     *
     * `ConfigurationException`, not the internal-invariant `RuntimeException` this test asserted
     * before 04-04: `ConfigurationException::unboundSyncModel()` is now the one exception `for()`
     * throws on any miss, whether the caller is `ServiceProvider::boot()`'s own defensive check or
     * a model genuinely absent from `hubspot.models` (04-04-PLAN.md's key_links).
     */
    public function test_for_throws_for_a_class_that_was_never_bound(): void
    {
        try {
            $this->bindings()->for(self::class);

            self::fail('Expected for() to throw for a class that was never bound.');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                ConfigurationException::unboundSyncModel(self::class)->getMessage(),
                $exception->getMessage(),
            );
        }
    }

    public function test_validate_passes_silently_when_every_binding_declares_an_id_property(): void
    {
        $this->bindings()->validate();

        $this->addToAssertionCount(1);
    }

    public function test_an_empty_models_map_validates_silently(): void
    {
        (new ModelBindings(new ConfigRepository(['hubspot' => ['models' => []]])))->validate();

        $this->addToAssertionCount(1);
    }
}
