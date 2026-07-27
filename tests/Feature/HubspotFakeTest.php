<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature;

use ReyemTech\Hubspot\Facades\Hubspot;
use ReyemTech\Hubspot\Gateway\AssociationPair;
use ReyemTech\Hubspot\Gateway\ObjectRef;
use ReyemTech\Hubspot\Gateway\SearchQuery;
use ReyemTech\Hubspot\HubspotManager;
use ReyemTech\Hubspot\Testing\HubspotFake;
use ReyemTech\Hubspot\Testing\RecordedRequest;
use ReyemTech\Hubspot\Testing\RequestLog;
use ReyemTech\Hubspot\Tests\Support\FailedAssertion;
use ReyemTech\Hubspot\Tests\TestCase;
use RuntimeException;

/**
 * # The consumer-facing assertion surface: what a test written against `Hubspot::fake()` can prove.
 *
 * Every assertion here reads the **`Middleware::history()` request log** installed in plan 02-01 — one
 * data source, and deliberately no second one. A gateway that kept its own record of what it "synced"
 * could drift from what it actually sent, and the entire value of this double is that it observes the
 * wire rather than the intention. Writes are told from reads by the recorded HTTP method and path, not
 * by asking a gateway to declare its own intent, so a gateway that issues an unexpected extra request
 * cannot hide it behind its own bookkeeping.
 *
 * ## Why every failure message is asserted in full
 *
 * A failed `assertSynced` states what was written instead; a failed `assertNothingSynced` states what
 * was written at all; a failed `assertRequestCount` states both the expected and the actual count. That
 * is a requirement of this plan and not a nicety: without it, the first thing every developer does on a
 * red run is add the debugging output the assertion should have produced. Those messages are therefore
 * pinned with {@see FailedAssertion::messageOf()} as **exact** strings rather than sampled by
 * substring — see that class for the 31-survivor argument behind the distinction.
 *
 * ## Why the no-fake case is tested for every assertion
 *
 * An assertion called when `fake()` was never installed must fail loudly, naming `fake()`. A vacuous
 * pass there would let an entire test file assert nothing at all while reporting green — threat
 * T-02-17, and the single cheapest way for this whole surface to become worthless.
 */
mutates(
    HubspotFake::class,
    RequestLog::class,
    RecordedRequest::class,
    HubspotManager::class,
);

final class HubspotFakeTest extends TestCase
{
    public function test_assert_synced_passes_after_a_create_of_that_object_type(): void
    {
        Hubspot::fake();

        Hubspot::objects()->create('deals', ['dealname' => 'Acme']);

        Hubspot::assertSynced('deals');
    }

    public function test_assert_synced_passes_after_an_update_of_that_object_type(): void
    {
        Hubspot::fake();

        Hubspot::objects()->update('deals', '7', ['dealname' => 'Acme']);

        Hubspot::assertSynced('deals');
    }

    /**
     * A batch write is a sync of every record in it. The batch route is a different path shape
     * (`/objects/deals/batch/create`) and would be missed by a naive exact-path match on
     * `/objects/deals`, which is exactly the N+1-shaped blind spot `assertRequestCount` exists to
     * prevent (STANDARDS §11).
     */
    public function test_assert_synced_passes_after_a_batch_create_of_that_object_type(): void
    {
        Hubspot::fake();

        Hubspot::objects()->createMany('deals', [['dealname' => 'One'], ['dealname' => 'Two']]);

        Hubspot::assertSynced('deals');
        Hubspot::assertRequestCount(1);
    }

    /**
     * A batch write submits several records in **one** request, and a property assertion has to be able
     * to find its record in any of them. An implementation that read only the top-level `properties`
     * key — the shape a single create sends — would report every batch-written property as absent, which
     * is the assertion failing for precisely the request shape STANDARDS §11 requires the package to use.
     */
    public function test_assert_synced_finds_a_property_in_any_record_of_a_batch_write(): void
    {
        Hubspot::fake();

        Hubspot::objects()->createMany('deals', [['dealname' => 'One'], ['dealname' => 'Two']]);

        Hubspot::assertSynced('deals', ['dealname' => 'One']);
        Hubspot::assertSynced('deals', ['dealname' => 'Two']);

        self::assertSame(
            "Expected HubSpot to have synced object type 'deals' with property 'dealname' => \"Three\" (string), "
            .'but the value(s) recorded for it were: "One" (string), "Two" (string).',
            FailedAssertion::messageOf(static fn () => Hubspot::assertSynced('deals', ['dealname' => 'Three'])),
        );
    }

