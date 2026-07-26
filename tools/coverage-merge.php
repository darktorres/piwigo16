<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

require __DIR__ . '/../vendor/autoload.php';

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Data\RawCodeCoverageData;
use SebastianBergmann\CodeCoverage\Driver\Selector;
use SebastianBergmann\CodeCoverage\Filter;
use SebastianBergmann\CodeCoverage\Report\Html\Facade as HtmlReport;
use SebastianBergmann\CodeCoverage\Report\Text;
use SebastianBergmann\CodeCoverage\Report\Thresholds;
use SebastianBergmann\FileIterator\Facade as FileIteratorFacade;

/**
 * Validates that an unserialize()'d pcov dump really is the
 * array<string, array<int, int>> shape RawCodeCoverageData::
 * fromXdebugWithoutPathCoverage() requires, rather than trusting whatever
 * a .raw file on disk happens to contain.
 *
 * @return array<string, array<int, int>>|null
 */
function normalizeRawLineCoverage(mixed $raw): ?array
{
    if (! is_array($raw)) {
        return null;
    }

    $normalized = [];
    foreach ($raw as $file => $lines) {
        if (! is_string($file) || ! is_array($lines)) {
            return null;
        }

        $normalizedLines = [];
        foreach ($lines as $line => $status) {
            if (! is_int($line) || ! is_int($status)) {
                return null;
            }

            $normalizedLines[$line] = $status;
        }

        $normalized[$file] = $normalizedLines;
    }

    return $normalized;
}

/**
 * Merges every coverage source this project can produce into one report:
 * CLI --coverage-php dumps (Unit+Arch, Integration -- passed as argv paths,
 * each a PHP file returning a real CodeCoverage object, see
 * SebastianBergmann\CodeCoverage\Report\PHP::process()) plus every
 * per-request raw pcov dump Piwigo\Core\CoverageCollector wrote under
 * _data/coverage-raw/web/*.raw while Contract/Browser tests drove the live
 * Apache instance.
 *
 * Usage: php tools/coverage-merge.php [unit-arch.cov] [integration.cov] ...
 */
$root = dirname(__DIR__);
$srcDir = $root . '/src/Piwigo';
$webDumpDir = $root . '/_data/coverage-raw/web';
$htmlDir = $root . '/_data/coverage-raw/html';

$filter = new Filter();
$filter->includeFiles((new FileIteratorFacade())->getFilesAsArray($srcDir, '.php'));

$coverage = new CodeCoverage((new Selector())->forLineCoverage($filter), $filter);

$webFiles = is_dir($webDumpDir) ? glob($webDumpDir . '/*.raw') : [];
$webFiles = $webFiles !== false ? $webFiles : [];
foreach ($webFiles as $i => $file) {
    $raw = unserialize((string) file_get_contents($file));
    $lineCoverage = normalizeRawLineCoverage($raw);
    if ($lineCoverage === null) {
        continue;
    }

    $coverage->append(RawCodeCoverageData::fromXdebugWithoutPathCoverage($lineCoverage), 'web-' . $i);
}

foreach (array_slice($argv ?? [], 1) as $cliDumpPath) {
    if (! is_file($cliDumpPath)) {
        fwrite(STDERR, "coverage-merge.php: skipping missing file {$cliDumpPath}\n");
        continue;
    }

    $loaded = require $cliDumpPath;
    if (! $loaded instanceof CodeCoverage) {
        fwrite(STDERR, "coverage-merge.php: {$cliDumpPath} did not return a CodeCoverage instance, skipping\n");
        continue;
    }

    $coverage->merge($loaded);
}

echo count($webFiles) . " web request dump(s) merged.\n\n";
echo (new Text(Thresholds::default(), showUncoveredFiles: true))->process($coverage);

(new HtmlReport())->process($coverage, $htmlDir);
echo "\nHTML report written to {$htmlDir}\n";
