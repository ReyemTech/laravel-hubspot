<?php

declare(strict_types=1);

use Composer\Semver\Semver;

/**
 * @return array<string, mixed>
 */
function loadComposerManifest(): array
{
    $composerJsonPath = dirname(__DIR__, 2).'/composer.json';

    expect(is_file($composerJsonPath))->toBeTrue('Expected composer.json to exist.');

    $composer = json_decode((string) file_get_contents($composerJsonPath), true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($composer)) {
        throw new RuntimeException('Expected composer.json to decode to an array.');
    }

    $result = [];

    foreach ($composer as $key => $value) {
        if (! is_string($key)) {
            throw new RuntimeException('Expected composer.json to have only string keys.');
        }

        $result[$key] = $value;
    }

    return $result;
}

/**
 * @return array<string, string>
 */
function composerManifestRequires(): array
{
    $composer = loadComposerManifest();

    $require = $composer['require'] ?? [];

    if (! is_array($require)) {
        throw new RuntimeException('Expected composer.json "require" to be an array.');
    }

    $result = [];

    foreach ($require as $package => $constraint) {
        if (! is_string($package) || ! is_string($constraint)) {
            throw new RuntimeException('Expected every "require" entry to be a string package mapped to a string constraint.');
        }

        $result[$package] = $constraint;
    }

    return $result;
}

it('has exactly seven production requires', function (): void {
    expect(composerManifestRequires())->toHaveCount(7);
});

it('requires exactly the seven approved packages, no eighth', function (): void {
    expect(array_keys(composerManifestRequires()))->toEqualCanonicalizing([
        'php',
        'hubspot/api-client',
        'illuminate/contracts',
        'illuminate/support',
        'illuminate/database',
        'laravel/prompts',
        'illuminate/view',
    ]);
});

it('constrains every illuminate package to ^12.0|^13.0, since Laravel 11 was dropped', function (): void {
    $require = composerManifestRequires();

    foreach (['illuminate/contracts', 'illuminate/support', 'illuminate/database', 'illuminate/view'] as $package) {
        expect($require)->toHaveKey($package);
        expect($require[$package])->toBe('^12.0|^13.0');
    }
});

it('admits PHP 8.3 and rejects PHP 8.2', function (): void {
    $require = composerManifestRequires();

    expect($require)->toHaveKey('php');

    $phpConstraint = $require['php'];

    expect(Semver::satisfies('8.3.0', $phpConstraint))->toBeTrue();
    expect(Semver::satisfies('8.2.0', $phpConstraint))->toBeFalse();
});

it('carries no version key, since Packagist derives the version from the git tag', function (): void {
    expect(loadComposerManifest())->not->toHaveKey('version');
});
