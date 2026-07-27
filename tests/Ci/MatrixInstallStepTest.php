<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/**
 * Parses the real `ci.yml` and returns the `tests` job's step list, narrowed
 * to a concrete array shape. `.gitignore` deliberately excludes
 * `composer.lock` (committing one would pin every consumer's resolution and
 * defeat the point of testing a version matrix), so the "Install dependencies"
 * step must resolve its own lock in-memory per matrix cell. A step that hands
 * `composer update` a positional package list — e.g.
 * `composer update laravel/framework:12.* --prefer-stable` — fails hard on a
 * fresh checkout with "Cannot update only a partial set of packages without a
 * lock file present", because Composer treats named-argument `update` as a
 * partial update, which requires an existing lock to diff against. This test
 * locks the step to the lockless-safe shape: a full `composer update` (no
 * positional package arguments) with per-cell constraints passed via
 * `--with`, which temporarily overrides the resolved constraint without
 * requiring a preexisting lock file.
 *
 * @return list<array<string, mixed>>
 */
function ciTestsJobSteps(): array
{
    $workflowPath = dirname(__DIR__, 2).'/.github/workflows/ci.yml';

    expect(is_file($workflowPath))->toBeTrue('Expected .github/workflows/ci.yml to exist.');

    $workflow = Yaml::parseFile($workflowPath);

    if (! is_array($workflow)) {
        throw new RuntimeException('Expected .github/workflows/ci.yml to parse to an array.');
    }

    $jobs = $workflow['jobs'] ?? null;

    if (! is_array($jobs)) {
        throw new RuntimeException('Expected ci.yml to declare a "jobs" map.');
    }

    $testsJob = $jobs['tests'] ?? null;

    if (! is_array($testsJob)) {
        throw new RuntimeException('Expected ci.yml to declare a "tests" job.');
    }

    $steps = $testsJob['steps'] ?? null;

    if (! is_array($steps)) {
        throw new RuntimeException('Expected the "tests" job to declare "steps".');
    }

    /** @var list<array<string, mixed>> $steps */
    return $steps;
}

function ciInstallDependenciesStepRun(): string
{
    foreach (ciTestsJobSteps() as $step) {
        if (($step['name'] ?? null) === 'Install dependencies') {
            $run = $step['run'] ?? null;

            if (! is_string($run)) {
                throw new RuntimeException('Expected the "Install dependencies" step to declare a string "run".');
            }

            return $run;
        }
    }

    throw new RuntimeException('Expected the "tests" job to declare an "Install dependencies" step.');
}

it('never passes a positional package argument to composer update, since that requires a preexisting lock file', function (): void {
    $run = ciInstallDependenciesStepRun();

    // A partial update names packages as bare positional arguments, e.g.
    // "composer update laravel/framework:12.*". The lockless-safe shape
    // constrains via --with=pkg:constraint instead, so this must never
    // contain a package spec that isn't directly preceded by --with,
    // anywhere in the command -- not only immediately after "composer
    // update". See ciRunHasPositionalPackageSpec() for why a simple regex
    // pair does not catch a spec appended after the --with flags.
    expect(ciRunHasPositionalPackageSpec($run))->toBeFalse();
});

it('detects a positional package spec appended after every --with flag, not only one immediately after "composer update"', function (): void {
    // A regex anchored to "composer update <spec>" only ever inspects the
    // token(s) directly after "composer update"; a negative lookahead for
    // "--with anywhere later" disables itself the instant the command
    // contains a legitimate --with flag, which the real step always does.
    // Neither shape catches a positional spec smuggled in after the
    // legitimate --with options -- exactly the shape Composer still treats
    // as a partial (lockfile-dependent) update.
    $maliciousRun = 'composer update --with illuminate/contracts:12.* --with illuminate/support:12.* laravel/framework:12.* --prefer-stable --prefer-dist --no-interaction';

    expect(ciRunHasPositionalPackageSpec($maliciousRun))->toBeTrue();
});

it('does not flag a package spec that is directly preceded by --with', function (): void {
    $run = 'composer update --with illuminate/contracts:12.* --with orchestra/testbench:10.* --prefer-stable --prefer-dist --no-interaction';

    expect(ciRunHasPositionalPackageSpec($run))->toBeFalse();
});

it('flags a positional package spec with no version constraint at all', function (): void {
    $maliciousRun = 'composer update --with illuminate/contracts:12.* laravel/framework --prefer-stable';

    expect(ciRunHasPositionalPackageSpec($maliciousRun))->toBeTrue();
});

it('constrains illuminate/* and orchestra/testbench per matrix cell via --with, not composer.json edits', function (): void {
    $run = ciInstallDependenciesStepRun();

    foreach (['illuminate/contracts', 'illuminate/support', 'illuminate/database', 'illuminate/view'] as $package) {
        expect($run)->toContain("--with {$package}:\${{ matrix.laravel }}");
    }

    expect($run)->toContain('--with orchestra/testbench:${{ matrix.testbench }}');
});

it('runs a full update honoring the stability axis', function (): void {
    $run = ciInstallDependenciesStepRun();

    expect($run)->toContain('--${{ matrix.stability }}');
});