    /**
     * The object type is matched on a path BOUNDARY, not as a prefix: a write to `deals` must not
     * satisfy an assertion about `deal`, and a write to `line_items` must not satisfy one about `line`.
     * A prefix match here would make the assertion pass for a record of a type nobody wrote.
     */
    public function test_assert_synced_matches_the_object_type_on_a_path_boundary_not_as_a_prefix(): void
    {
        Hubspot::fake();

        Hubspot::objects()->create('deals', ['dealname' => 'Acme']);

        self::assertSame(
            "Expected HubSpot to have synced object type 'deal', but no write of that type was recorded. "
            .'Writes recorded: POST /crm/v3/objects/deals.',
            FailedAssertion::messageOf(static fn () => Hubspot::assertSynced('deal')),
        );
    }

    public function test_assert_synced_fails_listing_the_object_type_that_was_actually_written(): void
    {
        Hubspot::fake();

        Hubspot::objects()->create('deals', ['dealname' => 'Acme']);

        $message = FailedAssertion::messageOf(static fn () => Hubspot::assertSynced('contacts'));

        // Exact, not a substring: the message is a dozen concatenated fragments and a substring check
        // cannot tell a correct one from a reordered or truncated one.
        self::assertSame(
            "Expected HubSpot to have synced object type 'contacts', but no write of that type was recorded. "
            .'Writes recorded: POST /crm/v3/objects/deals.',
            $message,
        );

        // Stated separately as the CONTRACT the exact assertion above happens to pin: a failed
        // assertSynced names what was written instead. If the wording is ever reworked, this is the
        // requirement that must survive the rewording.
        self::assertStringContainsString('/objects/deals', $message);
    }

    public function test_assert_synced_fails_naming_the_only_traffic_when_that_traffic_was_a_read(): void
    {
        Hubspot::fake();

        Hubspot::objects()->find('deals', '7');

        self::assertSame(
            "Expected HubSpot to have synced object type 'deals', but no write of that type was recorded. "
            .'No write was recorded; the only traffic was: GET /crm/v3/objects/deals/7.',
            FailedAssertion::messageOf(static fn () => Hubspot::assertSynced('deals')),
        );
    }

    public function test_assert_synced_fails_saying_so_plainly_when_no_request_was_made_at_all(): void
    {
        Hubspot::fake();

        self::assertSame(
            "Expected HubSpot to have synced object type 'deals', but no write of that type was recorded. "
            .'No request was recorded at all.',
            FailedAssertion::messageOf(static fn () => Hubspot::assertSynced('deals')),
        );
    }

    public function test_assert_synced_matches_a_property_subset_read_from_the_recorded_request_body(): void
    {
        Hubspot::fake();

        Hubspot::objects()->create('deals', ['dealname' => 'Acme', 'amount' => '100', 'pipeline' => 'default']);

        // A subset, not the whole set: a consumer asserting one property must not have to restate
        // every property the package happens to send alongside it.
        Hubspot::assertSynced('deals', ['amount' => '100']);
        Hubspot::assertSynced('deals', ['amount' => '100', 'dealname' => 'Acme']);
    }

    /**
     * Every property HubSpot returns and accepts is a **string**, including numeric and boolean ones.
     * A loose comparison would report success for a package that sent the integer `100` where the
     * string `'100'` belonged, and the whole justification for `declare(strict_types=1)` here
     * (STANDARDS §4) is that those two are not the same value. The message therefore names the type of
     * both sides, because `100` and `"100"` are indistinguishable in a message that prints only the
     * value.
     */
    public function test_assert_synced_compares_property_values_strictly_and_names_both_types(): void
    {
        Hubspot::fake();

        Hubspot::objects()->create('deals', ['amount' => '100']);

        self::assertSame(
            "Expected HubSpot to have synced object type 'deals' with property 'amount' => 100 (int), "
            .'but the value(s) recorded for it were: "100" (string).',
            FailedAssertion::messageOf(static fn () => Hubspot::assertSynced('deals', ['amount' => 100])),
        );
    }

    public function test_assert_synced_fails_naming_the_property_that_was_not_written_at_all(): void
    {
        Hubspot::fake();

        Hubspot::objects()->create('deals', ['dealname' => 'Acme']);

        self::assertSame(
            "Expected HubSpot to have synced object type 'deals' with property 'amount' => \"100\" (string), "
            .'but the value(s) recorded for it were: not written.',
            FailedAssertion::messageOf(static fn () => Hubspot::assertSynced('deals', ['amount' => '100'])),
        );
    }

