<?php

declare(strict_types=1);

use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Admin\Extensions\PemCatalog;
use Piwigo\Admin\Extensions\ZipExtractor;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\Kernel;
use Piwigo\Core\Logger;
use Piwigo\Core\Paths;

// getVersionsToCheck()
// is a pure, zero-network function (covered directly below, no fixture
// server needed). getServerExtensions()/getIncompatibleExtensions()/
// extractArchive() call HttpClientService::fetch()/fetchToFile(), but
// against a plain static manifest.json + zip, not a real external PEM
// server -- which makes them genuinely testable (see ExtensionUpdateCheckerTest's own docblock for why *that*
// class's PEM-touching methods stay untestable: they still hit the real,
// deliberately-unreachable AppInfo::URL). guardedFetch()'s own self-request
// exemption (HttpClientService's docblock) legitimately allows a plain
// http:// loopback request when $_SERVER['HTTP_HOST'] matches the target
// host[:port] -- the same real `php -S 127.0.0.1:<port>` + matching
// HTTP_HOST technique HttpClientServiceTest.php's own docblock established,
// reused here via pem_catalog_start_local_server()/..._stop_local_server()
// below, with PIWIGO_ALT_PLUGINS_PEM_URL/PIWIGO_ALT_THEMES_PEM_URL
// pointed at the disposable server's own docroot.
//
// The 4 sort comparators and the 2 local-filesystem helpers
// (getLocallyMergedExtensions()/deleteObsoleteFiles()) below stay covered
// exactly as before -- both read Piwigo\Core\CurrentPaths directly
// (getLocallyMergedExtensions() for install/obsolete_extensions.list,
// removed entirely -- always [] against the real repo root now;
// deleteObsoleteFiles() via ExtensionType::scanDirectory() for its own
// trash-path string), so this suite seeds it against this repo's real
// root, same convention as every other Unit test touching CurrentPaths
// (e.g. ExtensionTypeTest).
//
// Line 383's `$extractPathRealpath === false` guard is a genuine
// TOCTOU-only defensive check: file_exists($extractPath . '/obsolete.list')
// (the guard just before it) already structurally implies $extractPath
// itself exists as a real directory, so realpath($extractPath) can only
// fail here via a race condition between that check and this one (the
// directory vanishing mid-call) -- not something any deterministic test
// can construct.
//
// Lines 376/377 (the `$oldFiles === false` / `return;` guard right
// before deleteObsoleteFiles()'s own debug-log call) ARE already
// covered by the new "cannot be read" test below -- hand-mutating either
// one and running that ONE test filtered, standalone, produces a real
// failure (breaking the guard leaves $oldFiles === false, and
// `$oldFiles[] = 'obsolete.list';` on a bool throws "Cannot use a scalar
// value as an array", an uncaught Error). Same blind spot as
// ExtensionScanner.php's own crash-based guards this same G2 batch --
// `pest --mutate` doesn't credit an uncaught crash as "tested" the way
// it credits a normal assertion failure.
//
// Line 433's FalseToTrue mutation (`$lines !== false` ->
// `$lines !== true`, getLocallyMergedExtensions()'s own file-read guard)
// is genuinely inert, per hand-mutation against the new
// "cannot be read" test below: unlike ExtensionScanner.php's own
// analogous guards (which feed a string-typed, strict_types=1 param and
// throw a real TypeError when broken), `foreach (false as $x)` just
// emits a suppressible E_WARNING and performs zero iterations -- so
// both the correct guard (skip the loop) and the mutated one (enter the
// if, but the foreach still no-ops on a non-iterable) produce the exact
// same empty result.

function pem_catalog_test_marker(): string
{
    /** @var string|null */
    static $marker = null;

    return $marker ??= sys_get_temp_dir() . '/piwigo-pem-catalog-test-' . bin2hex(random_bytes(8));
}

beforeEach(function (): void {
    mkdir(pem_catalog_test_marker(), 0o777, true);
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 4)));
});

afterEach(function (): void {
    Kernel::reset();
    $dir = pem_catalog_test_marker();
    if (is_dir($dir)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $path) {
            assert($path instanceof SplFileInfo);
            $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname());
        }
        rmdir($dir);
    }
});

test('compareByRevisionDate sorts descending, newest first', function (): void {
    $older = [
        'revision_date' => '2026-01-01',
    ];
    $newer = [
        'revision_date' => '2026-06-01',
    ];

    expect(PemCatalog::compareByRevisionDate($newer, $older))->toBe(-1);
    expect(PemCatalog::compareByRevisionDate($older, $newer))->toBe(1);
});

test('compareByRevisionDate treats an identical date as a tie, not "$a is later"', function (): void {
    // The same-date branch only
    // takes the `< ? 1 : -1` path once, via the `-1` (a is not-later)
    // arm -- a `<` -> `<=` mutation flips this specific case's result to
    // 1 without breaking either directional assertion above.
    $same = [
        'revision_date' => '2026-06-01',
    ];

    expect(PemCatalog::compareByRevisionDate($same, $same))->toBe(-1);
});

