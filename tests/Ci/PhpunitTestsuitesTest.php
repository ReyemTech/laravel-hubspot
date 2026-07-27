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

it('still registers Feature and Ci alongside Arch — this is additive, not a replacement', function (): void {
    expect(phpunitDistTestsuiteNames())->toEqualCanonicalizing(['Feature', 'Ci', 'Arch']);
});
