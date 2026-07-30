<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Sync;

use Illuminate\Foundation\Application;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\ServiceProvider;
use ReyemTech\Hubspot\Tests\Support\Sync\SyncedLead;
use ReyemTech\Hubspot\Tests\TestCase;
use Throwable;

/**
 * # D-12 also covers a whitespace-only `id_property`, not merely an absent one.
 *
 * `ModelBindings::validate()` originally rejected only the literal empty string
 * (`$binding->idProperty === ''`), which let `'id_property' => '   '` boot cleanly. The deferred
 * failure is the exact shape D-12 exists to prevent: `PropertyMapper::map()` casts every resolved
 * value to a string and `SyncHubspotObjectJob::handle()` reads `$properties[$binding->idProperty]`
 * -- a whitespace key that happens to resolve to nothing throws `idPropertyNotMapped()` instead,
 * on a worker, long after the config that caused it was written. Codex, PR #39.
 *
 * Structured identically to {@see MissingIdPropertyThrowsAtBootTest}: a genuinely fresh
 * application is required to prove the failure happens while `ServiceProvider::boot()` runs, not
 * on first use, so this does not share {@see SyncTestCase}'s already-valid app.
 */
final class WhitespaceIdPropertyThrowsAtBootTest extends TestCase
{
    private ?Throwable $bootException = null;

    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [ServiceProvider::class];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app->make('config')->set('hubspot.models', [
            SyncedLead::class => [
                'object' => 'contacts',
                // Whitespace-only, deliberately: not absent, and not the literal empty string
                // `=== ''` alone catches.
                'id_property' => '   ',
            ],
        ]);
    }

    protected function setUp(): void
    {
        try {
            parent::setUp();
        } catch (Throwable $exception) {
            $this->bootException = $exception;
        }
    }

    public function test_a_whitespace_only_id_property_throws_while_the_application_boots(): void
    {
        self::assertInstanceOf(
            ConfigurationException::class,
            $this->bootException,
            'Expected a whitespace-only id_property to throw ConfigurationException at boot, '.
            'the same as an absent one.',
        );

        self::assertSame(
            ConfigurationException::missingIdProperty(SyncedLead::class)->getMessage(),
            $this->bootException->getMessage(),
        );
    }
}
