<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Support\Webhooks;

/**
 * A trivial, container-resolvable dependency with no constructor arguments of its own -- used to
 * prove `HandlerMapTest`'s claim that a handler's own constructor dependencies are resolved from
 * the container rather than requiring a no-arg constructor.
 */
final class GreetingService
{
    public function greeting(): string
    {
        return 'hello';
    }
}
