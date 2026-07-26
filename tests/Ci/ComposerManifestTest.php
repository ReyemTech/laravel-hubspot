<?php

declare(strict_types=1);

use Composer\Semver\Semver;

beforeEach(function (): void {
    $composerJsonPath = dirname(__DIR__, 2).'/composer.json';

    expect(is_file($composerJsonPath))->toBeTrue('Expected composer.json to exist.');

    /** @var array<string, mixed> $composer */
    $composer = json_decode((string) file_get_contents($composerJsonPath), true, flags: JSON_THROW_ON_ERROR);

    $this->composer = $composer;
    $this->require = $composer['require'] ?? [];
});

it('has exactly seven production requires', function (): void {
    expect($this->require)->toHaveCount(7);
});

it('requires exactly the seven approved packages, no eighth', function (): void {
    expect(array_keys($this->require))->toEqualCanonicalizing([
        'php',
        'hubspot/api-client',
        'illuminate/contracts',
        'illuminate/support',
        'illuminate/database',
        'laravel/prompts',
        'illuminate/view',
    ]);
});

it('constrains every illuminate package to ^11.0|^12.0|^13.0', function (): void {
    foreach (['illuminate/contracts', 'illuminate/support', 'illuminate/database', 'illuminate/view'] as $package) {
        expect($this->require)->toHaveKey($package);
        expect($this->require[$package])->toBe('^11.0|^12.0|^13.0');
    }
});

it('admits PHP 8.3 and rejects PHP 8.2', function (): void {
    expect($this->require)->toHaveKey('php');

    $phpConstraint = $this->require['php'];

    expect(Semver::satisfies('8.3.0', $phpConstraint))->toBeTrue();
    expect(Semver::satisfies('8.2.0', $phpConstraint))->toBeFalse();
});

it('carries no version key, since Packagist derives the version from the git tag', function (): void {
    expect($this->composer)->not->toHaveKey('version');
});
