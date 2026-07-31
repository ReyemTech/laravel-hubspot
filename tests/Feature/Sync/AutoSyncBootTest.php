<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Sync;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use ReyemTech\Hubspot\Facades\Hubspot;
use ReyemTech\Hubspot\Sync\HubspotObserver;
use ReyemTech\Hubspot\Sync\SyncHubspotObjectJob;
use ReyemTech\Hubspot\Tests\Support\Sync\DisabledAutoSyncLead;
use ReyemTech\Hubspot\Tests\Support\Sync\NarrowedAutoSyncLead;
use ReyemTech\Hubspot\Tests\Support\Sync\SoftDeletingLead;
use ReyemTech\Hubspot\Tests\Support\Sync\SyncedLead;
use ReyemTech\Hubspot\Tests\Support\Sync\SyncTestCase;

/**
 * # The auto-sync event surface, and the three gates that decide whether an event reaches HubSpot
 *
 * SYNC-03b. The provider's own boot is the whole registration -- nothing goes in the consumer's
 * `AppServiceProvider` -- and from there three independent switches decide whether a given model
 * event dispatches: `auto_sync.enabled`, membership in `auto_sync.on`, and the per-model
 * `$hubspotAutoSync` override.
 *
 * ## Why the gates are tested one operand at a time
 *
 * A single test that turns all three off proves nothing about any of them: it passes just as
 * happily against a gate that consults only the first, or against one that has stopped consulting
 * anything and always refuses. Each test below therefore flips exactly ONE operand and leaves the
 * other two at values that would otherwise allow the dispatch (T-04-20). That is also what the
 * mutation floor measures -- the boolean chain in the gate is this phase's densest mutation target.
 *
 * ## Why zero-HTTP is asserted alongside the dispatch
 *
 * STANDARDS §11 has claimed "no API call in a request lifecycle" in the present tense since Phase 1.
 * Asserting only that a job was pushed would pass against an implementation that pushed the job AND
 * called the API synchronously, which is exactly the regression that claim exists to prevent
 * (T-04-19). So the request-lifecycle test asserts both halves at once.
 */
mutates(HubspotObserver::class);

