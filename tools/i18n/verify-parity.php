<?php

/**
 * Verify parity between .lang.php and .po files for every locale.
 *
 * Checks that every key in the PHP file is present in the PO file.
 * Exits 0 on success, 1 on failure.
 *
 * Usage:
 *   php tools/i18n/verify-parity.php [--root=<path>] [--locale=<locale>]
 */

declare(strict_types=1);

require __DIR__ . '/vendor-po-reader.php';

$root   = getcwd();
$filterLocale = null;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--root=')) {
        $root = substr($arg, 7);
    }
    if (str_starts_with($arg, '--locale=')) {
        $filterLocale = substr($arg, 9);
    }
}

$langDir = $root . '/language';
$errors  = 0;
$checked = 0;

foreach (scandir($langDir) ?: [] as $locale) {
    if ($locale === '.' || $locale === '..') {
        continue;
    }
    if ($filterLocale !== null && $locale !== $filterLocale) {
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
        $domain  = substr($file, 0, -strlen('.lang.php'));
        $poFile  = $localeDir . '/' . $domain . '.po';

        // Load PHP keys first so we can skip truly empty source files
        $lang = [];
        (static function () use ($phpFile, &$lang): void {
            include $phpFile;
        })();

        // No string translations at all — skip (PO is optional for empty sources)
        $stringKeys = array_filter($lang, 'is_string');
        if (empty($stringKeys)) {
            continue;
        }

        if (!file_exists($poFile)) {
            echo "MISSING PO: $poFile\n";
            $errors++;
            continue;
        }

        // Load PO keys
        $poKeys = read_po_keys($poFile);

        foreach ($stringKeys as $key => $val) {
            if (!is_string($key)) {
                continue;
            }
            if (!isset($poKeys[$key])) {
                echo "MISSING KEY [{$locale}/{$domain}]: " . json_encode($key) . "\n";
                $errors++;
            }
        }

        $checked++;
    }
}

echo "\nChecked: $checked file pairs. Errors: $errors\n";
exit($errors > 0 ? 1 : 0);
