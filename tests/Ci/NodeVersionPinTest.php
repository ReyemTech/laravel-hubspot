<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/**
 * pnpm 11.17.0 (pinned by `pnpm/action-setup@v4` in both js.yml and
 * docs.yml) declares `"engines": {"node": ">=22.13"}` on its own
 * package.json (confirmed via `npm view pnpm@11.17.0 engines`) -- this is
 * pnpm's own runtime floor, independent of any workspace's Vitest/Astro
 * engines field. A Node 20 pin satisfies resources/js's Vitest
 * (`^20.0.0 || ^22.0.0 || >=24.0.0`) but not pnpm itself, and fails hard at
 * runtime with `Error [ERR_UNKNOWN_BUILTIN_MODULE]: No such built-in module:
 * node:sqlite` the moment pnpm executes on the pinned runner (confirmed
 * against the real GitHub Actions log for PR #3). Node 22 satisfies pnpm,
 * Vitest, and (per docs.yml) Astro's floor of >=22.12.0, so every
 * Node-consuming job is locked to Node >=22 here.
 *
 * @return array<string, mixed>
 */
function nodeSetupStepWithForWorkflowJob(string $workflowFile, string $jobId): array
{
    $workflowPath = dirname(__DIR__, 2)."/.github/workflows/{$workflowFile}";

    expect(is_file($workflowPath))->toBeTrue("Expected .github/workflows/{$workflowFile} to exist.");

    $workflow = Yaml::parseFile($workflowPath);

    if (! is_array($workflow)) {
        throw new RuntimeException("Expected {$workflowFile} to parse to an array.");
    }

    $jobs = $workflow['jobs'] ?? null;

    if (! is_array($jobs)) {
        throw new RuntimeException("Expected {$workflowFile} to declare a \"jobs\" map.");
    }

    $job = $jobs[$jobId] ?? null;

    if (! is_array($job)) {
        throw new RuntimeException("Expected {$workflowFile} to declare a \"{$jobId}\" job.");
    }

    $steps = $job['steps'] ?? null;

    if (! is_array($steps)) {
        throw new RuntimeException("Expected the \"{$jobId}\" job to declare \"steps\".");
    }

    foreach ($steps as $step) {
        if (! is_array($step)) {
            continue;
        }

        if (($step['uses'] ?? null) !== null && str_starts_with((string) $step['uses'], 'actions/setup-node@')) {
            $with = $step['with'] ?? null;

            if (! is_array($with)) {
                throw new RuntimeException("Expected the \"{$jobId}\" job's setup-node step to declare \"with\".");
            }

            /** @var array<string, mixed> $with */
            return $with;
        }
    }

    throw new RuntimeException("Expected the \"{$jobId}\" job to declare an actions/setup-node step.");
}

it('pins js.yml\'s coverage job to Node >=22, satisfying pnpm 11.17\'s own >=22.13 engines floor', function (): void {
    $with = nodeSetupStepWithForWorkflowJob('js.yml', 'coverage');

    $nodeVersion = (string) ($with['node-version'] ?? '');

    expect((int) $nodeVersion)->toBeGreaterThanOrEqual(22);
});

it('keeps docs.yml\'s build job pinned to Node >=22, matching Astro\'s >=22.12.0 floor', function (): void {
    $with = nodeSetupStepWithForWorkflowJob('docs.yml', 'build');

    $nodeVersion = (string) ($with['node-version'] ?? '');

    expect((int) $nodeVersion)->toBeGreaterThanOrEqual(22);
});
