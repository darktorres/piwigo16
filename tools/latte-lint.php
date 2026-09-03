<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

/**
 * Latte template linter -- wraps Latte's bundled `Latte\Tools\Linter`
 * with `Piwigo\Template\Latte\PiwigoExtension` registered, so it
 * recognizes Piwigo's own filters/functions (`|translate`, `|noescape`,
 * `combineScript`, ...) instead of warning "Unknown filter". Also runs
 * P59's zero-tolerance `|noescape` gate (`tools/latte-noescape-gate.php`)
 * against the full `themes/` tree, regardless of any custom scan path
 * below -- a repo-wide invariant, not something a scoped invocation
 * should skip.
 *
 * Two-process design -- `Latte\Tools\Linter` is `final`, its
 * `writeError()` writes warnings to STDERR (not STDOUT), and its
 * `lintLatte()` installs a private per-file `set_error_handler()` that
 * suppresses `E_USER_*` propagation. None of those hooks let this run
 * cleanly fail the build on an unknown filter from inside the same
 * process. So this front-end script spawns `bin/piwigo lint:latte:inner`
 * (`Piwigo\Command\LintLatteCommand`, a hidden command -- resolves
 * `PiwigoExtension` through the real DI container the same way every
 * other command does) as a subprocess and inspects its stderr for
 * `[WARNING]`/`[DEPRECATED]` markers; any hit fails the build.
 *
 * Usage:
 *   php tools/latte-lint.php                        # scans themes/
 *   php tools/latte-lint.php themes/admin/default    # scans a custom path
 *   composer lint:latte
 */

/** @var list<string> $argv */
$paths = array_slice($argv, 1);

$root = dirname(__DIR__);

require $root . '/tools/latte-noescape-gate.php';
/** @var array<string, int> $noescapeAllowlist */
$noescapeAllowlist = require $root . '/tools/latte-noescape-allowlist.php';
$noescapeViolations = scanNoescapeCorpus($root . '/themes', $noescapeAllowlist);
if ($noescapeViolations !== []) {
    fwrite(STDERR, 'Latte |noescape gate: ' . count($noescapeViolations) . " violation(s) -- failing build.\n");
    foreach ($noescapeViolations as $violation) {
        fwrite(STDERR, "  {$violation}\n");
    }
    exit(1);
}
$cmd = array_merge([PHP_BINARY, $root . '/bin/piwigo', 'lint:latte:inner'], $paths);

$proc = proc_open(
    $cmd,
    [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ],
    $pipes,
    $root,
);
if (! is_resource($proc)) {
    fwrite(STDERR, "Failed to start lint:latte:inner\n");
    exit(2);
}

$rawStdout = stream_get_contents($pipes[1]);
$stdout = $rawStdout === false ? '' : $rawStdout;
$rawStderr = stream_get_contents($pipes[2]);
$stderr = $rawStderr === false ? '' : $rawStderr;
fclose($pipes[1]);
fclose($pipes[2]);
$exit = proc_close($proc);

fwrite(STDOUT, $stdout);
fwrite(STDERR, $stderr);

$warnings = preg_match_all('/^\[(?:WARNING|DEPRECATED)\]/m', $stderr);
if ($warnings > 0) {
    fwrite(STDERR, "Latte lint: {$warnings} warning(s) -- failing build.\n");
    exit(1);
}

exit($exit);
