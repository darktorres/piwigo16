<?php

declare(strict_types=1);

/**
 * Latte template linter — wraps Latte\Tools\Linter with our PiwigoExtension
 * registered, so the bundled linter recognizes Piwigo-specific filters
 * (|translate, |translate_dec, …) instead of warning "Unknown filter".
 *
 * Scope grows with §1.2 Wave 2: as more Smarty plugins port to
 * PiwigoExtension, the linter automatically picks them up. Until the first
 * .latte template lands the run is a no-op pass — by design, so CI doesn't
 * fail on the empty case.
 *
 * Two-process design — Latte's bundled Linter is `final`, its
 * writeError() writes warnings to STDERR (not STDOUT), and its lintLatte()
 * installs a private per-file set_error_handler that suppresses E_USER_*
 * propagation. None of those hooks let us cleanly fail the run on an
 * unknown filter from inside the same process. So this front-end script
 * spawns _latte-lint-inner.php as a subprocess and inspects its stderr for
 * [WARNING]/[DEPRECATED] markers; any hit fails the build.
 *
 * Usage:
 *   php tools/latte-lint.php                # scans themes/ and plugins/
 *   php tools/latte-lint.php themes/_base   # scans a custom path
 *   composer lint:latte
 */

/** @var list<string> $argv */
global $argv;
$paths = array_slice($argv, 1);
if ($paths === []) {
    $paths = ['themes', 'plugins'];
}

$inner = __DIR__ . '/_latte-lint-inner.php';
$cmd = array_merge([PHP_BINARY, $inner], $paths);

$proc = proc_open(
    $cmd,
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
);
if (!is_resource($proc)) {
    fwrite(STDERR, "Failed to start inner linter\n");
    exit(2);
}

$stdout = stream_get_contents($pipes[1]) ?: '';
$stderr = stream_get_contents($pipes[2]) ?: '';
fclose($pipes[1]);
fclose($pipes[2]);
$exit = proc_close($proc);

fwrite(STDOUT, $stdout);
fwrite(STDERR, $stderr);

$warnings = preg_match_all('/^\[(?:WARNING|DEPRECATED)\]/m', $stderr);
if ($warnings > 0) {
    fwrite(STDERR, "Latte lint: $warnings warning(s) — failing build.\n");
    exit(1);
}

exit($exit);