test('compareByName sorts case-insensitively by extension_name', function (): void {
    $a = [
        'extension_name' => 'zebra',
    ];
    $b = [
        'extension_name' => 'Apple',
    ];

    expect(PemCatalog::compareByName($a, $b))->toBeGreaterThan(0);
    expect(PemCatalog::compareByName($b, $a))->toBeLessThan(0);
    // 'Same' vs 'same' can't tell a
    // real strtolower() from a removed one on the *second* argument alone,
    // since 'same' is already lowercase -- both sides need real uppercase
    // to force each of the two strtolower() calls to matter independently.
    expect(PemCatalog::compareByName([
        'extension_name' => 'SAME',
    ], [
        'extension_name' => 'same',
    ]))->toBe(0);
    expect(PemCatalog::compareByName([
        'extension_name' => 'same',
    ], [
        'extension_name' => 'SAME',
    ]))->toBe(0);
});

test('compareByName falls back to an empty name for a non-scalar extension_name', function (): void {
    expect(PemCatalog::compareByName([
        'extension_name' => ['not', 'scalar'],
    ], [
        'extension_name' => 'apple',
    ]))->toBeLessThan(0);
    expect(PemCatalog::compareByName([
        'extension_name' => 'apple',
    ], [
        'extension_name' => null,
    ]))->toBeGreaterThan(0);
});

test('compareByName string-casts a real scalar extension_name instead of comparing it raw', function (): void {
    // Removing the (string) cast on
    // an already-string value is invisible -- an int forces it to matter
    // (strtolower()/strcmp() both require a real string argument).
    expect(PemCatalog::compareByName([
        'extension_name' => 20,
    ], [
        'extension_name' => '3',
    ]))->toBeLessThan(0);
    // The case above only forces
    // $a's own (string) cast (a separate line/mutation from $b's) --
    // this file declares strict_types=1, so a removed cast on $b leaves
    // a real int flowing into strtolower(), which throws a TypeError
    // rather than silently comparing wrong. $a stays a plain string here
    // to isolate $b's own cast.
    expect(PemCatalog::compareByName([
        'extension_name' => 'apple',
    ], [
        'extension_name' => 20,
    ]))->toBeGreaterThan(0);
});

test('compareByAuthor sorts case-insensitively by author_name, falling back to compareByName on a tie', function (): void {
    $a = [
        'author_name' => 'Alice',
        'extension_name' => 'zebra',
    ];
    $b = [
        'author_name' => 'Bob',
        'extension_name' => 'apple',
    ];
    expect(PemCatalog::compareByAuthor($a, $b))->toBeLessThan(0);

    $tieA = [
        'author_name' => 'same author',
        'extension_name' => 'zebra',
    ];
    $tieB = [
        'author_name' => 'Same Author',
        'extension_name' => 'apple',
    ];
    expect(PemCatalog::compareByAuthor($tieA, $tieB))->toBeGreaterThan(0);
});

test('compareByAuthor falls back to an empty author for a non-scalar author_name', function (): void {
    expect(PemCatalog::compareByAuthor([
        'author_name' => ['not', 'scalar'],
    ], [
        'author_name' => 'bob',
    ]))->toBeLessThan(0);
    expect(PemCatalog::compareByAuthor([
        'author_name' => 'bob',
    ], [
        'author_name' => null,
    ]))->toBeGreaterThan(0);
});

test('compareByAuthor string-casts a real scalar author_name instead of comparing it raw', function (): void {
    expect(PemCatalog::compareByAuthor([
        'author_name' => 20,
        'extension_name' => 'x',
    ], [
        'author_name' => '3',
        'extension_name' => 'x',
    ]))->toBeLessThan(0);
    // Same "$a's cast alone can't
    // prove $b's separate cast line" reasoning as compareByName above --
    // $a stays a plain string here so only $b's removed (string) cast
    // can be what forces strcasecmp()'s real int-vs-string TypeError
    // under strict_types=1.
    expect(PemCatalog::compareByAuthor([
        'author_name' => 'apple',
        'extension_name' => 'x',
    ], [
        'author_name' => 20,
        'extension_name' => 'x',
    ]))->toBeGreaterThan(0);
});

test('compareByDownloads sorts descending, most downloaded first', function (): void {
    $popular = [
        'extension_nb_downloads' => 500,
    ];
    $unpopular = [
        'extension_nb_downloads' => 3,
    ];

    expect(PemCatalog::compareByDownloads($popular, $unpopular))->toBe(-1);
    expect(PemCatalog::compareByDownloads($unpopular, $popular))->toBe(1);
});

test('compareByDownloads treats an identical count as a tie, not "$a has fewer"', function (): void {
    $same = [
        'extension_nb_downloads' => 500,
    ];

    expect(PemCatalog::compareByDownloads($same, $same))->toBe(-1);
});

