<?php

declare(strict_types=1);

/**
 * Smarty → Latte mechanical converter CLI.
 *
 * Usage:
 *   php tools/smarty-to-latte/convert.php <path>...
 *   php tools/smarty-to-latte/convert.php --dry-run <path>...
 *
 * Each argument is a `.tpl` file or a directory; directories are
 * scanned recursively for `*.tpl`. The converter writes a parallel
 * `.latte` file alongside each input — the original `.tpl` is left in
 * place so the two engines can render in parallel during the migration
 * window. `--dry-run` prints the converted output to stdout instead of
 * writing.
 *
 * Constructs that don't fit any rewrite rule pass through unchanged
 * and are flagged in the run summary; those need hand-fix.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Piwigo\Tools\SmartyToLatte\Converter;

/** @var list<string> $argv */
global $argv;

$args = array_slice($argv, 1);
$dryRun = false;
$force = false;
$paths = [];
foreach ($args as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
        continue;
    }
    if ($arg === '--force') {
        $force = true;
        continue;
    }
    if ($arg === '--help' || $arg === '-h') {
        fwrite(STDERR, "Usage: php tools/smarty-to-latte/convert.php [--dry-run] [--force] <path>...\n");
        fwrite(STDERR, "  --dry-run   print converted output to stdout instead of writing\n");
        fwrite(STDERR, "  --force     overwrite existing .latte files (default: skip them so manual\n");
        fwrite(STDERR, "              post-conversion annotations like |noescape aren't lost)\n");
        exit(0);
    }
    $paths[] = $arg;
}
if ($paths === []) {
    fwrite(STDERR, "No input paths.\n");
    fwrite(STDERR, "Usage: php tools/smarty-to-latte/convert.php [--dry-run] <path>...\n");
    exit(1);
}

$converter = new Converter();

$converted = 0;
$skipped = 0;
foreach ($paths as $path) {
    foreach (collectTemplates($path) as $tpl) {
        $source = file_get_contents($tpl);
        if ($source === false) {
            fwrite(STDERR, "Failed to read: $tpl\n");
            $skipped++;
            continue;
        }

        $latte = $converter->convert($source);
        $out = preg_replace('/\.tpl$/', '.latte', $tpl);
        if (!is_string($out)) {
            $skipped++;
            continue;
        }

        if ($dryRun) {
            echo "--- $tpl → $out ---\n";
            echo $latte;
            echo "\n";
        } else {
            if (!$force && file_exists($out)) {
                echo "Skipped (exists, use --force to overwrite): $out\n";
                $skipped++;
                continue;
            }
            if (file_put_contents($out, $latte) === false) {
                fwrite(STDERR, "Failed to write: $out\n");
                $skipped++;
                continue;
            }
            echo "Converted: $tpl → $out\n";
        }
        $converted++;
    }
}

echo "\nDone. Converted: $converted, skipped: $skipped\n";

/**
 * @return iterable<string>
 */
function collectTemplates(string $path): iterable
{
    if (is_file($path)) {
        if (str_ends_with($path, '.tpl')) {
            yield $path;
        }
        return;
    }
    if (!is_dir($path)) {
        fwrite(STDERR, "Not found: $path\n");
        return;
    }

    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
    foreach ($rii as $file) {
        $pathname = $file->getPathname();
        if ($file->isFile() && str_ends_with($pathname, '.tpl')) {
            yield $pathname;
        }
    }
}
