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
 * Bootstrap: only `PHPWG_ROOT_PATH` and `Config::dataLocation()` are
 * required; the latter falls back to its `'_data/'` default when
 * `Config::$data` is empty, so no `Kernel::boot()` / DB connection is
 * needed (and would be unhelpful in CI).
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

if (!defined('PHPWG_ROOT_PATH')) {
    define('PHPWG_ROOT_PATH', dirname(__DIR__) . '/');
}

use Piwigo\Template\LatteEngine;

$root = PHPWG_ROOT_PATH . 'themes';

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
