<?php

declare(strict_types=1);

/**
 * Parses the real, shipped `phpunit.xml.dist` rather than trusting a comment or a copied
 * string, matching this repository's established pattern (see MatrixShapeTest.php,
 * ComposerManifestTest.php) — so a future edit that drops the Arch testsuite fails this test
 * immediately instead of silently reintroducing the bug this file exists to prevent: a bare
 * `vendor/bin/pest` run (no path argument) skipping every architecture test because
 * `<testsuites>` never declared where to find them.
 *
 * Registering a suite is therefore never a standalone edit: `phpunit.xml.dist` runs with
 * `failOnWarning` enabled, so a declared testsuite pointing at a directory with no test files in
 * it is itself a build failure. Add the `<testsuite>` element and this file's expected set in the
 * SAME commit as that suite's first test — never ahead of it. (The `Unit` suite arrived in plan
 * 02-04 with `tests/Unit/Gateway/AssociationPairTest.php`, for exactly this reason.)
 *
 * @return list<string>
 */
function phpunitDistTestsuiteNames(): array
{
    $path = dirname(__DIR__, 2).'/phpunit.xml.dist';

    expect(is_file($path))->toBeTrue('Expected phpunit.xml.dist to exist.');

    $document = new DOMDocument;
    $loaded = $document->load($path);

    if (! $loaded) {
        throw new RuntimeException('Expected phpunit.xml.dist to parse as XML.');
    }

    $names = [];

    foreach ($document->getElementsByTagName('testsuite') as $testsuite) {
        $name = $testsuite->getAttribute('name');

        if ($name === '') {
            throw new RuntimeException('Expected every <testsuite> to declare a non-empty "name" attribute.');
        }

        $names[] = $name;
    }

    return $names;
}

it('registers the Arch testsuite, so a bare `vendor/bin/pest` run exercises the architecture tests', function (): void {
    expect(phpunitDistTestsuiteNames())->toContain('Arch');
});

it('registers the Unit testsuite, so a pure value-object test under tests/Unit actually runs', function (): void {
    expect(phpunitDistTestsuiteNames())->toContain('Unit');
});

it('still registers Feature and Ci alongside Unit and Arch — this is additive, not a replacement', function (): void {
    expect(phpunitDistTestsuiteNames())->toEqualCanonicalizing(['Feature', 'Unit', 'Ci', 'Arch']);
});
