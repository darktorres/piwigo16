<?php

declare(strict_types=1);

/**
 * A ported extension's own tests (Pest/PHPUnit) belong in its own
 * package, alongside its src/templates/CSS/JS -- ../piwigo16-plugins/
 * ../piwigo16-themes, not this repo's own tree (matching the
 * "ported themes/plugins are committed only in the sibling catalog
 * repo" rule everything else about a port already follows). Neither
 * sibling repo has any Pest/PHPUnit install of its own, so this repo's
 * own vendor/bin/pest is still what runs them.
 *
 * PHPUnit's <directory> config is static XML, with no glob support --
 * unlike analyse-ported-extensions.sh/build-ported-extension-assets.mjs,
 * which glob their sibling paths fresh on every invocation, a single
 * hardcoded <directory> per port would need a phpunit.xml.dist edit for
 * every new port. This script closes that gap the same way: globbed
 * fresh on every run, refreshing a symlink per port under
 * tests/PortedExtensions/<port-dir-name> (gitignored -- generated, not
 * real content) so phpunit.xml.dist's own single
 * <directory>tests/PortedExtensions</directory> entry needs no change
 * as new ports are added.
 *
 * Run automatically as the first step of `composer test:ported-extensions`.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

$root = dirname(__DIR__);
$linkDir = $root . '/tests/PortedExtensions';

if (! is_dir($linkDir)) {
    mkdir($linkDir, 0o777, true);
}

// Drop stale symlinks from a port that no longer has a tests/ dir (or was
// removed entirely) before relinking -- keeps this idempotent across runs.
$existingEntries = scandir($linkDir);
foreach ($existingEntries === false ? [] : $existingEntries as $existing) {
    $path = $linkDir . '/' . $existing;
    if (is_link($path)) {
        unlink($path);
    }
}

$siblingDirs = ['../piwigo16-plugins', '../piwigo16-themes'];
$linked = 0;

foreach ($siblingDirs as $siblingDir) {
    $absoluteSiblingDir = $root . '/' . $siblingDir;
    if (! is_dir($absoluteSiblingDir)) {
        continue;
    }

    $portDirs = glob($absoluteSiblingDir . '/*_17.0.0', GLOB_ONLYDIR);
    foreach ($portDirs === false ? [] : $portDirs as $portDir) {
        $testsDir = $portDir . '/tests';
        if (! is_dir($testsDir)) {
            continue;
        }

        $linkName = basename($portDir);
        symlink($testsDir, $linkDir . '/' . $linkName);
        $linked++;
    }
}

fwrite(STDERR, $linked === 0
    ? "No ported plugin/theme has a tests/ directory -- nothing to link.\n"
    : "Linked {$linked} ported extension test director" . ($linked === 1 ? 'y' : 'ies') . " into tests/PortedExtensions/.\n");
