<?php

declare(strict_types=1);

// P23 batch 9: door-lock tests for the finished legacy elimination
// (batches 8a-8g deleted every legacy library, page file, and frozen
// script). What legitimately remains of the historical directory layout
// is pinned exactly here, so a regression -- a new .php file dropped into
// include/, a resurrected admin/include/, an include statement reaching
// into a legacy path -- fails Arch instead of silently reviving the old
// layout. Directory-content allowlists (not string bans) on purpose:
// provenance docblocks legitimately mention old paths all over src/.

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

test('include/ does not exist -- the bootstrap seam was inlined into RequestBootstrap::bootEntryPoint()', function (): void {
    // P23 (include/+admin/ deletion batch): common.inc.php's body moved
    // into Piwigo\Bootstrap\RequestBootstrap::bootEntryPoint(), called
    // directly by every real entry point; env.inc.php's one-line
    // `require vendor/autoload.php` is now just done explicitly by every
    // caller; index.php's anti-listing stub is moot once the directory
    // itself doesn't exist to list. A resurrected include/ directory of
    // any kind is legacy code coming back.
    expect(is_dir(dirname(__DIR__, 2) . '/include'))->toBeFalse();
});

test('admin/ does not exist -- its only remaining content (themes/) moved to themes/admin/', function (): void {
    // Part II (web-root isolation): admin/popuphelp.php moved to
    // public/admin/popuphelp.php (a real entry point) earlier. admin/
    // itself is now fully gone: its last real content, the 3 admin
    // themes' live assets, relocated to themes/admin/ (automatically
    // web-reachable via the existing public/themes symlink, no new
    // symlink needed) so admin/'s own anti-listing stub had nothing left
    // to protect. admin/include/ died with batch 9; a resurrected admin/
    // directory of any kind is legacy code coming back.
    expect(is_dir(dirname(__DIR__, 2) . '/admin'))->toBeFalse();
});

test('public/ contains exactly the relocated entry points, robots.txt, and the sanctioned asset symlinks', function (): void {
    // Part II (web-root isolation, docs/PLAN.md P32's pulled-forward
    // slice): DocumentRoot is public/, not the repo root -- every PHP entry
    // point lives here now, plus symlinks back to the 3 static asset
    // directories real requests need (themes/, dist/, _data/combined/).
    // The former separate public/admin/themes symlink is gone -- the 3
    // admin themes' live assets relocated to themes/admin/ (include/+
    // admin/ deletion batch), automatically covered by the existing
    // themes/ symlink, so public/admin/ now holds only popuphelp.php.
    // upload/galleries/local/language/plugins and every other _data/
    // subdirectory are deliberately NOT bridged here -- being directly,
    // statically reachable was a live SEC-33/35/38/47 gap this phase
    // closes, not a feature to preserve (see docs/REFERENCE.md's "Web
    // root" section).
    expect(listDirectoryEntries(dirname(__DIR__, 2) . '/public'))->toBe([
        '.htaccess',
        '__test_errors.php',
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
        'ws.php',
    ]);

    expect(listDirectoryEntries(dirname(__DIR__, 2) . '/public/admin'))->toBe([
        'popuphelp.php',
    ]);

    expect(listDirectoryEntries(dirname(__DIR__, 2) . '/public/_data'))->toBe([
        'combined',
    ]);
});

test('install/ contains only data files and the anti-listing stub', function (): void {
    // The 145 frozen install/db/*.php + install/upgrade_X.Y.Z.php scripts
    // were migrated to Piwigo\Admin\Install\{DbPatch,VersionUpgrade}
    // classes and deleted (P23 batch 8g) -- those classes were themselves
    // later deleted entirely (gap-closure Stage 1a-bis: the whole
    // in-place-upgrade chain contradicted this codebase's own documented
    // "no in-place upgrade" architecture). Only schema/config data files
    // may live here.
    expect(listDirectoryEntries(dirname(__DIR__, 2) . '/install'))->toBe([
        'config.sql',
        'index.php',
        'obsolete.list',
        'obsolete_extensions.list',
        'piwigo_structure-mysql.sql',
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

test('no include/require statement targets a legacy include/ or admin/ path -- both directories are gone', function (): void {
    // Previously allowlisted 'include/common.inc.php' and 'include/env.inc.php'
    // as the two sanctioned seam-file targets; both are deleted now
    // (include/+admin/ deletion batch), so the sanctioned list is empty --
    // any include/require statement reaching into either legacy path is a
    // violation, full stop.
    $root = dirname(__DIR__, 2);

    $files = array_merge(
        globPaths($root . '/*.php'),
        globPaths($root . '/config/*.php'),
        globPaths($root . '/public/*.php'),
        globPaths($root . '/public/admin/*.php'),
    );
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $fileInfo) {
        if ($fileInfo instanceof SplFileInfo && $fileInfo->isFile() && $fileInfo->getExtension() === 'php') {
            $files[] = $fileInfo->getPathname();
        }
    }

    $violations = findLegacyIncludeTargets($files);

    expect($violations)->toBe([]);
});
