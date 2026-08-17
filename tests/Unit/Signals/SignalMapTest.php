<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Unit\Signals;

use Illuminate\Config\Repository;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Signals\BoundModelReader;
use ReyemTech\Hubspot\Signals\MergeRule;
use ReyemTech\Hubspot\Signals\SignalMap;
use ReyemTech\Hubspot\Tests\Support\Signals\IntentScore;
use ReyemTech\Hubspot\Tests\Support\Signals\SignalCompanySubject;
use ReyemTech\Hubspot\Tests\Support\Signals\SignalSubject;
use ReyemTech\Hubspot\Tests\TestCase;

mutates(SignalMap::class);

/**
 * SIG-03/D-03/D-07: `hubspot.signals.map`, validated as one whole, and consumed one signal at a
 * time. Unit-tested directly against a plain `Illuminate\Config\Repository`, mirroring
 * `BoundModelReaderTest` -- no application booted, no database.
 */
final class SignalMapTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $signalsMap
     * @param  array<class-string, array<string, mixed>>  $models
     */
    private function map(array $signalsMap, array $models = []): SignalMap
    {
        $config = new Repository([
            'hubspot' => [
                'signals' => ['map' => $signalsMap],
                'models' => $models,
            ],
        ]);

        return new SignalMap($config, new BoundModelReader($config));
    }

    /**
     * @return array<class-string, array<string, mixed>>
     */
    private function contactsBinding(): array
    {
        return [SignalSubject::class => ['object' => 'contacts', 'id_property' => 'email']];
    }

    public function test_a_well_formed_map_with_mixed_verbs_validates_without_throwing(): void
    {
        $this->expectNotToPerformAssertions();

        $map = $this->map([
            'pricing_page_viewed' => [
                'object' => 'contacts',
                'properties' => [
                    'pricing_page_views' => 'increment',
                    'first_touch_source' => 'first_wins:source',
                    'last_pricing_view' => 'last_wins:occurred_at',
                ],
            ],
            'demo_requested' => [
                'object' => 'contacts',
                'properties' => [
                    'intent_score' => IntentScore::class,
                ],
            ],
            'deal_value_seen' => [
                'object' => 'contacts',
                'properties' => [
                    'deal_value_total' => 'sum:value',
                ],
            ],
        ], $this->contactsBinding());

        $map->validate();
    }

    public function test_an_empty_map_validates_without_throwing(): void
    {
        $this->expectNotToPerformAssertions();

        $this->map([])->validate();
    }

    public function test_an_unset_map_key_behaves_identically_to_an_empty_map(): void
    {
        $config = new Repository(['hubspot' => ['models' => []]]);
        $map = new SignalMap($config, new BoundModelReader($config));

        $map->validate();

        self::assertSame([], $map->names());
    }

    public function test_a_missing_object_key_throws_naming_the_signal_and_the_object_key(): void
    {
        try {
            $this->map([
                'pricing_page_viewed' => [
                    'properties' => ['pricing_page_views' => 'increment'],
                ],
            ], $this->contactsBinding())->validate();

            self::fail('Expected a ConfigurationException for a missing "object" key.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('pricing_page_viewed', $exception->getMessage());
            self::assertStringContainsString('object', $exception->getMessage());
        }
    }

    public function test_a_non_array_properties_value_throws_naming_the_signal(): void
    {
        try {
            $this->map([
                'pricing_page_viewed' => [
                    'object' => 'contacts',
                    'properties' => 'not-an-array',
                ],
            ], $this->contactsBinding())->validate();

            self::fail('Expected a ConfigurationException for a non-array "properties" value.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('pricing_page_viewed', $exception->getMessage());
        }
    }

    public function test_a_bad_merge_verb_throws_naming_the_verb_signal_property_and_valid_verbs(): void
    {
        try {
            $this->map([
                'pricing_page_viewed' => [
                    'object' => 'contacts',
                    'properties' => ['pricing_page_views' => 'overwrite'],
                ],
            ], $this->contactsBinding())->validate();

            self::fail('Expected a ConfigurationException for an unknown merge verb.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('overwrite', $exception->getMessage());
            self::assertStringContainsString('pricing_page_viewed', $exception->getMessage());
            self::assertStringContainsString('pricing_page_views', $exception->getMessage());
            self::assertStringContainsString('first_wins', $exception->getMessage());
            self::assertStringContainsString('last_wins', $exception->getMessage());
            self::assertStringContainsString('increment', $exception->getMessage());
            self::assertStringContainsString('sum', $exception->getMessage());
        }
    }

    public function test_a_map_with_faults_under_two_signals_throws_for_the_first_declared(): void
    {
        try {
            $this->map([
                'pricing_page_viewed' => [
                    'object' => 'contacts',
                    'properties' => ['pricing_page_views' => 'overwrite'],
                ],
                'demo_requested' => [
                    'object' => 'contacts',
                    'properties' => ['intent_score' => 'also-bad'],
                ],
            ], $this->contactsBinding())->validate();

            self::fail('Expected a ConfigurationException for the first declared fault.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('pricing_page_viewed', $exception->getMessage());
            self::assertStringNotContainsString('demo_requested', $exception->getMessage());
        }
    }

    public function test_knows_is_false_for_an_unmapped_name(): void
    {
        $map = $this->map([]);

        self::assertFalse($map->knows('unmapped_name'));
    }

    public function test_object_type_for_throws_unknown_signal_name_naming_the_mapped_names(): void
    {
        $map = $this->map([
            'pricing_page_viewed' => [
                'object' => 'contacts',
                'properties' => ['pricing_page_views' => 'increment'],
            ],
        ], $this->contactsBinding());

        try {
            $map->objectTypeFor('unmapped_name');

            self::fail('Expected a ConfigurationException for an unmapped signal name.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('unmapped_name', $exception->getMessage());
            self::assertStringContainsString('pricing_page_viewed', $exception->getMessage());
        }
    }

    /**
     * `unknownSignalName()`'s zero-mapped-names branch, distinct from the test above: an entirely
     * empty map has no mapped names to list, so the message says so rather than rendering an
     * empty list.
     */
    public function test_object_type_for_on_an_entirely_empty_map_names_that_nothing_is_mapped(): void
    {
        try {
            $this->map([])->objectTypeFor('unmapped_name');

            self::fail('Expected a ConfigurationException for an unmapped signal name.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('unmapped_name', $exception->getMessage());
            self::assertStringContainsString('No signal names are mapped', $exception->getMessage());
        }
    }

    public function test_object_type_for_returns_the_normalised_object_type(): void
    {
        $lower = $this->map([
            'pricing_page_viewed' => [
                'object' => 'contacts',
                'properties' => ['pricing_page_views' => 'increment'],
            ],
        ], $this->contactsBinding());

        $upper = $this->map([
            'pricing_page_viewed' => [
                'object' => 'Contacts',
                'properties' => ['pricing_page_views' => 'increment'],
            ],
        ], $this->contactsBinding());

        self::assertSame('contacts', $lower->objectTypeFor('pricing_page_viewed'));
        self::assertSame('contacts', $upper->objectTypeFor('pricing_page_viewed'));
    }

    public function test_a_map_object_no_bound_model_claims_throws_signal_object_type_mismatch(): void
    {
        try {
            $this->map([
                'deal_value_seen' => [
                    'object' => 'deals',
                    'properties' => ['deal_value_total' => 'sum:value'],
                ],
            ], $this->contactsBinding())->validate();

            self::fail('Expected a ConfigurationException for an unclaimed object type.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('deal_value_seen', $exception->getMessage());
            self::assertStringContainsString('deals', $exception->getMessage());
            self::assertStringContainsString('contacts', $exception->getMessage());
        }
    }

    /**
     * `representativeBinding()`'s empty-bindings branch: `hubspot.models` has no entries at all,
     * so there is no representative binding to name -- distinct from the test above, where a
     * binding exists but does not claim the map's declared object type.
     */
    public function test_a_map_object_with_no_bindings_configured_at_all_throws(): void
    {
        try {
            $this->map([
                'deal_value_seen' => [
                    'object' => 'deals',
                    'properties' => ['deal_value_total' => 'sum:value'],
                ],
            ], [])->validate();

            self::fail('Expected a ConfigurationException for zero configured bindings.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('deal_value_seen', $exception->getMessage());
            self::assertStringContainsString('deals', $exception->getMessage());
            self::assertStringContainsString('(none)', $exception->getMessage());
        }
    }

    public function test_a_map_object_spelled_differently_from_the_binding_still_validates(): void
    {
        $this->expectNotToPerformAssertions();

        $map = $this->map([
            'pricing_page_viewed' => [
                'object' => 'Contacts',
                'properties' => ['pricing_page_views' => 'increment'],
            ],
        ], $this->contactsBinding());

        $map->validate();
    }

    public function test_rules_for_returns_merge_rules_keyed_by_property(): void
    {
        $map = $this->map([
            'pricing_page_viewed' => [
                'object' => 'contacts',
                'properties' => [
                    'pricing_page_views' => 'increment',
                    'first_touch_source' => 'first_wins:source',
                ],
            ],
        ], $this->contactsBinding());

        $rules = $map->rulesFor('pricing_page_viewed');

        self::assertCount(2, $rules);
        self::assertInstanceOf(MergeRule::class, $rules['pricing_page_views']);
        self::assertSame('increment', $rules['pricing_page_views']->verb());
        self::assertInstanceOf(MergeRule::class, $rules['first_touch_source']);
        self::assertSame('first_wins', $rules['first_touch_source']->verb());
        self::assertSame('source', $rules['first_touch_source']->field());
    }

    public function test_names_returns_every_declared_signal_name(): void
    {
        $map = $this->map([
            'pricing_page_viewed' => [
                'object' => 'contacts',
                'properties' => ['pricing_page_views' => 'increment'],
            ],
            'demo_requested' => [
                'object' => 'contacts',
                'properties' => ['intent_score' => IntentScore::class],
            ],
        ], $this->contactsBinding());

        self::assertSame(['pricing_page_viewed', 'demo_requested'], $map->names());
    }

    public function test_two_bindings_of_different_object_types_still_resolve_correctly(): void
    {
        $map = $this->map([
            'pricing_page_viewed' => [
                'object' => 'contacts',
                'properties' => ['pricing_page_views' => 'increment'],
            ],
            'company_page_viewed' => [
                'object' => 'companies',
                'properties' => ['company_page_views' => 'increment'],
            ],
        ], [
            SignalSubject::class => ['object' => 'contacts', 'id_property' => 'email'],
            SignalCompanySubject::class => ['object' => 'companies', 'id_property' => 'domain'],
        ]);

        $map->validate();

        self::assertSame('contacts', $map->objectTypeFor('pricing_page_viewed'));
        self::assertSame('companies', $map->objectTypeFor('company_page_viewed'));
    }
}
