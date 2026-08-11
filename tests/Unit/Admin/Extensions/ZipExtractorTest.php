<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use Piwigo\Admin\Extensions\Projection\ExtractedFileEntry;
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

// [Mutation] Remaining untested mutations after mutation testing, all
// verified genuinely inert via hand-mutation + a full filtered rerun --
// zero new tests needed, triaged into 4 groups:
//
// 1. For-loop bound mutations on `for ($i = 0; $i < $zip->numFiles;
//    $i++)` (SmallerToSmallerOrEqual, DecrementInteger -- listFilenames()
//    Line 46, extract()'s size-accumulation loop Line 92, extract()'s
//    main loop Line 121) plus their sibling `$stat === false` guards
//    (FalseToTrue, Lines 48/94/123): confirmed via a direct probe
//    (`ZipArchive::statIndex()` on an out-of-range index, negative or
//    >= numFiles) that it returns `false` just like any other stat
//    failure -- the existing `if ($stat === false) { continue; }` guard
//    already absorbs one extra out-of-bounds iteration at either end
//    with zero observable difference in the final result.
//
// 2. `$zip->close()` removal (RemoveMethodCall -- Lines 53/86/99/136/
//    158/198, one per early-return/success path): pure resource
//    cleanup, confirmed via a direct probe that an unclosed ZipArchive
//    doesn't hold any OS-level lock preventing the underlying file from
//    being unlinked afterward -- no black-box assertion in this suite
//    (or any suite, short of inspecting open file descriptors) can
//    distinguish a closed archive from an unclosed one. Same reasoning
//    for the 3 `fclose($source)`/`fclose($dest)` removals (Lines
//    182/189/190) -- also confirmed `stream_copy_to_stream()` itself
//    fully flushes without needing the later fclose() (the existing
//    "extract writes files..." test still passes byte-for-byte with
//    Line 190's fclose($dest) removed).
//
// 3. EmptyStringToNotEmpty on `$removePrefix !== ''` (Lines 110/141/147)
//    and `$destPath === ''` (Line 151), each traced through to a
//    DIFFERENT reason it can't manifest observably:
//    - Line 110: mutating this makes `$removePrefix` become '/' instead
//      of staying '' when the caller passes '.'  -- but Line 135
//      already rejects the WHOLE archive for any entry whose stored
//      name starts with '/', so no entry that survives to the
//      prefix-stripping logic below can ever start with '/' either,
//      making the '' vs '/' distinction unreachable downstream.
//    - Line 141: with the real, correct $removePrefix === '', no real
//      archive entry is ever stored as the literal empty string, so
//      `$storedName === $removePrefix` is false regardless of which
//      value the mutated first clause lets through.
//    - Line 147: with $removePrefix === '', `str_starts_with($storedName,
//      '')` is ALWAYS true (every string starts with the empty string)
//      -- so `substr($storedName, 0)` (0-length prefix) is itself a
//      no-op, making it irrelevant whether the branch is entered or not.
//    - Line 151: confirmed via a direct, real extract() call that
//      `$destPath === ''` always makes EVERY entry fail the zip-slip
//      containment check a few lines later (normalizePath('') resolves
//      to the bare root '/', and no real entry's own normalized path
//      can satisfy containment under just '/') -- extract() returns
//      null regardless of whether this specific ternary takes its true
//      or false branch, confirmed both with and without the mutation.
//
// 4. Lines 179/180 (`$source`/`$dest` failure detection,
//    `if ($source === false || $dest === false)`): when $source
//    genuinely fails, the ternary on the line above ALSO sets $dest to
//    false, so mutating either half of this `||` individually still
//    lets the other half catch the same real failure -- confirmed via
//    hand-mutation of each independently.
//
// Line 227's ConcatRemoveLeft (`'/' . implode('/', $resolved)` in
// normalizePath(), dropping the leading '/') is genuinely inert too,
// for a security-relevant reason worth calling out explicitly: this
// function's ONLY real use is comparing two of its own outputs against
// each other (the zip-slip containment check), never against an
// externally-supplied absolute path -- dropping the leading '/'
// uniformly from BOTH sides of that self-consistent comparison changes
// nothing. Confirmed via the full zip-slip test suite still passing
// byte-for-byte with the mutation applied.

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

    expect($result)->toEqual([
        new ExtractedFileEntry('plugin_id/', 'plugin_id/', 'filtered'),
        new ExtractedFileEntry($dest . '/main.inc.php', 'plugin_id/main.inc.php', 'ok'),
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

    expect($result)->toEqual([
        new ExtractedFileEntry($dest . '/assets/img/', 'plugin_id/assets/img/', 'ok'),
        new ExtractedFileEntry($dest . '/assets/img/logo.png', 'plugin_id/assets/img/logo.png', 'ok'),
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

    expect($result)->toEqual([
        new ExtractedFileEntry($dest . '/foo/', 'plugin_id/foo/', 'ok'),
        new ExtractedFileEntry($dest . '/foo', 'plugin_id/foo', 'already_a_directory'),
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

    expect($result)->toEqual([
        new ExtractedFileEntry($dest . '/main.inc.php', 'plugin_id/main.inc.php', 'ok'),
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

    expect($result)->toEqual([
        new ExtractedFileEntry($dest . '/main.inc.php', 'plugin_id/main.inc.php', 'write_error'),
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

test('extract rejects an archive whose total uncompressed size is exactly one byte over MAX_UNCOMPRESSED_BYTES', function (): void {
    // Real gap, found via mutation testing: DecrementInteger on the
    // accumulator's `= 0` initializer (`= -1`) shifts every running total
    // down by exactly one byte, so it takes exactly
    // MAX_UNCOMPRESSED_BYTES + 1 real bytes -- not the "525,000,000 over"
    // test's huge margin, nor the "exactly MAX" boundary test's exact
    // match -- to distinguish a correct `0` starting point from the
    // wrong `-1` one.
    $archive = zip_extractor_test_marker() . '/size-boundary-plus-one.zip';
    $bigFile = zip_extractor_test_marker() . '/big-boundary-plus-one.bin';

    $size = 500 * 1024 * 1024 + 1;
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

test('extract with a bare "." removePrefix does not strip any entry name, even one that looks like a prefix match', function (): void {
    // Real gap, found via mutation testing: EmptyStringToNotEmpty on the
    // `$removePrefix = '';` assignment inside the `=== '.'` branch
    // replaces it with pest-plugin-mutate's own fixed placeholder text
    // ('PEST Mutator was here!', confirmed deterministic across this
    // codebase -- see e.g. SessionServiceTest.php's own docblock). A
    // normal archive (no entry coincidentally named after that
    // placeholder) can't tell the two apart, since "stripping" a prefix
    // that matches nothing is a no-op either way -- so this deliberately
    // uses an entry whose name starts with that exact literal to prove
    // no entry ever gets treated as prefixed once '.' has collapsed to
    // ''.
    $archive = zip_extractor_test_marker() . '/a.zip';
    zip_extractor_build_archive($archive, [
        'PEST Mutator was here!/main.inc.php' => '<?php // main',
    ]);
    $dest = zip_extractor_test_marker() . '/extracted';

    $result = new ZipExtractor()->extract($archive, $dest, '.', new CurrentConfig());

    expect($result)->toEqual([
        new ExtractedFileEntry($dest . '/PEST Mutator was here!/main.inc.php', 'PEST Mutator was here!/main.inc.php', 'ok'),
    ]);
    expect(file_get_contents($dest . '/PEST Mutator was here!/main.inc.php'))->toBe('<?php // main');
});

test('extract does not double a removePrefix that already ends with a trailing slash', function (): void {
    // Real gap, found via mutation testing: BooleanAndToBooleanOr and
    // StrEndsWithToStrStartsWith on `$removePrefix !== '' && !
    // str_ends_with($removePrefix, '/')` both only diverge from the
    // correct code when removePrefix is passed in already ending with
    // '/' -- in every other case they happen to append the same slash
    // the correct code would. With a trailing slash already present, the
    // correct code leaves removePrefix alone; either mutant appends one
    // more, corrupting it to a double slash that no real entry name can
    // ever start with.
    $archive = zip_extractor_test_marker() . '/a.zip';
    zip_extractor_build_archive($archive, [
        'plugin_id/main.inc.php' => '<?php // main',
    ]);
    $dest = zip_extractor_test_marker() . '/extracted';

    $result = new ZipExtractor()->extract($archive, $dest, 'plugin_id/', new CurrentConfig());

    expect($result)->toEqual([
        new ExtractedFileEntry($dest . '/main.inc.php', 'plugin_id/main.inc.php', 'ok'),
    ]);
    expect(file_get_contents($dest . '/main.inc.php'))->toBe('<?php // main');
});

test('extract strips exactly the two-character "./" prefix from destPath, not just the leading dot', function (): void {
    // Real gap, found via mutation testing: DecrementInteger on
    // `substr($destPath, 2)` (-> `substr($destPath, 1)`) leaves a stray
    // leading slash in destPath. Since zip_extractor_test_marker()
    // itself starts with '/', prefixing it with './' produces a raw
    // destPath with a doubled '/' right after the dot -- and on Linux a
    // leading "//" and a leading "/" resolve to the identical real file,
    // so file-content assertions alone can't tell substr(..., 2) from
    // the wrong substr(..., 1) apart. The RAW (never normalized)
    // 'filename' string in the result, by contrast, does differ.
    $archive = zip_extractor_test_marker() . '/a.zip';
    zip_extractor_build_archive($archive, [
        'plugin_id/main.inc.php' => '<?php // main',
    ]);
    $dest = './' . zip_extractor_test_marker() . '/extracted';

    $result = new ZipExtractor()->extract($archive, $dest, 'plugin_id', new CurrentConfig());

    expect($result)->toEqual([
        new ExtractedFileEntry(zip_extractor_test_marker() . '/extracted/main.inc.php', 'plugin_id/main.inc.php', 'ok'),
    ]);
});

test('extract strips a trailing slash from destPath before joining entry names onto it', function (): void {
    // Real gap, found via mutation testing: UnwrapRtrim on `$destPath =
    // rtrim($destPath, '/');` removes the rtrim() entirely. As with the
    // "./" test above, a doubled trailing/joining slash resolves to the
    // identical real file on Linux, so this asserts on the exact
    // 'filename' string rather than just file content.
    $archive = zip_extractor_test_marker() . '/a.zip';
    zip_extractor_build_archive($archive, [
        'plugin_id/main.inc.php' => '<?php // main',
    ]);
    $dest = zip_extractor_test_marker() . '/extracted/';

    $result = new ZipExtractor()->extract($archive, $dest, 'plugin_id', new CurrentConfig());

    expect($result)->toEqual([
        new ExtractedFileEntry(zip_extractor_test_marker() . '/extracted/main.inc.php', 'plugin_id/main.inc.php', 'ok'),
    ]);
});

test('extract does not strip removePrefix from an entry whose stored name does not actually start with it', function (): void {
    // Real gap, found via mutation testing: BooleanAndToBooleanOr on
    // `$removePrefix !== '' && str_starts_with($storedName,
    // $removePrefix)` -- with a non-empty removePrefix, the `||` mutant
    // short-circuits to true from the first operand alone, so EVERY
    // entry gets `substr($storedName, strlen($removePrefix))` applied
    // regardless of whether it actually starts with removePrefix,
    // mangling any entry that doesn't share the prefix.
    $archive = zip_extractor_test_marker() . '/a.zip';
    zip_extractor_build_archive($archive, [
        'plugin_id/main.inc.php' => '<?php // main',
        'unrelated/readme.txt' => 'not part of the plugin',
    ]);
    $dest = zip_extractor_test_marker() . '/extracted';

    $result = new ZipExtractor()->extract($archive, $dest, 'plugin_id', new CurrentConfig());

    expect($result)->toEqual([
        new ExtractedFileEntry($dest . '/main.inc.php', 'plugin_id/main.inc.php', 'ok'),
        new ExtractedFileEntry($dest . '/unrelated/readme.txt', 'unrelated/readme.txt', 'ok'),
    ]);
    expect(file_get_contents($dest . '/main.inc.php'))->toBe('<?php // main');
    expect(file_get_contents($dest . '/unrelated/readme.txt'))->toBe('not part of the plugin');
});

test('extract rejects an entry that resolves to a sibling directory sharing destPath as a string prefix', function (): void {
    // Real gap, found via mutation testing: ConcatRemoveRight on the
    // zip-slip guard's `str_starts_with($normalizedFilename,
    // $normalizedDestPath . '/')` drops the '/' boundary -- without it,
    // an entry resolving to ".../extracted-evil/hack.php" would pass a
    // bare string-prefix check against ".../extracted" (since
    // "extracted-evil" literally starts with "extracted"), even though
    // "extracted-evil" is a completely different, sibling directory, not
    // really inside destPath at all.
    $archive = zip_extractor_test_marker() . '/evil3.zip';
    zip_extractor_build_archive($archive, [
        'plugin_id/../extracted-evil/hack.php' => '<?php // escaped',
    ]);
    $dest = zip_extractor_test_marker() . '/extracted';
    $siblingEscapePath = zip_extractor_test_marker() . '/extracted-evil/hack.php';

    $result = new ZipExtractor()->extract($archive, $dest, 'plugin_id', new CurrentConfig());

    expect($result)->toBeNull();
    expect(file_exists($siblingEscapePath))->toBeFalse();
});

test('extract continues processing later entries after marking one as already_a_directory', function (): void {
    // Real gap, found via mutation testing: ContinueToBreak on the
    // already_a_directory branch's `continue` -- the sibling existing
    // test's already_a_directory entry happens to be LAST in its
    // archive, so continue vs break produce the same result there.
    $archive = zip_extractor_test_marker() . '/a.zip';
    zip_extractor_build_archive($archive, [
        'plugin_id/foo/' => '',
        'plugin_id/foo' => 'should not be written',
        'plugin_id/after.txt' => 'still extracted',
    ]);
    $dest = zip_extractor_test_marker() . '/extracted';

    $result = new ZipExtractor()->extract($archive, $dest, 'plugin_id', new CurrentConfig());

    expect($result)->toEqual([
        new ExtractedFileEntry($dest . '/foo/', 'plugin_id/foo/', 'ok'),
        new ExtractedFileEntry($dest . '/foo', 'plugin_id/foo', 'already_a_directory'),
        new ExtractedFileEntry($dest . '/after.txt', 'plugin_id/after.txt', 'ok'),
    ]);
    expect(file_get_contents($dest . '/after.txt'))->toBe('still extracted');
});

test('extract does not create a destination file when the archive entry itself cannot be read as a stream', function (): void {
    // Real gap, found via mutation testing: two FalseToTrue mutants on
    // `$dest = $source !== false ? @fopen($filename, 'wb') : false;`
    // (`!== true` and the else-arm `: true`) and an IfNegated mutant on
    // `if (is_resource($source)) { fclose($source); }` all require a
    // genuinely-reachable `$source === false` to distinguish -- getStream()
    // returns false for an AES-encrypted entry when no password was set
    // (extract() never calls setPassword()), confirmed live: a real,
    // naturally-occurring case, not a mock.
    $archive = zip_extractor_test_marker() . '/encrypted.zip';
    $zip = new ZipArchive();
    $zip->open($archive, ZipArchive::CREATE);
    $zip->addFromString('plugin_id/secret.txt', 'top secret contents');
    $zip->setEncryptionName('plugin_id/secret.txt', ZipArchive::EM_AES_256, 'correct-password');
    $zip->close();
    $dest = zip_extractor_test_marker() . '/extracted';

    $result = new ZipExtractor()->extract($archive, $dest, 'plugin_id', new CurrentConfig());

    expect($result)->toEqual([
        new ExtractedFileEntry($dest . '/secret.txt', 'plugin_id/secret.txt', 'write_error'),
    ]);
    expect(file_exists($dest . '/secret.txt'))->toBeFalse();
});

test('extract continues processing later entries after a write_error result', function (): void {
    // Real gap, found via mutation testing: ContinueToBreak on the
    // write_error branch's `continue`. Reuses the encrypted-entry
    // technique above to reach write_error without any filesystem
    // permission trickery.
    $archive = zip_extractor_test_marker() . '/mixed.zip';
    $zip = new ZipArchive();
    $zip->open($archive, ZipArchive::CREATE);
    $zip->addFromString('plugin_id/secret.txt', 'top secret contents');
    $zip->setEncryptionName('plugin_id/secret.txt', ZipArchive::EM_AES_256, 'correct-password');
    $zip->addFromString('plugin_id/after.txt', 'still extracted');
    $zip->close();
    $dest = zip_extractor_test_marker() . '/extracted';

    $result = new ZipExtractor()->extract($archive, $dest, 'plugin_id', new CurrentConfig());

    expect($result)->toEqual([
        new ExtractedFileEntry($dest . '/secret.txt', 'plugin_id/secret.txt', 'write_error'),
        new ExtractedFileEntry($dest . '/after.txt', 'plugin_id/after.txt', 'ok'),
    ]);
    expect(file_get_contents($dest . '/after.txt'))->toBe('still extracted');
});

test('extract sets each extracted file\'s mtime to the archive entry\'s stored mtime', function (): void {
    // Real gap, found via mutation testing: RemoveFunctionCall on
    // `touch($filename, $stat['mtime']);`. A fixed, clearly-not-"now"
    // mtime is used (rather than relying on the archive's own
    // just-created timestamp, which could coincidentally be within a
    // second of "now" either way) -- confirmed live that
    // ZipArchive::setMtimeName()/statIndex() round-trip this exact
    // integer with no DOS-time rounding.
    $archive = zip_extractor_test_marker() . '/a.zip';
    $zip = new ZipArchive();
    $zip->open($archive, ZipArchive::CREATE);
    $zip->addFromString('plugin_id/main.inc.php', '<?php // main');
    $fixedMtime = mktime(12, 0, 0, 6, 15, 2001);
    Assert::assertIsInt($fixedMtime);
    $zip->setMtimeName('plugin_id/main.inc.php', $fixedMtime);
    $zip->close();
    $dest = zip_extractor_test_marker() . '/extracted';

    $result = new ZipExtractor()->extract($archive, $dest, 'plugin_id', new CurrentConfig());

    expect($result)->not->toBeNull();
    expect(filemtime($dest . '/main.inc.php'))->toBe($fixedMtime);
});

test('extract rejects a zip-slip entry that escapes destPath through a "./.." segment sequence', function (): void {
    // Real gap, found via mutation testing: normalizePath()'s `$segment
    // === '' || $segment === '.'` guard must skip BOTH kinds of no-op
    // segment so a later '..' pops a REAL ancestor directory, not the
    // no-op placeholder itself -- otherwise "extracted/./../evil.txt"
    // (which really means "escape extracted/ into its parent") gets
    // miscomputed as staying safely inside destPath, because the '..'
    // ends up popping the '.' instead of 'extracted'.
    $archive = zip_extractor_test_marker() . '/evil4.zip';
    zip_extractor_build_archive($archive, [
        'plugin_id/./../evil.txt' => '<?php // escaped',
    ]);
    $dest = zip_extractor_test_marker() . '/extracted';

    $result = new ZipExtractor()->extract($archive, $dest, 'plugin_id', new CurrentConfig());

    expect($result)->toBeNull();
    expect(file_exists(zip_extractor_test_marker() . '/evil.txt'))->toBeFalse();
    expect(file_exists($dest . '/evil.txt'))->toBeFalse();
});

test('extract rejects a zip-slip entry that escapes destPath through a doubled "/" segment sequence', function (): void {
    // Real gap, found via mutation testing: normalizePath()'s `$segment
    // === ''` half of the same guard must ALSO skip the segment produced
    // by a doubled '/' -- otherwise a later '..' pops that empty
    // placeholder instead of a REAL ancestor directory, letting an entry
    // like "plugin_id//../evil.txt" (stripped to "/../evil.txt") wrongly
    // resolve as staying inside destPath.
    $archive = zip_extractor_test_marker() . '/evil5.zip';
    zip_extractor_build_archive($archive, [
        'plugin_id//../evil.txt' => '<?php // escaped',
    ]);
    $dest = zip_extractor_test_marker() . '/extracted';

    $result = new ZipExtractor()->extract($archive, $dest, 'plugin_id', new CurrentConfig());

    expect($result)->toBeNull();
    expect(file_exists(zip_extractor_test_marker() . '/evil.txt'))->toBeFalse();
    expect(file_exists($dest . '/evil.txt'))->toBeFalse();
});

test('extract rejects a zip-slip entry that escapes destPath through a single ".." segment', function (): void {
    // Real gap, found via mutation testing: IfNegated and
    // IdenticalToNotIdentical both invert `if ($segment === '..')`
    // identically -- under either mutant, EVERY non-'..' segment
    // triggers an (empty-array, harmless no-op) pop instead of being
    // pushed, while '..' segments themselves get pushed as literal
    // strings and later popped by the FOLLOWING segment. For a destPath
    // with no '..' of its own, this makes normalizePath() degenerate to
    // a constant '/' for any path whose '..' count is exactly cancelled
    // out by later segments -- exactly what "plugin_id/../evil.txt"
    // produces, silently defeating the zip-slip guard (both sides
    // collapse to the same '/').
    $archive = zip_extractor_test_marker() . '/evil6.zip';
    zip_extractor_build_archive($archive, [
        'plugin_id/../evil.txt' => '<?php // escaped',
    ]);
    $dest = zip_extractor_test_marker() . '/extracted';

    $result = new ZipExtractor()->extract($archive, $dest, 'plugin_id', new CurrentConfig());

    expect($result)->toBeNull();
    expect(file_exists(zip_extractor_test_marker() . '/evil.txt'))->toBeFalse();
});

test('extract accepts an entry whose stored path uses ".." to reference a sibling within destPath', function (): void {
    // Real gap, found via mutation testing: ArrayPopToArrayShift on the
    // '..' handler -- normalizePath() must remove the MOST RECENTLY
    // pushed segment (the nearest enclosing directory), not the FIRST
    // one ever pushed, or an entry like "subdir/../other.txt" (a normal,
    // harmless way of referring to a file next to "subdir", still fully
    // inside destPath) gets its real ancestor segments corrupted and is
    // wrongly rejected as an escape. The 'subdir/' directory entry is
    // listed first so it physically exists before the ".."-containing
    // file entry is resolved, keeping this test independent of any
    // recursive-mkdir-with-a-literal-".."-segment edge case.
    $archive = zip_extractor_test_marker() . '/a.zip';
    zip_extractor_build_archive($archive, [
        'plugin_id/subdir/' => '',
        'plugin_id/subdir/../other.txt' => '<?php // other',
    ]);
    $dest = zip_extractor_test_marker() . '/extracted';

    $result = new ZipExtractor()->extract($archive, $dest, 'plugin_id', new CurrentConfig());

    expect($result)->toEqual([
        new ExtractedFileEntry($dest . '/subdir/', 'plugin_id/subdir/', 'ok'),
        new ExtractedFileEntry($dest . '/subdir/../other.txt', 'plugin_id/subdir/../other.txt', 'ok'),
    ]);
    expect(file_get_contents($dest . '/other.txt'))->toBe('<?php // other');
});

test('extract rejects a zip-slip entry that escapes destPath through consecutive ".." segments after a real one', function (): void {
    // Real gap, found via mutation testing: ContinueToBreak on the '..'
    // handler exits normalizePath()'s whole segment loop after popping
    // for just the FIRST '..' it sees, instead of moving on to evaluate
    // the REST of the path -- so "a/../../evil.txt" (a genuine two-level
    // escape once fully resolved) gets silently truncated right after
    // absorbing the first '..' against the harmless 'a' segment, landing
    // exactly back on destPath itself and passing the containment check
    // before the SECOND '..' (the one that actually escapes) is ever
    // reached.
    $archive = zip_extractor_test_marker() . '/evil7.zip';
    zip_extractor_build_archive($archive, [
        'plugin_id/a/../../evil.txt' => '<?php // escaped',
    ]);
    $dest = zip_extractor_test_marker() . '/extracted';

    $result = new ZipExtractor()->extract($archive, $dest, 'plugin_id', new CurrentConfig());

    expect($result)->toBeNull();
    expect(file_exists(zip_extractor_test_marker() . '/evil.txt'))->toBeFalse();
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
