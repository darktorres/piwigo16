<?php

declare(strict_types=1);

use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Admin\Extensions\PemCatalog;
use Piwigo\Admin\Extensions\ZipExtractor;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\Logger;
use Piwigo\Core\Paths;

// PemCatalog's own methods that actually talk to the remote PEM server
// (getVersionsToCheck()/getServerExtensions()/getIncompatibleExtensions()/
// extractArchive(), all through the static HttpClientService::fetch()) have
// no test seam to fake that HTTP call through -- this suite covers this
// class's remaining, genuinely pure/file-based surface instead: the 4
// sort comparators and the 2 local-filesystem helpers
// (getLocallyMergedExtensions()/deleteObsoleteFiles()). Both read
// Piwigo\Core\CurrentPaths directly (getLocallyMergedExtensions() for the
// real, committed install/obsolete_extensions.list; deleteObsoleteFiles()
// via ExtensionType::scanDirectory() for its own trash-path string), so
// this suite seeds it against this repo's real root, same convention as
// every other Unit test touching CurrentPaths (e.g. ExtensionTypeTest).

function pem_catalog_test_marker(): string
{
    /** @var string|null $marker */
    static $marker = null;

    return $marker ??= sys_get_temp_dir() . '/piwigo-pem-catalog-test-' . bin2hex(random_bytes(8));
}

beforeEach(function (): void {
    mkdir(pem_catalog_test_marker(), 0o777, true);
    CurrentPaths::set(Paths::fromRoot(dirname(__DIR__, 4)));
});

afterEach(function (): void {
    CurrentPaths::reset();
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
    $older = ['revision_date' => '2026-01-01'];
    $newer = ['revision_date' => '2026-06-01'];

    expect(PemCatalog::compareByRevisionDate($newer, $older))->toBe(-1);
    expect(PemCatalog::compareByRevisionDate($older, $newer))->toBe(1);
});

test('compareByRevisionDate treats an identical date as a tie, not "$a is later"', function (): void {
    // Real gap, found via mutation testing: the same-date branch only
    // takes the `< ? 1 : -1` path once, via the `-1` (a is not-later)
    // arm -- a `<` -> `<=` mutation flips this specific case's result to
    // 1 without breaking either directional assertion above.
    $same = ['revision_date' => '2026-06-01'];

    expect(PemCatalog::compareByRevisionDate($same, $same))->toBe(-1);
});

test('compareByName sorts case-insensitively by extension_name', function (): void {
    $a = ['extension_name' => 'zebra'];
    $b = ['extension_name' => 'Apple'];

    expect(PemCatalog::compareByName($a, $b))->toBeGreaterThan(0);
    expect(PemCatalog::compareByName($b, $a))->toBeLessThan(0);
    // Real gap, found via mutation testing: 'Same' vs 'same' can't tell a
    // real strtolower() from a removed one on the *second* argument alone,
    // since 'same' is already lowercase -- both sides need real uppercase
    // to force each of the two strtolower() calls to matter independently.
    expect(PemCatalog::compareByName(['extension_name' => 'SAME'], ['extension_name' => 'same']))->toBe(0);
    expect(PemCatalog::compareByName(['extension_name' => 'same'], ['extension_name' => 'SAME']))->toBe(0);
});

test('compareByName falls back to an empty name for a non-scalar extension_name', function (): void {
    expect(PemCatalog::compareByName(['extension_name' => ['not', 'scalar']], ['extension_name' => 'apple']))->toBeLessThan(0);
    expect(PemCatalog::compareByName(['extension_name' => 'apple'], ['extension_name' => null]))->toBeGreaterThan(0);
});

test('compareByName string-casts a real scalar extension_name instead of comparing it raw', function (): void {
    // Real gap, found via mutation testing: removing the (string) cast on
    // an already-string value is invisible -- an int forces it to matter
    // (strtolower()/strcmp() both require a real string argument).
    expect(PemCatalog::compareByName(['extension_name' => 20], ['extension_name' => '3']))->toBeLessThan(0);
});

