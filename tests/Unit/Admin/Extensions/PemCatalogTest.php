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

test('compareByName sorts case-insensitively by extension_name', function (): void {
    $a = ['extension_name' => 'zebra'];
    $b = ['extension_name' => 'Apple'];

    expect(PemCatalog::compareByName($a, $b))->toBeGreaterThan(0);
    expect(PemCatalog::compareByName($b, $a))->toBeLessThan(0);
    expect(PemCatalog::compareByName(['extension_name' => 'Same'], ['extension_name' => 'same']))->toBe(0);
});

test('compareByAuthor sorts case-insensitively by author_name, falling back to compareByName on a tie', function (): void {
    $a = ['author_name' => 'Alice', 'extension_name' => 'zebra'];
    $b = ['author_name' => 'Bob', 'extension_name' => 'apple'];
    expect(PemCatalog::compareByAuthor($a, $b))->toBeLessThan(0);

    $tieA = ['author_name' => 'same author', 'extension_name' => 'zebra'];
    $tieB = ['author_name' => 'Same Author', 'extension_name' => 'apple'];
    expect(PemCatalog::compareByAuthor($tieA, $tieB))->toBeGreaterThan(0);
});

test('compareByDownloads sorts descending, most downloaded first', function (): void {
    $popular = ['extension_nb_downloads' => 500];
    $unpopular = ['extension_nb_downloads' => 3];

    expect(PemCatalog::compareByDownloads($popular, $unpopular))->toBe(-1);
    expect(PemCatalog::compareByDownloads($unpopular, $popular))->toBe(1);
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

test('deleteObsoleteFiles does nothing when there is no obsolete.list at all', function (): void {
    $extractPath = pem_catalog_test_marker() . '/plugin3';
    mkdir($extractPath, 0o777, true);
    file_put_contents($extractPath . '/keep-me.php', 'still here');

    pem_catalog_delete_obsolete_files(ExtensionType::Plugin, $extractPath);

    expect(file_exists($extractPath . '/keep-me.php'))->toBeTrue();
});
