<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Signals;

use Illuminate\Database\Eloquent\Model;
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

/**
 * `Hubspot::identify()`'s completed behaviour (06-03-PLAN.md Task 2): D-02's blank-`id_property`
 * refusal, D-09's asymmetric rebind refusal, and the multi-visitor-to-one-subject backfill D-09
 * requires. The happy-path tracer tests already live in `SignalTracerTest`; this file covers what
 * 06-01 deliberately left unimplemented.
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

    public function test_a_null_id_property_value_throws_and_mutates_no_row(): void
    {
        Hubspot::fake();
        Bus::fake();

        Hubspot::signal('pricing_page_viewed', 'visitor-1');
        $subject = new SignalSubject; // unsaved -- 'email' was never set, so it is null.

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

    public function test_an_empty_or_whitespace_only_id_property_value_throws_and_a_single_character_is_accepted(): void
    {
        Hubspot::fake();
        Bus::fake();

        foreach (['', '   '] as $blankValue) {
            $subject = new SignalSubject(['email' => $blankValue]);

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
}
