<?php

/**
 * Convert .lang.php files in a Piwigo language extension repository to PO format.
 *
 * The extension repo uses directories named {locale}_{version}/ (e.g. fr_FR_16.3.0/)
 * rather than the core's language/{locale}/ layout.
 *
 * Usage (from the piwigo16 repo root):
 *   php tools/i18n/convert-ext-languages.php [--dir=../piwigo16-languages] [--dry-run]
 *
 * The plural pairs are re-extracted from the core source tree automatically.
 * PO files are written alongside the .lang.php files they replace.
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/extract-pairs.php';
require __DIR__ . '/plural-forms.php';
require __DIR__ . '/php-to-po-fn.php';

$extDir = dirname(__DIR__, 2) . '/../piwigo16-languages';
$dryRun = false;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--dir=')) {
        $extDir = substr($arg, 6);
    }
    if ($arg === '--dry-run') {
        $dryRun = true;
    }
}

$extDir = realpath($extDir) ?: $extDir;

if (!is_dir($extDir)) {
    fwrite(STDERR, "Directory not found: $extDir\n");
    exit(1);
}

echo "Extracting plural pairs from core source...\n";
$rootDir = dirname(__DIR__, 2);
$pairs   = extract_plural_pairs($rootDir);
echo count($pairs) . " plural pairs found.\n\n";

$converted = 0;
$skipped   = 0;

foreach (scandir($extDir) ?: [] as $entry) {
    if ($entry === '.' || $entry === '..') {
        continue;
    }

    $localeDir = $extDir . '/' . $entry;
    if (!is_dir($localeDir)) {
        continue;
    }

    // Extract locale code: strip trailing _{version} suffix (e.g. fr_FR_16.3.0 → fr_FR)
    if (!preg_match('/^([a-z]{2,3}_[A-Z]{2,4})(?:_\d+(?:\.\d+)*)?$/', $entry, $m)) {
        continue;
    }
    $locale = $m[1];

    foreach (scandir($localeDir) ?: [] as $file) {
        if (!str_ends_with($file, '.lang.php')) {
            continue;
        }

        $phpFile = $localeDir . '/' . $file;
        $domain  = substr($file, 0, -strlen('.lang.php'));
        $poFile  = $localeDir . '/' . $domain . '.po';

        $po = convert_lang_php_to_po($phpFile, $locale, $pairs);

        if ($po === null) {
            echo "  SKIP (empty): $phpFile\n";
            $skipped++;
            continue;
        }

        if ($dryRun) {
            echo "  [DRY] $locale/$domain.po\n";
        } else {
            file_put_contents($poFile, $po);
            echo "  OK: $locale/$domain.po\n";
        }
        $converted++;
    }
}

echo "\nDone. Converted: $converted, Skipped: $skipped\n";
