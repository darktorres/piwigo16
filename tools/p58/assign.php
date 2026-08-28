<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

/**
 * [P58] Assigns each traced (View, property) pair to exactly one fix
 * technique.
 *
 * The techniques overlap in reality -- one chain can contain a flatten, a
 * loose leaf VO *and* an `array_merge` -- so this is a single-assignment
 * rule with a deliberate precedence, not a classification of the code. It
 * answers "which technique should this commit use", and the counts it
 * produces are "findings this technique resolves", not a partition of the
 * codebase.
 *
 * Precedence matters: `CheckIntegrityView::$c13yList` reaches its View as
 * `$c13yResult->c13yList` (which looks like technique 2) but is a flatten
 * one step upstream, so the read-confirmed flatten list is consulted before
 * the `$x->prop` shape.
 *
 * Usage:
 *   php tools/p58/trace.php census.json > trace.json
 *   php tools/p58/assign.php trace.json [--out=assignment.json]
 *
 * The per-pair mapping is written only when `--out` is given: it is derived
 * data, and a committed copy would go stale the moment the tree moves.
 */
$root = dirname(__DIR__, 2);
chdir($root);

/** @var list<string> $argv */
$argv = $_SERVER['argv'];

$tracePath = $argv[1] ?? null;
if ($tracePath === null || ! is_file($tracePath)) {
    fwrite(STDERR, "usage: php tools/p58/assign.php <trace.json>\n");
    exit(1);
}

/** Views that are never constructed: their values arrive via assignContext(). */
const CONTRACT_ONLY = [
    'MenubarBlockView', 'BatchManagerFilterView', 'MonthCalendarView', 'NavigationBarView',
];

/**
 * Flattens established by reading the producer, where the View argument
 * itself does not contain `toArray()` -- a local sits in between, or the
 * flatten is one class upstream.
 */
const READ_CONFIRMED_FLATTEN = [
    'CheckIntegrityView::c13yList',
    'IndexView::imageOrders',
    'NotificationByMailView::send',
];

/** Properties typed `?T`/`T|false` and used as a definite `T` (technique 11). */
const NULLABLE_MISMATCH = [
    'checkVersion', 'devVersion', 'timeElapsedSinceLastCalc', 'additionalFiltType',
    'majorReleaseUrl', 'username', 'userGrantedIndirectGroups', 'thumbParams',
    'pdfNbPages', 'levelOptions', 'introduction', 'watermarkFiles',
    'nbUsersByStatus', 'nbUsersByLevel', 'orderByOptions', 'pluginAuthButtons',
    'updatesExtension',
];

/** The nav/element payload carried by the two untyped picture events. */
const PICTURE_VIEWS = ['PictureView', 'PictureContentView', 'SlideshowView'];
const PICTURE_PROPS = [
    'navCurrent', 'navPrevious', 'navNext', 'navLast', 'navFirst',
    'commentAdd', 'rateSummary', 'relatedTags', 'current',
];

/**
 * The expression a producer passes for one View property, or '' when no
 * construction site is found (contract-only Views, or a builder this simple
 * scan cannot see).
 */
function producerArgument(string $view, string $property): string
{
    /** @var array<string, string> $memo */
    static $memo = [];
    $key = $view . '::' . $property;
    if (isset($memo[$key])) {
        return $memo[$key];
    }

    /** @var list<string>|null $files */
    static $files = null;
    if ($files === null) {
        $files = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('src/Piwigo', FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            /** @var SplFileInfo $file RecursiveIteratorIterator loses this over RecursiveDirectoryIterator */
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }

    foreach ($files as $file) {
        $src = (string) file_get_contents($file);
        $offset = 0;
        while (($pos = strpos($src, 'new ' . $view . '(', $offset)) !== false) {
            $segment = substr($src, $pos, 4000);
            // Stop at the next named argument as well as at a line break:
            // a single-line `new View(a: $x, b: $y->z())` otherwise captures
            // the whole tail and every property looks like a `$x->y` chain.
            $pattern = '/(?<![\w>])' . preg_quote($property, '/')
                . ':\s*(.+?)(?=,\s*\w+:|,\n|\n\s*\);|\)\s*;)/s';
            if (preg_match($pattern, $segment, $m) === 1) {
                return $memo[$key] = trim((string) preg_replace('/\s+/', ' ', $m[1]));
            }
            $offset = $pos + 1;
        }
    }

    return $memo[$key] = '';
}

$trace = json_decode((string) file_get_contents($tracePath), true);
if (! is_array($trace)) {
    fwrite(STDERR, "trace file is not the expected JSON shape\n");
    exit(1);
}

$counts = [];
$pairs = [];
foreach ($trace as $finding) {
    if (! is_array($finding)) {
        continue;
    }
    if (($finding['codegen'] ?? false) === true) {
        $counts['A0b codegen'] = ($counts['A0b codegen'] ?? 0) + 1;
        continue;
    }

    $viewFqcn = is_string($finding['view'] ?? null) ? $finding['view'] : '';
    $view = $viewFqcn === '' ? '' : substr((string) strrchr('\\' . $viewFqcn, '\\'), 1);
    $property = is_string($finding['property'] ?? null) ? $finding['property'] : '';

    if ($property === '') {
        $technique = '9 locals/globals';
    } elseif ($view === 'MenubarBlockView') {
        $technique = '5 menubar';
    } elseif (in_array($view, CONTRACT_ONLY, true)) {
        $technique = '4 contexts';
    } elseif (in_array($view . '::' . $property, READ_CONFIRMED_FLATTEN, true)
        || str_contains(producerArgument($view, $property), 'toArray()')) {
        $technique = '1 flatten';
    } elseif (in_array($property, NULLABLE_MISMATCH, true)) {
        $technique = '11 nullable';
    } elseif (in_array($view, PICTURE_VIEWS, true) && in_array($property, PICTURE_PROPS, true)) {
        $technique = '6 picture';
    } else {
        $argument = producerArgument($view, $property);
        $technique = ($argument !== '' && str_starts_with($argument, '$') && str_contains($argument, '->'))
            ? '2 leaf VO'
            : '3 row VO';
    }

    $counts[$technique] = ($counts[$technique] ?? 0) + 1;
    if ($property !== '') {
        $pairs[$view . '::' . $property] = $technique;
    }
}

arsort($counts);
foreach ($counts as $technique => $count) {
    printf("  %-20s %4d\n", $technique, $count);
}
printf("  %-20s %4d\n", 'TOTAL', array_sum($counts));

ksort($pairs);
$outPath = null;
foreach (array_slice($argv, 2) as $arg) {
    if (str_starts_with($arg, '--out=')) {
        $outPath = substr($arg, 6);
    }
}

printf("\n%d (View, property) pairs\n", count($pairs));
if ($outPath !== null) {
    file_put_contents($outPath, json_encode($pairs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    printf("written to %s\n", $outPath);
}
