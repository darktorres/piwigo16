<?php

declare(strict_types=1);

/**
 * Latte template pre-compiler — walks Piwigo's bundled `.latte` templates
 * and warms `_data/templates_c/latte/` so the runtime first-request hit
 * does not pay the compile cost. Also serves as a CI gate: a syntax
 * regression in any template fails the build at PR time, before
 * deployment.
 *
 * Cache-key parity: Latte's compile cache keys on the path passed to its
 * loader. `Template::resolveLatteTemplatePath()` resolves bare filenames
 * to absolute paths at runtime, so this script must also pass absolute
 * paths to `LatteEngine::warmupCache()` for the warmed entry to match
 * what runtime hits.
 *
 * Bootstrap: a Paths instance is constructed from this file's location
 * and Kernel::boot() runs with it so LatteEngine::default() resolves
 * the cache dir through DI exactly as in production. The DB connection
 * is never resolved (factory closures are lazy), so this is safe to run
 * in CI before the DB exists.
 *
 * Plugin sandbox cache (`_data/templates_c/latte_plugin/`) is not warmed
 * here — there are zero plugin `.latte` templates in tree today, and
 * §1.3 will add the wiring when plugin templates land.
 *
 * Usage:
 *   php tools/precompile_templates.php
 *   composer precompile:templates
 */

require __DIR__ . '/../vendor/autoload.php';

use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Template\LatteEngine;

$paths = Paths::fromRoot(dirname(__DIR__));
Kernel::boot($paths);

$root = $paths->root . 'themes';

$engine = LatteEngine::default();
$failed = [];
$count = 0;

$iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iter as $file) {
    /** @var SplFileInfo $file */
    if (!$file->isFile() || $file->getExtension() !== 'latte') {
        continue;
    }
    $abs = $file->getPathname();
    try {
        $engine->warmupCache($abs);
        $count++;
    } catch (Throwable $e) {
        $failed[] = "$abs: " . $e->getMessage();
    }
}

if ($failed !== []) {
    fwrite(STDERR, implode("\n", $failed) . "\n");
    fwrite(STDERR, sprintf("Failed: %d, succeeded: %d\n", count($failed), $count));
    exit(1);
}

echo "Compiled successfully: $count templates.\n";
