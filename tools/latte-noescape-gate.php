<?php

declare(strict_types=1);

/**
 * P59's zero-tolerance `|noescape` gate. Scans every `.latte` file
 * under a themes root for a literal `|noescape` filter use and
 * compares the per-file count against an allowlist (relative path =>
 * expected count, see `tools/latte-noescape-allowlist.php`) -- an
 * EXACT match is required in both directions, so a newly introduced
 * `|noescape` (not yet allowlisted, or over the allowed count) is a
 * violation, and so is removing a documented exception without
 * updating the allowlist (keeps it from going stale).
 *
 * A plain substring/regex scan, not a real Latte parse: `|noescape`
 * has no other meaning as a substring anywhere legitimate (a template
 * comment describing WHY a site is allowlisted uses the bare word
 * "noescape", never the pipe-prefixed filter syntax), so this stays
 * accurate without needing PiwigoExtension or a compiled AST.
 *
 * @param array<string, int> $allowlist relative path (from $themesRoot) => expected |noescape count
 * @return list<string> human-readable violation messages, empty when clean
 */
function scanNoescapeCorpus(string $themesRoot, array $allowlist): array
{
    $found = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($themesRoot, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'latte') {
            continue;
        }

        $relative = ltrim(substr($file->getPathname(), strlen($themesRoot)), '/');
        $content = file_get_contents($file->getPathname());
        if ($content === false) {
            continue;
        }

        $count = preg_match_all('/\|noescape\b/', $content);
        if ($count > 0) {
            $found[$relative] = $count;
        }
    }

    $allFiles = array_unique([...array_keys($found), ...array_keys($allowlist)]);
    sort($allFiles);

    $violations = [];
    foreach ($allFiles as $relative) {
        $actual = $found[$relative] ?? 0;
        $expected = $allowlist[$relative] ?? 0;

        if ($actual !== $expected) {
            $violations[] = sprintf(
                '%s: found %d |noescape occurrence(s), allowlist expects %d -- %s',
                $relative,
                $actual,
                $expected,
                $actual > $expected
                    ? 'a new noescape needs a documented, justified allowlist entry in tools/latte-noescape-allowlist.php, not a silent addition'
                    : 'the allowlist entry is stale -- update tools/latte-noescape-allowlist.php to match',
            );
        }
    }

    return $violations;
}
