<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Sync;

use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use ReyemTech\Hubspot\Facades\Hubspot;
use ReyemTech\Hubspot\Gateway\HubspotClientFactory;
use ReyemTech\Hubspot\HubspotManager;
use ReyemTech\Hubspot\Tests\Support\Sync\SyncedLead;
use ReyemTech\Hubspot\Tests\Support\Sync\SyncTestCase;

/**
 * # The package survives a worker that outlives the request (issue #55)
 *
 * On PHP-FPM a process handles one request and dies, so a container singleton cannot leak into
 * anything. Octane keeps the worker alive across many requests, and `HubspotManager` is a singleton
 * with two mutable properties -- so without this, a `withoutSyncing()` block left open by a fatal,
 * or a fake installed by one request, would answer for every later request that worker served.
 *
 * A silently dropped sync is the worst failure this package has: nothing downstream reports it.
 *
 * ## Why the events are named as strings
 *
 * `laravel/octane` is not a dependency and cannot become one -- D-03's vendor allow-list admits
 * `php`, `hubspot/api-client`, `illuminate/*` and `laravel/prompts`. Laravel's dispatcher keys
 * listeners on the event's class NAME, so a string registration fires for Octane's real event object
 * and costs nothing when Octane is absent. These tests dispatch by the same string, which is exactly
 * what the dispatcher would do with the real object.
 */
final class OctaneStateResetTest extends SyncTestCase
{
    /**
     * @return list<array{string}>
     */
    public static function octaneBoundaries(): array
    {
        return [
            ['Laravel\Octane\Events\RequestTerminated'],
            ['Laravel\Octane\Events\TaskTerminated'],
            ['Laravel\Octane\Events\TickTerminated'],
        ];
    }

    /**
     * Every entry point, not just requests. Octane runs tasks and ticks in the same long-lived
     * process, and a tick that ran with suppression left on would be as silent as a request that did.
     */
    #[DataProvider('octaneBoundaries')]
    public function test_an_octane_boundary_clears_suppression_left_behind(string $event): void
    {
        $manager = app(HubspotManager::class);

        // Left open the way a fatal inside the callback would leave it, rather than by calling
        // withoutSyncing() -- whose own `finally` is what makes that impossible in a single flow.
        // The state under test is the one a NEXT request inherits, however it got there.
        (function (): void {
            $this->syncingSuppressed = true;
        })->call($manager);

        self::assertTrue($manager->syncingSuppressed(), 'The fixture must actually leave it open.');

        Event::dispatch($event);

        self::assertFalse(
            $manager->syncingSuppressed(),
            'A request inheriting an open suppression block silently drops every sync it makes.'
        );
    }

    /**
     * `$fake` is reset by the same boundary, and for the same reason. It has the identical
     * process-wide shape and has had it since 02-xx; resetting only the newer property would have
     * been arbitrary, and would leave one request's fake answering for the next.
     */
    public function test_an_octane_boundary_clears_a_fake_left_behind(): void
    {
        Hubspot::fake();

        $manager = app(HubspotManager::class);
        self::assertTrue($manager->isFaked());

        Event::dispatch('Laravel\Octane\Events\RequestTerminated');

        self::assertFalse(
            $manager->isFaked(),
            'A fake surviving the request that installed it makes every later request in that '
            .'worker assert against a transport nobody asked for.'
        );
    }

    /**
     * The TRANSPORT is reset, not just the flag that describes it (Codex, PR #56).
     *
     * `HubspotFake` replaces the container's `HubspotClientFactory` singleton, so clearing only the
     * `$fake` property would leave the next request reading `isFaked() === false` while every
     * gateway it resolved still answered from the previous request's mock. Asserting the flag alone
     * would pass against exactly that bug, which is why this asserts the container binding.
     */
    public function test_an_octane_boundary_puts_the_real_transport_back(): void
    {
        // A token, so that the REAL factory can actually be built after the flush. Without one it
        // throws ConfigurationException on construction -- which is itself evidence the fake-backed
        // instance is gone, but proves it by accident rather than by assertion.
        config()->set('hubspot.token', 'pat-na1-test-token');

        Hubspot::fake();

        $fakeBackedFactory = app(HubspotClientFactory::class);

        Event::dispatch('Laravel\Octane\Events\RequestTerminated');

        self::assertNotSame(
            $fakeBackedFactory,
            app(HubspotClientFactory::class),
            'A fake-backed factory surviving the boundary makes the next request answer from canned '
            .'responses while reporting that no fake is installed -- inconsistent, not merely stale.'
        );
    }

