<?php

/**
 * Verify parity between .lang.php and .po files in the extension language repo.
 *
 * Usage:
 *   php tools/i18n/verify-parity-ext.php [--dir=../piwigo16-languages] [--locale=fr_FR]
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/vendor-po-reader.php';

$extDir       = dirname(__DIR__, 2) . '/../piwigo16-languages';
$filterLocale = null;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--dir=')) {
        $extDir = substr($arg, 6);
    }
    if (str_starts_with($arg, '--locale=')) {
        $filterLocale = substr($arg, 9);
    }
}

$extDir = realpath($extDir) ?: $extDir;

$errors  = 0;
$checked = 0;

foreach (scandir($extDir) ?: [] as $entry) {
    if ($entry === '.' || $entry === '..') {
        continue;
    }
    $localeDir = $extDir . '/' . $entry;
    if (!is_dir($localeDir)) {
        continue;
    }
    if (!preg_match('/^([a-z]{2,3}_[A-Z]{2,4})(?:_\d+(?:\.\d+)*)?$/', $entry, $m)) {
        continue;
    }
    $locale = $m[1];
    if ($filterLocale !== null && $locale !== $filterLocale) {
        continue;
    }

    foreach (scandir($localeDir) ?: [] as $file) {
        if (!str_ends_with($file, '.lang.php')) {
            continue;
        }

        $phpFile = $localeDir . '/' . $file;
        $domain  = substr($file, 0, -strlen('.lang.php'));
        $poFile  = $localeDir . '/' . $domain . '.po';

        $lang = [];
        (static function () use ($phpFile, &$lang): void {
            include $phpFile;
        })();

        $stringKeys = array_filter($lang, 'is_string');
        if (empty($stringKeys)) {
            continue;
        }

        if (!file_exists($poFile)) {
            echo "MISSING PO: $locale/$domain.po\n";
            $errors++;
            continue;
        }

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
