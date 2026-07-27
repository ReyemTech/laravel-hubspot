<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/**
 * Deploy-only and release-only workflows are excluded from the required-checks comparison by
 * this explicit, reviewed allowlist -- never by a name-pattern guess (e.g. matching against
 * "release*.yml" or "deploy*.yml"). Each entry here is a deliberate decision with its own reason,
 * checked every time a new workflow file is added.
 *
 * @return list<string>
 */
function requiredChecksAllowlistedWorkflowFiles(): array
{
    return [
        // Triggers on push to main only (see requiredChecksTriggersOnPullRequest()) -- cuts tags
        // and opens the release PR, never a pull-request gate a contributor's PR has to pass.
        'release-please.yml',

        // Does trigger on pull_request, but its single job is gated on
        // `github.actor == 'dependabot[bot]'` and merges the PR itself on green rather than
        // gating it -- it is PR-triggered automation, not a status check a contributor's own
        // PR needs to pass. See docs/repo/owner-gated-checklist.md's "Dependabot auto-merge"
        // section for the repository-setting half of this feature (auto-merge must also be
        // enabled at Settings -> General -> Pull Requests).
        'dependabot-auto-merge.yml',
    ];
}

/**
 * @return list<string>
 */
function requiredChecksWorkflowFiles(): array
{
    $directory = dirname(__DIR__, 2).'/.github/workflows';

    expect(is_dir($directory))->toBeTrue('Expected .github/workflows to exist.');

    $files = glob($directory.'/*.yml');

    if ($files === false) {
        throw new RuntimeException('Expected to be able to list .github/workflows/*.yml.');
    }

    sort($files);

    return $files;
}

/**
 * @param  array<array-key, mixed>  $value
 * @return array<string, mixed>
 */
function requiredChecksEnsureStringKeyedArray(array $value, string $context): array
{
    $result = [];

    foreach ($value as $key => $item) {
        if (! is_string($key)) {
            throw new RuntimeException("Expected {$context} to have only string keys.");
        }

        $result[$key] = $item;
    }

    return $result;
}

/**
 * @param  array<string, mixed>  $workflow
 */
function requiredChecksTriggersOnPullRequest(array $workflow): bool
{
    $on = $workflow['on'] ?? null;

    if (is_string($on)) {
        return $on === 'pull_request';
    }

    if (is_array($on)) {
        if (array_is_list($on)) {
            return in_array('pull_request', $on, true);
        }

        return array_key_exists('pull_request', requiredChecksEnsureStringKeyedArray($on, '"on"'));
    }

    return false;
}

/**
 * Every job id (the YAML key under `jobs:`, not the human-readable `name:` string) declared by
 * every pull-request-triggered workflow, keyed by workflow filename so a mismatch names its
 * source file.
 *
 * @return array<string, list<string>>
 */
function requiredChecksShippedJobsByFile(): array
{
    $result = [];

    foreach (requiredChecksWorkflowFiles() as $path) {
        $filename = basename($path);

        if (in_array($filename, requiredChecksAllowlistedWorkflowFiles(), true)) {
            continue;
        }

        $parsed = Yaml::parseFile($path);

        if (! is_array($parsed)) {
            throw new RuntimeException("Expected {$filename} to parse to an array.");
        }

        $workflow = requiredChecksEnsureStringKeyedArray($parsed, $filename);

        if (! requiredChecksTriggersOnPullRequest($workflow)) {
            continue;
        }

        $jobs = $workflow['jobs'] ?? null;

        if (! is_array($jobs)) {
            throw new RuntimeException("Expected {$filename} to declare a \"jobs\" map.");
        }

        $result[$filename] = array_keys(requiredChecksEnsureStringKeyedArray($jobs, "{$filename} \"jobs\""));
    }

    return $result;
}

/**
 * @return list<string> the flat, de-duplicated set of every shipped job id
 */
function requiredChecksShippedJobs(): array
{
    $jobs = [];

    foreach (requiredChecksShippedJobsByFile() as $fileJobs) {
        array_push($jobs, ...$fileJobs);
    }

    return array_values(array_unique($jobs));
}

/**
 * Reads the machine-checked required-checks list out of the "Required status checks" section of
 * the owner-gated checklist: every backtick-quoted token in that section is treated as one
 * documented job id. This is parsed from the real file, not asserted against a copied string, so
 * a job added to a workflow without being documented -- or a documented name that no longer
 * matches any job -- both fail this test.
 *
 * @return list<string>
 */
function requiredChecksDocumentedJobs(): array
{
    $path = dirname(__DIR__, 2).'/docs/repo/owner-gated-checklist.md';

    expect(is_file($path))->toBeTrue('Expected docs/repo/owner-gated-checklist.md to exist.');

    $contents = file_get_contents($path);

    if ($contents === false) {
        throw new RuntimeException('Expected to be able to read docs/repo/owner-gated-checklist.md.');
    }

    if (! preg_match('/### Required status checks\b(.*?)(?:\n##\s|\z)/s', $contents, $matches)) {
        throw new RuntimeException('Expected a "### Required status checks" section in docs/repo/owner-gated-checklist.md.');
    }

    $section = $matches[1];

    preg_match_all('/`([a-zA-Z0-9][a-zA-Z0-9._-]*)`/', $section, $codeMatches);

    return array_values(array_unique($codeMatches[1]));
}

it('lists a required status check for every pull-request-triggered job this phase shipped', function (): void {
    $shipped = requiredChecksShippedJobs();
    $documented = requiredChecksDocumentedJobs();

    $missing = array_values(array_diff($shipped, $documented));

    expect($missing)->toBe([], 'Expected every shipped job to be documented as a required check. Missing: '.implode(', ', $missing));
});

it('documents no required status check that does not resolve to a real job', function (): void {
    $shipped = requiredChecksShippedJobs();
    $documented = requiredChecksDocumentedJobs();

    $stale = array_values(array_diff($documented, $shipped));

    expect($stale)->toBe([], 'Expected every documented required check to name a real job. Stale: '.implode(', ', $stale));
});

it('names every pull-request-triggered workflow file at least once in the required-checks list, per file', function (): void {
    $documented = requiredChecksDocumentedJobs();

    foreach (requiredChecksShippedJobsByFile() as $filename => $jobs) {
        foreach ($jobs as $job) {
            expect(in_array($job, $documented, true))->toBeTrue(
                "Expected {$filename}'s \"{$job}\" job to be a documented required check."
            );
        }
    }
});

it('excludes release-please.yml from the comparison via an explicit allowlist, not a name-pattern guess', function (): void {
    expect(requiredChecksAllowlistedWorkflowFiles())->toContain('release-please.yml');

    $shippedFiles = array_keys(requiredChecksShippedJobsByFile());

    expect($shippedFiles)->not->toContain('release-please.yml');
});

it('ships at least the required checks named in STANDARDS Sec.12b and D-29', function (): void {
    $shipped = requiredChecksShippedJobs();

    foreach ([
        'tests',
        'composer-validate',
        'manifest',
        'phpstan',
        'pint',
        'code-shape',
        'source-hygiene',
        'quality-gates-fire',
        'mutation',
        'architecture-tests',
        'arch-rules-fire',
        'composer-audit',
        'bc-check',
        'commitlint',
        'governance',
        'coverage',
        'build',
    ] as $expectedJob) {
        expect($shipped)->toContain($expectedJob);
    }
});
