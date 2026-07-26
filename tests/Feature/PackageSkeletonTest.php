<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Illuminate\Contracts\Foundation\Application;

/**
 * Collapses `.` and `..` segments without touching the filesystem, since the
 * PSR-4 path this test asserts against may not exist yet (see the "six layer
 * directories" test below) and realpath() would return false for it.
 */
function normalizePath(string $path): string
{
    $isAbsolute = str_starts_with(str_replace('\\', '/', $path), '/');
    $segments = explode('/', str_replace('\\', '/', $path));
    $normalized = [];

    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }

        if ($segment === '..') {
            array_pop($normalized);

            continue;
        }

        $normalized[] = $segment;
    }

    return ($isAbsolute ? '/' : '').implode('/', $normalized);
}

it('boots a Laravel application under orchestra/testbench and resolves it from the container', function (): void {
    expect($this->app)->toBeInstanceOf(Application::class);
    expect($this->app->bound(Application::class))->toBeTrue();
});

it('registers the ReyemTech\Hubspot\ PSR-4 prefix mapped at src/ in the runtime autoloader', function (): void {
    $loader = null;

    foreach (spl_autoload_functions() as $function) {
        if (is_array($function) && $function[0] instanceof ClassLoader) {
            $loader = $function[0];
            break;
        }
    }

    expect($loader)->toBeInstanceOf(ClassLoader::class);

    $prefixes = $loader->getPrefixesPsr4();

    expect($prefixes)->toHaveKey('ReyemTech\\Hubspot\\');

    $paths = array_map(normalizePath(...), $prefixes['ReyemTech\\Hubspot\\']);

    expect($paths)->toContain(normalizePath(dirname(__DIR__, 2).'/src'));
});

it('has all six layer directories present under src/', function (): void {
    $srcPath = dirname(__DIR__, 2).'/src';

    foreach (['Gateway', 'Registry', 'Sync', 'Webhooks', 'Signals', 'Frontend'] as $layer) {
        expect(is_dir($srcPath.'/'.$layer))->toBeTrue("Expected src/{$layer} to exist.");
    }
});
