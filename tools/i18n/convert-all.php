<?php

/**
 * Batch-convert all Piwigo .lang.php files to PO format.
 *
 * Usage:
 *   php tools/i18n/convert-all.php [--root=<path>] [--dry-run]
 *
 * For each language/<locale>/<domain>.lang.php:
 *   - generates language/<locale>/<domain>.po
 *
 * The plural pairs are extracted once from the source tree and reused for
 * every file so the scan runs only once.
 */

declare(strict_types=1);

require __DIR__ . '/extract-pairs.php';
require __DIR__ . '/plural-forms.php';
require __DIR__ . '/php-to-po-fn.php';

$root   = getcwd();
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
if (!is_dir($langDir)) {
    fwrite(STDERR, "language/ directory not found at: $langDir\n");
    exit(1);
}

$converted = 0;
$skipped   = 0;

foreach (scandir($langDir) ?: [] as $locale) {
    if ($locale === '.' || $locale === '..') {
        continue;
    }
    $localeDir = $langDir . '/' . $locale;
    if (!is_dir($localeDir)) {
        continue;
    }

    foreach (scandir($localeDir) ?: [] as $file) {
        if (!str_ends_with($file, '.lang.php')) {
            continue;
        }

        $phpFile = $localeDir . '/' . $file;
        $domain  = substr($file, 0, -strlen('.lang.php')); // e.g. "common"
        $poFile  = $localeDir . '/' . $domain . '.po';

        $po = convert_lang_php_to_po($phpFile, $locale, $pairs);

        if ($po === null) {
            echo "  SKIP (empty): $phpFile\n";
            $skipped++;
            continue;
        }

        if ($dryRun) {
            echo "  [DRY] Would write: $poFile\n";
        } else {
            file_put_contents($poFile, $po);
            echo "  OK: $poFile\n";
        }
        $converted++;
    }
}

echo "\nDone. Converted: $converted, Skipped: $skipped\n";
