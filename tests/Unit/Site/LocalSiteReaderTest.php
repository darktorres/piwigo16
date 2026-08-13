<?php

declare(strict_types=1);

use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\FilterState;
use Piwigo\Core\InstallationFlag;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Lang\Translator;
use Piwigo\Metadata\MetadataRepository;
use Piwigo\Metadata\MetadataService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionService;
use Piwigo\Site\LocalSiteReader;
use Piwigo\Site\Projection\ElementUpdateAttributes;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Users\CurrentUser;

/**
 * LocalSiteReader is otherwise exercised end-to-end by
 * tests/Browser/SiteUpdateSubControllerTest.php (a single flat directory
 * with one picture-extension element, `enable_formats` off) -- that
 * leaves the following genuinely uncovered here: the '.'/'..' skip inside
 * getElements()'s readdir loop, the representative-extension lookup for
 * a *non*-picture file_ext element (getRepresentativeExt() itself,
 * found and not-found), the `enable_formats` branch (getFormats(),
 * found and skipped-because-absent), and the matching branch inside
 * getElementUpdateAttributes(). getMetadataAttributes() and
 * getElementMetadata() (the two MetadataService-delegating methods)
 * are already fully covered by that same browser flow and are not
 * exercised again here -- this file never touches the bare, throwaway
 * MetadataService it constructs to satisfy LocalSiteReader's required
 * constructor collaborator, so no DB access is needed.
 *
 * Mutation-sweep notes (pest --mutate --class='Piwigo\Site\LocalSiteReader'):
 * three mutants on getElements() are true equivalent mutants under any
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
 *    lines up, at the top of getElements(), and $path is unchanged for
 *    the rest of the method. So this clause becomes a tautology. The only
 *    entries that could ever expose the drop are directory entries that
 *    are neither a regular file nor a real directory (e.g. a broken
 *    symlink or a FIFO), and even then getElements()'s own top-of-method
 *    is_dir() guard makes the recursive call return [] either way, so the
 *    returned array is identical regardless. Deliberately not chased with
 *    a broken-symlink/FIFO fixture, since a FIFO with no reader/writer on
 *    the other end risks hanging the test process.
 *
 * One further mutant is a known, *not* equivalent, gap: `filesize()`'s
 * `!== false` check inside getFormats() (mutated to `!== true`, which is
 * always true since filesize() never returns bool(true)). Reaching the
 * difference requires filesize() to genuinely fail immediately after
 * is_file() succeeded on the very same path -- normal stat() failure
 * modes need either a TOCTOU race (delete/replace the file between the
 * two calls) or a permissions trick, both of which trade a real bug in
 * favor of a flaky test. Left untested on purpose; not claimed to be
 * equivalent.
 */
// PHPStan can't see across the beforeEach()/test() closure boundary that
// $this->currentConfig is always a real CurrentConfig by the time a test
// body runs (Pest always runs beforeEach() first) -- same "narrow for
// real rather than widen" reasoning as ExtensionUpdateCheckerTest.php's
// own requireFixtureRoot() helper. Only needed at the 2 call sites below
// that call a method on it directly (method.nonObject); the constructor
// itself accepts $this->currentConfig unnarrowed like every other
// site in this file already passes $this->root unnarrowed.
function requireCurrentConfig(mixed $currentConfig): CurrentConfig
{
    if (! $currentConfig instanceof CurrentConfig) {
        throw new RuntimeException('currentConfig not initialized -- beforeEach() must run first');
    }

    return $currentConfig;
}

/**
 * LocalSiteReader now takes MetadataService as a required constructor
 * collaborator -- this file never touches it (see this file's own
 * docblock above), so a bare, DB-free, Kernel-free instance is enough.
 */
function lsrTestMetadataService(): MetadataService
{
    $currentConfig = new CurrentConfig();

    return new MetadataService(
        new Lang(new Translator($currentConfig), HtmlServiceTestFactory::build(), Paths::fromRoot(sys_get_temp_dir()), new InstallationFlag()),
        new MetadataRepository(EntityManagerFactory::build(DbConnection::build())),
        new CurrentLogger(),
        new EventDispatcher(),
        $currentConfig,
        new CurrentUser($currentConfig),
        new SessionService(EntityManagerFactory::build(DbConnection::build())->getRepository(SessionEntity::class), $currentConfig),
        new FilterState(),
        Paths::fromRoot(sys_get_temp_dir()),
    );
}

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
    CurrentConfigTestFactory::get()->reset();
    // LocalSiteReader takes CurrentConfig as a real constructor
    // collaborator instead of reading it statically -- a fresh, per-test
    // instance, since this file never boots the Kernel and every test
    // constructs its own LocalSiteReader directly from it (not through
    // CurrentConfigTestFactory::get()'s pre-boot fallback).
    $this->currentConfig = new CurrentConfig();
});