final class AutoSyncBootTest extends SyncTestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        /** @var ConfigRepository $config */
        $config = $app->make('config');

        // Extends rather than replaces the parent's single binding: SyncedLead stays the model with
        // no override, so every override test has an un-overridden model to be compared against.
        $config->set('hubspot.models', [
            SyncedLead::class => ['object' => 'contacts', 'id_property' => 'email'],
            SoftDeletingLead::class => ['object' => 'contacts', 'id_property' => 'email'],
            NarrowedAutoSyncLead::class => ['object' => 'contacts', 'id_property' => 'email'],
            DisabledAutoSyncLead::class => ['object' => 'contacts', 'id_property' => 'email'],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('soft_deleting_leads', function (Blueprint $table): void {
            $table->id();
            $table->string('email');
            $table->string('first_name')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        foreach (['narrowed_auto_sync_leads', 'disabled_auto_sync_leads'] as $table) {
            Schema::create($table, function (Blueprint $blueprint): void {
                $blueprint->id();
                $blueprint->string('email');
                $blueprint->timestamps();
            });
        }
    }

    /**
     * The registration contract: a consumer installs the package, adds a `hubspot.models` entry,
     * and creating a model syncs. This Testbench application registers nothing of its own beyond
     * the package provider, which is what makes the assertion mean "the provider's boot is the
     * whole registration" rather than "something, somewhere, attached an observer".
     */
    public function test_a_bound_model_dispatches_on_created_with_nothing_in_the_consumer_app(): void
    {
        Bus::fake();

        SyncedLead::create(['email' => 'created@example.com', 'first_name' => 'Ada']);

        Bus::assertDispatched(SyncHubspotObjectJob::class);
    }

    public function test_updated_dispatches_when_it_is_listed_in_auto_sync_on(): void
    {
        Hubspot::fake();

        $lead = SyncedLead::create(['email' => 'updated@example.com', 'first_name' => 'Ada']);

        Bus::fake();

        $lead->update(['first_name' => 'Grace']);

        Bus::assertDispatched(SyncHubspotObjectJob::class);
    }

    /**
     * Gate 1 of 3, flipped alone: `auto_sync.on` keeps its default (which contains `created`), and
     * the model declares no override, so `enabled` is the only reason nothing may dispatch.
     */
    public function test_auto_sync_enabled_false_alone_stops_the_dispatch(): void
    {
        config()->set('hubspot.auto_sync.enabled', false);

        Bus::fake();

        SyncedLead::create(['email' => 'disabled@example.com', 'first_name' => 'Ada']);

        Bus::assertNotDispatched(SyncHubspotObjectJob::class);
    }

    /**
     * Gate 2 of 3, flipped alone: `enabled` stays true and the model declares no override, so the
     * event's absence from `auto_sync.on` is the only reason nothing may dispatch.
     */
    public function test_an_event_absent_from_auto_sync_on_alone_stops_the_dispatch(): void
    {
        Hubspot::fake();

        $lead = SyncedLead::create(['email' => 'not-listed@example.com', 'first_name' => 'Ada']);

        config()->set('hubspot.auto_sync.on', ['created']);

        Bus::fake();

        $lead->update(['first_name' => 'Grace']);

        Bus::assertNotDispatched(SyncHubspotObjectJob::class);
    }

    /**
     * Gate 3 of 3, flipped alone, in its narrowing form. `auto_sync.on` deliberately keeps BOTH
     * events and `enabled` stays true, so the ONLY thing that can stop the update is the model's own
     * `$hubspotAutoSync = ['created']`. The created half is asserted in the same test, because a
     * gate that refused everything for this model would otherwise pass the half that matters.
     */
    public function test_a_per_model_array_override_alone_narrows_to_its_listed_events(): void
    {
        Hubspot::fake();

        Bus::fake();

        $lead = NarrowedAutoSyncLead::create(['email' => 'narrowed@example.com']);

        Bus::assertDispatched(SyncHubspotObjectJob::class);

        Bus::fake();

        $lead->update(['email' => 'narrowed-again@example.com']);

        Bus::assertNotDispatched(SyncHubspotObjectJob::class);
    }

    /**
     * The same gate in its `false` form, paired with a model that does sync in the same run --
     * "nothing was dispatched" is evidence of an opt-out only if something else dispatched.
     */
    public function test_a_per_model_false_override_disables_only_that_model(): void
    {
        Bus::fake();

        DisabledAutoSyncLead::create(['email' => 'opted-out@example.com']);

        Bus::assertNotDispatched(SyncHubspotObjectJob::class);

        SyncedLead::create(['email' => 'still-syncs@example.com', 'first_name' => 'Ada']);

        Bus::assertDispatched(SyncHubspotObjectJob::class);
    }

    /**
     * T-04-19, and the assertion STANDARDS §11 has been making in prose since Phase 1. Both halves
     * in one test on purpose: the job was pushed, AND zero HTTP requests happened while the model
     * event was being handled.
     */
    public function test_a_model_event_pushes_a_job_and_issues_no_http_request(): void
    {
        Hubspot::fake();
        Bus::fake();

        SyncedLead::create(['email' => 'no-http@example.com', 'first_name' => 'Ada']);

        Bus::assertDispatched(SyncHubspotObjectJob::class);
        Hubspot::assertRequestCount(0);
    }

    /**
     * D-17. `SoftDeletes::restore()` calls `save()` internally, so Eloquent fires `updated` BEFORE
     * `restored` -- an unguarded `updated` handler therefore costs a property push on every restore,
     * on top of whatever the `restored` handler (04-06) does, for two API calls where one was
     * intended.
     *
     * `getOriginal('deleted_at')` is the only signal stock Eloquent exposes for this: during the
     * restore's save the attribute is being set to null while its ORIGINAL value is still the delete
     * timestamp, which is what distinguishes this save from any other update.
     *
     * The fixture declares no `$hubspotAutoSync`, so `updated` is genuinely enabled for it -- on a
     * model that had opted out, this test would pass while proving nothing.
     */
    public function test_restoring_a_soft_deleted_model_dispatches_no_property_push(): void
    {
        Hubspot::fake();

        $lead = SoftDeletingLead::create(['email' => 'restored@example.com', 'first_name' => 'Ada']);
        $lead->delete();

        Bus::fake();

        $lead->restore();

        Bus::assertNotDispatched(SyncHubspotObjectJob::class);
    }
}
