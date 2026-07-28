<?php

declare(strict_types=1);

use Piwigo\Config\CurrentConfig;
use Piwigo\Site\LocalSiteReader;

/**
 * LocalSiteReader is otherwise exercised end-to-end by
 * tests/Browser/SiteUpdateSubControllerTest.php (a single flat directory
 * with one picture-extension element, `enable_formats` off) -- that
 * leaves the following genuinely uncovered here: the '.'/'..' skip inside
 * get_elements()'s readdir loop, the representative-extension lookup for
 * a *non*-picture file_ext element (get_representative_ext() itself,
 * found and not-found), the `enable_formats` branch (get_formats(),
 * found and skipped-because-absent), and the matching branch inside
 * get_element_update_attributes(). get_metadata_attributes() and
 * get_element_metadata() (the two MetadataService-delegating methods)
 * are already fully covered by that same browser flow and are not
 * exercised again here -- this file never touches the lazy-constructed
 * default MetadataService, so no DB access is needed.
 */
function lsrRrmdir(string $dir): void
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
        if (is_dir($path)) {
            lsrRrmdir($path);
        } else {
            unlink($path);
        }
    }

    rmdir($dir);
}

beforeEach(function (): void {
    $this->root = sys_get_temp_dir() . '/piwigo-lsr-test-' . bin2hex(random_bytes(8));
    mkdir($this->root, 0o777, true);
    CurrentConfig::reset();
});

afterEach(function (): void {
    lsrRrmdir(is_string($this->root) ? $this->root : '');
    CurrentConfig::reset();
});

test('open returns true for an existing directory and false for a missing one', function (): void {
    $reader = new LocalSiteReader($this->root);
    expect($reader->open())->toBeTrue();

    $missing = new LocalSiteReader($this->root . '/does-not-exist');
    expect($missing->open())->toBeFalse();
});

test('get_elements skips the . and .. readdir entries', function (): void {
    // An otherwise-empty directory only ever yields '.' and '..' from
    // readdir(), so a non-empty result here (rather than a fatal loop)
    // proves the skip branch ran without needing extra fixture files.
    $reader = new LocalSiteReader($this->root);

    expect($reader->get_elements($this->root))->toBe([]);
});

test('get_elements looks up a representative extension for a non-picture file_ext element and finds one under pwg_representative', function (): void {
    mkdir($this->root . '/pwg_representative');
    file_put_contents($this->root . '/holiday-report.pdf', 'pdf-bytes');
    file_put_contents($this->root . '/pwg_representative/holiday-report.png', 'png-bytes');

    $reader = new LocalSiteReader($this->root);
    $elements = $reader->get_elements($this->root);

    expect($elements)->toBe([
        $this->root . '/holiday-report.pdf' => ['representative_ext' => 'png'],
    ]);
});

test('get_elements looks up a representative extension for a non-picture file_ext element and finds none', function (): void {
    file_put_contents($this->root . '/podcast-episode.mp3', 'mp3-bytes');

    $reader = new LocalSiteReader($this->root);
    $elements = $reader->get_elements($this->root);

    expect($elements)->toBe([
        $this->root . '/podcast-episode.mp3' => ['representative_ext' => null],
    ]);
});

test('get_elements does not look up a representative extension for a picture-extension element', function (): void {
    file_put_contents($this->root . '/family-photo.jpg', 'jpg-bytes');

    $reader = new LocalSiteReader($this->root);
    $elements = $reader->get_elements($this->root);

    expect($elements)->toBe([
        $this->root . '/family-photo.jpg' => ['representative_ext' => null],
    ]);
});

test('get_elements attaches per-format sizes under pwg_format when enable_formats is on', function (): void {
    CurrentConfig::setIsFormatsEnabled(true);

    mkdir($this->root . '/pwg_format');
    file_put_contents($this->root . '/scan.jpg', 'jpg-bytes');
    file_put_contents($this->root . '/pwg_format/scan.cr2', str_repeat('x', 2048));
    file_put_contents($this->root . '/pwg_format/scan.tif', str_repeat('y', 5120));

    $reader = new LocalSiteReader($this->root);
    $elements = $reader->get_elements($this->root);

    expect($elements)->toBe([
        $this->root . '/scan.jpg' => [
            'representative_ext' => null,
            'formats' => ['cr2' => 2.0, 'tif' => 5.0],
        ],
    ]);
});

test('get_elements attaches an empty formats array when enable_formats is on but no pwg_format directory exists', function (): void {
    CurrentConfig::setIsFormatsEnabled(true);
    file_put_contents($this->root . '/scan.jpg', 'jpg-bytes');

    $reader = new LocalSiteReader($this->root);
    $elements = $reader->get_elements($this->root);

    expect($elements)->toBe([
        $this->root . '/scan.jpg' => [
            'representative_ext' => null,
            'formats' => [],
        ],
    ]);
});

test('get_element_update_attributes finds a representative extension for a non-picture element', function (): void {
    mkdir($this->root . '/pwg_representative');
    file_put_contents($this->root . '/pwg_representative/document.gif', 'gif-bytes');

    $reader = new LocalSiteReader($this->root);

    expect($reader->get_element_update_attributes($this->root . '/document.pdf'))->toBe([
        'representative_ext' => 'gif',
    ]);
});

test('get_element_update_attributes returns a null representative extension for a picture element', function (): void {
    $reader = new LocalSiteReader($this->root);

    expect($reader->get_element_update_attributes($this->root . '/photo.png'))->toBe([
        'representative_ext' => null,
    ]);
});

test('get_representative_ext returns the first matching picture extension found under pwg_representative', function (): void {
    mkdir($this->root . '/pwg_representative');
    file_put_contents($this->root . '/pwg_representative/movie.jpg', 'jpg-bytes');

    $reader = new LocalSiteReader($this->root);

    expect($reader->get_representative_ext($this->root, 'movie'))->toBe('jpg');
});

test('get_representative_ext returns null when no representative file exists for any picture extension', function (): void {
    mkdir($this->root . '/pwg_representative');

    $reader = new LocalSiteReader($this->root);

    expect($reader->get_representative_ext($this->root, 'movie'))->toBeNull();
});

test('get_formats returns the on-disk size in kilobytes for each matching format extension present, in formatExtensions order', function (): void {
    mkdir($this->root . '/pwg_format');
    file_put_contents($this->root . '/pwg_format/negative.tif', str_repeat('a', 4096));
    file_put_contents($this->root . '/pwg_format/negative.psd', str_repeat('b', 1024));

    $reader = new LocalSiteReader($this->root);

    expect($reader->get_formats($this->root, 'negative'))->toBe([
        'tif' => 4.0,
        'psd' => 1.0,
    ]);
});

test('get_formats returns an empty array when the pwg_format directory does not exist', function (): void {
    $reader = new LocalSiteReader($this->root);

    expect($reader->get_formats($this->root, 'negative'))->toBe([]);
});
