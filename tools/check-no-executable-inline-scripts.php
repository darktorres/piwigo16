<?php

declare(strict_types=1);

/**
 * CI guard against executable inline scripts in Latte templates and PHP source.
 *
 * Walks themes/**\/*.latte and src/**\/*.php (and any extra paths passed
 * on argv) and flags any `<script>` tag that is either missing a `type=`
 * attribute or carries a `type` outside the non-executable allow-list.
 * This pairs with §1.5 Wave C's CSP `script-src 'self'` — if an
 * executable inline tag ever lands, the browser will refuse it; this
 * lint moves the failure earlier (build time) so authors get feedback
 * before shipping.
 *
 * For .php files, `<script>` tags that carry a `src=` attribute are
 * skipped — those are external references, not inline scripts, and are
 * allowed under CSP `script-src 'self'`.
 *
 * Mail templates under themes/**\/template/mail/** are skipped: they
 * render to email bodies, not HTTP responses, so CSP doesn't apply.
 *
 * Allow-listed `type` values per CSP3 §6.1.5 + WHATWG HTML 4.12:
 *   - application/json
 *   - application/ld+json
 *   - importmap
 *   - speculationrules
 *
 * Usage:
 *   php tools/check-no-executable-inline-scripts.php
 *   php tools/check-no-executable-inline-scripts.php themes src
 *   composer lint:no-inline-scripts
 */

const ALLOWED_TYPES = [
    'application/json',
    'application/ld+json',
    'importmap',
    'speculationrules',
];

const SCANNED_EXTENSIONS = ['latte', 'php'];

/**
 * @param list<string> $paths
 * @return array{0:int,1:list<array{file:string,line:int,reason:string}>}
 */
function scanPaths(array $paths): array
{
    $offenders = [];
    $checked = 0;
    foreach ($paths as $root) {
        if (!is_dir($root)) {
            continue;
        }
        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($rii as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || !in_array($file->getExtension(), SCANNED_EXTENSIONS, true)) {
                continue;
            }
            $path = $file->getPathname();
            if (str_contains(str_replace(DIRECTORY_SEPARATOR, '/', $path), '/template/mail/')) {
                continue;
            }
            $checked++;
            foreach (scanFile($path) as $hit) {
                $offenders[] = $hit;
            }
        }
    }
    return [$checked, $offenders];
}

/**
 * @return list<array{file:string,line:int,reason:string}>
 */
function scanFile(string $path): array
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        return [];
    }
    $isPhp = str_ends_with($path, '.php');
    $lines = $isPhp ? explode("\n", $contents) : [];
    $offenders = [];
    if (!preg_match_all('/<script\b([^>]*)>/i', $contents, $matches, PREG_OFFSET_CAPTURE)) {
        return [];
    }
    /** @var array<int,array{0:string,1:int}> $tags */
    $tags = $matches[0];
    /** @var array<int,array{0:string,1:int}> $attrs */
    $attrs = $matches[1];
    foreach ($tags as $i => [$tag, $offset]) {
        $rawAttrs = $attrs[$i][0];
        $lineNo = substr_count($contents, "\n", 0, $offset) + 1;
        if ($isPhp) {
            $sourceLine = trim($lines[$lineNo - 1] ?? '');
            if (str_starts_with($sourceLine, '*') || str_starts_with($sourceLine, '//') || str_starts_with($sourceLine, '/*')) {
                continue;
            }
            if (preg_match('/\bsrc\s*=/i', $rawAttrs)) {
                continue;
            }
        }
        if (!preg_match('/\btype\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $rawAttrs, $typeMatch)) {
            $offenders[] = ['file' => $path, 'line' => $lineNo, 'reason' => '<script> without type= attribute'];
            continue;
        }
        $value = strtolower(trim($typeMatch[2] !== '' ? $typeMatch[2] : ($typeMatch[3] !== '' ? $typeMatch[3] : ($typeMatch[4] ?? ''))));
        if (!in_array($value, ALLOWED_TYPES, true)) {
            $offenders[] = ['file' => $path, 'line' => $lineNo, 'reason' => sprintf('<script type="%s"> not in allow-list', $value)];
        }
    }
    return $offenders;
}

if (PHP_SAPI === 'cli' && realpath((string) ($argv[0] ?? '')) === __FILE__) {
    /** @var list<string> $argv */
    $paths = array_slice($argv, 1);
    if ($paths === []) {
        $paths = ['themes', 'src'];
    }
    [$checked, $offenders] = scanPaths($paths);
    if ($offenders !== []) {
        fwrite(STDERR, "Executable inline <script> tags found:\n");
        foreach ($offenders as $o) {
            fwrite(STDERR, sprintf("  %s:%d — %s\n", $o['file'], $o['line'], $o['reason']));
        }
        fwrite(STDERR, sprintf("\n%d offender(s) across %d file(s).\n", count($offenders), $checked));
        exit(1);
    }
    fwrite(STDOUT, sprintf("OK — %d file(s) scanned, no executable inline scripts.\n", $checked));
    exit(0);
}
