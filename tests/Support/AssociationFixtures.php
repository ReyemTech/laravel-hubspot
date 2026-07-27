<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Support;

use ReyemTech\Hubspot\Gateway\AssociationPair;
use ReyemTech\Hubspot\Gateway\Contracts\AssociationTypeResolver;
use ReyemTech\Hubspot\Gateway\ObjectRef;

/**
 * The one directed pair and the one resolver arrangement that both labelled-write test files drive the
 * gateway through, extracted rather than copied when the reverse-direction tests moved into
 * `ReverseDirectionWriteTest` (STANDARDS §6b: logic used more than once is extracted immediately, not
 * on the third occurrence).
 *
 * Extracting these is not only tidiness. Two files each holding their own "the note pair" and "the
 * resolver that knows the forward direction" would drift — one would gain the reverse direction, or a
 * different label, or a different id — and the pair of files would then be asserting slightly
 * different things while reading identically. On this seam the difference between `202` and `201` is
 * the whole subject, so a fixture that quietly diverges is a test that quietly stops testing.
 *
 * Lives beside {@see DirectedMapResolver} under `tests/Support/`, which is deliberately NOT a
 * registered testsuite in `phpunit.xml.dist`: it holds no tests, and `failOnWarning` turns a
 * declared-but-empty testsuite into a build failure.
 */
final class AssociationFixtures
{
    /**
     * Note -> Contact, the direction the design documents name as the canonical mistake: 202 forward,
     * 201 inverse. Both are real ids HubSpot accepts without complaint, which is what makes the pair
     * worth testing against.
     */
    public static function noteToContact(): AssociationPair
    {
        return new AssociationPair(
            from: new ObjectRef('notes', '10'),
            to: new ObjectRef('contacts', '20'),
        );
    }

    /**
     * Binds a resolver that knows `notes -> contacts` under `Attached note` (202) and **nothing else**
     * — not the reversed direction, and no other label. Every negative case in the labelled-write
     * suite is expressed by what this resolver does not hold.
     */
    public static function bindResolverKnowingNoteToContact(): void
    {
        app()->instance(
            AssociationTypeResolver::class,
            DirectedMapResolver::knowing('notes', 'contacts', 'Attached note', 202),
        );
    }
}