    /**
     * The first expected property that no written record satisfies is the one named. Reporting only
     * "the properties did not match" would send the reader diffing two arrays by eye, which is the
     * work the assertion exists to have already done.
     */
    public function test_assert_synced_names_the_first_property_that_no_written_record_satisfies(): void
    {
        Hubspot::fake();

        Hubspot::objects()->create('deals', ['dealname' => 'Acme', 'amount' => '100']);

        self::assertSame(
            "Expected HubSpot to have synced object type 'deals' with property 'pipeline' => \"default\" (string), "
            .'but the value(s) recorded for it were: not written.',
            FailedAssertion::messageOf(
                static fn () => Hubspot::assertSynced('deals', ['dealname' => 'Acme', 'pipeline' => 'default']),
            ),
        );
    }

    /**
     * Several writes of one object type are searched, not just the first. A package that syncs a
     * collection issues one batch request per type but may legitimately issue several single writes,
     * and an assertion that only inspected the first would fail for a record that was genuinely
     * written.
     */
    public function test_assert_synced_searches_every_written_record_not_only_the_first(): void
    {
        Hubspot::fake();

        Hubspot::objects()->create('deals', ['dealname' => 'One']);
        Hubspot::objects()->create('deals', ['dealname' => 'Two']);

        Hubspot::assertSynced('deals', ['dealname' => 'Two']);

        self::assertSame(
            "Expected HubSpot to have synced object type 'deals' with property 'dealname' => \"Three\" (string), "
            .'but the value(s) recorded for it were: "One" (string), "Two" (string).',
            FailedAssertion::messageOf(static fn () => Hubspot::assertSynced('deals', ['dealname' => 'Three'])),
        );
    }

    public function test_assert_nothing_synced_passes_when_no_request_was_made(): void
    {
        Hubspot::fake();

        Hubspot::assertNothingSynced();
    }

    /**
     * **A read is not a sync.** Three shapes of read, all of which a method-only classification would
     * get wrong: `find()` is a GET, but `search()` and `findMany()` are both POSTs, because HubSpot
     * takes their query in a request body. Counting either of those as a write would make
     * `assertNothingSynced` fail for a package that read and wrote nothing — and a consumer whose
     * assertion fires spuriously deletes the assertion.
     */
    public function test_a_read_is_not_a_sync_even_when_hubspot_takes_it_as_a_post(): void
    {
        Hubspot::fake();

        Hubspot::objects()->find('deals', '7');
        Hubspot::objects()->search('deals', SearchQuery::make());
        Hubspot::objects()->findMany('deals', ['7', '8']);

        Hubspot::assertNothingSynced();
        Hubspot::assertRequestCount(3);
    }

    public function test_assert_nothing_synced_fails_naming_what_was_written(): void
    {
        Hubspot::fake();

        Hubspot::objects()->create('deals', ['dealname' => 'Acme']);

        $message = FailedAssertion::messageOf(static fn () => Hubspot::assertNothingSynced());

        self::assertSame(
            'Expected HubSpot to have synced nothing, but 1 write(s) were recorded: POST /crm/v3/objects/deals.',
            $message,
        );

        // The contract, stated where the exact assertion above cannot state it: the message says what
        // was written, not merely that something was.
        self::assertStringContainsString('POST /crm/v3/objects/deals', $message);
    }

    /**
     * An archive is a write. It sends no body at all, so an implementation that classified writes by
     * "has a properties payload" would miss the one request in this package that destroys data.
     */
    public function test_an_archive_and_a_batch_archive_both_count_as_writes(): void
    {
        Hubspot::fake();

        Hubspot::objects()->archive('deals', '7');
        Hubspot::objects()->archiveMany('deals', ['8', '9']);

        self::assertSame(
            'Expected HubSpot to have synced nothing, but 2 write(s) were recorded: '
            .'DELETE /crm/v3/objects/deals/7; POST /crm/v3/objects/deals/batch/archive.',
            FailedAssertion::messageOf(static fn () => Hubspot::assertNothingSynced()),
        );

        // And an archive of `deals` IS a sync of `deals` for the purposes of assertSynced: the record
        // was written to. Asserting a property against it finds none, which is the honest answer — a
        // write with no body at all reports "not written" rather than raising on the absent payload.
        Hubspot::assertSynced('deals');

        self::assertSame(
            "Expected HubSpot to have synced object type 'deals' with property 'dealname' => \"Acme\" (string), "
            .'but the value(s) recorded for it were: not written.',
            FailedAssertion::messageOf(static fn () => Hubspot::assertSynced('deals', ['dealname' => 'Acme'])),
        );
    }

