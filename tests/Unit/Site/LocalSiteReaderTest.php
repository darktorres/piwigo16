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
 *
 * Mutation-sweep notes (pest --mutate --class='Piwigo\Site\LocalSiteReader'):
 * three mutants on get_elements() are true equivalent mutants under any
 * plain-file/plain-directory fixture and were verified as such by live
 * sed-mutate-and-rerun (apply the exact mutant, confirm this whole file
 * still passes, then restore the source) rather than by reasoning alone --
 * none of them are worth chasing with a real test:
 *  - the `(bool)` cast on `($contents = opendir($path))` inside the
 *    `if (is_dir($path) && ...)` guard: PHP coerces the condition to bool
 *    regardless of the explicit cast, so removing it cannot change control
 *    flow.
 *  - `closedir($contents)`: its only effect is releasing the directory
 *    handle: a pure resource-leak with no return value and no effect on
 *    subsequent opendir()/readdir() calls within a single test run. It is
 *    deliberately not chased through directory-count/ulimit exhaustion,
 *    since that would make the test's pass/fail depend on the running
 *    system's open-file-descriptor limit rather than on this class's
 *    behavior.
 *  - `is_dir($path . '/' . $node)` mutated to `is_dir($path . '/')`
 *    (dropping `$node` from the concatenation): this reduces to `is_dir
 *    ($path)`, which is already known true here -- it's checked a few
 *    lines up, at the top of get_elements(), and $path is unchanged for
 *    the rest of the method. So this clause becomes a tautology. The only
 *    entries that could ever expose the drop are directory entries that
 *    are neither a regular file nor a real directory (e.g. a broken
 *    symlink or a FIFO), and even then get_elements()'s own top-of-method
 *    is_dir() guard makes the recursive call return [] either way, so the
 *    returned array is identical regardless. Deliberately not chased with
 *    a broken-symlink/FIFO fixture, since a FIFO with no reader/writer on
 *    the other end risks hanging the test process.
 *
 * One further mutant is a known, *not* equivalent, gap: `filesize()`'s
 * `!== false` check inside get_formats() (mutated to `!== true`, which is
 * always true since filesize() never returns bool(true)). Reaching the
 * difference requires filesize() to genuinely fail immediately after
 * is_file() succeeded on the very same path -- normal stat() failure
 * modes need either a TOCTOU race (delete/replace the file between the
 * two calls) or a permissions trick, both of which trade a real bug in
 * favor of a flaky test. Left untested on purpose; not claimed to be
 * equivalent.
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

test('get_elements does not look up a representative extension for a picture-extension element even when a matching representative file exists', function (): void {
    // flip_picture_ext is what get_elements() consults to decide whether
    // to skip the pwg_representative lookup for a given extension. A
    // representative-extension file that genuinely matches is planted
    // here on purpose: if flip_picture_ext were ever the *unflipped*
    // array (extension values under integer keys, instead of extension
    // keys), `isset($flip_picture_ext['jpg'])` would wrongly read false,
    // the lookup would wrongly run, and it would wrongly find this file
    // -- so a non-null representative_ext here would prove that bug.
    mkdir($this->root . '/pwg_representative');
    file_put_contents($this->root . '/family-photo.jpg', 'jpg-bytes');
    file_put_contents($this->root . '/pwg_representative/family-photo.png', 'png-bytes');

    $reader = new LocalSiteReader($this->root);
    $elements = $reader->get_elements($this->root);

    expect($elements)->toBe([
        $this->root . '/family-photo.jpg' => ['representative_ext' => null],
    ]);
});

test('get_elements lower-cases the file extension before matching it against the configured extension lists', function (): void {
    file_put_contents($this->root . '/vacation.JPG', 'jpg-bytes');

    $reader = new LocalSiteReader($this->root);
    $elements = $reader->get_elements($this->root);

    expect($elements)->toBe([
        $this->root . '/vacation.JPG' => ['representative_ext' => null],
    ]);
});

test('get_elements recurses into ordinary subdirectories -- including names that are a substring or superstring of an excluded name -- while skipping exactly pwg_high, pwg_representative, pwg_format and thumbnail', function (): void {
    // 'keepme' is a plain subdirectory with no special meaning: it must
    // be recursed into. 'thumbnails' (superstring of 'thumbnail') and
    // 'humbnail' (substring of 'thumbnail') must *also* be recursed into
    // -- only an exact name match excludes a directory. The 4 exactly
    // named directories must each be skipped: their content must never
    // appear in the result.
    mkdir($this->root . '/keepme');
    file_put_contents($this->root . '/keepme/inner-a.jpg', 'a-bytes');

    mkdir($this->root . '/thumbnails');
    file_put_contents($this->root . '/thumbnails/inner-b.jpg', 'b-bytes');

    mkdir($this->root . '/humbnail');
    file_put_contents($this->root . '/humbnail/inner-c.jpg', 'c-bytes');

    mkdir($this->root . '/pwg_high');
    file_put_contents($this->root . '/pwg_high/hidden-1.jpg', 'h1-bytes');

    mkdir($this->root . '/pwg_representative');
    file_put_contents($this->root . '/pwg_representative/hidden-2.jpg', 'h2-bytes');

    mkdir($this->root . '/pwg_format');
    file_put_contents($this->root . '/pwg_format/hidden-3.jpg', 'h3-bytes');

    mkdir($this->root . '/thumbnail');
    file_put_contents($this->root . '/thumbnail/hidden-4.jpg', 'h4-bytes');

    $reader = new LocalSiteReader($this->root);
    $elements = $reader->get_elements($this->root);

    expect($elements)->toBe([
        $this->root . '/humbnail/inner-c.jpg' => ['representative_ext' => null],
        $this->root . '/keepme/inner-a.jpg' => ['representative_ext' => null],
        $this->root . '/thumbnails/inner-b.jpg' => ['representative_ext' => null],
    ]);
});

test('get_elements returns keys in sorted order regardless of the on-disk readdir order', function (): void {
    // Filenames deliberately chosen so their natural readdir() order
    // (filesystem/hash-dependent, effectively unordered for a small
    // directory on ext4) is very unlikely to already be alphabetical --
    // the assertion below only passes if ksort() actually ran.
    file_put_contents($this->root . '/zebra.jpg', 'z-bytes');
    file_put_contents($this->root . '/mango.jpg', 'm-bytes');
    file_put_contents($this->root . '/apple.jpg', 'a-bytes');

    $reader = new LocalSiteReader($this->root);
    $elements = $reader->get_elements($this->root);

    expect(array_keys($elements))->toBe([
        $this->root . '/apple.jpg',
        $this->root . '/mango.jpg',
        $this->root . '/zebra.jpg',
    ]);
});

test('get_formats floors a non-multiple-of-1024 file size to kilobytes', function (): void {
    // 2047 bytes / 1024 = 1.9990234375: floor() -> 1.0, while round()
    // and ceil() both -> 2.0, and dividing by 1023 instead of 1024
    // (an off-by-one on the divisor) also -> floor(2047/1023) = 2.0.
    // This one size therefore distinguishes floor() from all three.
    mkdir($this->root . '/pwg_format');
    file_put_contents($this->root . '/pwg_format/negative.tif', str_repeat('a', 2047));

    $reader = new LocalSiteReader($this->root);

    expect($reader->get_formats($this->root, 'negative'))->toBe([
        'tif' => 1.0,
    ]);
});
