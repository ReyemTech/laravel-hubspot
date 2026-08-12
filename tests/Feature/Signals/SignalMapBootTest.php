<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Signals;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\ServiceProvider;
use ReyemTech\Hubspot\Tests\Support\Signals\IntentScore;
use ReyemTech\Hubspot\Tests\Support\Signals\SignalSubject;
use ReyemTech\Hubspot\Tests\TestCase;
use Throwable;

mutates(ServiceProvider::class);

/**
 * D-07: the signal map is validated in `ServiceProvider::boot()`, guarded by
 * `hubspot.signals.enabled === true` -- so a typo costs an install that opted in a boot failure,
 * and costs an install that did not opt in nothing at all.
 *
 * **One class, environment chosen per-test by method-name prefix.** `defineEnvironment()` runs
 * once per test method, and PHPUnit's own file-based test discovery registers only one class per
 * file -- proven empirically in `MigrationGateTest`'s own docblock, reused here for the identical
 * reason. `setUp()` reads `$this->name()`, available before `parent::setUp()` triggers
 * `defineEnvironment()`.
 *
 * **`parent::setUp()` itself throws for the "broken map, enabled" scenarios**, because
 * `ServiceProvider::boot()` runs during application creation. Catching that here, rather than
 * inside a test body's own try/catch, is what lets a test assert "boot failed for this reason"
 * without `expectException()` -- which only wraps the test body, never `setUp()`.
 */
final class SignalMapBootTest extends TestCase
{
    private ?Throwable $bootException = null;

    protected function setUp(): void
    {
        try {
            parent::setUp();
        } catch (Throwable $exception) {
            $this->bootException = $exception;
        }
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var ConfigRepository $config */
        $config = $app->make('config');

        $name = $this->name();

        if (str_starts_with($name, 'test_disabled_with_a_broken_map_boots_without_throwing')) {
            $config->set('hubspot.signals.enabled', false);
            $config->set('hubspot.signals.map', self::brokenMap());

            return;
        }

        if (str_starts_with($name, 'test_enabled_with_a_broken_map_throws_at_boot')) {
            $config->set('hubspot.signals.enabled', true);
            $config->set('hubspot.signals.map', self::brokenMap());

            return;
        }

        if (str_starts_with($name, 'test_enabled_with_a_valid_map_boots')) {
            $config->set('hubspot.signals.enabled', true);
            self::bindContacts($config);
            $config->set('hubspot.signals.map', self::validMap());

            return;
        }

        if (str_starts_with($name, 'test_enabled_with_no_map_key_at_all_boots')) {
            $config->set('hubspot.signals.enabled', true);
            // Deliberately no hubspot.signals.map write -- the shipped config default, [], applies.
            return;
        }

        if (str_starts_with($name, 'test_a_truthy_non_bool_enabled_value_does_not_validate')) {
            $config->set('hubspot.signals.enabled', '1');
            $config->set('hubspot.signals.map', self::brokenMap());

            return;
        }

        if (str_starts_with($name, 'test_config_cache_succeeds_against_an_invokable_class_string_map')) {
            $config->set('hubspot.signals.enabled', true);
            self::bindContacts($config);
            $config->set('hubspot.signals.map', [
                'demo_requested' => [
                    'object' => 'contacts',
                    'properties' => [
                        'intent_score' => IntentScore::class,
                    ],
                ],
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function brokenMap(): array
    {
        return [
            'pricing_page_viewed' => [
                'object' => 'contacts',
                'properties' => [
                    'pricing_page_views' => 'overwrite',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function validMap(): array
    {
        return [
            'pricing_page_viewed' => [
                'object' => 'contacts',
                'properties' => [
                    'pricing_page_views' => 'increment',
                ],
            ],
        ];
    }

    private static function bindContacts(ConfigRepository $config): void
    {
        $config->set('hubspot.models', [
            SignalSubject::class => ['object' => 'contacts', 'id_property' => 'email'],
        ]);
    }

    public function test_disabled_with_a_broken_map_boots_without_throwing(): void
    {
        self::assertNull($this->bootException);
    }

    public function test_enabled_with_a_broken_map_throws_at_boot(): void
    {
        self::assertInstanceOf(ConfigurationException::class, $this->bootException);
    }

    public function test_enabled_with_a_valid_map_boots(): void
    {
        self::assertNull($this->bootException);
    }

    public function test_enabled_with_no_map_key_at_all_boots(): void
    {
        self::assertNull($this->bootException);
    }

    public function test_a_truthy_non_bool_enabled_value_does_not_validate_and_boots(): void
    {
        self::assertSame('1', config('hubspot.signals.enabled'));
        self::assertNull($this->bootException);
    }

    public function test_config_cache_succeeds_against_an_invokable_class_string_map(): void
    {
        self::assertNull($this->bootException);

        try {
            $exitCode = Artisan::call('config:cache');

            self::assertSame(0, $exitCode);

            /** @var array<string, mixed> $cachedMap */
            $cachedMap = config('hubspot.signals.map');

            self::assertSame(
                IntentScore::class,
                $cachedMap['demo_requested']['properties']['intent_score'],
            );
        } finally {
            Artisan::call('config:clear');
        }
    }
}
