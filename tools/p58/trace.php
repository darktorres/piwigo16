<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

/**
 * [P58] Resolves each Campaign A finding to the View property it originates
 * from.
 *
 * A finding's own location is a line in a *compiled* template, which names
 * nothing useful -- the offending expression is usually a loop variable
 * (`$comment`, `$cat`, `$c13y`) several `foreach` levels below the property
 * that actually carries the wrong type. This walks each finding back:
 *
 *   compiled line -> root variable -> foreach bindings in the .latte source
 *   -> outermost template variable -> the {templateType} View's own property
 *
 * Findings whose root is *not* a declared constructor parameter of that View
 * are reported with an empty property: they are template locals, `{include}`
 * arguments or fallback-union globals, and their fix lands somewhere other
 * than a View. Roughly one finding in six is one of these, and treating them
 * as properties inflates the per-View counts.
 *
 * Usage:
 *   php tools/p58/census.php --json=census.json
 *   php tools/p58/trace.php census.json > trace.json
 */
$root = dirname(__DIR__, 2);
chdir($root);

/** @var list<string> $argv */
$argv = $_SERVER['argv'];

$censusPath = $argv[1] ?? null;
if ($censusPath === null || ! is_file($censusPath)) {
    fwrite(STDERR, "usage: php tools/p58/trace.php <census.json>\n");
    exit(1);
}

/** Campaign B identifiers are excluded: this maps Campaign A only. */
const CAMPAIGN_B = [
    'equal.notAllowed', 'notEqual.notAllowed', 'empty.notAllowed',
    'if.alwaysTrue', 'booleanOr.rightAlwaysFalse', 'identical.alwaysFalse',
];

/**
 * Compiled analysis filenames flatten the template path with dashes, so the
 * mapping back is built from the real tree rather than un-mangled.
 *
 * @return array<string, string> compiled basename (no .php) => template path
 */
function templatesByCompiledName(): array
{
    $map = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('themes', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        /** @var SplFileInfo $file RecursiveIteratorIterator loses this over RecursiveDirectoryIterator */
        if (! $file->isFile() || $file->getExtension() !== 'latte') {
            continue;
        }
        $path = str_replace('\\', '/', $file->getPathname());
        $map['themes-' . str_replace('/', '-', substr($path, strlen('themes/')))] = $path;
    }

    return $map;
}

/**
 * Walks `foreach` bindings back to the outermost template variable. Handles
 * both `{foreach $xs as $x}` and the `n:foreach="$xs as $x"` attribute form;
 * missing the latter leaves half the loop variables unresolved.
 */
function rootVariable(string $templateSource, string $var, int $depth = 0): string
{
    if ($depth > 6) {
        return $var;
    }

    $quoted = preg_quote($var, '/');
    foreach ([
        '/foreach\s+([^\s]+)\s+as\s+(?:\$\w+\s*=>\s*)?\$' . $quoted . '\b/',
        '/n:foreach\s*=\s*"([^"]+?)\s+as\s+(?:\$\w+\s*=>\s*)?\$' . $quoted . '\b/',
    ] as $pattern) {
        if (preg_match($pattern, $templateSource, $m) === 1) {
            if (preg_match('/^\$(\w+)/', $m[1], $inner) === 1) {
                return rootVariable($templateSource, $inner[1], $depth + 1);
            }

            return $var;
        }
    }

    return $var;
}

/**
 * @return list<string> the View's declared constructor parameter names
 */
function viewProperties(string $class): array
{
    /** @var array<string, list<string>> $memo */
    static $memo = [];
    if (isset($memo[$class])) {
        return $memo[$class];
    }

    $file = 'src/' . str_replace('\\', '/', ltrim($class, '\\')) . '.php';
    if (! is_file($file)) {
        return $memo[$class] = [];
    }
    $src = (string) file_get_contents($file);
    if (preg_match('/public function __construct\((.*?)\n    \) \{\}/s', $src, $m) !== 1) {
        return $memo[$class] = [];
    }
    preg_match_all('/public\s+(?:readonly\s+)?(?:\??[\w\\\\|]+\s+)?\$(\w+)/', $m[1], $params);

    return $memo[$class] = $params[1];
}

$census = json_decode((string) file_get_contents($censusPath), true);
if (! is_array($census) || ! isset($census['files'])) {
    fwrite(STDERR, "census file is not the expected JSON shape\n");
    exit(1);
}

$censusFiles = $census['files'];
if (! is_array($censusFiles)) {
    fwrite(STDERR, "census file had no files map\n");
    exit(1);
}

$templates = templatesByCompiledName();
$sourceCache = [];
$viewCache = [];
$out = [];

foreach ($censusFiles as $compiledPath => $info) {
    $compiledPath = (string) $compiledPath;
    $messages = is_array($info) ? ($info['messages'] ?? null) : null;
    if (! is_array($messages)) {
        continue;
    }
    $compiledName = basename($compiledPath, '.php');
    $templatePath = $templates[$compiledName] ?? null;
    $compiledLines = file($compiledPath, FILE_IGNORE_NEW_LINES);
    if ($compiledLines === false) {
        $compiledLines = [];
    }

    if ($templatePath !== null && ! isset($sourceCache[$templatePath])) {
        $sourceCache[$templatePath] = (string) file_get_contents($templatePath);
        $view = '';
        if (preg_match('/^\{templateType ([^}]+)\}/', $sourceCache[$templatePath], $m) === 1) {
            $view = trim($m[1]);
        }
        $viewCache[$templatePath] = $view;
    }

    foreach ($messages as $message) {
        if (! is_array($message)) {
            continue;
        }
        $rawIdentifier = $message['identifier'] ?? null;
        $identifier = is_string($rawIdentifier) ? $rawIdentifier : '(none)';
        if (in_array($identifier, CAMPAIGN_B, true)) {
            continue;
        }

        $lineNumber = is_int($message['line'] ?? null) ? $message['line'] : 0;
        $line = $compiledLines[$lineNumber - 1] ?? '';
        $text = is_string($message['message'] ?? null) ? $message['message'] : '';
        $rootVar = preg_match('/\$([A-Za-z_][A-Za-z0-9_]*)/', $line, $m) === 1 ? $m[1] : '';

        $property = '';
        $view = $templatePath !== null ? $viewCache[$templatePath] : '';
        if ($templatePath !== null && $rootVar !== '') {
            $candidate = rootVariable($sourceCache[$templatePath], $rootVar);
            if ($view !== '' && in_array($candidate, viewProperties($view), true)) {
                $property = $candidate;
            }
            $rootVar = $candidate;
        }

        $out[] = [
            'template' => $templatePath ?? $compiledName,
            'view' => $view,
            'root' => $rootVar,
            'property' => $property,
            'identifier' => $identifier,
            // Flagged by message, not by source line: that is what A0b's
            // ignore matches on, and one getParent() finding sits on a line
            // naming neither class.
            'codegen' => str_contains($text, 'CachingIterator')
                || str_contains($text, 'Latte\\Runtime\\Template'),
        ];
    }
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
