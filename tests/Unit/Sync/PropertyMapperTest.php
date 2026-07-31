<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Unit\Sync;

use ReyemTech\Hubspot\Sync\PropertyMapper;
use ReyemTech\Hubspot\Tests\Support\Sync\SyncedLead;
use ReyemTech\Hubspot\Tests\TestCase;

/**
 * `PropertyMapper::map()`'s literal-attribute form -- the only form 04-02 builds. The dot-notation
 * and closure forms are 04-03's, and this file is expected to grow alongside them rather than be
 * replaced: `map()`'s signature does not change when they arrive.
 *
 * Not listed in 04-02-PLAN.md's `files_modified` -- added under deviation Rule 2. `PropertyMapper`
 * is a `phase_artifacts`-owned deliverable of THIS plan, and its is_scalar/null branches were
 * otherwise unreachable by `TracerSyncTest.php` alone, which would leave real branches uncovered
 * against STANDARDS' coverage and mutation floors.
 */
mutates(PropertyMapper::class);

final class PropertyMapperTest extends TestCase
{
    public function test_a_literal_attribute_resolves_its_value(): void
    {
        $lead = new SyncedLead(['email' => 'ada@example.com', 'first_name' => 'Ada']);

        $properties = (new PropertyMapper)->map($lead, ['email' => 'email', 'firstname' => 'first_name']);

        self::assertSame(['email' => 'ada@example.com', 'firstname' => 'Ada'], $properties);
    }

    /**
     * The non-string entry is placed FIRST deliberately: a `continue`/`break` confusion inside the
     * loop would still pass a test whose only non-string entry is last (both skip and abort leave
     * the same result), because nothing after it would run either way. Putting a resolvable entry
     * AFTER the skipped one is what makes "skip this one and keep going" distinguishable from "stop
     * entirely".
     */
    public function test_a_non_string_map_entry_is_skipped_rather_than_resolved(): void
    {
        $lead = new SyncedLead(['email' => 'ada@example.com']);

        /** @var array<string, mixed> $map */
        $map = ['dealstage' => 42, 'email' => 'email'];

        $properties = (new PropertyMapper)->map($lead, $map);

        self::assertSame(['email' => 'ada@example.com'], $properties);
    }

    public function test_a_non_string_scalar_attribute_value_is_cast_to_a_string(): void
    {
        $lead = new SyncedLead(['email' => 'ada@example.com', 'id' => 42]);

        $properties = (new PropertyMapper)->map($lead, ['record_id' => 'id']);

        self::assertSame(['record_id' => '42'], $properties);
    }

    public function test_a_null_attribute_value_resolves_to_an_empty_string(): void
    {
        $lead = new SyncedLead(['email' => 'ada@example.com', 'first_name' => null]);

        $properties = (new PropertyMapper)->map($lead, ['firstname' => 'first_name']);

        self::assertSame(['firstname' => ''], $properties);
    }
}
