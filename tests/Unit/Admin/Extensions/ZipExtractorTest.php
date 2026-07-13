<?php

declare(strict_types=1);

use Piwigo\Admin\Extensions\ZipExtractor;

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

    $result = new ZipExtractor()->extract($archive, $dest, 'plugin_id');

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

    $result = new ZipExtractor()->extract($archive, $dest, 'plugin_id');

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

    $result = new ZipExtractor()->extract($archive, $dest, 'plugin_id');

    expect($result)->toBeNull();
});

test('extract returns null for an archive that does not exist', function (): void {
    $result = new ZipExtractor()->extract(
        zip_extractor_test_marker() . '/does-not-exist.zip',
        zip_extractor_test_marker() . '/extracted',
        'plugin_id',
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

    $result = new ZipExtractor()->extract($archive, $dest, 'plugin_id', null, 'plugin_id/lib/helper.php');

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

    $result = new ZipExtractor()->extract($archive, $dest, '.');

    expect($result)->not->toBeNull();
    expect(file_get_contents($dest . '/main.inc.php'))->toBe('<?php // main');
});
