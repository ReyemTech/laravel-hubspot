<?php

declare(strict_types=1);

/**
 * release-please's DefaultVersioningStrategy (src/versioning-strategies/default.ts) only
 * consults `bumpPatchForMinorPreMajor` when a release contains a `feat:` commit and no
 * breaking change: with it true and the current version pre-1.0.0, a feature bump is
 * downgraded from Minor to Patch (e.g. 0.1.0 -> 0.1.1 instead of 0.1.0 -> 0.2.0). That
 * contradicts STANDARDS' policy that feature commits bump the minor version, and is
 * unrelated to what `bump-minor-pre-major` alone already guarantees: breaking changes
 * bump the minor (not the major) while pre-1.0.0, with no effect on feature commits.
 *
 * @return array<string, mixed>
 */
function releasePleaseRootPackageConfig(): array
{
    $path = dirname(__DIR__, 2).'/release-please-config.json';

    expect(is_file($path))->toBeTrue('Expected release-please-config.json to exist.');

    $contents = file_get_contents($path);

    if ($contents === false) {
        throw new RuntimeException('Expected to be able to read release-please-config.json.');
    }

    $config = json_decode($contents, true);

    if (! is_array($config)) {
        throw new RuntimeException('Expected release-please-config.json to decode to an array.');
    }

    $packages = $config['packages'] ?? null;

    if (! is_array($packages)) {
        throw new RuntimeException('Expected release-please-config.json to declare a "packages" map.');
    }

    $root = $packages['.'] ?? null;

    if (! is_array($root)) {
        throw new RuntimeException('Expected release-please-config.json to configure the "." package.');
    }

    /** @var array<string, mixed> $root */
    return $root;
}

it('keeps breaking changes bumping the minor, not the major, before 1.0.0', function (): void {
    $root = releasePleaseRootPackageConfig();

    expect($root)->toHaveKey('bump-minor-pre-major');
    expect($root['bump-minor-pre-major'])->toBeTrue();
});

it('never downgrades a feat commit to a patch bump before 1.0.0', function (): void {
    $root = releasePleaseRootPackageConfig();

    // bump-patch-for-minor-pre-major converts a feat commit's normal minor
    // bump into a patch bump while pre-1.0.0 (release-please's
    // DefaultVersioningStrategy::determineReleaseType), contradicting
    // STANDARDS' policy that feature commits bump the minor version.
    expect($root)->not->toHaveKey('bump-patch-for-minor-pre-major');
});
