<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature;

use ReflectionClass;
use ReflectionMethod;
use ReyemTech\Hubspot\Facades\Hubspot;
use ReyemTech\Hubspot\HubspotManager;
use ReyemTech\Hubspot\Tests\TestCase;

/**
 * # The facade advertises what the manager offers, and nothing else
 *
 * A `Facade` resolves by `__callStatic`, so a method missing from the `@method` block still WORKS --
 * it is simply invisible to IDEs, to static analysis and to anyone reading the class to find out
 * what the package can do. That makes the drift silent, and silent drift is what this file exists to
 * stop: `withoutSyncing()` shipped as public API in 04-07 and was not advertised at all until Codex
 * pointed it out on PR #56.
 *
 * Both directions are asserted. A missing entry hides a real capability; a STALE entry advertises a
 * method that no longer exists, which is worse -- a consumer writes the call, their IDE agrees with
 * them, and it fails at runtime.
 */
final class FacadeContractTest extends TestCase
{
    public function test_every_public_manager_method_is_advertised_on_the_facade(): void
    {
        $missing = array_values(array_diff(self::managerMethods(), self::advertisedMethods()));

        self::assertSame([], $missing, sprintf(
            'These public %s methods have no @method entry on the facade, so they are invisible to '
            .'IDEs and to static analysis: %s',
            HubspotManager::class,
            implode(', ', $missing),
        ));
    }

    public function test_the_facade_advertises_no_method_the_manager_does_not_have(): void
    {
        $stale = array_values(array_diff(self::advertisedMethods(), self::managerMethods()));

        self::assertSame([], $stale, sprintf(
            'These @method entries name nothing on %s, so a consumer following them writes a call '
            .'their IDE accepts and the runtime rejects: %s',
            HubspotManager::class,
            implode(', ', $stale),
        ));
    }

    /**
     * @return list<string>
     */
    private static function managerMethods(): array
    {
        $names = [];

        foreach ((new ReflectionClass(HubspotManager::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isConstructor() || $method->isStatic()) {
                continue;
            }

            $names[] = $method->getName();
        }

        sort($names);

        return $names;
    }

    /**
     * @return list<string>
     */
    private static function advertisedMethods(): array
    {
        $docComment = (new ReflectionClass(Hubspot::class))->getDocComment();

        self::assertIsString($docComment, 'The facade must carry a docblock advertising the manager.');

        preg_match_all('/@method\s+static\s+\S+\s+(\w+)\s*\(/', $docComment, $matches);

        /** @var list<string> $names */
        $names = $matches[1];
        sort($names);

        return $names;
    }
}
