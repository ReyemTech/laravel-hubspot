<?php

declare(strict_types=1);
use Composer\Autoload\ClassLoader;

/**
 * Bootstrap used exclusively by scripts/ci/verify-arch-rules-fire.sh, via
 * `vendor/bin/pest --bootstrap`. It overrides the ReyemTech\Hubspot\ PSR-4 mapping,
 * for this process only, to point at a scratch directory the harness assembled
 * (real src/ plus exactly one rule's violation fixture). This never touches the
 * real vendor/ or src/ on disk — it edits the already-registered, in-memory
 * Composer\Autoload\ClassLoader instance for the lifetime of this one pest run.
 *
 * Why not a scratch git worktree with a symlinked vendor/ instead (the more obvious
 * approach)? It was tried first and does not work: Composer's generated
 * vendor/composer/autoload_*.php files compute the project root via
 * `dirname(__DIR__)`, and PHP's __DIR__ resolves *through* a symlink to the real
 * target directory rather than the symlink's own location. A vendor/ symlinked into
 * a scratch worktree therefore still resolves ReyemTech\Hubspot\ back to the real
 * repo's (empty) src/, not the scratch tree's — the fixture would never be seen.
 * Some package binaries (pest's own vendor/bin/pest proxy, observed directly) go
 * further and re-require the *real* vendor/autoload.php via the same symlink-following
 * __DIR__ logic even when a scratch vendor/autoload.php already loaded successfully,
 * producing a fatal "Cannot redeclare class" error. This PSR-4-override approach
 * sidesteps all of that: it runs against the real, already-working vendor/ install,
 * and only ever touches the one Composer prefix mapping this package owns.
 */
$scratchSrc = getenv('ARCH_FIRE_SCRATCH_SRC');

if ($scratchSrc === false || $scratchSrc === '') {
    fwrite(STDERR, "arch-fire-bootstrap.php: ARCH_FIRE_SCRATCH_SRC is not set.\n");

    exit(1);
}

if (! is_dir($scratchSrc)) {
    fwrite(STDERR, "arch-fire-bootstrap.php: ARCH_FIRE_SCRATCH_SRC '{$scratchSrc}' is not a directory.\n");

    exit(1);
}

$overridden = false;

foreach (spl_autoload_functions() as $autoloadFunction) {
    if (is_array($autoloadFunction) && $autoloadFunction[0] instanceof ClassLoader) {
        $autoloadFunction[0]->setPsr4('ReyemTech\\Hubspot\\', [$scratchSrc]);
        $overridden = true;
    }
}

if (! $overridden) {
    fwrite(STDERR, "arch-fire-bootstrap.php: no Composer\\Autoload\\ClassLoader was registered to override.\n");

    exit(1);
}
