<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Signals;

use ReflectionMethod;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\ServiceProvider;
use ReyemTech\Hubspot\Signals\Contracts\SignalStore;
use ReyemTech\Hubspot\Signals\Stores\LocalSignalStore;
use ReyemTech\Hubspot\Tests\TestCase;

mutates(ServiceProvider::class);

/**
 * SIG-07's driver resolution: `HUBSPOT_SIGNAL_STORE` selects `Signals\Contracts\SignalStore`'s bound
 * implementation, `local` is the default and the only arm this phase ships, and every other value --
 * including a Phase 7 driver name typed early -- throws rather than silently falling back. Mirrors
 * `RegistryBindingsTest`'s own store-resolution tests in shape, but with hardcoded literal messages
 * rather than a factory-against-factory comparison (06-05-PLAN.md's explicit instruction): comparing
 * a factory's output against itself can never catch a mutated internal string.
 */
final class SignalStoreResolutionTest extends TestCase
{
    public function test_an_unset_store_resolves_to_local_signal_store(): void
    {
        self::assertSame(
            'local',
            config('hubspot.signals.store'),
            'local is the shipped default (HUBSPOT_SIGNAL_STORE unset) -- this test is only '
            .'meaningful against that default.',
        );

        $store = app(SignalStore::class);

        self::assertInstanceOf(LocalSignalStore::class, $store);
        self::assertSame($store, app(SignalStore::class), 'The store is shared.');
    }

    public function test_local_resolves_to_local_signal_store(): void
    {
        config(['hubspot.signals.store' => 'local']);

        self::assertInstanceOf(LocalSignalStore::class, app(SignalStore::class));
    }

    public function test_custom_object_throws_naming_the_valid_drivers(): void
    {
        config(['hubspot.signals.store' => 'custom_object']);

        try {
            app(SignalStore::class);

            self::fail('Expected "custom_object" to throw -- it is not implemented until Phase 7.');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                'HUBSPOT_SIGNAL_STORE is set to "custom_object", which is not a supported signal '
                .'store. Set it to one of: local.',
                $exception->getMessage(),
            );
        }
    }

    public function test_an_unknown_store_name_throws_rather_than_falling_back_to_local(): void
    {
        config(['hubspot.signals.store' => 'redis']);

        try {
            app(SignalStore::class);

            self::fail('Expected an unrecognised store name to throw rather than fall back to local.');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                'HUBSPOT_SIGNAL_STORE is set to "redis", which is not a supported signal store. '
                .'Set it to one of: local.',
                $exception->getMessage(),
            );
        }
    }

    public function test_an_empty_store_name_throws_naming_the_valid_drivers(): void
    {
        config(['hubspot.signals.store' => '']);

        try {
            app(SignalStore::class);

            self::fail('Expected an empty store name to throw.');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                'HUBSPOT_SIGNAL_STORE is set to "", which is not a supported signal store. Set '
                .'it to one of: local.',
                $exception->getMessage(),
            );
        }
    }

    /**
     * Byte-exact comparison: no case folding, no trimming.
     */
    public function test_a_case_or_whitespace_variant_of_local_throws(): void
    {
        config(['hubspot.signals.store' => 'Local']);

        try {
            app(SignalStore::class);

            self::fail('Expected "Local" to throw -- byte-exact comparison, no case folding.');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                'HUBSPOT_SIGNAL_STORE is set to "Local", which is not a supported signal store. '
                .'Set it to one of: local.',
                $exception->getMessage(),
            );
        }

        config(['hubspot.signals.store' => ' local']);

        try {
            app(SignalStore::class);

            self::fail('Expected " local" to throw -- byte-exact comparison, no trimming.');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                'HUBSPOT_SIGNAL_STORE is set to " local", which is not a supported signal store. '
                .'Set it to one of: local.',
                $exception->getMessage(),
            );
        }
    }

    public function test_a_non_string_store_value_is_reported_through_get_debug_type(): void
    {
        config(['hubspot.signals.store' => 42]);

        try {
            app(SignalStore::class);

            self::fail('Expected a non-string store name to throw.');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                'HUBSPOT_SIGNAL_STORE is set to "int", which is not a supported signal store. Set '
                .'it to one of: local.',
                $exception->getMessage(),
            );
        }
    }

    public function test_supported_signal_stores_returns_exactly_local_in_this_phase(): void
    {
        $method = new ReflectionMethod(ServiceProvider::class, 'supportedSignalStores');
        $method->setAccessible(true);

        self::assertSame(['local'], $method->invoke(null));
    }

    public function test_the_exception_message_names_hubspot_signal_store_as_the_env_var_to_correct(): void
    {
        config(['hubspot.signals.store' => 'timeline']);

        try {
            app(SignalStore::class);

            self::fail('Expected an exception naming HUBSPOT_SIGNAL_STORE.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('HUBSPOT_SIGNAL_STORE', $exception->getMessage());
        }
    }
}