test('getLocallyMergedExtensions returns an empty array against the real repo root -- install/obsolete_extensions.list is gone', function (): void {
    // install/obsolete_extensions.list (a static list of pre-fork PWG-era
    // plugin ids merged into core) was removed entirely -- no committed
    // asset left to parse, so this now always returns [] for the real
    // repo root. The other 2 tests below cover the real parsing/error
    // paths against their own throwaway fixture files.
    $catalog = new PemCatalog(new ZipExtractor(), new CurrentLogger(), Paths::fromRoot(dirname(__DIR__, 4)), new CurrentConfig());

    expect($catalog->getLocallyMergedExtensions())
        ->toBe([]);
});

test('getLocallyMergedExtensions reads the paths->root-prefixed file, not a bare CWD-relative one', function (): void {
    // A ConcatRemoveLeft mutation
    // that drops $this->paths->root entirely leaves a bare
    // 'install/obsolete_extensions.list', which is CWD-relative rather
    // than root-relative. A Paths root that genuinely differs from the
    // process's working directory is the only way to tell the two
    // apart -- the fixture list below has content the real, committed
    // install/obsolete_extensions.list does not.
    $root = pem_catalog_test_marker();
    mkdir($root . '/install', 0o777, true);
    file_put_contents($root . '/install/obsolete_extensions.list', "999:fixture_only_extension\n");

    $catalog = new PemCatalog(new ZipExtractor(), new CurrentLogger(), Paths::fromRoot($root), new CurrentConfig());

    expect($catalog->getLocallyMergedExtensions())
        ->toBe([
            999 => 'fixture_only_extension',
        ]);
});

test('getLocallyMergedExtensions returns an empty array, not a crash, when the list file cannot be read', function (): void {
    // A real, unreadable file (0000,
    // torres owns it but no read bit for anyone) -- file() genuinely
    // returns false here, not a mock, matching this project's own
    // established permission-denied convention (torres is a non-root user
    // in this environment, per `id`, so owning a file does not
    // bypass its own permission bits).
    $root = pem_catalog_test_marker();
    mkdir($root . '/install', 0o777, true);
    $listFile = $root . '/install/obsolete_extensions.list';
    file_put_contents($listFile, "999:fixture_only_extension\n");
    chmod($listFile, 0o000);

    $catalog = new PemCatalog(new ZipExtractor(), new CurrentLogger(), Paths::fromRoot($root), new CurrentConfig());

    set_error_handler(static fn (): bool => true, E_WARNING);
    try {
        $merged = $catalog->getLocallyMergedExtensions();
    } finally {
        restore_error_handler();
        chmod($listFile, 0o644);
    }

    expect($merged)
        ->toBe([]);
});

function pem_catalog_delete_obsolete_files(ExtensionType $type, string $extractPath): void
{
    $catalog = new PemCatalog(new ZipExtractor(), new CurrentLogger(), Paths::fromRoot(dirname(__DIR__, 4)), new CurrentConfig());
    $method = new ReflectionMethod($catalog, 'deleteObsoleteFiles');
    $method->invoke($catalog, $type, $extractPath, new Logger([
        'severity' => Logger::OFF,
    ]));
}

/**
 * Recursively removes a directory -- used for the standalone log
 * directories the debug-logging tests below point a real, DEBUG-severity
 * Logger at (separate from pem_catalog_test_marker(), same convention as
 * ImageExtImagickTest's own imageExtImagickTestRrmdir()).
 */
