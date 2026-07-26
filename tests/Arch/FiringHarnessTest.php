<?php

declare(strict_types=1);

/**
 * Proves tests/Arch/rules.json (the canonical rule manifest) and
 * tests/Arch/Fixtures/ (the violation fixtures scripts/ci/verify-arch-rules-fire.sh
 * plays back) agree with each other. An unfired rule and a missing fixture are the
 * same defect: a rule declared here without a fixture would silently inherit a
 * firing proof it never earned the moment a later phase adds it.
 */

/**
 * @return array{rules: array<int, array{id: string, description: string, fixtures: array<int, string>}>}
 */
function reyemtech_hubspot_arch_manifest(): array
{
    $path = __DIR__.'/rules.json';

    /** @var array{rules: array<int, array{id: string, description: string, fixtures: array<int, string>}>} $decoded */
    $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    return $decoded;
}

test('the manifest declares at least one rule', function (): void {
    $manifest = reyemtech_hubspot_arch_manifest();

    expect($manifest['rules'])->not->toBeEmpty();
});

test('every rule in the manifest has a unique id', function (): void {
    $manifest = reyemtech_hubspot_arch_manifest();

    $ids = array_map(static fn (array $rule): string => $rule['id'], $manifest['rules']);

    expect($ids)->toEqual(array_unique($ids));
});

test('every rule in the manifest declares at least one fixture that exists on disk', function (): void {
    $manifest = reyemtech_hubspot_arch_manifest();

    foreach ($manifest['rules'] as $rule) {
        expect($rule['fixtures'])
            ->not->toBeEmpty("Rule {$rule['id']} declares no fixtures in tests/Arch/rules.json.");

        foreach ($rule['fixtures'] as $fixture) {
            $fixturePath = __DIR__.'/Fixtures/'.$fixture;

            expect(is_file($fixturePath))
                ->toBeTrue("Rule {$rule['id']}'s fixture '{$fixture}' does not exist at {$fixturePath}.");
        }
    }
});

test('every fixture file on disk belongs to a declared rule', function (): void {
    $manifest = reyemtech_hubspot_arch_manifest();

    $declaredFixtures = [];
    foreach ($manifest['rules'] as $rule) {
        foreach ($rule['fixtures'] as $fixture) {
            $declaredFixtures[$fixture] = true;
        }
    }

    $fixturesDir = __DIR__.'/Fixtures';
    $onDisk = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($fixturesDir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($onDisk as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = ltrim(str_replace($fixturesDir, '', $file->getPathname()), DIRECTORY_SEPARATOR);
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);

        expect($declaredFixtures)
            ->toHaveKey($relative, "Fixture file '{$relative}' exists but is not declared by any rule in rules.json.");
    }
});

test('every declared fixture file declares a namespace under ReyemTech\\Hubspot\\', function (): void {
    $manifest = reyemtech_hubspot_arch_manifest();

    foreach ($manifest['rules'] as $rule) {
        foreach ($rule['fixtures'] as $fixture) {
            $fixturePath = __DIR__.'/Fixtures/'.$fixture;
            $contents = (string) file_get_contents($fixturePath);

            preg_match('/^namespace\s+([^;]+);/m', $contents, $matches);

            expect($matches[1] ?? null)
                ->toStartWith('ReyemTech\\Hubspot\\', "Fixture '{$fixture}' for rule {$rule['id']} must declare a namespace under ReyemTech\\Hubspot\\ so the harness can place it.");
        }
    }
});
