<?php

declare(strict_types=1);

use Piwigo\Admin\Extensions\ZipExtractor;
use Piwigo\Config\CurrentConfig;

// Marker-based filesystem safety: this suite writes and extracts real zip
// archives, so every path must be scoped to a unique temp subdirectory it
// creates and tears down itself -- never touching PHPWG_ROOT_PATH (see
// UploadServiceTest's own docblock for the incident this pattern was built
// to prevent).
function zip_extractor_test_marker(): string
{
    /** @var string|null $marker */
    static $marker = null;

    return $marker ??= sys_get_temp_dir() . '/piwigo-zip-extractor-test-' . bin2hex(random_bytes(8));
}

/**
 * @param array<string, string> $entries stored-name => contents
 */
function zip_extractor_build_archive(string $path, array $entries): void
{
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE);
    foreach ($entries as $name => $contents) {
        $zip->addFromString($name, $contents);
    }
    $zip->close();
}

function zip_extractor_rrmdir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    $entries = scandir($dir);
    foreach ($entries !== false ? $entries : [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        is_dir($path) ? zip_extractor_rrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

beforeEach(function (): void {
    mkdir(zip_extractor_test_marker(), 0o777, true);
});

afterEach(function (): void {
    zip_extractor_rrmdir(zip_extractor_test_marker());
});

test('listFilenames returns every stored entry name', function (): void {
    $archive = zip_extractor_test_marker() . '/a.zip';
    zip_extractor_build_archive($archive, [
        'plugin_id/main.inc.php' => '<?php',
        'plugin_id/lib/helper.php' => '<?php',
    ]);

    $names = new ZipExtractor()->listFilenames($archive);

    expect($names)->toBe(['plugin_id/main.inc.php', 'plugin_id/lib/helper.php']);
});

test('listFilenames returns null for a non-archive file', function (): void {
    $notAZip = zip_extractor_test_marker() . '/not-a-zip.zip';
    file_put_contents($notAZip, 'not a zip');

    expect(new ZipExtractor()->listFilenames($notAZip))->toBeNull();
});

test('extract writes files under destPath with the prefix stripped', function (): void {
    $archive = zip_extractor_test_marker() . '/a.zip';
    zip_extractor_build_archive($archive, [
        'plugin_id/main.inc.php' => '<?php // main',
        'plugin_id/lib/helper.php' => '<?php // helper',
    ]);
    $dest = zip_extractor_test_marker() . '/extracted';

    $result = new ZipExtractor()->extract($archive, $dest, 'plugin_id', new CurrentConfig());

    expect($result)->not->toBeNull();
    expect(file_get_contents($dest . '/main.inc.php'))->toBe('<?php // main');
    expect(file_get_contents($dest . '/lib/helper.php'))->toBe('<?php // helper');
});

test('extract rejects a zip-slip entry that would escape destPath', function (): void {
    $archive = zip_extractor_test_marker() . '/evil.zip';
    $marker = zip_extractor_test_marker();
    zip_extractor_build_archive($archive, [
        'plugin_id/main.inc.php' => '<?php',
        '../../../../../../../../tmp/zip-extractor-escape-marker.php' => '<?php // escaped',
    ]);
    $dest = $marker . '/extracted';
    $escapePath = '/tmp/zip-extractor-escape-marker.php';
    if (file_exists($escapePath)) {
        unlink($escapePath);
    }

    $result = new ZipExtractor()->extract($archive, $dest, 'plugin_id', new CurrentConfig());

    expect($result)->toBeNull();
    expect(file_exists($escapePath))->toBeFalse();
    if (file_exists($escapePath)) {
        unlink($escapePath);
    }
});

test('extract rejects an entry with an absolute path', function (): void {
    $archive = zip_extractor_test_marker() . '/evil2.zip';
    zip_extractor_build_archive($archive, [
        'plugin_id/main.inc.php' => '<?php',
        '/etc/passwd-lookalike' => 'evil',
    ]);
    $dest = zip_extractor_test_marker() . '/extracted';

    $result = new ZipExtractor()->extract($archive, $dest, 'plugin_id', new CurrentConfig());

    expect($result)->toBeNull();
});

test('extract returns null for an archive that does not exist', function (): void {
    $result = new ZipExtractor()->extract(
        zip_extractor_test_marker() . '/does-not-exist.zip',
        zip_extractor_test_marker() . '/extracted',
        'plugin_id',
        new CurrentConfig(),
    );

    expect($result)->toBeNull();
});

test('extract with onlyStoredName extracts just that one entry', function (): void {
    $archive = zip_extractor_test_marker() . '/a.zip';
    zip_extractor_build_archive($archive, [
        'plugin_id/main.inc.php' => '<?php // main',
        'plugin_id/lib/helper.php' => '<?php // helper',
    ]);
    $dest = zip_extractor_test_marker() . '/extracted';

    $result = new ZipExtractor()->extract($archive, $dest, 'plugin_id', new CurrentConfig(), null, 'plugin_id/lib/helper.php');

    expect($result)->not->toBeNull();
    expect(file_exists($dest . '/main.inc.php'))->toBeFalse();
    expect(file_get_contents($dest . '/lib/helper.php'))->toBe('<?php // helper');
});

test('extract with a bare "." removePrefix does not strip anything', function (): void {
    $archive = zip_extractor_test_marker() . '/a.zip';
    zip_extractor_build_archive($archive, [
        'main.inc.php' => '<?php // main',
    ]);
    $dest = zip_extractor_test_marker() . '/extracted';

    $result = new ZipExtractor()->extract($archive, $dest, '.', new CurrentConfig());

    expect($result)->not->toBeNull();
    expect(file_get_contents($dest . '/main.inc.php'))->toBe('<?php // main');
});

test('extract marks the directory entry that exactly matches removePrefix as filtered instead of creating it', function (): void {
    $archive = zip_extractor_test_marker() . '/a.zip';
    zip_extractor_build_archive($archive, [
        // The archive's own root marker directory entry -- stored name
        // equals removePrefix ('plugin_id/') exactly once the trailing
        // slash is appended, so it must be filtered rather than mkdir'd
        // into the destination tree as a real 'plugin_id' subfolder.
        'plugin_id/' => '',
        'plugin_id/main.inc.php' => '<?php // main',
    ]);
    $dest = zip_extractor_test_marker() . '/extracted';

    $result = new ZipExtractor()->extract($archive, $dest, 'plugin_id', new CurrentConfig());

    expect($result)->toBe([
        [
            'filename' => 'plugin_id/',
            'stored_filename' => 'plugin_id/',
            'status' => 'filtered',
        ],
        [
            'filename' => $dest . '/main.inc.php',
            'stored_filename' => 'plugin_id/main.inc.php',
            'status' => 'ok',
        ],
    ]);
    expect(is_dir($dest . '/plugin_id'))->toBeFalse();
    expect(file_get_contents($dest . '/main.inc.php'))->toBe('<?php // main');
});

test('extract recursively creates a nested directory entry and lists it with ok status', function (): void {
    $archive = zip_extractor_test_marker() . '/a.zip';
    zip_extractor_build_archive($archive, [
        'plugin_id/assets/img/' => '',
        'plugin_id/assets/img/logo.png' => 'PNGDATA',
    ]);
    $dest = zip_extractor_test_marker() . '/extracted';

    $result = new ZipExtractor()->extract($archive, $dest, 'plugin_id', new CurrentConfig());

    expect($result)->toBe([
        [
            'filename' => $dest . '/assets/img/',
            'stored_filename' => 'plugin_id/assets/img/',
            'status' => 'ok',
        ],
        [
            'filename' => $dest . '/assets/img/logo.png',
            'stored_filename' => 'plugin_id/assets/img/logo.png',
            'status' => 'ok',
        ],
    ]);
    expect(is_dir($dest . '/assets/img'))->toBeTrue();
    expect(file_get_contents($dest . '/assets/img/logo.png'))->toBe('PNGDATA');
});

test('extract marks a file entry as already_a_directory when its target path was already created as a directory', function (): void {
    $archive = zip_extractor_test_marker() . '/a.zip';
    zip_extractor_build_archive($archive, [
        // A directory entry ('foo/') followed by a file entry whose
        // stripped name ('foo') resolves to the exact same destination
        // path -- the file must not clobber the directory.
        'plugin_id/foo/' => '',
        'plugin_id/foo' => 'should not be written',
    ]);
    $dest = zip_extractor_test_marker() . '/extracted';

    $result = new ZipExtractor()->extract($archive, $dest, 'plugin_id', new CurrentConfig());

    expect($result)->toBe([
        [
            'filename' => $dest . '/foo/',
            'stored_filename' => 'plugin_id/foo/',
            'status' => 'ok',
        ],
        [
            'filename' => $dest . '/foo',
            'stored_filename' => 'plugin_id/foo',
            'status' => 'already_a_directory',
        ],
    ]);
    expect(is_dir($dest . '/foo'))->toBeTrue();
    expect(is_file($dest . '/foo'))->toBeFalse();
});

test('extract overwrites an existing destination file with the archive contents', function (): void {
    $archive = zip_extractor_test_marker() . '/a.zip';
    zip_extractor_build_archive($archive, [
        'plugin_id/main.inc.php' => '<?php // new',
    ]);
    $dest = zip_extractor_test_marker() . '/extracted';
    mkdir($dest, 0o777, true);
    file_put_contents($dest . '/main.inc.php', '<?php // old');

    $result = new ZipExtractor()->extract($archive, $dest, 'plugin_id', new CurrentConfig());

    expect($result)->toBe([
        [
            'filename' => $dest . '/main.inc.php',
            'stored_filename' => 'plugin_id/main.inc.php',
            'status' => 'ok',
        ],
    ]);
    expect(file_get_contents($dest . '/main.inc.php'))->toBe('<?php // new');
});

test('extract records a write_error result and leaves the file unwritten when the destination directory is not writable', function (): void {
    $archive = zip_extractor_test_marker() . '/a.zip';
    zip_extractor_build_archive($archive, [
        'plugin_id/main.inc.php' => '<?php // main',
    ]);
    $dest = zip_extractor_test_marker() . '/extracted';
    mkdir($dest, 0o777, true);
    chmod($dest, 0o555);

    // fopen()'s own permission-denied failure raises a real E_WARNING --
    // this project's phpunit.xml.dist (failOnWarning) would otherwise
    // convert that into a test failure. Suppressed here deliberately, same
    // technique as tests/Integration/BackupServiceTest.php's own
    // set_error_handler() use, so extract() reaches its own write_error
    // status instead of a PHPUnit\Framework\Error\Warning.
    set_error_handler(static fn (): bool => true, E_WARNING);
    try {
        $result = new ZipExtractor()->extract($archive, $dest, 'plugin_id', new CurrentConfig());
    } finally {
        restore_error_handler();
    }

    expect($result)->toBe([
        [
            'filename' => $dest . '/main.inc.php',
            'stored_filename' => 'plugin_id/main.inc.php',
            'status' => 'write_error',
        ],
    ]);
    expect(file_exists($dest . '/main.inc.php'))->toBeFalse();
});

test('extract applies the given chmod mode to each extracted file', function (): void {
    $archive = zip_extractor_test_marker() . '/a.zip';
    zip_extractor_build_archive($archive, [
        'plugin_id/main.inc.php' => '<?php // main',
    ]);
    $dest = zip_extractor_test_marker() . '/extracted';

    $result = new ZipExtractor()->extract($archive, $dest, 'plugin_id', new CurrentConfig(), 0o640);

    expect($result)->not->toBeNull();
    expect(fileperms($dest . '/main.inc.php') & 0o777)->toBe(0o640);
});

test('extract returns null for a corrupt (non-zip) archive file', function (): void {
    $corrupt = zip_extractor_test_marker() . '/corrupt.zip';
    file_put_contents($corrupt, 'this is not a zip file');
    $dest = zip_extractor_test_marker() . '/extracted';

    $result = new ZipExtractor()->extract($corrupt, $dest, 'plugin_id', new CurrentConfig());

    expect($result)->toBeNull();
});

test('extract returns null when the archive has more than MAX_ENTRIES entries', function (): void {
    $archive = zip_extractor_test_marker() . '/entry-bomb.zip';
    $zip = new ZipArchive();
    $zip->open($archive, ZipArchive::CREATE);
    // MAX_ENTRIES is 20000 (private const, confirmed by direct read) --
    // 20001 empty entries is the smallest archive that trips it, and
    // addFromString('') keeps this fast (well under a second, confirmed
    // empirically) despite the entry count.
    for ($i = 0; $i < 20001; $i++) {
        $zip->addFromString('f' . $i . '.txt', '');
    }
    $zip->close();
    $dest = zip_extractor_test_marker() . '/extracted';

    $result = new ZipExtractor()->extract($archive, $dest, '.', new CurrentConfig());

    expect($result)->toBeNull();
    expect(is_dir($dest))->toBeFalse();
});

test('extract accepts an archive with exactly MAX_ENTRIES entries', function (): void {
    // Real gap, found via mutation testing: the sibling "more than
    // MAX_ENTRIES" test above uses 20001 (one past the boundary), which
    // can't tell a `>` from a `>=` -- both reject 20001. Exactly 20000
    // must still be *accepted* to prove the real boundary.
    $archive = zip_extractor_test_marker() . '/entry-boundary.zip';
    $zip = new ZipArchive();
    $zip->open($archive, ZipArchive::CREATE);
    for ($i = 0; $i < 20000; $i++) {
        $zip->addFromString('f' . $i . '.txt', '');
    }
    $zip->close();
    $dest = zip_extractor_test_marker() . '/extracted';

    $result = new ZipExtractor()->extract($archive, $dest, '.', new CurrentConfig());

    expect($result)->not->toBeNull();
    expect(is_dir($dest))->toBeTrue();
});

test('extract returns null when the archive\'s total uncompressed size exceeds MAX_UNCOMPRESSED_BYTES', function (): void {
    $archive = zip_extractor_test_marker() . '/size-bomb.zip';
    $bigFile = zip_extractor_test_marker() . '/big.bin';

    // MAX_UNCOMPRESSED_BYTES is 500 * 1024 * 1024 (private const, confirmed
    // by direct read) -- a genuinely 501MB-on-disk fixture would be slow to
    // write and wasteful to keep around, so this uses a *sparse* file
    // instead: fseek() past the end + a single fwrite() sets the file's
    // logical size (what filesize()/ZipArchive read for the entry's
    // uncompressed size) to 525,000,000 bytes while the filesystem only
    // allocates a few real disk blocks for it (confirmed via `du` showing
    // 4.0K of actual usage against a 501M logical size) -- and the
    // resulting archive compresses those actual zero bytes down to ~500KB
    // in well under a second, since DEFLATE handles a run of zeros
    // trivially.
    $size = 525_000_000;
    $handle = fopen($bigFile, 'wb');
    if ($handle === false) {
        throw new RuntimeException('Could not open ' . $bigFile . ' for writing');
    }
    fseek($handle, $size - 1);
    fwrite($handle, "\0");
    fclose($handle);

    $zip = new ZipArchive();
    $zip->open($archive, ZipArchive::CREATE);
    $zip->addFile($bigFile, 'big.bin');
    $zip->close();
    unlink($bigFile);
    $dest = zip_extractor_test_marker() . '/extracted';

    $result = new ZipExtractor()->extract($archive, $dest, '.', new CurrentConfig());

    expect($result)->toBeNull();
    expect(is_dir($dest))->toBeFalse();
});

test('extract accepts an archive whose total uncompressed size is exactly MAX_UNCOMPRESSED_BYTES', function (): void {
    // Same "off by one from the boundary" reasoning as the MAX_ENTRIES
    // test above -- 525,000,000 can't tell `>` from `>=`. Exactly
    // 500*1024*1024 must still be *accepted*. Same sparse-file trick.
    $archive = zip_extractor_test_marker() . '/size-boundary.zip';
    $bigFile = zip_extractor_test_marker() . '/big-boundary.bin';

    $size = 500 * 1024 * 1024;
    $handle = fopen($bigFile, 'wb');
    if ($handle === false) {
        throw new RuntimeException('Could not open ' . $bigFile . ' for writing');
    }
    fseek($handle, $size - 1);
    fwrite($handle, "\0");
    fclose($handle);

    $zip = new ZipArchive();
    $zip->open($archive, ZipArchive::CREATE);
    $zip->addFile($bigFile, 'big.bin');
    $zip->close();
    unlink($bigFile);
    $dest = zip_extractor_test_marker() . '/extracted';

    $result = new ZipExtractor()->extract($archive, $dest, '.', new CurrentConfig());

    expect($result)->not->toBeNull();
    expect(is_dir($dest))->toBeTrue();
});

test('extract strips a leading "./" from destPath before writing', function (): void {
    $archive = zip_extractor_test_marker() . '/a.zip';
    zip_extractor_build_archive($archive, [
        'plugin_id/main.inc.php' => '<?php // main',
    ]);
    // A relative destPath prefixed with './' -- extract() must strip the
    // prefix before joining entry names onto it (otherwise every written
    // path would carry a literal "./" segment).
    $dest = './' . zip_extractor_test_marker() . '/extracted';

    $result = new ZipExtractor()->extract($archive, $dest, 'plugin_id', new CurrentConfig());

    expect($result)->not->toBeNull();
    expect(file_get_contents(zip_extractor_test_marker() . '/extracted/main.inc.php'))->toBe('<?php // main');
});

// ZipExtractor::listFilenames()/extract() each guard `$zip->statIndex($i)
// === false` for every $i in [0, $zip->numFiles) -- ZipArchive's own
// documented `array|false` return contract for statIndex(). Verified
// empirically (not assumed) that this cannot actually happen once open()
// has succeeded, across every corruption strategy a real attacker or a
// genuinely damaged download could produce: an out-of-range filename-length
// field in one central-directory record (cascades into a full open()
// failure, error 21/ZIP_ER_INCONS, before any entry is ever iterated),
// invalid UTF-8 bytes in a name with the EFS flag set (same -- rejected at
// open(), not per-entry), a truncated/malformed Zip64 extra field (same),
// and even a TOCTOU truncation of the underlying file *after* a successful
// open() (libzip has already parsed and cached the whole central directory
// in memory by then, confirmed via a live truncate-then-statIndex() spike --
// every entry still stat'd correctly). Every real-world path that could
// make one specific entry unstat-able instead makes the whole archive
// unopenable, which the `$zip->open($archive) !== true` guard already
// covers and this suite already tests. Left uncovered rather than
// papered over with a fake ZipArchive subclass: ZipExtractor constructs
// `new ZipArchive()` directly (no injection seam), and subclassing/mocking
// the class under test's own collaborator to force a return value the real
// library never produces would assert nothing about real behavior.