function pem_catalog_rrmdir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    $nodes = scandir($dir);
    foreach ($nodes !== false ? $nodes : [] as $node) {
        if ($node === '.' || $node === '..') {
            continue;
        }
        $path = $dir . '/' . $node;
        is_dir($path) ? pem_catalog_rrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

test('deleteObsoleteFiles logs the real function name and old-files list, not just any debug line', function (): void {
    // Every other deleteObsoleteFiles
    // test here passes a Logger::OFF instance (see
    // pem_catalog_delete_obsolete_files()), so nothing ever observed the
    // debug() calls' own message content -- a ConcatRemoveLeft/Right/
    // SwitchSides mutation on either could silently corrupt or blank the
    // logged message with zero test-visible effect.
    $logDir = sys_get_temp_dir() . '/piwigo-pem-catalog-test-log-' . bin2hex(random_bytes(8));
    mkdir($logDir, 0o777, true);

    try {
        $extractPath = pem_catalog_test_marker() . '/log_old_files';
        mkdir($extractPath, 0o777, true);
        file_put_contents($extractPath . '/old-file.php', 'stale code');
        file_put_contents($extractPath . '/obsolete.list', "old-file.php\n");

        $catalog = new PemCatalog(new ZipExtractor(), new CurrentLogger(), Paths::fromRoot(dirname(__DIR__, 4)), new CurrentConfig());
        $method = new ReflectionMethod($catalog, 'deleteObsoleteFiles');
        $method->invoke($catalog, ExtensionType::Plugin, $extractPath, new Logger([
            'severity' => Logger::DEBUG,
            'directory' => $logDir,
            'filename' => 'old-files.log',
        ]));

        $logContent = file_get_contents($logDir . '/old-files.log');
        if ($logContent === false) {
            throw new RuntimeException('Failed to read the real log file written by deleteObsoleteFiles().');
        }
        expect($logContent)
            ->toContain('deleteObsoleteFiles, $old_files = {old-file.php},{obsolete.list}');
    } finally {
        pem_catalog_rrmdir($logDir);
    }
});

test('deleteObsoleteFiles logs the real function name and full path before deleting each entry', function (): void {
    // Same underlying issue as the
    // sibling "old-files list" test above, for the OTHER debug() call --
    // the one right before each individual file/dir is actually removed.
    $logDir = sys_get_temp_dir() . '/piwigo-pem-catalog-test-log-' . bin2hex(random_bytes(8));
    mkdir($logDir, 0o777, true);

    try {
        $extractPath = pem_catalog_test_marker() . '/log_to_delete';
        mkdir($extractPath, 0o777, true);
        file_put_contents($extractPath . '/old-file.php', 'stale code');
        file_put_contents($extractPath . '/obsolete.list', "old-file.php\n");

        $catalog = new PemCatalog(new ZipExtractor(), new CurrentLogger(), Paths::fromRoot(dirname(__DIR__, 4)), new CurrentConfig());
        $method = new ReflectionMethod($catalog, 'deleteObsoleteFiles');
        $method->invoke($catalog, ExtensionType::Plugin, $extractPath, new Logger([
            'severity' => Logger::DEBUG,
            'directory' => $logDir,
            'filename' => 'to-delete.log',
        ]));

        $logContent = file_get_contents($logDir . '/to-delete.log');
        if ($logContent === false) {
            throw new RuntimeException('Failed to read the real log file written by deleteObsoleteFiles().');
        }
        expect($logContent)
            ->toContain('deleteObsoleteFiles, to delete = ' . $extractPath . '/old-file.php');
    } finally {
        pem_catalog_rrmdir($logDir);
    }
});

test('deleteObsoleteFiles removes every file listed in obsolete.list, plus the list itself', function (): void {
    $extractPath = pem_catalog_test_marker() . '/plugin';
    mkdir($extractPath, 0o777, true);
    file_put_contents($extractPath . '/old-file.php', 'stale code');
    file_put_contents($extractPath . '/obsolete.list', "old-file.php\n");

    pem_catalog_delete_obsolete_files(ExtensionType::Plugin, $extractPath);

    expect(file_exists($extractPath . '/old-file.php'))->toBeFalse();
    expect(file_exists($extractPath . '/obsolete.list'))->toBeFalse();
});

test('deleteObsoleteFiles refuses to delete a path traversal entry outside the extract directory', function (): void {
    $extractPath = pem_catalog_test_marker() . '/plugin2';
    mkdir($extractPath, 0o777, true);
    $canary = pem_catalog_test_marker() . '/canary.txt';
    file_put_contents($canary, 'must survive');
    file_put_contents($extractPath . '/obsolete.list', "../canary.txt\n");

    pem_catalog_delete_obsolete_files(ExtensionType::Plugin, $extractPath);

    expect(file_exists($canary))
        ->toBeTrue();
});

test('deleteObsoleteFiles trims whitespace/slashes and skips a blank line, then still processes a later real entry', function (): void {
    // A single-entry list can't
    // distinguish `continue` from `break` on the blank-line skip, since
    // there's nothing left to process either way. A blank line followed
    // by a real, still-to-be-deleted file forces continue's "keep going"
    // behavior to actually matter.
    $extractPath = pem_catalog_test_marker() . '/plugin4';
    mkdir($extractPath, 0o777, true);
    file_put_contents($extractPath . '/old-file.php', 'stale code');
    file_put_contents($extractPath . '/obsolete.list', "  \n  /old-file.php/  \n");

    pem_catalog_delete_obsolete_files(ExtensionType::Plugin, $extractPath);

    expect(file_exists($extractPath . '/old-file.php'))->toBeFalse();
    // An EmptyStringToNotEmpty
    // mutation on the blank-line guard falls through into treating the
    // extract directory itself (path = extractPath + '/' + '') as a
    // listed entry -- is_dir() is true for it, so it gets wholesale
    // deltree()'d, which *coincidentally* also removes old-file.php,
    // making the assertion above pass even under the mutation. Only
    // checking that the extract directory itself is still standing
    // actually catches it.
    expect(is_dir($extractPath))
        ->toBeTrue();
});

test('deleteObsoleteFiles skips a listed entry that does not actually exist on disk', function (): void {
    $extractPath = pem_catalog_test_marker() . '/plugin5';
    mkdir($extractPath, 0o777, true);
    file_put_contents($extractPath . '/obsolete.list', "does-not-exist.php\n");

    // realpath() on a non-existent path returns false -- the guard must
    // skip it (not fatal, not delete anything) rather than assume it
    // exists.
    pem_catalog_delete_obsolete_files(ExtensionType::Plugin, $extractPath);

    expect(file_exists($extractPath . '/obsolete.list'))->toBeFalse();
});

// [Mutation] Line 387's $trashPath construction
// (`$type->scanDirectory(...) . 'trash'`) is difficult to
// exercise deterministically without root, not simply untestable: the
// test below's own scenario, despite its name, does NOT actually reach
// this path -- FilesystemHelper::deltree() tries a plain rmdir() FIRST
// and only falls back to $trash_path if that fails, and an empty
// stale-dir under a normal writable parent always succeeds via rmdir()
// directly. Forcing the fallback needs rmdir() to fail while dirname()'s
// own is_writable() check still passes (required for the later rename()
// attempt) -- tried making the extract directory itself read-only
// (blocks BOTH rmdir() and rename() -- both return false, per a direct
// probe) and making an inner file undeletable so the directory
// isn't empty (rmdir() correctly fails with ENOTEMPTY, but the
// subsequent rename() ALSO failed in a direct probe -- Linux's rename()
// needs write access on the moved directory's own inode, not just its
// parent, to update its own '..' entry). No non-root technique found
// that reliably triggers a real trash-path rename() in this sandbox.
test('deleteObsoleteFiles moves a listed directory to trash rather than unlinking it', function (): void {
    // Every other test here lists
    // only plain files (is_file() -> @unlink()) -- nothing exercised the
    // is_dir() -> FilesystemHelper::deltree() branch. See the docblock
    // above for why this does NOT reach the trash-path fallback itself.
    $extractPath = pem_catalog_test_marker() . '/plugin6';
    mkdir($extractPath . '/stale-dir', 0o777, true);
    file_put_contents($extractPath . '/stale-dir/inner.php', 'stale');
    file_put_contents($extractPath . '/obsolete.list', "stale-dir\n");

    pem_catalog_delete_obsolete_files(ExtensionType::Plugin, $extractPath);

    expect(is_dir($extractPath . '/stale-dir'))->toBeFalse();
});

test('deleteObsoleteFiles does nothing when there is no obsolete.list at all', function (): void {
    $extractPath = pem_catalog_test_marker() . '/plugin3';
    mkdir($extractPath, 0o777, true);
    file_put_contents($extractPath . '/keep-me.php', 'still here');

    pem_catalog_delete_obsolete_files(ExtensionType::Plugin, $extractPath);

    expect(file_exists($extractPath . '/keep-me.php'))->toBeTrue();
});

test('deleteObsoleteFiles does nothing, not a crash, when obsolete.list exists but cannot be read', function (): void {
    // A real, unreadable file (0000)
    // -- file() genuinely returns false here, same established
    // permission-denied convention as this file's own sibling
    // getLocallyMergedExtensions test above. file_exists() (the guard
    // just before this one) stays true regardless of the chmod -- only
    // the later read fails -- so this genuinely exercises the `$oldFiles
    // === false` guard, not the guard above it.
    $extractPath = pem_catalog_test_marker() . '/plugin7';
    mkdir($extractPath, 0o777, true);
    file_put_contents($extractPath . '/keep-me.php', 'still here');
    $listFile = $extractPath . '/obsolete.list';
    file_put_contents($listFile, "keep-me.php\n");
    chmod($listFile, 0o000);

    set_error_handler(static fn (): bool => true, E_WARNING);
    try {
        pem_catalog_delete_obsolete_files(ExtensionType::Plugin, $extractPath);
    } finally {
        restore_error_handler();
        chmod($listFile, 0o644);
    }

    expect(file_exists($extractPath . '/keep-me.php'))->toBeTrue()
        ->and(file_exists($listFile))
        ->toBeTrue();
});

// ----------------------------------------------------------------------

/**
 * Same real `php -S 127.0.0.1:<port>` technique HttpClientServiceTest.php's
 * own docblock establishes -- guardedFetch()'s self-request exemption
 * (matching $_SERVER['HTTP_HOST']) is what legitimately allows the plain
 * http:// loopback request the tests below make, not a test-only bypass.
 *
 * @return array{0: resource, 1: int}
 */
function pem_catalog_start_local_server(string $docRoot): array
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $port = random_int(20_000, 60_000);
        $proc = proc_open(['php', '-S', '127.0.0.1:' . $port, '-t', $docRoot], $descriptors, $pipes);
        if (! is_resource($proc)) {
            throw new RuntimeException('failed to start local test server');
        }

        set_error_handler(static fn (): bool => true);
        try {
            for ($i = 0; $i < 100; $i++) {
                $sock = fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
                if (is_resource($sock)) {
                    fclose($sock);

                    return [$proc, $port];
                }
                usleep(20_000);
            }
        } finally {
            restore_error_handler();
        }

        proc_terminate($proc);
        proc_close($proc);
    }

    throw new RuntimeException('local test server never became reachable after 5 attempts');
}

