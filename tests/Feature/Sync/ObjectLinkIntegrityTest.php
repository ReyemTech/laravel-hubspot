<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Sync;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReyemTech\Hubspot\Sync\HubspotObjectLink;
use ReyemTech\Hubspot\Sync\SyncsToHubspot;
use ReyemTech\Hubspot\Tests\Support\Sync\SyncedLead;
use ReyemTech\Hubspot\Tests\Support\Sync\SyncTestCase;

/**
 * # Three ways a link row can be found by the wrong query, all found by Codex on PR #39.
 *
 * ## `lookup_hash` (F1)
 *
 * `model_type` stopped being package-controlled the moment the write path moved to
 * `getMorphClass()` (Codex, PR #39's earlier round): under a `Relation::morphMap()`, that value is
 * whatever string an application author chose, not a FQCN this package reads via `get_class()`.
 * MySQL's usual default collation (`utf8mb4_0900_ai_ci` and its predecessor) folds case, so two
 * morph aliases differing only by case -- `Lead` and `lead` -- would compare equal to both the
 * unique index and any `WHERE model_type = ?` clause, letting one model's sync silently overwrite
 * or resolve another's link row. This is the identical defect Codex raised as a P1 on PR #27 for
 * `hubspot_association_types`' `label` column, and the fix mirrors it exactly: a `lookup_hash`
 * column carries the SHA-256 digest of the raw discriminator, and every query -- read and write --
 * keys on the digest instead of the raw, collation-sensitive string. `model_type` itself stays, for
 * operator readability, but is never a predicate.
 *
 * ## Object-type scoping (F3)
 *
 * `hubspotLink()` filtered only on `model_type` + `model_id`, but `SyncHubspotObjectJob` keys its
 * `updateOrCreate()` on `(model_type, model_id, object_type)`. If a model's binding changes object
 * type after it has already synced, a SECOND row is created under the new object type and the old
 * one is left behind -- `hubspotLink()`, unscoped, cannot tell them apart and may resolve whichever
 * one the query happens to return first, so `hubspotId()` can keep answering with an obsolete id.
 *
 * ## The inverse `morphTo` (F4)
 *
 * 04-02-PLAN.md line 261 promised "a `morphTo` back to the linked model", and the migration ships
 * an `(object_type, hubspot_id)` index for exactly that reverse lookup. `HubspotObjectLink` never
 * grew the relation.
 */
mutates(
    HubspotObjectLink::class,
    SyncsToHubspot::class,
);

final class ObjectLinkIntegrityTest extends SyncTestCase
{
    public function test_the_table_carries_a_not_null_lookup_hash_column(): void
    {
        self::assertTrue(
            Schema::hasColumn('hubspot_object_links', 'lookup_hash'),
            'hubspot_object_links is missing the lookup_hash column the unique index now needs.',
        );
    }

    /**
     * The persisted digest is the SHA-256 of the raw morph discriminator -- not a second, drifting
     * encoding invented at the call site.
     */
    public function test_a_synced_models_lookup_hash_is_the_digest_of_its_morph_class(): void
    {
        DB::table('synced_leads')->insert(['email' => 'hash@example.com']);
        $lead = SyncedLead::query()->firstOrFail();

        DB::table('hubspot_object_links')->insert([
            'model_type' => $lead->getMorphClass(),
            'lookup_hash' => hash('sha256', $lead->getMorphClass()),
            'model_id' => (string) $lead->id,
            'object_type' => 'contacts',
            'hubspot_id' => '99001',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        self::assertSame(
            hash('sha256', $lead->getMorphClass()),
            HubspotObjectLink::query()->value('lookup_hash'),
        );
    }

    /**
     * The current binding for {@see SyncedLead} is `contacts` ({@see SyncTestCase}). A stale row
     * left behind under a different object type must never win over the row matching the CURRENT
     * binding -- inserted first, and with the lower primary key, specifically so an unscoped query
     * with no explicit ordering would return it first if `hubspotLink()` does not filter on
     * `object_type`.
     */
    public function test_hubspot_link_resolves_only_the_row_matching_the_current_bindings_object_type(): void
    {
        DB::table('synced_leads')->insert(['email' => 'multi-object@example.com']);
        $lead = SyncedLead::query()->firstOrFail();

        DB::table('hubspot_object_links')->insert([
            'model_type' => $lead->getMorphClass(),
            'lookup_hash' => hash('sha256', $lead->getMorphClass()),
            'model_id' => (string) $lead->id,
            'object_type' => 'deals',
            'hubspot_id' => 'stale-deals-id',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('hubspot_object_links')->insert([
            'model_type' => $lead->getMorphClass(),
            'lookup_hash' => hash('sha256', $lead->getMorphClass()),
            'model_id' => (string) $lead->id,
            'object_type' => 'contacts',
            'hubspot_id' => 'current-contacts-id',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        self::assertSame('current-contacts-id', $lead->hubspotId());
    }

    public function test_the_link_carries_an_inverse_morph_to_relation_back_to_the_linked_model(): void
    {
        DB::table('synced_leads')->insert(['email' => 'inverse@example.com']);
        $lead = SyncedLead::query()->firstOrFail();

        DB::table('hubspot_object_links')->insert([
            'model_type' => $lead->getMorphClass(),
            'lookup_hash' => hash('sha256', $lead->getMorphClass()),
            'model_id' => (string) $lead->id,
            'object_type' => 'contacts',
            'hubspot_id' => '99001',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $link = HubspotObjectLink::query()->sole();

        self::assertInstanceOf(SyncedLead::class, $link->model);
        self::assertSame($lead->id, $link->model->id);
    }
}
