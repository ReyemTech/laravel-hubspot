<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Sync;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Schema;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Facades\Hubspot;
use ReyemTech\Hubspot\Sync\HubspotObjectLink;
use ReyemTech\Hubspot\Sync\ModelBindings;
use ReyemTech\Hubspot\Sync\SyncsToHubspot;
use ReyemTech\Hubspot\Tests\Support\Sync\MultiBindingTestCase;
use ReyemTech\Hubspot\Tests\Support\Sync\NarrowedLead;
use ReyemTech\Hubspot\Tests\Support\Sync\SyncedContact;
use ReyemTech\Hubspot\Tests\Support\Sync\SyncedIntake;
use ReyemTech\Hubspot\Tests\Support\Sync\SyncedLead;

/**
 * # SC2 — three models on one object type, and an API-only type with nothing bound at all.
 *
 * `Lead`, `Contact` and `HealthCheckIntake` all syncing to `contacts` is the originating
 * application's own real shape, and the one tapp's single global `contact_id_column` cannot
 * express. {@see MultiBindingTestCase} binds all three; a shared-row regression here would merge
 * three different people's CRM records into one, which is exactly what the unique index on
 * `(lookup_hash, model_id, object_type)` exists to prevent.
 *
 * The API-only half needs zero new code (D-15) -- `Hubspot::objects()->find()` already works via
 * the Phase 2 gateway with no `hubspot.models` entry at all. Asserting it here is what stops SC2
 * from being a claim nobody checked.
 *
 * The unbound-model half is D-12's inverse: a model that applies `SyncsToHubspot` without a
 * `hubspot.models` entry must throw naming the fix, never silently resolve to a guessed object
 * type. {@see NarrowedLead} is reused for this -- it applies the trait, shares `synced_leads`'s
 * schema (already created by this case), and is deliberately absent from this case's bindings.
 */
mutates(
    SyncsToHubspot::class,
    ModelBindings::class,
    ConfigurationException::class,
);

final class ModelBindingTest extends MultiBindingTestCase
{
    public function test_three_models_bound_to_contacts_resolve_three_distinct_link_rows_and_ids(): void
    {
        Hubspot::fake();

        $lead = SyncedLead::create(['email' => 'lead@example.com', 'first_name' => 'Ada']);
        $contact = SyncedContact::create(['email' => 'contact@example.com', 'last_name' => 'Lovelace']);
        $intake = SyncedIntake::create(['email' => 'intake@example.com']);

        Hubspot::assertRequestCount(3);

        self::assertSame(3, HubspotObjectLink::query()->count());

        self::assertSame('lead@example.com', $lead->hubspotId());
        self::assertSame('contact@example.com', $contact->hubspotId());
        self::assertSame('intake@example.com', $intake->hubspotId());

        // A shared row would make two of these the same value -- the collision the unique index
        // on (lookup_hash, model_id, object_type) exists to prevent.
        self::assertNotSame($lead->hubspotId(), $contact->hubspotId());
        self::assertNotSame($contact->hubspotId(), $intake->hubspotId());
        self::assertNotSame($lead->hubspotId(), $intake->hubspotId());
    }

    public function test_the_three_models_are_distinguished_in_the_link_table_by_model_type(): void
    {
        Hubspot::fake();

        SyncedLead::create(['email' => 'lead@example.com', 'first_name' => 'Ada']);
        SyncedContact::create(['email' => 'contact@example.com', 'last_name' => 'Lovelace']);
        SyncedIntake::create(['email' => 'intake@example.com']);

        /** @var list<string> $modelTypes */
        $modelTypes = HubspotObjectLink::query()->orderBy('id')->pluck('model_type')->all();

        self::assertSame(
            [SyncedLead::class, SyncedContact::class, SyncedIntake::class],
            $modelTypes,
        );

        // Three distinct model classes must never share a lookup_hash -- the digest the unique
        // index actually keys on, not the readable model_type column beside it.
        self::assertSame(3, HubspotObjectLink::query()->distinct()->count('lookup_hash'));
    }

    public function test_an_api_only_object_type_is_usable_with_no_binding_no_model_and_no_table(): void
    {
        Hubspot::fake([
            'line_items' => Hubspot::response(['id' => '501', 'properties' => ['name' => 'Widget']], 200),
        ]);

        $object = Hubspot::objects()->find('line_items', '501');

        self::assertSame('501', $object->id);

        // No binding, no model, no table for line_items: this case's own hubspot.models config
        // names only the three contact-bound classes above.
        /** @var ConfigRepository $config */
        $config = $this->app->make('config');

        /** @var array<class-string, array<string, string>> $models */
        $models = $config->get('hubspot.models', []);

        foreach ($models as $binding) {
            self::assertNotSame('line_items', $binding['object'] ?? null);
        }

        self::assertFalse(Schema::hasTable('line_items'));
    }

    public function test_a_trait_using_model_absent_from_hubspot_models_throws_naming_the_fix(): void
    {
        $model = new NarrowedLead(['email' => 'never-bound@example.com']);

        try {
            $model->hubspotId();

            self::fail('Expected an unbound trait-using model to throw.');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                ConfigurationException::unboundSyncModel(NarrowedLead::class)->getMessage(),
                $exception->getMessage(),
            );
        }
    }
}
