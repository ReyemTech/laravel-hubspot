<?php

declare(strict_types=1);

/**
 * **The layer boundaries permit the code the next three phases are designed to write.**
 *
 * `scripts/ci/verify-arch-rules-fire.sh` proves every rule in `rules.json` CAN go red under a violation
 * fixture. That is half a gate. This file proves the other half for the case plan 02-05 found the hard
 * way: a `Registry` resolver implementing `Gateway\Contracts\AssociationTypeResolver` and throwing
 * `Exceptions\AssociationTypeException` on a miss — which the contract's non-nullable return type,
 * STANDARDS §9 and 02-CONTEXT.md rule 3 all require of it — used to fail R2 with:
 *
 * > Expecting 'ReyemTech\Hubspot\Registry' to only use 'ReyemTech\Hubspot\Gateway'. However, it also
 * > uses 'ReyemTech\Hubspot\Exceptions\AssociationTypeException'.
 *
 * `ReyemTech\Hubspot\Exceptions` was in no layer's allow-list, so R2 through R5 forbade every layer from
 * throwing the package's own exceptions — making "a single shared hierarchy consumers catch" and "never
 * let a raw SDK exception reach userland" (STANDARDS §9) mutually impossible. The hierarchy is a
 * cross-cutting namespace, not a layer, and the allow-lists now say so. This test is what stops that
 * from being undone by a well-meaning tightening later.
 *
 * ## How it works, and why it runs a nested pest process
 *
 * `pest-plugin-arch` reads the PSR-4 prefixes registered on Composer's in-memory `ClassLoader`, and it
 * deliberately ignores every directory under the project's own `tests/` (see
 * `Pest\Arch\Support\Composer::userNamespacesWithDirectories()`). A fixture living under `tests/` can
 * therefore never be seen by an `arch()` expectation in this process, however it is autoloaded. The
 * only way to evaluate a real rule against a real file is the mechanism
 * `verify-arch-rules-fire.sh` already uses: assemble a scratch copy of `src/` with the fixture merged
 * in, and run one filtered pest process against it with `scripts/ci/arch-fire-bootstrap.php` overriding
 * the `ReyemTech\Hubspot\` prefix. Each run takes about a quarter of a second and writes nothing into
 * the working tree — every artefact lives under a temporary directory this file removes again.
 *
 * The second test is the non-vacuity guard, and it is not optional: `R2` passes trivially over an empty
 * `Registry` namespace, so a broken overlay — a bootstrap that silently failed to override, a fixture
 * copied to the wrong path — would make the first test green for the worst possible reason. Playing
 * R2's committed violation fixture through the identical helper and requiring red is what proves the
 * scratch tree is the tree the rule read.
 */

/**
 * Runs one architecture rule against a scratch `src/` tree carrying the given seam fixtures.
 *
 * @param  array<string, string>  $fixtures  path under `tests/Arch/` => directory under the scratch
 *                                           `src/` root the file is copied into
 * @return array{exitCode: int, output: string}
 */
function reyemtech_hubspot_arch_rule_over_fixtures(string $ruleId, array $fixtures): array
{
    $root = dirname(__DIR__, 2);
    $scratch = reyemtech_hubspot_scratch_directory();

    reyemtech_hubspot_copy_directory($root.'/src', $scratch);

    foreach ($fixtures as $fixture => $targetDirectory) {
        $source = $root.'/tests/Arch/'.$fixture;
        $destination = $scratch.'/'.$targetDirectory;

        if (! is_dir($destination) && ! mkdir($destination, 0o777, true)) {
            throw new RuntimeException("Could not create the scratch directory {$destination}.");
        }

        if (! copy($source, $destination.'/'.basename($fixture))) {
            throw new RuntimeException("Could not copy the seam fixture {$source}.");
        }
    }

    $command = sprintf(
        'cd %s && ARCH_FIRE_SCRATCH_SRC=%s vendor/bin/pest --bootstrap %s --filter=%s '
        .'--do-not-cache-result --colors=never %s 2>&1',
        escapeshellarg($root),
        escapeshellarg($scratch),
        escapeshellarg('scripts/ci/arch-fire-bootstrap.php'),
        escapeshellarg($ruleId.':'),
        escapeshellarg('tests/Arch/LayerBoundariesTest.php'),
    );

    $lines = [];
    $exitCode = 0;
    exec($command, $lines, $exitCode);

    reyemtech_hubspot_remove_directory($scratch);

    return ['exitCode' => $exitCode, 'output' => implode("\n", $lines)];
}

