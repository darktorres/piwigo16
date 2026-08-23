<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

/**
 * Finding 5 (post-DI-campaign shim/facade audit): docs/PLAN.md and
 * docs/REFERENCE.md keep drifting on the exact same kind of claim --
 * hardcoded file/test counts and "X real construction sites" citations --
 * and manually re-verifying one batch of them fixes today's drift and
 * guarantees nothing about tomorrow's (proven again this same session:
 * a "94 files" Browser-test-count claim, itself the product of an earlier
 * re-verification pass, was already stale again -- 95 real files -- by
 * the time this tool was built to catch it).
 *
 * A doc author marks a claim as machine-checkable by dropping an HTML
 * comment (invisible when the Markdown renders) right next to it:
 *
 *   94 files in `tests/Browser/` ...
 *   <!-- doc-drift-check: cmd='find tests/Browser -maxdepth 1 -iname "*.php" | wc -l' expect="94" -->
 *
 * This script finds every such marker under docs/, runs `cmd` from the
 * repo root, and fails the build if the trimmed output doesn't match
 * `expect` exactly -- catching drift automatically going forward instead
 * of relying on the next person to happen to re-audit the same claim by
 * hand. Intentionally narrow: no new markers are required anywhere else
 * in the repo; existing prose stays prose unless someone chooses to
 * annotate it.
 *
 * `cmd` must use single quotes for any internal quoting (double quotes
 * delimit the attribute value itself) -- see the examples already
 * annotated in docs/REFERENCE.md.
 *
 * Usage:
 *   php tools/doc-drift-check.php
 *   composer check:doc-drift
 */
$root = dirname(__DIR__);
$docsDir = $root . '/docs';

$markdownFiles = glob($docsDir . '/*.md');
if ($markdownFiles === false) {
    fwrite(STDERR, "Failed to glob {$docsDir}/*.md\n");
    exit(2);
}

$pattern = '/<!--\s*doc-drift-check:\s*cmd=\'(?<cmd>[^\']*)\'\s*expect="(?<expect>[^"]*)"\s*-->/';

$checked = 0;
$failures = [];

foreach ($markdownFiles as $file) {
    $contents = file_get_contents($file);
    if ($contents === false) {
        fwrite(STDERR, "Failed to read {$file}\n");
        exit(2);
    }

    $lines = explode("\n", $contents);
    foreach ($lines as $lineNumber => $line) {
        if (preg_match($pattern, $line, $matches) !== 1) {
            continue;
        }

        $checked++;
        $cmd = $matches['cmd'];
        $expected = $matches['expect'];

        /**
         * @psalm-suppress ForbiddenCode This dev-only doc-drift checker
         *   deliberately shells out to re-run the exact command each
         *   doc comment claims produces its shown output, comparing the
         *   real output against what the docs say -- never invoked with
         *   user-controlled input.
         */
        $rawActual = shell_exec('cd ' . escapeshellarg($root) . ' && ' . $cmd);
        $actual = is_string($rawActual) ? trim($rawActual) : '';

        if ($actual !== $expected) {
            $failures[] = sprintf(
                "%s:%d\n  cmd:      %s\n  expected: %s\n  actual:   %s",
                $file,
                $lineNumber + 1,
                $cmd,
                $expected,
                $actual,
            );
        }
    }
}

if ($checked === 0) {
    fwrite(STDERR, "doc-drift-check: found 0 markers under docs/*.md -- nothing to verify.\n");
    exit(0);
}

if ($failures !== []) {
    fwrite(STDERR, 'doc-drift-check: ' . count($failures) . " of {$checked} doc claim(s) drifted:\n\n");
    fwrite(STDERR, implode("\n\n", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "doc-drift-check: all {$checked} doc claim(s) still match reality.\n");
exit(0);
