<?php

declare(strict_types=1);

// P23 batch 9: door-lock tests for the finished legacy elimination
// (batches 8a-8g deleted every legacy library, page file, and frozen
// script). What legitimately remains of the historical directory layout
// is pinned exactly here, so a regression -- a new .php file dropped into
// include/, a resurrected admin/include/, an include statement reaching
// into a legacy path -- fails Arch instead of silently reviving the old
// layout. Directory-content allowlists (not string bans) on purpose:
// provenance docblocks legitimately mention old paths all over src/, and
// DbPatch\Patch80 carries an 847-entry frozen data list of ancient
// PhpWebGallery file paths.

/**
 * @return list<string>
 */
function listDirectoryEntries(string $absoluteDir): array
{
    $entries = scandir($absoluteDir);
    if ($entries === false) {
        throw new RuntimeException("Unreadable directory: {$absoluteDir}");
    }

    return array_values(array_diff($entries, ['.', '..']));
}

/**
 * glob() with a false-safe, reindexed return -- keeps the callers' types
 * honest without short-ternary fallbacks.
 *
 * @return list<string>
 */
function globPaths(string $pattern): array
{
    $matches = glob($pattern);

    return $matches === false ? [] : $matches;
}

/**
 * Every include/require target literal under the given roots that points
 * into the historical include/, admin/, or install/ trees.
 *
 * Token-level on purpose: only string literals that are part of an actual
 * include/include_once/require/require_once statement count. A plain
 * regex over file contents would drown in provenance docblocks and
 * Patch80's frozen path-list data.
 *
 * @param list<string> $files absolute paths
 * @return array<string, list<string>> violation path literal => files using it
 */
function findLegacyIncludeTargets(array $files): array
{
    $includeTokenIds = [T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE];
    $found = [];

    foreach ($files as $file) {
        $source = file_get_contents($file);
        if ($source === false) {
            throw new RuntimeException("Unreadable file: {$file}");
        }

        $tokens = token_get_all($source);
        $tokenCount = count($tokens);

        for ($i = 0; $i < $tokenCount; $i++) {
            $token = $tokens[$i];
            if (! is_array($token) || ! in_array($token[0], $includeTokenIds, true)) {
                continue;
            }

            // Collect every constant-string literal that is part of this
            // include statement (concatenations like
            // `PHPWG_ROOT_PATH . 'include/common.inc.php'` yield the
            // literal directly; purely dynamic targets yield none).
            for ($j = $i + 1; $j < $tokenCount; $j++) {
                if ($tokens[$j] === ';') {
                    break;
                }
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_CONSTANT_ENCAPSED_STRING) {
                    $literal = trim($tokens[$j][1], "'\"");
                    if (preg_match('#^(include|admin|install)/#', $literal) === 1) {
                        $found[$literal][] = $file;
                    }
                }
            }
        }
    }

    return $found;
}

test('include/ contains exactly the sanctioned bootstrap seam files', function (): void {
    // The P23 8f-5 design: include/common.inc.php stays as the thin
    // include seam every entry point uses (top-level scope is
    // load-bearing for the bare-variable config includes, and SEC-60
    // keeps its define() calls out of src/), env.inc.php is the autoload
    // boundary, and index.php is the standard anti-directory-listing
    // stub. config_default.inc.php is gone -- "nothing is frozen"
    // gap-closure (2026-07-22) retired it in favor of
    // Piwigo\Config\Config::defaultsArray(), already the real source of
    // truth for every request's actual defaults (see that method's own
    // docblock). Anything appearing here beyond these 3 is legacy code
    // coming back.
    expect(listDirectoryEntries(dirname(__DIR__, 2) . '/include'))->toBe([
        'common.inc.php',
        'env.inc.php',
        'index.php',
    ]);
});

test('admin/ contains only the anti-listing stub and theme assets', function (): void {
    // Part II (web-root isolation): admin/popuphelp.php moved to
    // public/admin/popuphelp.php (a real entry point, must live under the
    // new DocumentRoot) -- admin/themes/ stays here and is bridged back via
    // public/admin/themes's own symlink instead of moving, matching
    // themes/ itself. admin/include/ died with batch 9; no other PHP
    // belongs at admin/ root -- every admin page is an AdminDispatcher
    // sub-controller now.
    expect(listDirectoryEntries(dirname(__DIR__, 2) . '/admin'))->toBe([
        'index.php',
        'themes',
    ]);
});

