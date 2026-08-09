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
use SebastianBergmann\CodeCoverage\Exception as CodeCoverageException;
use SebastianBergmann\CodeCoverage\Filter;
use SebastianBergmann\CodeCoverage\Report\Html\Facade as HtmlReport;
use SebastianBergmann\CodeCoverage\Report\Text;
use SebastianBergmann\CodeCoverage\Report\Thresholds;
use SebastianBergmann\CodeCoverage\Serialization\Unserializer;
use SebastianBergmann\FileIterator\Facade as FileIteratorFacade;

/**
 * Validates that an unserialize()'d pcov dump really is the
 * array<non-empty-string, array<int<1, max>, int>> shape
 * RawCodeCoverageData::fromLineCoverage() requires, rather than trusting
 * whatever a .raw file on disk happens to contain.
 *
 * @return array<non-empty-string, array<int<1, max>, int>>|null
 */
function normalizeRawLineCoverage(mixed $raw): ?array
{
    if (! is_array($raw)) {
        return null;
    }

    $normalized = [];
    foreach ($raw as $file => $lines) {
        if (! is_string($file) || $file === '' || ! is_array($lines)) {
            return null;
        }

        $normalizedLines = [];
        foreach ($lines as $line => $status) {
            if (! is_int($line) || $line < 1 || ! is_int($status)) {
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
 * each a metadata-wrapped array written by
 * SebastianBergmann\CodeCoverage\Serialization\Serializer, loaded back via
 * its Unserializer counterpart) plus every per-request raw pcov dump
 * Piwigo\Core\CoverageCollector wrote under _data/coverage-raw/web/*.raw
 * while Contract/Browser tests drove the live Apache instance.
 *
 * Usage: php tools/coverage-merge.php [unit-arch.cov] [integration.cov] ...
 */
$root = dirname(__DIR__);
$srcDir = $root . '/src/Piwigo';
$webDumpDir = $root . '/_data/coverage-raw/web';
$htmlDir = $root . '/_data/coverage-raw/html';

$filter = new Filter();
$filter->includeFiles((new FileIteratorFacade())->getFilesAsArray($srcDir, '.php'));

$coverage = new CodeCoverage((new Selector())->select($filter), $filter);

$webFiles = is_dir($webDumpDir) ? glob($webDumpDir . '/*.raw') : [];
$webFiles = $webFiles !== false ? $webFiles : [];
foreach ($webFiles as $i => $file) {
    $raw = unserialize((string) file_get_contents($file));
    $lineCoverage = normalizeRawLineCoverage($raw);
    if ($lineCoverage === null) {
        continue;
    }

    // @phpstan-ignore staticMethod.internalClass
    $coverage->append(RawCodeCoverageData::fromLineCoverage($lineCoverage), 'web-' . $i);
}

$unserializer = new Unserializer();
foreach (array_slice($argv ?? [], 1) as $cliDumpPath) {
    if ($cliDumpPath === '' || ! is_file($cliDumpPath)) {
        fwrite(STDERR, "coverage-merge.php: skipping missing file {$cliDumpPath}\n");
        continue;
    }

    try {
        $loaded = $unserializer->unserialize($cliDumpPath);
    } catch (CodeCoverageException $e) {
        fwrite(STDERR, "coverage-merge.php: {$cliDumpPath}: {$e->getMessage()}, skipping\n");
        continue;
    }

    // Serializer::serialize() runs every covered file through
    // PathReducer::reduce() first, rewriting absolute paths down to a
    // common-prefix-stripped relative form (returned here as basePath) for
    // a portable dump -- Unserializer has no inverse step, so the loaded
    // ProcessedCodeCoverageData's own file keys are still relative at this
    // point. Restoring them to real absolute paths before merging is
    // required: merging as-is silently DOUBLED the file count in this
    // dataset (844 -> 1688, confirmed live) instead of unioning by path,
    // because the relative keys never matched our own absolute-path-keyed
    // accumulator's existing entries for the same files.
    if ($loaded['basePath'] !== '') {
        // @phpstan-ignore method.internalClass
        foreach ($loaded['codeCoverage']->coveredFiles() as $relativeFile) {
            // @phpstan-ignore method.internalClass
            $loaded['codeCoverage']->renameFile($relativeFile, $loaded['basePath'] . DIRECTORY_SEPARATOR . $relativeFile);
        }
    }

    // --coverage-php dumps a metadata-wrapped array now, not a ready-to-use
    // CodeCoverage instance -- CodeCoverage::merge() needs private access
    // to $that->filter()/$that->data/$that->getTests() it can only get on
    // a real CodeCoverage, and there's no public constructor path back to
    // one from serialized data alone (CodeCoverage::__construct() requires
    // a live Driver). Merging the raw ProcessedCodeCoverageData directly
    // via CodeCoverage's own public getData()/merge() pair is the same
    // operation the library's own Serialization\Merger class performs
    // internally. testResults (per-test size/status/time metadata) has no
    // public merge point on CodeCoverage from outside the class, so it's
    // not carried over for argv-provided dumps -- only affects per-test
    // attribution for files covered solely by these dumps, not the
    // coverage percentages themselves, which come from the merged data.
    // @phpstan-ignore method.internalClass
    $coverage->getData()
        ->merge($loaded['codeCoverage']);
}

echo count($webFiles) . " web request dump(s) merged.\n\n";
// @phpstan-ignore method.internal
$report = $coverage->getReport();
// @phpstan-ignore new.internalClass, method.internalClass, method.internalClass
echo (new Text(Thresholds::default(), true))->process($report);

// @phpstan-ignore new.internalClass, method.internalClass, method.internalClass
(new HtmlReport())->process($report, $htmlDir);
echo "\nHTML report written to {$htmlDir}\n";