    /**
     * A boundary with NO fake installed leaves the transport alone (Codex, PR #56).
     *
     * An application may bind its own `HubspotClientFactory` at boot through the public
     * `forTransport()` seam -- a proxy, a recording transport, a shared Guzzle handler. Forgetting
     * the binding unconditionally would discard that at every request, task and tick, and silently
     * fall back to the config-built client. The reset exists to undo what a FAKE did; when no fake
     * was installed there is nothing to undo.
     */
    public function test_an_octane_boundary_leaves_a_custom_transport_alone(): void
    {
        config()->set('hubspot.token', 'pat-na1-test-token');

        $custom = app(HubspotClientFactory::class);

        Event::dispatch('Laravel\Octane\Events\RequestTerminated');

        self::assertSame(
            $custom,
            app(HubspotClientFactory::class),
            'An application that bound its own transport at boot must still have it after a '
            .'boundary that had no fake to clean up.'
        );
    }

    /**
     * A fake installed OVER an application's own transport puts that transport back, not the
     * config-built default (Codex, PR #56).
     *
     * This is the case the `$hadFake` guard alone did not cover: it stopped the reset from running
     * when no fake was involved, but when one WAS, `forgetInstance()` still discarded whatever the
     * application had bound at boot. The next request then silently used a different transport.
     */
    public function test_a_boundary_puts_back_the_transport_a_fake_replaced(): void
    {
        config()->set('hubspot.token', 'pat-na1-test-token');

        $custom = HubspotClientFactory::fromConfig('pat-na1-custom', 5.0, 2.0, false);
        app()->instance(HubspotClientFactory::class, $custom);

        Hubspot::fake();

        self::assertNotSame($custom, app(HubspotClientFactory::class), 'The fake must have replaced it.');

        Event::dispatch('Laravel\Octane\Events\RequestTerminated');

        self::assertSame(
            $custom,
            app(HubspotClientFactory::class),
            'The application chose that transport; cleaning up a fake must not substitute another.'
        );
    }

    /**
     * State prepared FOR a request survives into it (Codex, PR #56).
     *
     * The reset listens on `*Terminated` and never on `*Received`, and this is why. An application
     * or a test that installs `Hubspot::fake()` during boot -- or immediately before sending a
     * request -- would otherwise have it flushed before the request ran. In the testing environment
     * the consequence is silent and total: `SyncGate` suppresses every sync because no fake is
     * bound, and the assertions afterwards report that none ever was.
     *
     * `RequestReceived` is dispatched here deliberately. It must be a no-op.
     */
    public function test_a_fake_prepared_before_a_request_survives_into_it(): void
    {
        Hubspot::fake();

        Event::dispatch('Laravel\Octane\Events\RequestReceived');

        self::assertTrue(
            app(HubspotManager::class)->isFaked(),
            'A fake installed for the incoming request must still be there when it runs.'
        );

        SyncedLead::create(['email' => 'preparedfake@example.com', 'first_name' => 'Ada']);

        Hubspot::assertRequestCount(1);
    }

    /**
     * The consequence, rather than the flag: a model created in the "next request" syncs normally
     * once the boundary has cleared what the previous one left behind.
     */
    public function test_syncing_works_again_in_the_request_after_the_boundary(): void
    {
        $manager = app(HubspotManager::class);

        (function (): void {
            $this->syncingSuppressed = true;
        })->call($manager);

        Event::dispatch('Laravel\Octane\Events\RequestTerminated');

        // The "next request" installs its own fake, exactly as the first one would have.
        Hubspot::fake();

        SyncedLead::create(['email' => 'nextrequest@example.com', 'first_name' => 'Ada']);

        Hubspot::assertRequestCount(1);
    }
}
