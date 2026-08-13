<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Signals;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Exceptions\SignalException;
use ReyemTech\Hubspot\Facades\Hubspot;
use ReyemTech\Hubspot\Signals\FlushSignalsJob;
use ReyemTech\Hubspot\Signals\IdentityResolver;
use ReyemTech\Hubspot\Tests\Support\Signals\SecondSignalSubject;
use ReyemTech\Hubspot\Tests\Support\Signals\SignalsTestCase;
use ReyemTech\Hubspot\Tests\Support\Signals\SignalSubject;

mutates(IdentityResolver::class);

/**
 * `Hubspot::identify()`'s completed behaviour (06-03-PLAN.md Task 2): D-02's blank-`id_property`
 * refusal, D-09's asymmetric rebind refusal, and the multi-visitor-to-one-subject backfill D-09
 * requires. The happy-path tracer tests already live in `SignalTracerTest`; this file covers what
 * 06-01 deliberately left unimplemented.
 *
 * Missing its own `mutates()` declaration until now (PR #82 review's mutation gate): every test
 * below already exercised `IdentityResolver` directly, but a scoped `--class=IdentityResolver`
 * mutation run only associates tests a file DECLARES coverage for -- this file exercised the class
 * without ever declaring it, so a scoped run silently mutated it against `SignalTracerTest`'s
 * happy-path coverage alone, missing every D-02/D-09 refusal this file is the one that actually
 * proves.
 */
