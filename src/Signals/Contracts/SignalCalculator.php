<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Signals\Contracts;

use Illuminate\Support\Collection;

/**
 * The package-owned interface an invokable class-string in `hubspot.signals.map` must implement
 * (D-08). This REPLACES the design spec's closure escape hatch (§6 of
 * `docs/superpowers/specs/2026-07-26-signals-attribution-and-frontend-design.md`, superseded):
 * `php artisan config:cache` serialises `config/hubspot.php` with `var_export()`, which throws
 * *"Your configuration files are not serializable"* the moment a `Closure` appears anywhere in the
 * cached tree -- a production-breaking regression invisible until someone deploys with cached
 * config. A class-string loses no expressive power a closure had: the calculation still runs over
 * the same `Collection` of matching signals and returns the same shape of value. What it gains is
 * that `config/hubspot.php` stays a plain, `config:cache`-safe array, and that `MergeRule` can
 * verify the class exists and implements this interface at BOOT, with no config booted at all for
 * `MergeRuleTest`'s own unit tests.
 *
 * `MergeRule::fromDeclaration()` is the single place a `hubspot.signals.map` property declaration
 * is parsed into a class-string or one of the four merge verbs; `RollUpCalculator` (06-04) is the
 * single place a resolved `MergeRule` is INTERPRETED, and it is that class -- never `SignalMap`,
 * which only validates -- that ever constructs or invokes an implementation of this interface.
 *
 * T-06-06: `MergeRule::fromDeclaration()` validates a configured class-string with `class_exists()`
 * then `is_a($class, self::class, true)` -- the string-form check that resolves without
 * instantiating or invoking it, mirroring `Webhooks\HandlerMap::validateOne()` exactly. Boot-time
 * validation never constructs or calls an implementation of this interface.
 */
interface SignalCalculator
{
    /**
     * @param  Collection<int, array{signal_name: string}>  $signals  every one of the subject's
     *                                                                buffered rows matching this
     *                                                                property's signal name,
     *                                                                flushed included (D-10) --
     *                                                                `RollUpCalculator` never
     *                                                                filters by `flushed_at`
     */
    public function __invoke(Collection $signals): mixed;
}
