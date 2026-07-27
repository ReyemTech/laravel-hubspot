<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature;

use ReyemTech\Hubspot\Facades\Hubspot;
use ReyemTech\Hubspot\HubspotManager;
use ReyemTech\Hubspot\Testing\HubspotFake;
use ReyemTech\Hubspot\Testing\RecordedRequest;
use ReyemTech\Hubspot\Testing\RequestLog;
use ReyemTech\Hubspot\Tests\Support\FailedAssertion;
use ReyemTech\Hubspot\Tests\TestCase;

/**
 * # What a property expectation on `Hubspot::assertSynced()` means.
 *
 * Split out of `HubspotFakeTest` when the one-record rule below took that file to 499 lines against the
 * 500-line hard gate (STANDARDS §6b — extract, do not append; 02-05 split `LabelledAssociationTest` for
 * the same reason). It is a coherent subject rather than an overflow bucket: every test here is about
 * what `assertSynced($type, $properties)` claims, where `HubspotFakeTest` is about the object-type-level
 * assertions and their guards.
 *
 * Three rules, and each of them exists because its opposite is a false positive:
 *
 * 1. **A subset, not the whole set.** A consumer asserting one property must not have to restate every
 *    property the package sends alongside it, or the assertion becomes a change-detector nobody keeps up
 *    to date and everybody eventually deletes.
 * 2. **Compared strictly, with both types named on failure.** Every property HubSpot accepts and returns
 *    is a string, numeric and boolean ones included, so a loose comparison would report success for a
 *    package that sent the integer `100` where `'100'` belonged — the silent equivalence
 *    `declare(strict_types=1)` exists here to prevent (STANDARDS §4). `100` and `"100"` are
 *    indistinguishable in a message that prints only the value, so the message prints the type too.
 * 3. **One record must carry the whole subset.** Searching each property independently across every
 *    written record lets a broken multi-record sync pass — one that transposed two records' fields, or
 *    wrote the right values against the wrong ids — while the CRM holds neither record the caller
 *    described. Found by Codex on PR #20 and fixed there; the test naming it is below.
 *
 * Failure messages are asserted **exactly** through {@see FailedAssertion::messageOf()} rather than by
 * substring, for the reason set out in that class.
 */
mutates(
    HubspotFake::class,
    RequestLog::class,
    RecordedRequest::class,
    HubspotManager::class,
);

final class FakeSyncedPropertiesTest extends TestCase
{
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

    /**
     * **A property subset must be carried by ONE record, not assembled from several.** (Codex P1 on
     * PR #20.)
     *
     * Two records are written, `{dealname: One, amount: 10}` and `{dealname: Two, amount: 20}`. Every
     * value in `['dealname' => 'One', 'amount' => '20']` was written by *something*, and neither record
     * carries that combination. Satisfying the assertion by searching each property independently across
     * all records is a false positive of exactly the kind this whole surface exists to prevent: a broken
     * multi-record sync — one that transposed two records' fields, or wrote the right values against the
     * wrong ids — passes while the CRM holds neither record the caller described.
     *
     * The message for this case has to be its own, because there is no single property to blame: every
     * one of them was written. It reports the subset asked for and every record actually submitted.
     */
    public function test_assert_synced_requires_one_record_to_carry_the_whole_property_subset(): void
    {
        Hubspot::fake();

        Hubspot::objects()->createMany('deals', [
            ['dealname' => 'One', 'amount' => '10'],
            ['dealname' => 'Two', 'amount' => '20'],
        ]);

        // Each record on its own terms still passes.
        Hubspot::assertSynced('deals', ['dealname' => 'One', 'amount' => '10']);
        Hubspot::assertSynced('deals', ['dealname' => 'Two', 'amount' => '20']);

        $message = FailedAssertion::messageOf(
            static fn () => Hubspot::assertSynced('deals', ['dealname' => 'One', 'amount' => '20']),
        );

        self::assertSame(
            'Expected HubSpot to have synced object type \'deals\' with {"dealname":"One","amount":"20"} '
            .'on one record, but no single record carried all of them. Records written: '
            .'{"dealname":"One","amount":"10"}; {"dealname":"Two","amount":"20"}.',
            $message,
        );

        // The contract the exact assertion above happens to pin: the message says what each record
        // actually carried, so the reader can see which combination is missing.
        self::assertStringContainsString('{"dealname":"One","amount":"10"}', $message);
        self::assertStringContainsString('{"dealname":"Two","amount":"20"}', $message);
    }

    /**
     * A record that carries the right value for one expected property and **does not carry the other at
     * all** is not a match. Distinct from the value-mismatch case above, and worth its own test: records in
     * one batch legitimately have different property sets — a partial update sends only what changed — so
     * "absent" is a state the one-record check meets in practice rather than only in principle.
     */
    public function test_a_record_missing_one_expected_property_does_not_carry_the_subset(): void
    {
        Hubspot::fake();

        Hubspot::objects()->createMany('deals', [
            ['dealname' => 'One', 'amount' => '10'],
            ['dealname' => 'Two'],
        ]);

        self::assertSame(
            'Expected HubSpot to have synced object type \'deals\' with {"dealname":"Two","amount":"10"} '
            .'on one record, but no single record carried all of them. Records written: '
            .'{"dealname":"One","amount":"10"}; {"dealname":"Two"}.',
            FailedAssertion::messageOf(
                static fn () => Hubspot::assertSynced('deals', ['dealname' => 'Two', 'amount' => '10']),
            ),
        );
    }

    /**
     * The same rule across two separate single-record writes, not only within one batch. A consumer who
     * syncs two models in two calls has the same claim to make and the same false positive to avoid.
     */
    public function test_the_one_record_rule_holds_across_separate_writes_too(): void
    {
        Hubspot::fake();

        Hubspot::objects()->create('deals', ['dealname' => 'One', 'amount' => '10']);
        Hubspot::objects()->update('deals', '7', ['dealname' => 'Two', 'amount' => '20']);

        self::assertSame(
            'Expected HubSpot to have synced object type \'deals\' with {"dealname":"Two","amount":"10"} '
            .'on one record, but no single record carried all of them. Records written: '
            .'{"dealname":"One","amount":"10"}; {"dealname":"Two","amount":"20"}.',
            FailedAssertion::messageOf(
                static fn () => Hubspot::assertSynced('deals', ['dealname' => 'Two', 'amount' => '10']),
            ),
        );
    }
}
