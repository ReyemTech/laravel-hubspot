<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Unit\Sync;

use ReyemTech\Hubspot\Sync\PropertyMapper;
use ReyemTech\Hubspot\Tests\Support\Sync\MappedDeal;
use ReyemTech\Hubspot\Tests\Support\Sync\MappedStage;
use ReyemTech\Hubspot\Tests\TestCase;

/**
 * `PropertyMapper::map()`'s three resolution forms, every edge each one has, and
 * `mapForUpdate()`'s map-selection rule (SYNC-02, 04-03).
 *
 * Every bullet is its own named test rather than a branch of one, since this class is the
 * phase's densest boolean surface and the mutation floor rewards one assertion per behaviour.
 *
 * Dispatch is on the map VALUE's own shape (`Closure` vs. anything else -- a `data_get()` path),
 * never on the key or on whether a string contains a dot: the literal-attribute and dot-notation
 * forms are therefore the SAME code path through `map()`, exercised here by a single-segment vs.
 * a multi-segment path string rather than by two different branches of production code.
 *
 * The null-omits-the-key rule, recorded here because this is where this package's convention is
 * decided: at this layer a null traversed relation and a deliberate clear are indistinguishable,
 * and silently clearing a live CRM property is the worse failure (04-CONTEXT.md T-04-11) -- so a
 * `null` resolved value OMITS its key from the bag rather than being sent, while an empty string
 * is not null and is sent verbatim, which is how a consumer deliberately blanks a property.
 */
mutates(PropertyMapper::class);

final class PropertyMapperTest extends TestCase
{
    public function test_a_literal_attribute_resolves_its_value(): void
    {
        $deal = new MappedDeal(['title' => 'Acme Deal']);

        $properties = (new PropertyMapper)->map($deal, ['dealname' => 'title']);

        self::assertSame(['dealname' => 'Acme Deal'], $properties);
    }

    public function test_a_dot_notation_path_resolves_across_a_relation(): void
    {
        $stage = new MappedStage(['name' => 'Discovery']);
        $deal = new MappedDeal(['title' => 'Acme Deal']);
        $deal->setRelation('stage', $stage);

        $properties = (new PropertyMapper)->map($deal, ['dealstage' => 'stage.name']);

        self::assertSame(['dealstage' => 'Discovery'], $properties);
    }

    /**
     * A typed closure parameter is what makes this test meaningful: if `map()` ever passed an
     * attribute bag (or anything else) instead of the model instance itself, this closure would
     * fatal on the type mismatch rather than merely returning a wrong value.
     */
    public function test_a_closure_receives_the_model_instance_and_its_return_value_is_used_verbatim(): void
    {
        $deal = new MappedDeal(['title' => 'acme deal']);

        $properties = (new PropertyMapper)->map($deal, [
            'dealname' => static fn (MappedDeal $model): string => strtoupper((string) $model->title),
        ]);

        self::assertSame(['dealname' => 'ACME DEAL'], $properties);
    }

    /**
     * The other key (`dealname`) is asserted alongside the omitted one (`dealstage`) so this test
     * proves the OMISSION is scoped to the one null-resolving entry, not that resolution stopped
     * entirely the moment one entry came back null.
     */
    public function test_a_dot_notation_path_across_a_null_relation_omits_the_key(): void
    {
        $deal = new MappedDeal(['title' => 'Acme Deal']);
        $deal->setRelation('stage', null);

        $properties = (new PropertyMapper)->map($deal, [
            'dealstage' => 'stage.name',
            'dealname' => 'title',
        ]);

        self::assertSame(['dealname' => 'Acme Deal'], $properties);
    }

    public function test_a_closure_returning_null_omits_the_key(): void
    {
        $deal = new MappedDeal(['title' => 'Acme Deal']);

        $properties = (new PropertyMapper)->map($deal, [
            'dealname' => static fn (): ?string => null,
        ]);

        self::assertSame([], $properties);
    }

    public function test_an_empty_string_is_sent_verbatim_not_treated_as_null(): void
    {
        $deal = new MappedDeal(['title' => '']);

        $properties = (new PropertyMapper)->map($deal, ['dealname' => 'title']);

        self::assertSame(['dealname' => ''], $properties);
    }

    public function test_an_empty_map_produces_an_empty_bag(): void
    {
        $deal = new MappedDeal(['title' => 'Acme Deal']);

        $properties = (new PropertyMapper)->map($deal, []);

        self::assertSame([], $properties);
    }

    public function test_a_non_string_scalar_attribute_value_is_cast_to_a_string(): void
    {
        $deal = new MappedDeal(['title' => 'Acme Deal', 'amount' => 4200]);

        $properties = (new PropertyMapper)->map($deal, ['amount' => 'amount']);

        self::assertSame(['amount' => '4200'], $properties);
    }

    public function test_the_update_map_replaces_the_map_when_the_model_declares_one(): void
    {
        $deal = new MappedDeal(['title' => 'Acme Deal', 'amount' => 4200]);

        $properties = (new PropertyMapper)->mapForUpdate(
            $deal,
            ['dealname' => 'title', 'amount' => 'amount'],
            ['amount' => 'amount'],
        );

        self::assertSame(['amount' => '4200'], $properties);
    }

    public function test_the_update_map_falls_back_to_the_map_when_the_model_declares_none(): void
    {
        $deal = new MappedDeal(['title' => 'Acme Deal', 'amount' => 4200]);

        $properties = (new PropertyMapper)->mapForUpdate(
            $deal,
            ['dealname' => 'title', 'amount' => 'amount'],
            [],
        );

        self::assertSame(['dealname' => 'Acme Deal', 'amount' => '4200'], $properties);
    }
}