/**
 * @param resource $proc
 */
function pem_catalog_stop_local_server($proc): void
{
    proc_terminate($proc);
    proc_close($proc);
}

/**
 * Writes a manifest.json matching the real sibling-repo shape
 * (top-level {generated, source, category_id, count, extensions}). Each
 * $entries value is a manifest record plus an optional 'zipEntries'
 * (stored-name => contents) instruction -- stripped from the written
 * manifest, used to build the real .zip its own 'filename' field names.
 *
 * @param array<string, array<string, mixed>> $entries
 */
function pem_catalog_write_manifest_fixture(string $docRoot, array $entries): void
{
    $extensions = [];
    foreach ($entries as $key => $entry) {
        $zipEntries = $entry['zipEntries'] ?? null;
        unset($entry['zipEntries']);
        $extensions[$key] = $entry;

        $filename = $entry['filename'] ?? null;
        if (is_array($zipEntries) && is_string($filename)) {
            $zip = new ZipArchive();
            $zip->open($docRoot . '/' . $filename, ZipArchive::CREATE);
            foreach ($zipEntries as $storedName => $contents) {
                if (! is_string($storedName) || ! is_string($contents)) {
                    continue;
                }
                $zip->addFromString($storedName, $contents);
            }
            $zip->close();
        }
    }

    file_put_contents($docRoot . '/manifest.json', (string) json_encode([
        'generated' => '2026-08-13',
        'source' => 'fixture',
        'category_id' => 12,
        'count' => count($extensions),
        'extensions' => $extensions,
    ]));
}

