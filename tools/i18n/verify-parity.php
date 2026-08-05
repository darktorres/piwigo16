<?php

declare(strict_types=1);

use Gettext\Translation;

// Verifies parity between .lang.php and .po files for every locale: every
// string key present in the PHP source must also be present in the
// generated PO file. Exits 0 on success, 1 on failure.
//
// Usage:
//   php tools/i18n/verify-parity.php [--root=<path>] [--locale=<locale>]

require_once __DIR__ . '/../../vendor/autoload.php';

use Gettext\Loader\PoLoader;

/**
 * @return array<string, true>
 */
function read_po_keys(string $poFile): array
{
    $translations = new PoLoader()
        ->loadFile($poFile);
    $keys = [];
    foreach ($translations->getTranslations() as $t) {
        /** @var Translation $t Translations::getTranslations() has no generic @return annotation */
        $orig = $t->getOriginal();
        if ($orig !== '') {
            $keys[$orig] = true;
        }
        $plural = $t->getPlural();
        if ($plural !== null && $plural !== '') {
            $keys[$plural] = true;
        }
    }

    return $keys;
}

$root = getcwd();
if ($root === false) {
    fwrite(STDERR, "Could not resolve the current working directory.\n");
    exit(1);
}
$filterLocale = null;

/** @var list<string> $argv register_argc_argv is always on for the CLI SAPI this script runs under */
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--root=')) {
        $root = substr($arg, 7);
    }
    if (str_starts_with($arg, '--locale=')) {
        $filterLocale = substr($arg, 9);
    }
}

$langDir = $root . '/language';
$errors = 0;
$checked = 0;

$locales = scandir($langDir);
foreach ($locales !== false ? $locales : [] as $locale) {
    if ($locale === '.' || $locale === '..') {
        continue;
    }
    if ($filterLocale !== null && $locale !== $filterLocale) {
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

        // get_defined_vars() (rather than reading $lang back out of a
        // by-ref closure capture) keeps its real, include-dependent shape
        // visible to static analysis -- a bare `$lang = [];` capture
        // freezes PHPStan's inferred type at the empty-array literal,
        // making any offset read (e.g. $lang['day']) a permanent "does not
        // exist" error. Matches load_language()'s own established fix for
        // the same pattern (functions.inc.php).
        $lang = (static function () use ($phpFile): array {
            include $phpFile;
            $included = get_defined_vars();

            return is_array($included['lang'] ?? null) ? $included['lang'] : [];
        })();

        $stringKeys = array_filter($lang, is_string(...));

        // 'day'/'month' are array-valued (index => name) -- php-to-po-fn.php
        // flattens each index into its own piwigo_day_N/piwigo_month_N key
        // (see that file's docblock); check those synthesized keys landed
        // in the PO too, not just the plain string entries.
        $days = is_array($lang['day'] ?? null) ? $lang['day'] : [];
        $months = is_array($lang['month'] ?? null) ? $lang['month'] : [];
        foreach ($days as $i => $name) {
            if (is_int($i) && is_string($name) && $name !== '') {
                $stringKeys['piwigo_day_' . $i] = $name;
            }
        }
        foreach ($months as $m => $name) {
            if (is_int($m) && is_string($name) && $name !== '') {
                $stringKeys['piwigo_month_' . $m] = $name;
            }
        }

        if ($stringKeys === []) {
            // No string translations at all -- PO is optional for an
            // empty source (matches convert-all.php's own SKIP behavior).
            continue;
        }

        if (! file_exists($poFile)) {
            echo "MISSING PO: {$poFile}\n";
            $errors++;
            continue;
        }

        $poKeys = read_po_keys($poFile);

        foreach ($stringKeys as $key => $val) {
            if (! is_string($key)) {
                continue;
            }
            if (! isset($poKeys[$key])) {
                $encoded_key = json_encode($key);
                echo "MISSING KEY [{$locale}/{$domain}]: " . ($encoded_key === false ? $key : $encoded_key) . "\n";
                $errors++;
            }
        }

        $checked++;
    }
}

echo "\nChecked: {$checked} file pairs. Errors: {$errors}\n";
exit($errors > 0 ? 1 : 0);
