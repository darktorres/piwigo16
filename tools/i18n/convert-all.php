<?php

declare(strict_types=1);

// Batch-converts every Piwigo language/<locale>/<domain>.lang.php file to
// language/<locale>/<domain>.po.
//
// Usage:
//   php tools/i18n/convert-all.php [--root=<path>] [--dry-run]
//
// Plural pairs are extracted once from the source tree and reused for
// every file so the scan only runs once.

require __DIR__ . '/extract-pairs.php';
require __DIR__ . '/plural-forms.php';
require __DIR__ . '/php-to-po-fn.php';

$root = getcwd();
if ($root === false) {
    fwrite(STDERR, "Could not resolve the current working directory.\n");
    exit(1);
}
/** @var list<string> $argv register_argc_argv is always on for the CLI SAPI this script runs under */
$dryRun = in_array('--dry-run', $argv, true);

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--root=')) {
        $root = substr($arg, 7);
    }
}

echo "Extracting plural pairs from source...\n";
$pairs = extract_plural_pairs($root);
echo count($pairs) . " plural pairs found.\n\n";

$langDir = $root . '/language';
if (! is_dir($langDir)) {
    fwrite(STDERR, "language/ directory not found at: {$langDir}\n");
    exit(1);
}

$converted = 0;
$skipped = 0;

$locales = scandir($langDir);
foreach ($locales !== false ? $locales : [] as $locale) {
    if ($locale === '.' || $locale === '..') {
        continue;
    }
    $localeDir = $langDir . '/' . $locale;
    if (! is_dir($localeDir)) {
        continue;
    }

    $files = scandir($localeDir);
    foreach ($files !== false ? $files : [] as $file) {
        if (! str_ends_with($file, '.lang.php')) {
            continue;
        }

        $phpFile = $localeDir . '/' . $file;
        $domain = substr($file, 0, -strlen('.lang.php'));
        $poFile = $localeDir . '/' . $domain . '.po';

        $po = convert_lang_php_to_po($phpFile, $locale, $pairs);

        if ($po === null) {
            echo "  SKIP (empty): {$phpFile}\n";
            $skipped++;
            continue;
        }

        if ($dryRun) {
            echo "  [DRY] Would write: {$poFile}\n";
        } else {
            file_put_contents($poFile, $po);
            echo "  OK: {$poFile}\n";
        }
        $converted++;
    }
}

echo "\nDone. Converted: {$converted}, Skipped: {$skipped}\n";
