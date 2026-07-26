<?php

/**
 * Fixture for rule R9 — never production code.
 *
 * Violates: "Every PHP file declares strict_types=1." Deliberately omits the
 * declare(strict_types=1) statement that every real file in this package carries.
 */

namespace ReyemTech\Hubspot\Gateway;

final class MissingStrictTypes
{
}
