<?php

declare(strict_types=1);

// Door-lock tests for the legacy directory layout: what legitimately
// remains is pinned exactly here, so a regression -- a new .php file
// dropped into include/, a resurrected admin/include/, an include
// statement reaching into a legacy path -- fails Arch instead of
// silently reviving the old layout. Directory-content allowlists (not
// string bans) on purpose: provenance docblocks legitimately mention old
// paths all over src/.

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
 * into the include/, admin/, or install/ trees.
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
    // Request bootstrapping happens in
    // Piwigo\Bootstrap\RequestBootstrap::bootEntryPoint(), called
    // directly by every real entry point; there is no
    // include/common.inc.php or include/env.inc.php to require. A
    // resurrected include/ directory of any kind is legacy code coming
    // back.
    expect(is_dir(dirname(__DIR__, 2) . '/include'))->toBeFalse();
});

test('admin/ does not exist -- its only remaining content (themes/) moved to themes/admin/', function (): void {
    // admin/popuphelp.php lives at public/admin/popuphelp.php (a real
    // entry point). admin/ itself does not exist: its last real content,
    // the 3 admin themes' live assets, lives at themes/admin/
    // (automatically web-reachable via the existing public/themes
    // symlink, no separate symlink needed). A resurrected admin/
    // directory of any kind is legacy code coming back.
    expect(is_dir(dirname(__DIR__, 2) . '/admin'))->toBeFalse();
});

test('public/ contains exactly the relocated entry points, robots.txt, and the sanctioned asset symlinks', function (): void {
    // DocumentRoot is public/, not the repo root -- every PHP entry
    // point lives here, plus symlinks back to the 3 static asset
    // directories real requests need (themes/, dist/, _data/combined/).
    // public/admin/ holds only popuphelp.php: the admin themes' live
    // assets live at themes/admin/, already covered by the themes/
    // symlink. upload/galleries/local/language/plugins and every other
    // _data/ subdirectory are deliberately NOT bridged here -- they must
    // not be directly, statically reachable (SEC-33/35/38/47) (see
    // docs/REFERENCE.md's "Web root" section).
    expect(listDirectoryEntries(dirname(__DIR__, 2) . '/public'))->toBe([
        '.htaccess',
        '__test_errors.php',
        '_data',
        'about.php',
        'action.php',
        'admin',
        'admin.php',
        'analytics_vitals.php',
        'api.php',
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
    // No in-place-upgrade mechanism exists here -- that would contradict
    // this codebase's own documented "no in-place upgrade" architecture.
    // Only schema/config data files may live here.
    expect(listDirectoryEntries(dirname(__DIR__, 2) . '/install'))->toBe([
        'config.sql',
        'index.php',
        // One generated snapshot per provider. MariaDB has its own rather
        // than sharing MySQL's: the two differ at the engine level (no
        // native JSON type, `int(11)` display widths), so a shared file
        // made the CI drift guard unsatisfiable on that leg.
        'piwigo_structure-mariadb.sql',
        'piwigo_structure-mysql.sql',
        'piwigo_structure-pgsql.sql',
    ]);
});

test('root directory PHP files are exactly the tool configs -- every entry point moved to public/', function (): void {
    // The ~26 real entry points live under public/ (see the dedicated
    // public/ test above) -- only tool configs remain at the repo root,
    // since they're read by CLI tooling (composer/rector/ecs), never a
    // web request, and have their own SEC-01 deny-rule coverage
    // regardless of location.
    $names = array_map(basename(...), globPaths(dirname(__DIR__, 2) . '/*.php'));
    sort($names);

    expect($names)
        ->toBe([
            'composer-unused.php',
            'ecs.php',
            'rector.php',
        ]);
});

test('no include/require statement targets a legacy include/ or admin/ path -- both directories are gone', function (): void {
    // The sanctioned target list is empty -- any include/require statement
    // reaching into either legacy path is a violation, full stop.
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

    expect($violations)
        ->toBe([]);
});