afterEach(function (): void {
    lsrRrmdir($this->root);
    CurrentConfigTestFactory::get()->reset();
});

test('open returns true for an existing directory and false for a missing one', function (): void {
    $reader = new LocalSiteReader($this->root, $this->currentConfig, lsrTestMetadataService());
    expect($reader->open())
        ->toBeTrue();

    $missing = new LocalSiteReader($this->root . '/does-not-exist', $this->currentConfig, lsrTestMetadataService());
    expect($missing->open())
        ->toBeFalse();
});

test('getElements skips the . and .. readdir entries', function (): void {
    // An otherwise-empty directory only ever yields '.' and '..' from
    // readdir(), so a non-empty result here (rather than a fatal loop)
    // proves the skip branch ran without needing extra fixture files.
    $reader = new LocalSiteReader($this->root, $this->currentConfig, lsrTestMetadataService());

    expect($reader->getElements($this->root))
        ->toBe([]);
});

test('getElements looks up a representative extension for a non-picture file_ext element and finds one under pwg_representative', function (): void {
    mkdir($this->root . '/pwg_representative');
    file_put_contents($this->root . '/holiday-report.pdf', 'pdf-bytes');
    file_put_contents($this->root . '/pwg_representative/holiday-report.png', 'png-bytes');

    $reader = new LocalSiteReader($this->root, $this->currentConfig, lsrTestMetadataService());
    $elements = $reader->getElements($this->root);

    expect($elements)
        ->toBe([
            $this->root . '/holiday-report.pdf' => [
                'representative_ext' => 'png',
            ],
        ]);
});

test('getElements looks up a representative extension for a non-picture file_ext element and finds none', function (): void {
    file_put_contents($this->root . '/podcast-episode.mp3', 'mp3-bytes');

    $reader = new LocalSiteReader($this->root, $this->currentConfig, lsrTestMetadataService());
    $elements = $reader->getElements($this->root);

    expect($elements)
        ->toBe([
            $this->root . '/podcast-episode.mp3' => [
                'representative_ext' => null,
            ],
        ]);
});

test('getElements does not look up a representative extension for a picture-extension element', function (): void {
    file_put_contents($this->root . '/family-photo.jpg', 'jpg-bytes');

    $reader = new LocalSiteReader($this->root, $this->currentConfig, lsrTestMetadataService());
    $elements = $reader->getElements($this->root);

    expect($elements)
        ->toBe([
            $this->root . '/family-photo.jpg' => [
                'representative_ext' => null,
            ],
        ]);
});

test('getElements attaches per-format sizes under pwg_format when enable_formats is on', function (): void {
    requireCurrentConfig($this->currentConfig)->isFormatsEnabled = true;

    mkdir($this->root . '/pwg_format');
    file_put_contents($this->root . '/scan.jpg', 'jpg-bytes');
    file_put_contents($this->root . '/pwg_format/scan.cr2', str_repeat('x', 2048));
    file_put_contents($this->root . '/pwg_format/scan.tif', str_repeat('y', 5120));

    $reader = new LocalSiteReader($this->root, $this->currentConfig, lsrTestMetadataService());
    $elements = $reader->getElements($this->root);

    expect($elements)
        ->toBe([
            $this->root . '/scan.jpg' => [
                'representative_ext' => null,
                'formats' => [
                    'cr2' => 2.0,
                    'tif' => 5.0,
                ],
            ],
        ]);
});

test('getElements attaches an empty formats array when enable_formats is on but no pwg_format directory exists', function (): void {
    requireCurrentConfig($this->currentConfig)->isFormatsEnabled = true;
    file_put_contents($this->root . '/scan.jpg', 'jpg-bytes');

    $reader = new LocalSiteReader($this->root, $this->currentConfig, lsrTestMetadataService());
    $elements = $reader->getElements($this->root);

    expect($elements)
        ->toBe([
            $this->root . '/scan.jpg' => [
                'representative_ext' => null,
                'formats' => [],
            ],
        ]);
});

test('getElementUpdateAttributes finds a representative extension for a non-picture element', function (): void {
    mkdir($this->root . '/pwg_representative');
    file_put_contents($this->root . '/pwg_representative/document.gif', 'gif-bytes');

    $reader = new LocalSiteReader($this->root, $this->currentConfig, lsrTestMetadataService());

    expect($reader->getElementUpdateAttributes($this->root . '/document.pdf'))->toEqual(new ElementUpdateAttributes('gif'));
});

test('getElementUpdateAttributes returns a null representative extension for a picture element', function (): void {
    $reader = new LocalSiteReader($this->root, $this->currentConfig, lsrTestMetadataService());

    expect($reader->getElementUpdateAttributes($this->root . '/photo.png'))->toEqual(new ElementUpdateAttributes(null));
});