/**
 * extractArchive() reads $this->currentLogger->get() internally -- unlike
 * this file's other tests, which either never touch the logger or invoke
 * deleteObsoleteFiles() directly with an explicit Logger instance, a bare
 * `new CurrentLogger()` here would throw "CurrentLogger not initialised"
 * the moment extractArchive() itself calls ->get().
 */
function pem_catalog_ready_logger(): CurrentLogger
{
    $currentLogger = new CurrentLogger();
    $currentLogger->set(new Logger([
        'severity' => Logger::OFF,
    ]));

    return $currentLogger;
}

/**
 * Save+restore, never a bare clear -- see feedback_putenv_unset_must_restore.
 */
function pem_catalog_set_env(string $var, ?string $value): ?string
{
    $original = getenv($var);
    putenv($value === null ? $var : $var . '=' . $value);

    return $original === false ? null : $original;
}

function pem_catalog_restore_env(string $var, ?string $original): void
{
    putenv($original === null ? $var : $var . '=' . $original);
}

test('getVersionsToCheck returns [$version] with zero HTTP calls', function (): void {
    // No local server started at all, and PIWIGO_ALT_PLUGINS_PEM_URL left
    // unset -- if this reached HttpClientService::fetch() at all, it would
    // hit the real, deliberately-unreachable AppInfo::URL default and hang
    // past this test's own runtime rather than return instantly. Returning
    // the exact literal, fast, is itself the proof the round-trip is gone.
    $catalog = new PemCatalog(new ZipExtractor(), new CurrentLogger(), Paths::fromRoot(dirname(__DIR__, 4)), new CurrentConfig());

    expect($catalog->getVersionsToCheck(ExtensionType::Plugin, false, '17.0.0'))
        ->toBe(['17.0.0'])
        ->and($catalog->getVersionsToCheck(ExtensionType::Theme, true, '9.9.9'))
        ->toBe(['9.9.9']);
});