final class IdentityResolverTest extends SignalsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('second_signal_subjects', function (Blueprint $table): void {
            $table->id();
            $table->string('email');
            $table->timestamps();
        });

        /** @var array<class-string, array{object?: mixed, id_property?: mixed}> $existingModels */
        $existingModels = config('hubspot.models', []);

        config(['hubspot.models' => array_merge($existingModels, [
            SecondSignalSubject::class => ['object' => 'contacts', 'id_property' => 'email'],
        ])]);
    }

    public function test_identify_backfills_every_row_for_the_visitor_and_no_other_visitors_row(): void
    {
        Hubspot::fake();
        Bus::fake();

        Hubspot::signal('pricing_page_viewed', 'visitor-1');
        Hubspot::signal('pricing_page_viewed', 'visitor-1');
        Hubspot::signal('pricing_page_viewed', 'visitor-other');

        $subject = SignalSubject::query()->create(['email' => 'ada@example.com']);

        Hubspot::identify('visitor-1', $subject);

        $ownRows = DB::table('hubspot_signals')->where('visitor_id', 'visitor-1')->get();
        self::assertCount(2, $ownRows);

        foreach ($ownRows as $row) {
            self::assertSame(SignalSubject::class, $row->subject_type);
            self::assertSame((string) $subject->getKey(), $row->subject_id); // @phpstan-ignore-line cast.string
        }

        $otherRow = DB::table('hubspot_signals')->where('visitor_id', 'visitor-other')->first();
        self::assertNotNull($otherRow);
        self::assertNull($otherRow->subject_type);
        self::assertNull($otherRow->subject_id);
    }

    public function test_identifying_the_same_visitor_to_the_same_subject_twice_is_a_no_op(): void
    {
        Hubspot::fake();
        Bus::fake();

        Hubspot::signal('pricing_page_viewed', 'visitor-1');
        $subject = SignalSubject::query()->create(['email' => 'ada@example.com']);

        Hubspot::identify('visitor-1', $subject);
        Bus::assertDispatchedTimes(FlushSignalsJob::class, 1);

        Hubspot::identify('visitor-1', $subject);
        Bus::assertDispatchedTimes(FlushSignalsJob::class, 1);

        self::assertCount(1, DB::table('hubspot_signals')->where('visitor_id', 'visitor-1')->get());
    }

    public function test_a_visitor_id_already_bound_to_one_subject_refuses_a_different_one(): void
    {
        Hubspot::fake();
        Bus::fake();

        Hubspot::signal('pricing_page_viewed', 'visitor-1');
        $subject = SignalSubject::query()->create(['email' => 'ada@example.com']);
        $other = SecondSignalSubject::query()->create(['email' => 'grace@example.com']);

        Hubspot::identify('visitor-1', $subject);

        try {
            Hubspot::identify('visitor-1', $other);

            self::fail('Expected a SignalException for a visitor id rebound to a different subject.');
        } catch (SignalException) {
            // Expected.
        }

        $row = DB::table('hubspot_signals')->where('visitor_id', 'visitor-1')->first();
        self::assertNotNull($row);
        self::assertSame(SignalSubject::class, $row->subject_type);
        self::assertSame((string) $subject->getKey(), $row->subject_id); // @phpstan-ignore-line cast.string
    }

    public function test_many_visitor_ids_may_bind_to_one_subject(): void
    {
        Hubspot::fake();
        Bus::fake();

        Hubspot::signal('pricing_page_viewed', 'visitor-1');
        Hubspot::signal('pricing_page_viewed', 'visitor-2');
        $subject = SignalSubject::query()->create(['email' => 'ada@example.com']);

        Hubspot::identify('visitor-1', $subject);
        Hubspot::identify('visitor-2', $subject);

        $rows = DB::table('hubspot_signals')->where('subject_type', SignalSubject::class)->get();
        self::assertCount(2, $rows);

        foreach ($rows as $row) {
            self::assertSame((string) $subject->getKey(), $row->subject_id); // @phpstan-ignore-line cast.string
        }
    }

    public function test_binding_two_visitors_to_one_subject_in_the_opposite_order_binds_the_same_rows(): void
    {
        Hubspot::fake();
        Bus::fake();

        Hubspot::signal('pricing_page_viewed', 'visitor-1');
        Hubspot::signal('pricing_page_viewed', 'visitor-2');
        $subject = SignalSubject::query()->create(['email' => 'ada@example.com']);

        // The opposite call order from the test above.
        Hubspot::identify('visitor-2', $subject);
        Hubspot::identify('visitor-1', $subject);

        $rows = DB::table('hubspot_signals')->where('subject_type', SignalSubject::class)->get();
        self::assertCount(2, $rows);

        $visitorIds = $rows->pluck('visitor_id')->sort()->values()->all();
        self::assertSame(['visitor-1', 'visitor-2'], $visitorIds);
    }

    public function test_the_asymmetry_many_to_one_permitted_one_to_two_refused_is_a_single_pinned_fact(): void
    {
        Hubspot::fake();
        Bus::fake();

        Hubspot::signal('pricing_page_viewed', 'visitor-1');
        Hubspot::signal('pricing_page_viewed', 'visitor-2');
        $subject = SignalSubject::query()->create(['email' => 'ada@example.com']);
        $other = SecondSignalSubject::query()->create(['email' => 'grace@example.com']);

        // Many visitors to ONE subject: permitted, throws nothing.
        Hubspot::identify('visitor-1', $subject);
        Hubspot::identify('visitor-2', $subject);

        self::assertCount(2, DB::table('hubspot_signals')->where('subject_type', SignalSubject::class)->get());

        // ONE visitor to a second, different subject: refused.
        try {
            Hubspot::identify('visitor-1', $other);

            self::fail('Expected a SignalException for the one-visitor-to-two-subjects direction.');
        } catch (SignalException $exception) {
            self::assertStringContainsString('visitor-1', $exception->getMessage());
        }
    }

    /**
     * Saved (a real primary key), then `email` is nulled out in memory only -- isolating D-02's
     * `null` branch from `refuseUnsavedSubject()`'s own refusal (PR #82 review): an unsaved model's
     * `email` would ALSO be null by default, exercising the wrong check first and leaving D-02's own
     * `$value === null` match arm uncovered.
     */
    public function test_a_null_id_property_value_throws_and_mutates_no_row(): void
    {
        Hubspot::fake();
        Bus::fake();

        Hubspot::signal('pricing_page_viewed', 'visitor-1');
        $subject = SignalSubject::query()->create(['email' => 'placeholder@example.com']);
        $subject->setAttribute('email', null);

        try {
            Hubspot::identify('visitor-1', $subject);

            self::fail('Expected a SignalException for a null id_property value.');
        } catch (SignalException) {
            // Expected.
        }

        $row = DB::table('hubspot_signals')->where('visitor_id', 'visitor-1')->first();
        self::assertNotNull($row);
        self::assertNull($row->subject_type);
    }

    /**
     * The same family as D-02, for the same reason: `identify()` issues no HTTP, so refusing an
     * unsaved subject here is cheap, and the alternative -- binding buffered rows to `subject_id`
     * `''`, the string `getKey()` casts a null primary key to -- strands them permanently, since no
     * real subject will ever carry that id. `email` is deliberately non-blank here so D-02's own
     * check does not fire first; this test isolates the missing-primary-key refusal.
     */
    public function test_an_unsaved_subject_with_no_primary_key_throws_and_mutates_no_row(): void
    {
        Hubspot::fake();
        Bus::fake();

        Hubspot::signal('pricing_page_viewed', 'visitor-unsaved');
        $subject = new SignalSubject(['email' => 'unsaved@example.com']); // never ->save()'d.

        try {
            Hubspot::identify('visitor-unsaved', $subject);

            self::fail('Expected a SignalException for an unsaved subject with no primary key.');
        } catch (SignalException $exception) {
            self::assertStringContainsString(SignalSubject::class, $exception->getMessage());
        }

        $row = DB::table('hubspot_signals')->where('visitor_id', 'visitor-unsaved')->first();
        self::assertNotNull($row);
        self::assertNull($row->subject_type);
    }

    /**
     * Saved (a real primary key) for the same reason the null-value test above is: an unsaved
     * `SignalSubject` would trip `refuseUnsavedSubject()` first (PR #82 review), never reaching
     * D-02's own blank-value branch this test exists to prove.
     */
    public function test_an_empty_or_whitespace_only_id_property_value_throws_and_a_single_character_is_accepted(): void
    {
        Hubspot::fake();
        Bus::fake();

        foreach (['', '   '] as $blankValue) {
            $subject = SignalSubject::query()->create(['email' => 'placeholder@example.com']);
            $subject->setAttribute('email', $blankValue);

            try {
                Hubspot::identify('visitor-boundary', $subject);

                self::fail(sprintf('Expected a SignalException for id_property value "%s".', $blankValue));
            } catch (SignalException) {
                // Expected.
            }
        }

        Hubspot::signal('pricing_page_viewed', 'visitor-boundary-accepted');
        $accepted = SignalSubject::query()->create(['email' => 'a']);

        Hubspot::identify('visitor-boundary-accepted', $accepted);

        $row = DB::table('hubspot_signals')->where('visitor_id', 'visitor-boundary-accepted')->first();
        self::assertNotNull($row);
        self::assertSame(SignalSubject::class, $row->subject_type);
    }

    /**
     * D-02's cast branch: a non-string SCALAR `id_property` value (an int, here) is cast to a
     * string rather than refused -- only null, a non-scalar, or a value that trims to '' is
     * refused. `SignalSubject::email` carries no cast, so assigning an int leaves it as one in the
     * model's own attribute array until this check casts it.
     */
    public function test_a_non_string_scalar_id_property_value_is_cast_to_a_string_and_accepted(): void
    {
        Hubspot::fake();
        Bus::fake();

        Hubspot::signal('pricing_page_viewed', 'visitor-scalar');
        $subject = SignalSubject::query()->create(['email' => 'placeholder@example.com']);
        $subject->setAttribute('email', 12345);

        Hubspot::identify('visitor-scalar', $subject);

        $row = DB::table('hubspot_signals')->where('visitor_id', 'visitor-scalar')->first();
        self::assertNotNull($row);
        self::assertSame(SignalSubject::class, $row->subject_type);
    }

    /**
     * D-02's `default => null` branch: a non-scalar `id_property` value (an array, here) is
     * refused exactly like a missing one -- `is_scalar()` answers false for it, so it falls
     * through the `match` to the same `null` result a genuinely absent value produces.
     */
    public function test_a_non_scalar_id_property_value_throws_like_a_missing_one(): void
    {
        Hubspot::fake();
        Bus::fake();

        Hubspot::signal('pricing_page_viewed', 'visitor-non-scalar');
        $subject = SignalSubject::query()->create(['email' => 'placeholder@example.com']);
        $subject->setAttribute('email', ['not', 'a scalar']);

        try {
            Hubspot::identify('visitor-non-scalar', $subject);

            self::fail('Expected a SignalException for a non-scalar id_property value.');
        } catch (SignalException $exception) {
            self::assertStringContainsString('email', $exception->getMessage());
        }
    }

    /**
     * **A database failure that is not a missing table is not relabelled as one.** Mirrors
     * `MigrationGateTest::test_enabled_a_query_failure_with_the_table_present_is_not_reported_as_a_missing_table()`,
     * applied to `IdentityResolver::guarded()` -- the table is replaced by one of the same name
     * without the `subject_type` column `refuseRebindToADifferentSubject()` selects, so the query
     * fails while `Schema::hasTable()` still answers true.
     */
    public function test_a_query_failure_with_the_table_present_is_not_reported_as_a_missing_table(): void
    {
        Hubspot::fake();
        Bus::fake();

        DB::statement('DROP TABLE hubspot_signals');
        Schema::create('hubspot_signals', function (Blueprint $table): void {
            $table->id();
        });

        self::assertTrue(Schema::hasTable('hubspot_signals'));

        $subject = SignalSubject::query()->create(['email' => 'ada@example.com']);

        $this->expectException(QueryException::class);

        Hubspot::identify('visitor-1', $subject);
    }

    public function test_a_subject_class_absent_from_hubspot_models_throws_unbound_signal_subject(): void
    {
        Hubspot::fake();
        Bus::fake();

        Hubspot::signal('pricing_page_viewed', 'visitor-1');

        $unbound = new class extends Model
        {
            protected $table = 'signal_subjects';

            protected $guarded = [];
        };

        // Resolved from the container rather than called through the facade: PHPStan trusts the
        // Hubspot facade's `@method static void identify(...)` signature (no `@throws`) as
        // authoritative and flags `catch (ConfigurationException)` around a facade call as
        // unreachable, even though the concrete implementation throws it -- the identical false
        // positive `MigrationGateTest`/`SignalRecorderTest` document and avoid the same way
        // (06-01-SUMMARY.md, 06-02-SUMMARY.md).
        try {
            app(IdentityResolver::class)->identify('visitor-1', $unbound);

            self::fail('Expected ConfigurationException::unboundSignalSubject() for an unbound class.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('hubspot.models', $exception->getMessage());
        }
    }

    public function test_identifying_a_visitor_with_zero_buffered_rows_binds_nothing_and_dispatches_no_flush(): void
    {
        Hubspot::fake();
        Bus::fake();

        $subject = SignalSubject::query()->create(['email' => 'ada@example.com']);

        Hubspot::identify('visitor-unknown', $subject);

        Bus::assertNotDispatched(FlushSignalsJob::class);
        self::assertSame(0, DB::table('hubspot_signals')->where('visitor_id', 'visitor-unknown')->count());
    }

    public function test_subject_id_is_compared_as_a_string_so_a_reloaded_integer_key_binds_the_same_subject(): void
    {
        Hubspot::fake();
        Bus::fake();

        $subject = SignalSubject::query()->create(['email' => 'precision@example.com']);

        Hubspot::signal('pricing_page_viewed', 'visitor-int-1');
        Hubspot::identify('visitor-int-1', $subject);

        // Reloaded fresh from the database, rather than reusing the in-memory instance -- the
        // primary key now arrives through the driver's own native integer type, not whatever type
        // the original create() call happened to leave it as.
        $reloaded = SignalSubject::query()->find($subject->getKey());

        if (! $reloaded instanceof SignalSubject) {
            self::fail('Expected the reloaded subject to exist.');
        }

        Hubspot::signal('pricing_page_viewed', 'visitor-int-2');
        Hubspot::identify('visitor-int-2', $reloaded);

        $rows = DB::table('hubspot_signals')
            ->where('subject_type', SignalSubject::class)
            ->where('subject_id', (string) $subject->getKey()) // @phpstan-ignore-line cast.string
            ->get();

        self::assertCount(2, $rows);
    }

    public function test_the_whole_identify_path_issues_zero_http_requests(): void
    {
        Hubspot::fake();
        Bus::fake();

        Hubspot::signal('pricing_page_viewed', 'visitor-1');
        $subject = SignalSubject::query()->create(['email' => 'ada@example.com']);

        Hubspot::identify('visitor-1', $subject);

        Hubspot::assertRequestCount(0);
    }

    public function test_identify_reads_no_cookie_session_or_request_and_succeeds_outside_any_request_context(): void
    {
        Hubspot::fake();
        Bus::fake();

        // No route is ever registered and no controller ever runs in this test process --
        // Testbench boots a console-style application for the whole suite -- so a successful call
        // here already proves the identify path needs no active HTTP request to succeed (D9).
        self::assertTrue(app()->runningInConsole());

        Hubspot::signal('pricing_page_viewed', 'visitor-1');
        $subject = SignalSubject::query()->create(['email' => 'ada@example.com']);

        Hubspot::identify('visitor-1', $subject);

        $row = DB::table('hubspot_signals')->where('visitor_id', 'visitor-1')->first();
        self::assertNotNull($row);
        self::assertSame(SignalSubject::class, $row->subject_type);
    }

    /**
     * `Hubspot::identify()` reaches `Signals\IdentityResolver::identify()` with the arguments
     * unchanged (06-03-PLAN.md Task 3). The container's `IdentityResolver` singleton binding is
     * swapped for a plain recorder object -- `IdentityResolver` is `final`, so it cannot be
     * extended into a spy, and `instance()` binds whatever object it is given without checking
     * that it is actually one.
     */
    public function test_hubspot_identify_reaches_identity_resolver_with_the_arguments_unchanged(): void
    {
        $spy = new class
        {
            public ?string $visitorId = null;

            public ?Model $subject = null;

            public function identify(string $visitorId, Model $subject): void
            {
                $this->visitorId = $visitorId;
                $this->subject = $subject;
            }
        };

        app()->instance(IdentityResolver::class, $spy);

        $subject = SignalSubject::query()->create(['email' => 'ada@example.com']);

        Hubspot::identify('visitor-facade', $subject);

        self::assertSame('visitor-facade', $spy->visitorId);
        self::assertSame($subject, $spy->subject);
    }

    /**
     * The README's own promise: a shared-device merge is not silent, because the merged subject's
     * buffer rows carry more than one distinct `visitor_id` (D-09's accepted consequence,
     * 06-03-PLAN.md Task 3, README.md's Signals section).
     */
    public function test_a_merged_subjects_rows_carry_both_visitor_ids_so_an_operator_can_find_it(): void
    {
        Hubspot::fake();
        Bus::fake();

        Hubspot::signal('pricing_page_viewed', 'visitor-shared-device-1');
        Hubspot::signal('pricing_page_viewed', 'visitor-shared-device-2');
        $subject = SignalSubject::query()->create(['email' => 'shared@example.com']);

        Hubspot::identify('visitor-shared-device-1', $subject);
        Hubspot::identify('visitor-shared-device-2', $subject);

        $visitorIds = DB::table('hubspot_signals')
            ->where('subject_type', SignalSubject::class)
            ->where('subject_id', (string) $subject->getKey()) // @phpstan-ignore-line cast.string
            ->pluck('visitor_id')
            ->unique()
            ->sort()
            ->values()
            ->all();

        self::assertSame(['visitor-shared-device-1', 'visitor-shared-device-2'], $visitorIds);
    }
}