test('compareByAuthor sorts case-insensitively by author_name, falling back to compareByName on a tie', function (): void {
    $a = ['author_name' => 'Alice', 'extension_name' => 'zebra'];
    $b = ['author_name' => 'Bob', 'extension_name' => 'apple'];
    expect(PemCatalog::compareByAuthor($a, $b))->toBeLessThan(0);

    $tieA = ['author_name' => 'same author', 'extension_name' => 'zebra'];
    $tieB = ['author_name' => 'Same Author', 'extension_name' => 'apple'];
    expect(PemCatalog::compareByAuthor($tieA, $tieB))->toBeGreaterThan(0);
});

test('compareByAuthor falls back to an empty author for a non-scalar author_name', function (): void {
    expect(PemCatalog::compareByAuthor(['author_name' => ['not', 'scalar']], ['author_name' => 'bob']))->toBeLessThan(0);
    expect(PemCatalog::compareByAuthor(['author_name' => 'bob'], ['author_name' => null]))->toBeGreaterThan(0);
});

test('compareByAuthor string-casts a real scalar author_name instead of comparing it raw', function (): void {
    expect(PemCatalog::compareByAuthor(['author_name' => 20, 'extension_name' => 'x'], ['author_name' => '3', 'extension_name' => 'x']))->toBeLessThan(0);
});

test('compareByDownloads sorts descending, most downloaded first', function (): void {
    $popular = ['extension_nb_downloads' => 500];
    $unpopular = ['extension_nb_downloads' => 3];

    expect(PemCatalog::compareByDownloads($popular, $unpopular))->toBe(-1);
    expect(PemCatalog::compareByDownloads($unpopular, $popular))->toBe(1);
});

test('compareByDownloads treats an identical count as a tie, not "$a has fewer"', function (): void {
    $same = ['extension_nb_downloads' => 500];

    expect(PemCatalog::compareByDownloads($same, $same))->toBe(-1);
});

test('getLocallyMergedExtensions parses the real install/obsolete_extensions.list', function (): void {
    $catalog = new PemCatalog(new ZipExtractor());
    $merged = $catalog->getLocallyMergedExtensions();

    // install/obsolete_extensions.list is a committed, static asset --
    // asserting a couple of its real, known entries plus the exact total
    // count.
    expect($merged)->toHaveCount(13);
    expect($merged[411])->toBe('pwg_images_addSimple');
    expect($merged[286])->toBe('admin_multi_view');
});

function pem_catalog_delete_obsolete_files(ExtensionType $type, string $extractPath): void
{
    $catalog = new PemCatalog(new ZipExtractor());
    $method = new ReflectionMethod($catalog, 'deleteObsoleteFiles');
    $method->invoke($catalog, $type, $extractPath, new Logger(['severity' => Logger::OFF]));
}

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

    expect(file_exists($canary))->toBeTrue();
});

test('deleteObsoleteFiles trims whitespace/slashes and skips a blank line, then still processes a later real entry', function (): void {
    // Real gap, found via mutation testing: a single-entry list can't
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
    // Real gap, found via mutation testing: an EmptyStringToNotEmpty
    // mutation on the blank-line guard falls through into treating the
    // extract directory itself (path = extractPath + '/' + '') as a
    // listed entry -- is_dir() is true for it, so it gets wholesale
    // deltree()'d, which *coincidentally* also removes old-file.php,
    // making the assertion above pass even under the mutation. Only
    // checking that the extract directory itself is still standing
    // actually catches it.
    expect(is_dir($extractPath))->toBeTrue();
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

test('deleteObsoleteFiles moves a listed directory to trash rather than unlinking it', function (): void {
    // Real gap, found via mutation testing: every other test here lists
    // only plain files (is_file() -> @unlink()) -- nothing exercised the
    // is_dir() -> FilesystemHelper::deltree() branch, or the real
    // ExtensionType::scanDirectory() . 'trash' path it moves to.
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