test('getServerExtensions filters by piwigo_compat branch match, then by on-disk fs ids, and normalizes the returned record', function (): void {
    $docRoot = pem_catalog_test_marker() . '/mirror_server_extensions';
    mkdir($docRoot, 0o777, true);
    pem_catalog_write_manifest_fixture($docRoot, [
        'compat_new_1.0' => [
            'filename' => 'compat_new_1.0.zip',
            'extension_id' => '100',
            'extension_name' => 'Compat New',
            'revision_id' => 'r100',
            'revision_name' => '1.0',
            'revision_date' => '2026-01-01 00:00:00',
            'author_name' => 'Alice',
            'extension_description' => 'a compatible, not-yet-installed extension',
            'piwigo_compat' => ['17.0.0'],
        ],
        'incompat_1.0' => [
            'filename' => 'incompat_1.0.zip',
            'extension_id' => '101',
            'extension_name' => 'Incompat',
            'revision_id' => 'r101',
            'revision_name' => '1.0',
            'revision_date' => '2026-01-01 00:00:00',
            'author_name' => 'Bob',
            'extension_description' => 'incompatible -- only tagged for a legacy branch',
            'piwigo_compat' => ['1.6'],
        ],
        'compat_onfs_1.0' => [
            'filename' => 'compat_onfs_1.0.zip',
            'extension_id' => '102',
            'extension_name' => 'Compat OnFs',
            'revision_id' => 'r102',
            'revision_name' => '1.0',
            'revision_date' => '2026-01-01 00:00:00',
            'author_name' => 'Carol',
            'extension_description' => 'compatible, but already installed',
            'piwigo_compat' => ['17.0.0'],
        ],
    ]);

    [$proc, $port] = pem_catalog_start_local_server($docRoot);
    $originalHost = $_SERVER['HTTP_HOST'] ?? null;
    $_SERVER['HTTP_HOST'] = '127.0.0.1:' . $port;
    $originalEnv = pem_catalog_set_env('PIWIGO_ALT_PLUGINS_PEM_URL', 'http://127.0.0.1:' . $port);

    try {
        $catalog = new PemCatalog(new ZipExtractor(), new CurrentLogger(), Paths::fromRoot(dirname(__DIR__, 4)), new CurrentConfig());

        $new = $catalog->getServerExtensions(ExtensionType::Plugin, ['102'], true, false, '17.0.0');
        if ($new === null) {
            throw new RuntimeException('getServerExtensions() unexpectedly returned null.');
        }
        expect(array_keys($new))
            // PHP auto-casts a numeric-string array key to int -- expected
            // native behavior for $byExtensionId[$extensionId] = ....
            ->toBe([100])
            ->and($new['100']['extension_name'])->toBe('Compat New')
            ->and($new['100']['extension_nb_downloads'])->toBe(0)
            ->and($new['100']['rating_score'])->toBeNull()
            ->and($new['100']['nb_ratings'])->toBe(0)
            ->and($new['100']['tags'])->toBe([])
            ->and($new['100']['compatible_with_versions'])->toBe(['17.0.0']);

        $installed = $catalog->getServerExtensions(ExtensionType::Plugin, ['102'], false, false, '17.0.0');
        if ($installed === null) {
            throw new RuntimeException('getServerExtensions() unexpectedly returned null.');
        }
        expect(array_keys($installed))
            ->toBe([102]);
    } finally {
        pem_catalog_stop_local_server($proc);
        if ($originalHost === null) {
            unset($_SERVER['HTTP_HOST']);
        } else {
            $_SERVER['HTTP_HOST'] = $originalHost;
        }
        pem_catalog_restore_env('PIWIGO_ALT_PLUGINS_PEM_URL', $originalEnv);
    }
});

test('getServerExtensions returns null when the manifest.json fetch fails', function (): void {
    $originalHost = $_SERVER['HTTP_HOST'] ?? null;
    $_SERVER['HTTP_HOST'] = 'this-host-does-not-participate';
    // No server started, no env override set -- pemUrl() falls through to
    // the real AppInfo::URL default, a deliberately non-resolving .invalid
    // domain, so the fetch itself fails fast.
    $originalEnv = pem_catalog_set_env('PIWIGO_ALT_PLUGINS_PEM_URL', null);

    try {
        $catalog = new PemCatalog(new ZipExtractor(), new CurrentLogger(), Paths::fromRoot(dirname(__DIR__, 4)), new CurrentConfig());

        expect($catalog->getServerExtensions(ExtensionType::Plugin, [], true, false, '17.0.0'))
            ->toBeNull();
    } finally {
        if ($originalHost === null) {
            unset($_SERVER['HTTP_HOST']);
        } else {
            $_SERVER['HTTP_HOST'] = $originalHost;
        }
        pem_catalog_restore_env('PIWIGO_ALT_PLUGINS_PEM_URL', $originalEnv);
    }
});

test('getIncompatibleExtensions flags an fs extension whose installed version has no matching compatible manifest revision', function (): void {
    $docRoot = pem_catalog_test_marker() . '/mirror_incompatible';
    mkdir($docRoot, 0o777, true);
    pem_catalog_write_manifest_fixture($docRoot, [
        'known_good_2.0' => [
            'filename' => 'known_good_2.0.zip',
            'extension_id' => '200',
            'extension_name' => 'Known Good',
            'revision_id' => 'r200',
            'revision_name' => '2.0',
            'revision_date' => '2026-01-01 00:00:00',
            'author_name' => 'Dora',
            'extension_description' => 'the only compatible revision on record',
            'piwigo_compat' => ['17.0.0'],
        ],
    ]);

    [$proc, $port] = pem_catalog_start_local_server($docRoot);
    $originalHost = $_SERVER['HTTP_HOST'] ?? null;
    $_SERVER['HTTP_HOST'] = '127.0.0.1:' . $port;
    $originalEnv = pem_catalog_set_env('PIWIGO_ALT_PLUGINS_PEM_URL', 'http://127.0.0.1:' . $port);

    try {
        $catalog = new PemCatalog(new ZipExtractor(), new CurrentLogger(), Paths::fromRoot(dirname(__DIR__, 4)), new CurrentConfig());

        $fsExtensions = [
            'stale_local_plugin' => [
                'extension' => '200',
                'version' => '1.0',
            ],
            'current_local_plugin' => [
                'extension' => '200',
                'version' => '2.0',
            ],
        ];

        $incompatible = $catalog->getIncompatibleExtensions(ExtensionType::Plugin, $fsExtensions, [], true);
        if ($incompatible === false) {
            throw new RuntimeException('getIncompatibleExtensions() unexpectedly returned false.');
        }

        expect($incompatible)
            ->toHaveKey('stale_local_plugin')
            ->and($incompatible['stale_local_plugin'])->toBe('1.0')
            ->and($incompatible)
            ->not->toHaveKey('current_local_plugin');
    } finally {
        pem_catalog_stop_local_server($proc);
        if ($originalHost === null) {
            unset($_SERVER['HTTP_HOST']);
        } else {
            $_SERVER['HTTP_HOST'] = $originalHost;
        }
        pem_catalog_restore_env('PIWIGO_ALT_PLUGINS_PEM_URL', $originalEnv);
        unset($_SESSION['incompatible_plugins']);
    }
});