test('getRepresentativeExt returns the first matching picture extension found under pwg_representative', function (): void {
    mkdir($this->root . '/pwg_representative');
    file_put_contents($this->root . '/pwg_representative/movie.jpg', 'jpg-bytes');

    $reader = new LocalSiteReader($this->root, $this->currentConfig, lsrTestMetadataService());

    expect($reader->getRepresentativeExt($this->root, 'movie'))
        ->toBe('jpg');
});

test('getRepresentativeExt returns null when no representative file exists for any picture extension', function (): void {
    mkdir($this->root . '/pwg_representative');

    $reader = new LocalSiteReader($this->root, $this->currentConfig, lsrTestMetadataService());

    expect($reader->getRepresentativeExt($this->root, 'movie'))
        ->toBeNull();
});

test('getFormats returns the on-disk size in kilobytes for each matching format extension present, in formatExtensions order', function (): void {
    mkdir($this->root . '/pwg_format');
    file_put_contents($this->root . '/pwg_format/negative.tif', str_repeat('a', 4096));
    file_put_contents($this->root . '/pwg_format/negative.psd', str_repeat('b', 1024));

    $reader = new LocalSiteReader($this->root, $this->currentConfig, lsrTestMetadataService());

    expect($reader->getFormats($this->root, 'negative'))
        ->toBe([
            'tif' => 4.0,
            'psd' => 1.0,
        ]);
});

test('getFormats returns an empty array when the pwg_format directory does not exist', function (): void {
    $reader = new LocalSiteReader($this->root, $this->currentConfig, lsrTestMetadataService());

    expect($reader->getFormats($this->root, 'negative'))
        ->toBe([]);
});

test('getElements does not look up a representative extension for a picture-extension element even when a matching representative file exists', function (): void {
    // flip_picture_ext is what getElements() consults to decide whether
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

    $reader = new LocalSiteReader($this->root, $this->currentConfig, lsrTestMetadataService());
    $elements = $reader->getElements($this->root);

    expect($elements)
        ->toBe([
            $this->root . '/family-photo.jpg' => [
                'representative_ext' => null,
            ],
        ]);
});

test('getElements lower-cases the file extension before matching it against the configured extension lists', function (): void {
    file_put_contents($this->root . '/vacation.JPG', 'jpg-bytes');

    $reader = new LocalSiteReader($this->root, $this->currentConfig, lsrTestMetadataService());
    $elements = $reader->getElements($this->root);

    expect($elements)
        ->toBe([
            $this->root . '/vacation.JPG' => [
                'representative_ext' => null,
            ],
        ]);
});

test('getElements recurses into ordinary subdirectories -- including names that are a substring or superstring of an excluded name -- while skipping exactly pwg_high, pwg_representative, pwg_format and thumbnail', function (): void {
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

    $reader = new LocalSiteReader($this->root, $this->currentConfig, lsrTestMetadataService());
    $elements = $reader->getElements($this->root);

    expect($elements)
        ->toBe([
            $this->root . '/humbnail/inner-c.jpg' => [
                'representative_ext' => null,
            ],
            $this->root . '/keepme/inner-a.jpg' => [
                'representative_ext' => null,
            ],
            $this->root . '/thumbnails/inner-b.jpg' => [
                'representative_ext' => null,
            ],
        ]);
});

test('getElements returns keys in sorted order regardless of the on-disk readdir order', function (): void {
    // Filenames deliberately chosen so their natural readdir() order
    // (filesystem/hash-dependent, effectively unordered for a small
    // directory on ext4) is very unlikely to already be alphabetical --
    // the assertion below only passes if ksort() actually ran.
    file_put_contents($this->root . '/zebra.jpg', 'z-bytes');
    file_put_contents($this->root . '/mango.jpg', 'm-bytes');
    file_put_contents($this->root . '/apple.jpg', 'a-bytes');

    $reader = new LocalSiteReader($this->root, $this->currentConfig, lsrTestMetadataService());
    $elements = $reader->getElements($this->root);

    expect(array_keys($elements))
        ->toBe([
            $this->root . '/apple.jpg',
            $this->root . '/mango.jpg',
            $this->root . '/zebra.jpg',
        ]);
});

test('getFormats floors a non-multiple-of-1024 file size to kilobytes', function (): void {
    // 2047 bytes / 1024 = 1.9990234375: floor() -> 1.0, while round()
    // and ceil() both -> 2.0, and dividing by 1023 instead of 1024
    // (an off-by-one on the divisor) also -> floor(2047/1023) = 2.0.
    // This one size therefore distinguishes floor() from all three.
    mkdir($this->root . '/pwg_format');
    file_put_contents($this->root . '/pwg_format/negative.tif', str_repeat('a', 2047));

    $reader = new LocalSiteReader($this->root, $this->currentConfig, lsrTestMetadataService());

    expect($reader->getFormats($this->root, 'negative'))
        ->toBe([
            'tif' => 1.0,
        ]);
});