test('public/ contains exactly the relocated entry points, robots.txt, and the sanctioned asset symlinks', function (): void {
    // Part II (web-root isolation, docs/PLAN-REPLAY.md P32's pulled-forward
    // slice): DocumentRoot is public/, not the repo root -- every PHP entry
    // point lives here now, plus symlinks back to the 4 static asset
    // directories real requests need (themes/, admin/themes/, dist/,
    // _data/combined/). upload/galleries/local/language/plugins and every
    // other _data/ subdirectory are deliberately NOT bridged here -- being
    // directly, statically reachable was a live SEC-33/35/38/47 gap this
    // phase closes, not a feature to preserve (see docs/DEPLOYMENT.md's
    // "Web root" section).
    expect(listDirectoryEntries(dirname(__DIR__, 2) . '/public'))->toBe([
        '.htaccess',
        '_data',
        'about.php',
        'action.php',
        'admin',
        'admin.php',
        'analytics_vitals.php',
        'comments.php',
        'dist',
        'feed.php',
        'health.php',
        'i.php',
        'identification.php',
        'index.php',
        'install.php',
        'logo.php',
        'nbm.php',
        'notification.php',
        'password.php',
        'picture.php',
        'popuphelp.php',
        'profile.php',
        'qsearch.php',
        'random.php',
        'ready.php',
        'register.php',
        'robots.txt',
        'search.php',
        'tags.php',
        'themes',
        'upgrade.php',
        'upgrade_feed.php',
        'ws.php',
    ]);

    expect(listDirectoryEntries(dirname(__DIR__, 2) . '/public/admin'))->toBe([
        'popuphelp.php',
        'themes',
    ]);

    expect(listDirectoryEntries(dirname(__DIR__, 2) . '/public/_data'))->toBe([
        'combined',
    ]);
});

test('install/ contains only data files and the anti-listing stub', function (): void {
    // The 145 frozen install/db/*.php + install/upgrade_X.Y.Z.php scripts
    // were migrated to Piwigo\Admin\Install\{DbPatch,VersionUpgrade}
    // classes and deleted (P23 batch 8g). Only schema/config data files
    // may live here.
    expect(listDirectoryEntries(dirname(__DIR__, 2) . '/install'))->toBe([
        'config.sql',
        'index.php',
        'obsolete.list',
        'obsolete_extensions.list',
        'piwigo_structure-mysql.sql',
        'schema',
    ]);
});

test('root directory PHP files are exactly the tool configs -- every entry point moved to public/', function (): void {
    // Part II (web-root isolation): the ~26 real entry points now live
    // under public/ (see the dedicated public/ test above) -- only tool
    // configs remain at the repo root, since they're read by CLI tooling
    // (composer/rector/ecs), never a web request, and have their own
    // SEC-01 deny-rule coverage regardless of location.
    $names = array_map(basename(...), globPaths(dirname(__DIR__, 2) . '/*.php'));
    sort($names);

    expect($names)->toBe([
        'composer-unused.php',
        'ecs.php',
        'rector.php',
    ]);
});

test('no include/require statement targets a legacy path outside the sanctioned seams', function (): void {
    $root = dirname(__DIR__, 2);

    $files = array_merge(
        globPaths($root . '/*.php'),
        globPaths($root . '/config/*.php'),
        globPaths($root . '/include/*.php'),
        globPaths($root . '/admin/*.php'),
        globPaths($root . '/public/*.php'),
        globPaths($root . '/public/admin/*.php'),
    );
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $fileInfo) {
        if ($fileInfo instanceof SplFileInfo && $fileInfo->isFile() && $fileInfo->getExtension() === 'php') {
            $files[] = $fileInfo->getPathname();
        }
    }

    $sanctioned = [
        'include/common.inc.php',
        'include/env.inc.php',
    ];

    $violations = array_diff_key(findLegacyIncludeTargets($files), array_flip($sanctioned));

    expect($violations)->toBe([]);
});