test('extractArchive resolves a revision_id to its manifest filename, downloads it directly (no download.php), and locates the new-contract plugin.json marker', function (): void {
    $root = pem_catalog_test_marker() . '/extract_new_contract';
    mkdir($root . '/plugins', 0o777, true);

    $docRoot = pem_catalog_test_marker() . '/mirror_extract';
    mkdir($docRoot, 0o777, true);
    pem_catalog_write_manifest_fixture($docRoot, [
        'fixture_plugin_1.0' => [
            'filename' => 'fixture_plugin_1.0.zip',
            'extension_id' => '300',
            'extension_name' => 'Fixture Plugin',
            'revision_id' => 'r300',
            'revision_name' => '1.0',
            'revision_date' => '2026-01-01 00:00:00',
            'author_name' => 'Eve',
            'extension_description' => 'a migrated, plugin.json-only extension',
            'piwigo_compat' => ['17.0.0'],
            'zipEntries' => [
                'fixture_plugin/plugin.json' => '{"id":"fixture_plugin"}',
                'fixture_plugin/src/Plugin.php' => '<?php',
            ],
        ],
    ]);

    [$proc, $port] = pem_catalog_start_local_server($docRoot);
    $originalHost = $_SERVER['HTTP_HOST'] ?? null;
    $_SERVER['HTTP_HOST'] = '127.0.0.1:' . $port;
    $originalEnv = pem_catalog_set_env('PIWIGO_ALT_PLUGINS_PEM_URL', 'http://127.0.0.1:' . $port);

    try {
        $catalog = new PemCatalog(new ZipExtractor(), pem_catalog_ready_logger(), Paths::fromRoot($root), new CurrentConfig());

        $result = $catalog->extractArchive(ExtensionType::Plugin, 'install', 'r300', 'fixture_plugin');

        expect($result->status)
            ->toBe('ok')
            ->and($result->id)
            ->toBe('fixture_plugin')
            ->and(file_exists($root . '/plugins/fixture_plugin/plugin.json'))->toBeTrue()
            ->and(file_exists($root . '/plugins/fixture_plugin/src/Plugin.php'))->toBeTrue();
    } finally {
        pem_catalog_stop_local_server($proc);
        if ($originalHost === null) {
            unset($_SERVER['HTTP_HOST']);
        } else {
            $_SERVER['HTTP_HOST'] = $originalHost;
        }
        pem_catalog_restore_env('PIWIGO_ALT_PLUGINS_PEM_URL', $originalEnv);
    }
});

test('extractArchive fails with dl_archive_error when the revision id has no matching manifest entry', function (): void {
    $root = pem_catalog_test_marker() . '/extract_missing_revision';
    mkdir($root . '/plugins', 0o777, true);

    $docRoot = pem_catalog_test_marker() . '/mirror_missing_revision';
    mkdir($docRoot, 0o777, true);
    pem_catalog_write_manifest_fixture($docRoot, []);

    [$proc, $port] = pem_catalog_start_local_server($docRoot);
    $originalHost = $_SERVER['HTTP_HOST'] ?? null;
    $_SERVER['HTTP_HOST'] = '127.0.0.1:' . $port;
    $originalEnv = pem_catalog_set_env('PIWIGO_ALT_PLUGINS_PEM_URL', 'http://127.0.0.1:' . $port);

    try {
        $catalog = new PemCatalog(new ZipExtractor(), pem_catalog_ready_logger(), Paths::fromRoot($root), new CurrentConfig());

        $result = $catalog->extractArchive(ExtensionType::Plugin, 'install', 'no-such-revision', 'whatever');

        expect($result->status)
            ->toBe('dl_archive_error')
            ->and($result->id)
            ->toBeNull();
    } finally {
        pem_catalog_stop_local_server($proc);
        if ($originalHost === null) {
            unset($_SERVER['HTTP_HOST']);
        } else {
            $_SERVER['HTTP_HOST'] = $originalHost;
        }
        pem_catalog_restore_env('PIWIGO_ALT_PLUGINS_PEM_URL', $originalEnv);
    }
});