function reyemtech_hubspot_scratch_directory(): string
{
    $path = tempnam(sys_get_temp_dir(), 'seam-');

    if ($path === false) {
        throw new RuntimeException('Could not create a temporary directory for the seam proof.');
    }

    unlink($path);

    if (! mkdir($path, 0o777, true)) {
        throw new RuntimeException("Could not create the scratch directory {$path}.");
    }

    return $path;
}

function reyemtech_hubspot_copy_directory(string $from, string $to): void
{
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($entries as $entry) {
        if (! $entry instanceof SplFileInfo) {
            continue;
        }

        $target = $to.DIRECTORY_SEPARATOR.substr($entry->getPathname(), strlen($from) + 1);

        if ($entry->isDir()) {
            if (! is_dir($target) && ! mkdir($target, 0o777, true)) {
                throw new RuntimeException("Could not create {$target}.");
            }

            continue;
        }

        if (! copy($entry->getPathname(), $target)) {
            throw new RuntimeException("Could not copy {$entry->getPathname()} to {$target}.");
        }
    }
}

function reyemtech_hubspot_remove_directory(string $path): void
{
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($entries as $entry) {
        if (! $entry instanceof SplFileInfo) {
            continue;
        }

        if ($entry->isDir()) {
            rmdir($entry->getPathname());

            continue;
        }

        unlink($entry->getPathname());
    }

    rmdir($path);
}

test('a layer that throws the package exception hierarchy passes its own boundary rule', function (string $rule, string $fixture, string $directory): void {
    $result = reyemtech_hubspot_arch_rule_over_fixtures($rule, [$fixture => $directory]);

    // `toContain()` is variadic in Pest, so a failure message passed to it would be read as a second
    // needle. Every assertion here is therefore written as a boolean with a message, which is also what
    // makes the child process's own output readable in the failure — without it, a red run here reports
    // only "false is not true" about a process nobody can see.
    expect(str_contains($result['output'], 'No tests found'))->toBeFalse(
        "The {$rule} filter matched no test, so this proof would have been vacuous.\n\n{$result['output']}",
    );

    expect(str_contains($result['output'], 'Tests:    1 passed'))->toBeTrue(
        "{$rule} did not pass with tests/Arch/{$fixture} present. If the message below names "
        ."'ReyemTech\\Hubspot\\Exceptions', the layer allow-lists have been narrowed again and the layer can no "
        ."longer throw the package's own exceptions — which STANDARDS §9 requires of every one of them.\n\n"
        .$result['output'],
    );

    expect($result['exitCode'])->toBe(0, $result['output']);
})->with([
    // The resolver seam itself: what REG-02 ships, and the case plan 02-05 reproduced as a blocker.
    'R2, a Registry resolver that throws on a miss' => [
        'R2',
        'SeamFixtures/Registry/RegistryResolverThrowingOnAMiss.php',
        'Registry',
    ],
    'R3, Sync throwing an ObjectTypeException' => [
        'R3',
        'SeamFixtures/Sync/SyncThrowingAnObjectTypeException.php',
        'Sync',
    ],
    'R4, Webhooks throwing a ConfigurationException' => [
        'R4',
        'SeamFixtures/Webhooks/WebhooksThrowingAConfigurationException.php',
        'Webhooks',
    ],
    'R5, Signals throwing an AssociationTypeException' => [
        'R5',
        'SeamFixtures/Signals/SignalsThrowingAnAssociationTypeException.php',
        'Signals',
    ],
]);

test('the scratch overlay the proof above relies on is the tree the rule actually reads', function (): void {
    // R2's own committed violation fixture, played back through the identical helper. R2 passes
    // vacuously over an empty Registry namespace, so if the overlay were not being seen this run would
    // report green and every assertion in the test above would be worthless.
    $result = reyemtech_hubspot_arch_rule_over_fixtures('R2', [
        'Fixtures/R2/RegistryDependsOnSync.php' => 'Registry',
        'Fixtures/R2/SyncTarget.php' => 'Sync',
    ]);

    expect($result['exitCode'] === 0)->toBeFalse(
        'R2 stayed green with its own violation fixture in the scratch tree, so the scratch tree is not what the '
        ."rule is reading and the seam proof above proves nothing.\n\n".$result['output'],
    );

    expect(str_contains($result['output'], 'ReyemTech\Hubspot\Sync'))->toBeTrue(
        "R2 went red for a reason other than the fixture's forbidden dependency.\n\n".$result['output'],
    );
});
