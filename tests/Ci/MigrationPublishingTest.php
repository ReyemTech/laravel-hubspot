<?php

declare(strict_types=1);

/**
 * Guards the `vendor:publish` tag names against a silent rename.
 *
 * A publish tag is public API in the least visible way a package has: it lives in somebody else's
 * deploy script, their Dockerfile, their CI job and their onboarding README, none of which this
 * repository can see and none of which fail loudly when the tag stops existing —
 * `php artisan vendor:publish --tag=hubspot-migrations` simply publishes nothing and exits 0.
 *
 * Parsed out of the shipped `src/ServiceProvider.php` rather than asserted against a copied constant,
 * the same way `PhpunitTestsuitesTest` parses the real `phpunit.xml.dist`: a test that asserted a
 * string against itself would survive the rename it exists to catch.
 *
 * @return list<string>
 */
function reyemtechHubspotPublishTags(): array
{
    $path = dirname(__DIR__, 2).'/src/ServiceProvider.php';

    expect(is_file($path))->toBeTrue('Expected src/ServiceProvider.php to exist.');

    $source = (string) file_get_contents($path);

    // `[^;]*?` deliberately cannot cross a statement boundary, so each match is one publishes()
    // call and its own tag rather than the span between two of them.
    preg_match_all("/publishes\\([^;]*?,\\s*'([a-z0-9-]+)'\\s*\\);/s", $source, $matches);

    return $matches[1];
}

it('publishes the package migrations under the hubspot-migrations tag', function (): void {
    expect(reyemtechHubspotPublishTags())->toContain('hubspot-migrations');
});

it('still publishes the config under hubspot-config — the migration tag is additive', function (): void {
    expect(reyemtechHubspotPublishTags())->toContain('hubspot-config');
});

it('declares exactly the two publish tags, so a third arrives with its own documentation', function (): void {
    expect(reyemtechHubspotPublishTags())->toEqualCanonicalizing(['hubspot-config', 'hubspot-migrations']);
});

it('names the published migration in README, so the tag is discoverable without reading src', function (): void {
    $readme = (string) file_get_contents(dirname(__DIR__, 2).'/README.md');

    expect($readme)->toContain('hubspot-migrations');
});
