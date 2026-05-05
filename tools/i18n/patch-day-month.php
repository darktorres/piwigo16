<?php

/**
 * Recover $lang['day'] / $lang['month'] arrays from git history and append
 * piwigo_day_N / piwigo_month_N entries to each locale's common.po.
 *
 * These array-valued entries were skipped by the original converter; this
 * one-time patch adds them without regenerating everything.
 *
 * Usage (from piwigo16 repo root):
 *   php tools/i18n/patch-day-month.php [--commit=<sha>] [--dry-run]
 *
 * The commit SHA should be the last commit that still had the .lang.php files.
 */

declare(strict_types=1);

$commit  = '5a0f5b463'; // last commit before .lang.php deletion
$langDir = __DIR__ . '/../../language';
$dryRun  = false;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--commit=')) {
        $commit = substr($arg, 9);
    }
    if ($arg === '--dry-run') {
        $dryRun = true;
    }
}

$langDir = realpath($langDir) ?: $langDir;
$patched = 0;
$skipped = 0;

foreach (scandir($langDir) ?: [] as $locale) {
    if ($locale === '.' || $locale === '..') {
        continue;
    }
    $localeDir = $langDir . '/' . $locale;
    if (!is_dir($localeDir)) {
        continue;
    }

    $poFile = $localeDir . '/common.po';
    if (!file_exists($poFile)) {
        echo "  SKIP (no common.po): $locale\n";
        $skipped++;
        continue;
    }

    // Recover common.lang.php from git history
    $gitPath = "language/{$locale}/common.lang.php";
    $null = PHP_OS_FAMILY === 'Windows' ? '2>NUL' : '2>/dev/null';
    $phpContent = shell_exec("git show {$commit}:{$gitPath} {$null}");

    if ($phpContent === null || $phpContent === '') {
        echo "  SKIP (no git content): $locale\n";
        $skipped++;
        continue;
    }

    // Extract $lang['day'] and $lang['month'] from the PHP content
    [$days, $months] = extract_day_month($phpContent);

    if (empty($days) && empty($months)) {
        echo "  SKIP (no day/month): $locale\n";
        $skipped++;
        continue;
    }

    // Only append entries that aren't already present (idempotent re-run)
    $existingPo = file_get_contents($poFile) ?: '';
    $hasDays   = str_contains($existingPo, 'msgid "piwigo_day_0"');
    $hasMonths = str_contains($existingPo, 'msgid "piwigo_month_1"');

    if ($hasDays && $hasMonths) {
        echo "  SKIP (already patched): $locale\n";
        $skipped++;
        continue;
    }

    // Build PO entries to append (only missing ones)
    $extra = '';
    if (!$hasDays) {
        foreach ($days as $idx => $name) {
            $extra .= 'msgid ' . po_q("piwigo_day_{$idx}") . "\n";
            $extra .= 'msgstr ' . po_q($name) . "\n";
            $extra .= "\n";
        }
    }
    if (!$hasMonths) {
        foreach ($months as $idx => $name) {
            $extra .= 'msgid ' . po_q("piwigo_month_{$idx}") . "\n";
            $extra .= 'msgstr ' . po_q($name) . "\n";
            $extra .= "\n";
        }
    }

    if ($extra === '') {
        echo "  SKIP (nothing to add): $locale\n";
        $skipped++;
        continue;
    }

    $addedDays   = $hasDays ? 0 : count($days);
    $addedMonths = $hasMonths ? 0 : count($months);

    if ($dryRun) {
        echo '  [DRY] Would append ' . ($addedDays + $addedMonths) . " entries to $locale/common.po\n";
    } else {
        file_put_contents($poFile, $extra, FILE_APPEND);
        echo "  OK: $locale — {$addedDays} day + {$addedMonths} month entries added\n";
    }
    $patched++;
}

echo "\nPatched: $patched, Skipped: $skipped\n";

// ---------------------------------------------------------------------------

/** @return array{array<int,string>, array<int,string>} */
function extract_day_month(string $phpContent): array
{
    $days   = [];
    $months = [];

    // Match $lang['day'][N] or $lang['day']['N'] = 'Name';
    if (preg_match_all('/\$lang\[\'day\'\]\[\'?(\d+)\'?\]\s*=\s*[\'"]([^\'"]+)[\'"]\s*;/', $phpContent, $m, PREG_SET_ORDER)) {
        foreach ($m as $row) {
            $days[(int) $row[1]] = $row[2];
        }
        ksort($days);
    }

    // Match $lang['month'][N] or $lang['month']['N'] = 'Name';
    if (preg_match_all('/\$lang\[\'month\'\]\[\'?(\d+)\'?\]\s*=\s*[\'"]([^\'"]+)[\'"]\s*;/', $phpContent, $m, PREG_SET_ORDER)) {
        foreach ($m as $row) {
            $months[(int) $row[1]] = $row[2];
        }
        ksort($months);
    }

    return [$days, $months];
}

function po_q(string $s): string
{
    $s = str_replace(['\\', '"', "\n", "\r", "\t"], ['\\\\', '\\"', '\\n', '\\r', '\\t'], $s);
    return '"' . $s . '"';
}
