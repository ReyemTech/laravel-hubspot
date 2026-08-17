<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Signals;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Application;
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

    /**
     * `Illuminate\Foundation\Console\ConfigCacheCommand::getFreshConfiguration()` bootstraps a
     * WHOLE FRESH `Application` by `require`ing the skeleton's own `bootstrap/app.php` --
     * constructing that second `Application` instance calls `Container::setInstance()`, which
     * SWAPS THE GLOBAL CONTAINER SINGLETON the `config()`/`app()` helpers resolve through, for the
     * remainder of the PHP process. Literally invoking `Artisan::call('config:cache')` inside a
     * Testbench test therefore corrupts every later `config()`/`app()` call in the same test run,
     * in this test and every one that follows it -- confirmed empirically (the swapped app has no
     * knowledge of this test's runtime `config()->set()` calls, so a `config('hubspot.signals.map')`
     * read immediately afterward returns null). `Tests\Feature\Sync\SyncSuppressionTest::
     * test_the_config_file_contains_nothing_config_cache_cannot_serialise()` establishes the
     * established, safe pattern this test follows instead: reproduce
     * `ConfigCacheCommand::handle()`'s own serialisation mechanism directly --
     * `'<?php return '.var_export($config, true).';'` written to a file and `require`d back --
     * against THIS test's actual, already-resolved config value. That is exactly what
     * `php artisan config:cache` performs against a real consumer's application; only the
     * container-swap side effect is intentionally not reproduced.
     */
    public function test_config_cache_succeeds_against_an_invokable_class_string_map(): void
    {
        self::assertNull($this->bootException);

        /** @var array<string, mixed> $map */
        $map = app('config')->get('hubspot.signals.map');

        $exported = var_export($map, true);

        self::assertStringNotContainsString('Closure::__set_state', $exported);

        $tempFile = tempnam(sys_get_temp_dir(), 'hubspot_signals_map_cache_');
        self::assertNotFalse($tempFile);

        try {
            file_put_contents($tempFile, '<?php return '.$exported.';'.PHP_EOL);

            $roundTripped = require $tempFile;

            // Whole-structure equality, not a single leaf: proves the round trip lost nothing,
            // including the invokable class-string this test exists to exercise.
            self::assertEquals($map, $roundTripped);
        } finally {
            unlink($tempFile);
        }
    }
}