    /**
     * **The one deliberate asymmetry on this surface, and the direction it errs in.**
     *
     * `assertSynced('notes')` is a POSITIVE claim about one object type, so it must be precise: an
     * association write from a note is not a write of the note's own properties, and treating it as one
     * would report a sync that never happened.
     *
     * `assertNothingSynced()` is a NEGATIVE claim about the absence of writes, so it is inclusive: it
     * fails for ANY recorded write, association writes included. A negative assertion that ignores a
     * whole category of write is a vacuous pass waiting to happen, and the two failure directions are
     * not symmetric in cost — a missed write is silent CRM corruption, a spurious failure is a red run.
     */
    public function test_an_association_write_is_not_a_sync_of_its_from_type_but_still_breaks_assert_nothing_synced(): void
    {
        Hubspot::fake();

        Hubspot::associations()->associate(new AssociationPair(
            from: new ObjectRef('notes', '10'),
            to: new ObjectRef('contacts', '20'),
        ));

        self::assertSame(
            "Expected HubSpot to have synced object type 'notes', but no write of that type was recorded. "
            .'Writes recorded: PUT /crm/v4/objects/notes/10/associations/default/contacts/20.',
            FailedAssertion::messageOf(static fn () => Hubspot::assertSynced('notes')),
        );

        self::assertSame(
            'Expected HubSpot to have synced nothing, but 1 write(s) were recorded: '
            .'PUT /crm/v4/objects/notes/10/associations/default/contacts/20.',
            FailedAssertion::messageOf(static fn () => Hubspot::assertNothingSynced()),
        );
    }

    public function test_assert_request_count_failure_names_both_the_expected_and_the_actual_count(): void
    {
        Hubspot::fake();

        Hubspot::objects()->create('deals', ['dealname' => 'Acme']);

        $message = FailedAssertion::messageOf(static fn () => Hubspot::assertRequestCount(2));

        self::assertSame(
            'Expected 2 HubSpot request(s), but 1 were made. Requests recorded: POST /crm/v3/objects/deals.',
            $message,
        );

        // The contract: BOTH numbers are present. An N+1 reported as "the count was wrong" is a code
        // smell; reported as "expected 1, made 11, and here they are" it is a legible test failure
        // (STANDARDS §11, threat T-02-11).
        self::assertStringContainsString('2', $message);
        self::assertStringContainsString('1', $message);
    }

    public function test_assert_request_count_names_the_absence_when_nothing_was_recorded(): void
    {
        Hubspot::fake();

        self::assertSame(
            'Expected 1 HubSpot request(s), but 0 were made. No request was recorded at all.',
            FailedAssertion::messageOf(static fn () => Hubspot::assertRequestCount(1)),
        );
    }

    /**
     * **Threat T-02-17.** Every assertion on this surface, called without `fake()`, must fail naming
     * `fake()`. Asserted for the whole set together rather than one test each, because the failure mode
     * being closed is "one of them was forgotten" — a per-assertion test proves each one individually
     * and says nothing about the set, while this loop fails the moment another assertion is added
     * without the guard.
     */
    public function test_no_assertion_passes_vacuously_when_no_fake_is_installed(): void
    {
        $pair = new AssociationPair(
            from: new ObjectRef('notes', '10'),
            to: new ObjectRef('contacts', '20'),
        );

        $assertions = [
            'assertRequestCount' => static fn () => Hubspot::assertRequestCount(0),
            'assertSynced' => static fn () => Hubspot::assertSynced('deals'),
            'assertNothingSynced' => static fn () => Hubspot::assertNothingSynced(),
            // Unlabelled deliberately: a labelled call would consult the resolver, and the no-fake
            // guard must fire before anything else has a chance to throw for another reason.
            'assertAssociated' => static fn () => Hubspot::assertAssociated($pair),
        ];

        foreach ($assertions as $name => $assertion) {
            try {
                $assertion();
                self::fail(sprintf('%s passed with no fake installed. A vacuous pass here lets a whole test file assert nothing.', $name));
            } catch (RuntimeException $exception) {
                self::assertSame(
                    'No HubSpot fake installed. Call Hubspot::fake() before making assertions.',
                    $exception->getMessage(),
                    sprintf('%s must fail naming fake(), so the reader learns what to do rather than what broke.', $name),
                );
            }
        }
    }
}
