<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Image;

use Error;
use Exception;
use LogicException;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentThemeConfProvider;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Core\ThemeConfProviderInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Event\Picture\GetMimetypeLocation;
use Piwigo\Image\Event\GetSrcImageUrl;
use Piwigo\Image\ImageEntity;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\SrcImage;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentPathsTestFactory;
use Piwigo\Tests\Support\DbTransactionTestOverride;
use Piwigo\Tests\Support\EventDispatcherTestFactory;
use Piwigo\Tests\Support\KernelContainerOverride;
use RuntimeException;
use stdClass;

/**
 * Covers: the 3 static-setter-guarded accessors' not-set RuntimeException
 * (fatalError()/themeConf()/urlService()); the constructor's
 * representative_ext branch, its full mimetype-icon lookup (icon found,
 * icon missing -> falls back to 'unknown.png', and the ext==='svg'
 * special-case that falls back to the original path instead -- including
 * that path's own "still not a real image" Exception), and its
 * rotation-swap branch for width/height; getPath(); getUrl()'s
 * mimetype-icon branch; and getSize()'s DIM_NOT_GIVEN fatalError() (both
 * with and without an installed HtmlRenderingInterface) plus its live
 * getimagesize() re-read.
 *
 * SrcImage resolves htmlRenderer, urlService, imageRepository, and
 * themeConfProvider all the same way -- fresh from the container on
 * every call, no independently-settable static wrapper of its own. A
 * fake/real collaborator for the first three is installed via
 * KernelContainerOverride::with(); CurrentThemeConfProvider is the one
 * exception, since its own set()/get()/reset() already exist as a real,
 * dedicated, independently settable wrapper around the container-shared
 * instance -- srcImageTestSetThemeConfProvider() below resolves that
 * same container-shared instance and calls set()/reset() on it directly,
 * requiring Kernel already booted, same as themeConf() itself.
 */
function srcImageTestSetThemeConfProvider(?ThemeConfProviderInterface $provider): void
{
    $instance = Kernel::container()->get(CurrentThemeConfProvider::class);
    if (! $instance instanceof CurrentThemeConfProvider) {
        throw new LogicException('Container returned an unexpected type for ' . CurrentThemeConfProvider::class);
    }

    if ($provider instanceof ThemeConfProviderInterface) {
        $instance->set($provider);
    } else {
        $instance->reset();
    }
}

/**
 * Writes a real, GD-encoded PNG with exact known dimensions -- deliberately
 * distinct width/height per call site below (anti-transposition) rather
 * than a hardcoded byte blob, so getimagesize() returns a value this file
 * fully controls. No imagedestroy() call (PHP 8.5 deprecation): GD image
 * objects are plain garbage-collected values since PHP 8, not resources.
 */
/**
 * @param  int<1, max>  $width
 * @param  int<1, max>  $height
 */
function srcImageTestMakePng(string $path, int $width, int $height): void
{
    $dir = dirname($path);
    if (! is_dir($dir)) {
        mkdir($dir, 0o777, true);
    }
    $image = imagecreatetruecolor($width, $height);
    imagepng($image, $path);
}

function srcImageTestRrmdir(string $dir): void
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
        is_dir($path) ? srcImageTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

beforeEach(function (): void {
    CurrentConfigTestFactory::get()->reset();
});

afterEach(function (): void {
    // No explicit srcImageTestSetThemeConfProvider(null) needed here --
    // CurrentThemeConfProvider is now only ever reachable through the
    // container (no more static $fallback to leak across tests), so
    // Kernel::reset() below already guarantees the next test's own
    // Kernel::boot() gets a brand-new instance with $provider === null.
    CurrentConfigTestFactory::get()->reset();
    Kernel::reset();
});

test('themeConf() throws a RuntimeException when no ThemeConfProviderInterface has been installed yet', function (): void {
    // SrcImage's constructor needs a booted Kernel (it reads
    // pictureExtensions()) -- boot it, but never install a theme-conf
    // provider, so themeConf()'s own check is still what throws.
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir() . '/piwigo-srcimage-test-themeconf-not-set'));
    srcImageTestSetThemeConfProvider(null);

    expect(fn (): SrcImage => new SrcImage([
        'id' => 1,
        'path' => 'upload/2026/07/doc.pdf',
        'file' => 'doc.pdf',
    ]))->toThrow(RuntimeException::class, 'SrcImage: no theme-conf provider set (Template not constructed yet?)');
});

test('urlService() throws a RuntimeException when no UrlServiceInterface has been installed yet', function (): void {
    // SrcImage's constructor needs a booted Kernel (it reads
    // pictureExtensions()) -- construct it under a real boot, then
    // reset before calling getUrl() so urlService()'s own
    // Kernel::isBooted() guard is what throws, not SrcImage's. $src itself
    // holds no live container reference once built, so resetting
    // afterward doesn't invalidate it.
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir() . '/piwigo-srcimage-test-urlservice-not-set'));
    $src = new SrcImage([
        'id' => 1,
        'path' => 'upload/2026/07/photo.jpg',
        'file' => 'photo.jpg',
    ]);
    Kernel::reset();

    expect(fn (): string => $src->getUrl())
        ->toThrow(RuntimeException::class, 'SrcImage: no URL service set (RequestBootstrap not run yet?)');
});

test('getSize() throws a RuntimeException carrying the untranslated message when dimensions are required but not provided and no HtmlRenderingInterface is installed', function (): void {
    // SrcImage's constructor needs a booted Kernel (it reads
    // pictureExtensions()) -- construct it under a real boot, then
    // reset before calling getSize() so fatalError()'s own
    // Kernel::isBooted() guard falls through to the plain, untranslated
    // RuntimeException, not the container's real HtmlRenderingInterface.
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir() . '/piwigo-srcimage-test-get-size-no-renderer'));
    $src = new SrcImage([
        'id' => 5,
        'path' => 'upload/2026/07/photo.jpg',
        'file' => 'photo.jpg',
    ]);
    Kernel::reset();

    expect(fn (): ?array => $src->getSize())
        ->toThrow(RuntimeException::class, 'SrcImage dimensions required but not provided');
});

test('getSize() delegates the fatal message to the installed HtmlRenderingInterface instead of throwing RuntimeException directly', function (): void {
    $renderer = new SrcImageTestFatalRenderer();

    KernelContainerOverride::with([
        HtmlRenderingInterface::class => $renderer,
    ], function () use ($renderer): void {
        $src = new SrcImage([
            'id' => 5,
            'path' => 'upload/2026/07/photo.jpg',
            'file' => 'photo.jpg',
        ]);

        expect(fn (): ?array => $src->getSize())
            ->toThrow(SrcImageTestFatalSignal::class);
        expect($renderer->lastMessage)
            ->toBe('SrcImage dimensions required but not provided');
    });
});

test('constructor narrows a numeric-string id to a real int', function (): void {
    // Kills line 171's RemoveIntegerCast. SrcImage's constructor needs a
    // booted Kernel (it reads pictureExtensions()).
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir() . '/piwigo-srcimage-test-id-numeric-string'));
    $src = new SrcImage([
        'id' => '7',
        'path' => 'upload/2026/07/photo.jpg',
        'file' => 'photo.jpg',
    ]);

    expect($src->id)
        ->toBe(7);
});

test('constructor defaults id to exactly 0 for a non-numeric id', function (): void {
    // Kills line 171's DecrementInteger/IncrementInteger. SrcImage's
    // constructor needs a booted Kernel (it reads pictureExtensions()).
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir() . '/piwigo-srcimage-test-id-non-numeric'));
    $src = new SrcImage([
        'id' => 'not-numeric',
        'path' => 'upload/2026/07/photo.jpg',
        'file' => 'photo.jpg',
    ]);

    expect($src->id)
        ->toBe(0);
});

test('constructor builds a pwg_representative path when the extension is not a picture extension but a representative_ext is given', function (): void {
    // SrcImage's constructor needs a booted Kernel (it reads
    // pictureExtensions()).
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir() . '/piwigo-srcimage-test-representative-path'));
    $src = new SrcImage([
        'id' => 1,
        'path' => 'upload/2026/07/doc.pdf',
        'file' => 'doc.pdf',
        'representative_ext' => 'jpg',
    ]);

    expect($src->rel_path)
        ->toBe('upload/2026/07/pwg_representative/doc.jpg');
    expect($src->isOriginal())
        ->toBeFalse();
    expect($src->isMimetype())
        ->toBeFalse();
});

test('constructor matches a picture extension case-insensitively', function (): void {
    // Kills line 174's UnwrapStrtolower -- CurrentConfig::pictureExtensions()'s
    // own default set is all-lowercase; an uppercase real-world extension
    // only matches it through strtolower(). SrcImage's constructor needs a
    // booted Kernel (it reads pictureExtensions()).
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir() . '/piwigo-srcimage-test-case-insensitive'));
    $src = new SrcImage([
        'id' => 1,
        'path' => 'upload/2026/07/photo.JPG',
        'file' => 'photo.JPG',
    ]);

    expect($src->isOriginal())
        ->toBeTrue();
    expect($src->rel_path)
        ->toBe('upload/2026/07/photo.JPG');
});

test('constructor swaps width/height for an odd rotation code but not for an even one', function (): void {
    // SrcImage's constructor needs a booted Kernel (it reads
    // pictureExtensions()).
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir() . '/piwigo-srcimage-test-rotation-swap'));
    $rotated = new SrcImage([
        'id' => 1,
        'path' => 'upload/2026/07/photo.jpg',
        'file' => 'photo.jpg',
        'width' => 300,
        'height' => 200,
        'rotation' => 1,
    ]);
    expect($rotated->rotation)
        ->toBe(1);
    expect($rotated->hasSize())
        ->toBeTrue();
    expect($rotated->getSize())
        ->toBe([200, 300]);

    $unrotated = new SrcImage([
        'id' => 2,
        'path' => 'upload/2026/07/photo.jpg',
        'file' => 'photo.jpg',
        'width' => 300,
        'height' => 200,
        'rotation' => 2,
    ]);
    expect($unrotated->rotation)
        ->toBe(2);
    expect($unrotated->getSize())
        ->toBe([300, 200]);
});

test('getPath() joins the current root with the resolved rel_path', function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir() . '/piwigo-srcimage-test-path-only'));

    $src = new SrcImage([
        'id' => 1,
        'path' => 'upload/2026/07/photo.jpg',
        'file' => 'photo.jpg',
    ]);

    expect($src->getPath())
        ->toBe(CurrentPathsTestFactory::get()->root . 'upload/2026/07/photo.jpg');
});

test('constructor finds a real per-extension mimetype icon, and getUrl() embellishes the root-relative icon url', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-srcimage-test-' . bin2hex(random_bytes(8));
    srcImageTestMakePng($root . '/themes/default/icon/mimetypes/zzz.png', 16, 12);

    try {
        KernelContainerOverride::with([
            Paths::class => Paths::fromRoot($root),
            UrlServiceInterface::class => new SrcImageTestFakeUrlService(),
        ], function (): void {
            // Must be seeded AFTER the container above is built --
            // srcImageTestSetThemeConfProvider() resolves
            // CurrentThemeConfProvider straight from the container, so
            // calling it before KernelContainerOverride::with() rebuilds
            // that container would seed an instance the override then
            // immediately discards.
            srcImageTestSetThemeConfProvider(new SrcImageTestFakeThemeConfProvider('themes/default/icon/mimetypes/'));

            $src = new SrcImage([
                'id' => 1,
                'path' => 'upload/2026/07/file.zzz',
                'file' => 'file.zzz',
            ]);

            expect($src->isMimetype())
                ->toBeTrue();
            expect($src->isOriginal())
                ->toBeFalse();
            expect($src->rel_path)
                ->toBe('themes/default/icon/mimetypes/zzz.png');
            expect($src->hasSize())
                ->toBeTrue();
            expect($src->getSize())
                ->toBe([16, 12]);
            expect($src->getUrl())
                ->toBe('/root/themes/default/icon/mimetypes/zzz.png');
        });
    } finally {
        srcImageTestRrmdir($root);
    }
});

test('constructor falls back to the shared unknown.png icon when no icon exists for the extension', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-srcimage-test-' . bin2hex(random_bytes(8));
    Kernel::boot(Paths::fromRoot($root));
    srcImageTestSetThemeConfProvider(new SrcImageTestFakeThemeConfProvider('themes/default/icon/mimetypes/'));
    srcImageTestMakePng($root . '/themes/default/icon/mimetypes/unknown.png', 20, 10);

    try {
        $src = new SrcImage([
            'id' => 1,
            'path' => 'upload/2026/07/file.qqq',
            'file' => 'file.qqq',
        ]);

        expect($src->isMimetype())
            ->toBeTrue();
        expect($src->rel_path)
            ->toBe('themes/default/icon/mimetypes/unknown.png');
        expect($src->getSize())
            ->toBe([20, 10]);
    } finally {
        srcImageTestRrmdir($root);
    }
});

test('constructor falls back to the original path for a .svg with no icon, then throws when that path is not a real image either', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-srcimage-test-' . bin2hex(random_bytes(8));
    Kernel::boot(Paths::fromRoot($root));
    srcImageTestSetThemeConfProvider(new SrcImageTestFakeThemeConfProvider('themes/default/icon/mimetypes/'));
    mkdir($root . '/upload/2026/07', 0o777, true);
    file_put_contents($root . '/upload/2026/07/vector.svg', 'not-a-real-image-payload');

    try {
        expect(fn (): SrcImage => new SrcImage([
            'id' => 1,
            'path' => 'upload/2026/07/vector.svg',
            'file' => 'vector.svg',
        ]))->toThrow(Exception::class, 'SrcImage: unable to read size of fallback icon upload/2026/07/vector.svg');
    } finally {
        srcImageTestRrmdir($root);
    }
});

test('getSize() re-reads real dimensions from disk when width/height columns are present but null (not yet metadata-synced)', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-srcimage-test-' . bin2hex(random_bytes(8));
    Kernel::boot(Paths::fromRoot($root));
    srcImageTestMakePng($root . '/upload/2026/07/synced-later.png', 33, 22);

    try {
        // A nonexistent id, not the real fixture image 1 -- getSize()'s
        // own lazy-read path also persists back onto ImageRepository
        // (updateDimensions()), which silently no-ops for an id with no
        // matching row instead of corrupting a real fixture row this
        // test doesn't otherwise care about.
        $src = new SrcImage([
            'id' => 999999,
            'path' => 'upload/2026/07/synced-later.png',
            'file' => 'synced-later.png',
            'width' => null,
            'height' => null,
        ]);

        expect($src->hasSize())
            ->toBeFalse();
        expect($src->getSize())
            ->toBe([33, 22]);
        expect($src->hasSize())
            ->toBeTrue();
    } finally {
        srcImageTestRrmdir($root);
    }
});

/**
 * Confirmed-equivalent (re-verified 2026-08-10 against current line
 * numbers -- the file has shifted since this was first written, content
 * unchanged): line 186's TernaryNegated (the `is_string(...) ? ... :
 * null` inversion for $file) and its own CoalesceRemoveLeft
 * (`$infos['file'] ?? null` -> `null`), plus line 188's UnwrapStrtolower
 * (`strtolower(StringHelper::getExtension($file))`). $file's ONLY use
 * anywhere in this class is feeding `$infos['file_ext'] = ...` -- and
 * $infos['file_ext'] is itself never read again, by this class or any
 * real caller ($infos is a local constructor parameter, never stored or
 * returned; re-confirmed via a fresh grep for `file_ext` across the
 * current file -- only the one write site exists). All 3 mutations are
 * therefore dead code, not just untested.
 *
 * Also confirmed-equivalent: line 222's, line 231's, and line 299's
 * RemoveBooleanCast (`(bool) $this->size`, `(bool) ($this->rotation %
 * 2)`, `(bool) ($this->flags & self::DIM_NOT_GIVEN)`). An `if()`
 * condition already coerces its operand to bool on its own -- `if
 * ((bool) X)` and `if (X)` evaluate identically for every possible X,
 * a universal PHP semantics fact. Live sed-verified all 3 against the
 * full suite too.
 */
test('constructor treats a missing path as an empty string, not null, when building the representative path -- not a dead default', function (): void {
    // Kills line 172's EmptyStringToNotEmpty. Unlike $file above, $path
    // is NOT dead: it feeds $this->rel_path directly in the
    // representative_ext branch (ImagePathHelper::originalToRepresentative()),
    // so its own is_string() default is real, observable behavior for a
    // malformed/partial row, confirmed by hand-tracing
    // originalToRepresentative('', 'jpg') vs the mutant's non-empty
    // placeholder value producing genuinely different results.
    //
    // SrcImage's constructor needs a booted Kernel (it reads
    // pictureExtensions()).
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir() . '/piwigo-srcimage-test-missing-path'));
    $src = new SrcImage([
        'id' => 1,
        'path' => null,
        'file' => 'doc.pdf',
        'representative_ext' => 'jpg',
    ]);

    expect($src->rel_path)
        ->toBe('pjpg');
});

test('constructor throws when a get_mimetype_location handler returns something other than a GetMimetypeLocation instance', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-srcimage-test-' . bin2hex(random_bytes(8));
    Kernel::boot(Paths::fromRoot($root));
    srcImageTestSetThemeConfProvider(new SrcImageTestFakeThemeConfProvider('themes/default/icon/mimetypes/'));
    srcImageTestMakePng($root . '/themes/default/icon/mimetypes/zzz.png', 16, 12);

    $handler = static fn (): int => 42;
    EventDispatcherTestFactory::get()->addEventHandler(GetMimetypeLocation::class, $handler);

    try {
        expect(fn (): SrcImage => new SrcImage([
            'id' => 1,
            'path' => 'upload/2026/07/file.zzz',
            'file' => 'file.zzz',
        ]))->toThrow(Error::class, 'must return an instance of');
    } finally {
        EventDispatcherTestFactory::get()->removeEventHandler(GetMimetypeLocation::class, $handler);
        srcImageTestRrmdir($root);
    }
});

test('constructor throws when neither the per-extension icon nor the shared unknown.png fallback exist on disk', function (): void {
    // Kills line 205's FalseToTrue (`$size = ... : true` instead of
    // `: false`) -- the sibling .svg test above reaches a DIFFERENT
    // file_exists() check (the original path, not unknown.png) for its
    // own fallback. A non-svg extension with no per-extension icon AND
    // no unknown.png on disk at all is what actually reaches line 205's
    // own file_exists() with a genuinely false result.
    $root = sys_get_temp_dir() . '/piwigo-srcimage-test-' . bin2hex(random_bytes(8));
    Kernel::boot(Paths::fromRoot($root));
    srcImageTestSetThemeConfProvider(new SrcImageTestFakeThemeConfProvider('themes/default/icon/mimetypes/'));

    try {
        expect(fn (): SrcImage => new SrcImage([
            'id' => 1,
            'path' => 'upload/2026/07/file.qqq',
            'file' => 'file.qqq',
        ]))->toThrow(Exception::class, 'SrcImage: unable to read size of fallback icon themes/default/icon/mimetypes/unknown.png');
    } finally {
        srcImageTestRrmdir($root);
    }
});

test('constructor never reads $infos[\'height\'] when only width is present, leaving size unset for a later lazy read', function (): void {
    // Kills line 214's BooleanAndToBooleanOr -- with 'height' key
    // genuinely absent (not just null), the mutant's `||` would enter
    // this block on 'width' alone and hit an undefined array key
    // reading $infos['height'], setting a bogus [width, 0] size instead
    // of correctly leaving $this->size null for getSize()'s own lazy
    // disk re-read.
    $root = sys_get_temp_dir() . '/piwigo-srcimage-test-' . bin2hex(random_bytes(8));
    Kernel::boot(Paths::fromRoot($root));
    srcImageTestMakePng($root . '/upload/2026/07/width-only.png', 55, 44);

    try {
        // A nonexistent id, not the real fixture image 1 -- same
        // ImageRepository::updateDimensions() side-effect concern as the
        // sibling test above.
        $src = new SrcImage([
            'id' => 999999,
            'path' => 'upload/2026/07/width-only.png',
            'file' => 'width-only.png',
            'width' => 300,
        ]);

        expect($src->hasSize())
            ->toBeFalse();
        expect($src->getSize())
            ->toBe([55, 44]);
    } finally {
        srcImageTestRrmdir($root);
    }
});

test('constructor narrows a numeric-string width to a real int, and defaults a non-numeric height to exactly 0', function (): void {
    // Kills line 215's RemoveIntegerCast (numeric-string width survives
    // as a string without the cast) and line 216's DecrementInteger/
    // IncrementInteger (a non-numeric-but-present height's own 0
    // default). SrcImage's constructor needs a booted Kernel (it reads
    // pictureExtensions()).
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir() . '/piwigo-srcimage-test-width-numeric-string'));
    $src = new SrcImage([
        'id' => 1,
        'path' => 'upload/2026/07/photo.jpg',
        'file' => 'photo.jpg',
        'width' => '150',
        'height' => 'not-numeric',
        'rotation' => 2,
    ]);

    expect($src->getSize())
        ->toBe([150, 0]);
});

test('constructor defaults a non-numeric width to exactly 0, and narrows a numeric-string height to a real int', function (): void {
    // Kills line 215's DecrementInteger/IncrementInteger (a
    // non-numeric-but-present width's own 0 default) and line 216's
    // RemoveIntegerCast (numeric-string height survives as a string
    // without the cast) -- the sibling test above only ever exercises
    // width's cast and height's default, never the other pairing.
    // SrcImage's constructor needs a booted Kernel (it reads
    // pictureExtensions()).
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir() . '/piwigo-srcimage-test-height-numeric-string'));
    $src = new SrcImage([
        'id' => 1,
        'path' => 'upload/2026/07/photo.jpg',
        'file' => 'photo.jpg',
        'width' => 'not-numeric',
        'height' => '90',
        'rotation' => 2,
    ]);

    expect($src->getSize())
        ->toBe([0, 90]);
});

test('constructor defaults rotation to exactly 0 when the column is absent, not just non-numeric', function (): void {
    // Kills line 219's DecrementInteger/IncrementInteger -- every
    // sibling test above always supplies a real numeric 'rotation'.
    // SrcImage's constructor needs a booted Kernel (it reads
    // pictureExtensions()).
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir() . '/piwigo-srcimage-test-rotation-default'));
    $src = new SrcImage([
        'id' => 1,
        'path' => 'upload/2026/07/photo.jpg',
        'file' => 'photo.jpg',
        'width' => 300,
        'height' => 200,
    ]);

    expect($src->rotation)
        ->toBe(0);
    expect($src->getSize())
        ->toBe([300, 200]);
});

test('getUrl() for a real original image requests part "e" without download, through the non-mimetype branch', function (): void {
    // Kills line 270's TernaryNegated (isOriginal() ? 'e' : 'r') and
    // line 271's FalseToTrue (the literal `false` $download argument) --
    // every sibling getUrl() test above only exercises the mimetype-icon
    // branch, never this one.
    $fakeUrlService = new SrcImageTestFakeUrlService();

    KernelContainerOverride::with([
        UrlServiceInterface::class => $fakeUrlService,
    ], function () use ($fakeUrlService): void {
        $src = new SrcImage([
            'id' => 7,
            'path' => 'upload/2026/07/photo.jpg',
            'file' => 'photo.jpg',
        ]);

        expect($src->isOriginal())
            ->toBeTrue();
        expect($src->getUrl())
            ->toBe('/action/7/e');
        expect($fakeUrlService->lastActionUrlArgs)
            ->toBe([7, 'e', false]);
    });
});

test('getUrl() for a real representative image requests part "r"', function (): void {
    // Kills line 270's TernaryNegated from the other direction.
    $fakeUrlService = new SrcImageTestFakeUrlService();

    KernelContainerOverride::with([
        UrlServiceInterface::class => $fakeUrlService,
    ], function () use ($fakeUrlService): void {
        $src = new SrcImage([
            'id' => 8,
            'path' => 'upload/2026/07/doc.pdf',
            'file' => 'doc.pdf',
            'representative_ext' => 'jpg',
        ]);

        expect($src->isOriginal())
            ->toBeFalse();
        expect($src->getUrl())
            ->toBe('/action/8/r');
        expect($fakeUrlService->lastActionUrlArgs)
            ->toBe([8, 'r', false]);
    });
});

test('getUrl() throws when a get_src_image_url handler returns something other than a GetSrcImageUrl instance', function (): void {
    // Every sibling non-mimetype getUrl() test above has NO handler
    // registered for GetSrcImageUrl, so dispatchChange() returns the
    // pre-filter url unchanged (already a string), never reaching
    // dispatchChange()'s own instanceof enforcement.
    $fakeUrlService = new SrcImageTestFakeUrlService();

    KernelContainerOverride::with([
        UrlServiceInterface::class => $fakeUrlService,
    ], function (): void {
        // addEventHandler(), not addTypedHandler() -- a real plugin handler
        // is untyped from PHPStan's perspective, and this test exercises
        // dispatchChange()'s own runtime enforcement, not a static one.
        $handler = static fn (): int => 42;
        EventDispatcherTestFactory::get()->addEventHandler(GetSrcImageUrl::class, $handler);

        try {
            $src = new SrcImage([
                'id' => 7,
                'path' => 'upload/2026/07/photo.jpg',
                'file' => 'photo.jpg',
            ]);

            expect(static fn (): string => $src->getUrl())
                ->toThrow(Error::class, 'must return an instance of');
        } finally {
            EventDispatcherTestFactory::get()->removeEventHandler(GetSrcImageUrl::class, $handler);
        }
    });
});

test('getSize() persists the real, correctly-ordered width/height back onto the image repository', function (): void {
    // Kills line 300's 4 index-swap mutations (DecrementInteger/
    // IncrementInteger on $size[0]/$size[1]) -- a real image with
    // DISTINCT width/height (anti-transposition) and a real
    // ImageRepository persisting the call proves both arguments land in
    // their own correct column, not swapped or duplicated.
    //
    // Exempt from tests/Pest.php's blanket per-test transaction: `images`
    // carries a FULLTEXT index (images_ft_name_comment/images_ft_author)
    // whose auxiliary-index maintenance can deadlock against another
    // --parallel worker's own concurrent images INSERT when held open
    // for a whole test's duration -- same mechanism, same fix, as
    // TagServiceTest.php's 'getTagIds() creates a new tag for a plain
    // name when allowed' (reproduced live there: DeadlockException).
    DbTransactionTestOverride::rollback();
    $conn = DbConnection::build();
    $repo = EntityManagerFactory::build($conn)->getRepository(ImageEntity::class);

    $conn->createQueryBuilder()
        ->insert('images')
        ->values([
            'file' => ':file',
            'path' => ':path',
        ])
        ->setParameter('file', 'update-dimensions.jpg')
        ->setParameter('path', 'upload/2026/07/update-dimensions.jpg')
        ->executeStatement();
    $imageId = (int) $conn->lastInsertId();

    $root = sys_get_temp_dir() . '/piwigo-srcimage-test-' . bin2hex(random_bytes(8));
    srcImageTestMakePng($root . '/upload/2026/07/update-dimensions.jpg', 77, 55);

    try {
        // ImageRepository::class bound directly to this real, manually-built
        // repository instance -- getSize()'s own container resolve must
        // reach the SAME EntityManager/Connection this test reads back
        // through afterward, not the container's own default (unrelated)
        // ImageRepository instance.
        KernelContainerOverride::with([
            Paths::class => Paths::fromRoot($root),
            ImageRepository::class => $repo,
        ], function () use ($imageId): void {
            $src = new SrcImage([
                'id' => $imageId,
                'path' => 'upload/2026/07/update-dimensions.jpg',
                'file' => 'update-dimensions.jpg',
                'width' => null,
                'height' => null,
            ]);

            expect($src->getSize())
                ->toBe([77, 55]);
        });

        $row = $conn->fetchAssociative('SELECT width, height FROM images' . " WHERE id = {$imageId}");
        expect($row)
            ->toBe([
                'width' => 77,
                'height' => 55,
            ]);
    } finally {
        $conn->executeStatement('DELETE FROM images WHERE id = ?', [$imageId]);
        srcImageTestRrmdir($root);
    }
});

test('urlService() throws when the container returns an unexpected type for UrlServiceInterface', function (): void {
    // Kills line 71's InstanceOfToTrue -- container()->get(UrlServiceInterface::class)
    // never legitimately returns something other than a UrlServiceInterface
    // instance through Kernel::boot()'s own public API; only
    // KernelContainerOverride::withWrongTypeFor() can reach this branch.
    // $src is built under a normal boot first (its constructor only needs
    // currentConfig(), which the override below leaves untouched), then
    // reused inside the override the same way the pre-existing
    // "no UrlServiceInterface installed" test above reuses a pre-built
    // $src across a Kernel::reset().
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir() . '/piwigo-srcimage-test-urlservice-wrong-type'));
    $src = new SrcImage([
        'id' => 1,
        'path' => 'upload/2026/07/photo.jpg',
        'file' => 'photo.jpg',
    ]);

    expect(fn (): mixed => KernelContainerOverride::withWrongTypeFor(UrlServiceInterface::class, function () use ($src): void {
        $src->getUrl();
    }))->toThrow(RuntimeException::class, 'SrcImage: no URL service set (RequestBootstrap not run yet?)');
});

test('currentConfig() throws when the container returns an unexpected type for CurrentConfig', function (): void {
    // Kills line 91's InstanceOfToTrue -- same "never legitimately happens
    // through Kernel::boot()'s own public API" reasoning as urlService()'s
    // own sibling test above. The constructor is the only entry point that
    // reaches currentConfig() (it needs pictureExtensions() to classify
    // the extension), so it has to run entirely inside the override.
    expect(fn (): mixed => KernelContainerOverride::withWrongTypeFor(CurrentConfig::class, function (): void {
        new SrcImage([
            'id' => 1,
            'path' => 'upload/2026/07/photo.jpg',
            'file' => 'photo.jpg',
        ]);
    }))->toThrow(RuntimeException::class, 'SrcImage: no CurrentConfig set (RequestBootstrap not run yet?)');
});

test('paths() throws when the container returns an unexpected type for Paths', function (): void {
    // Kills line 109's InstanceOfToTrue -- getPath() is the simplest
    // public entry point that reaches paths() without needing anything
    // else from the container. Same reuse-a-pre-built-$src shape as
    // urlService()'s own sibling test above.
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir() . '/piwigo-srcimage-test-paths-wrong-type'));
    $src = new SrcImage([
        'id' => 1,
        'path' => 'upload/2026/07/photo.jpg',
        'file' => 'photo.jpg',
    ]);

    expect(fn (): mixed => KernelContainerOverride::withWrongTypeFor(Paths::class, function () use ($src): void {
        $src->getPath();
    }))->toThrow(RuntimeException::class, 'SrcImage: no Paths set (RequestBootstrap not run yet?)');
});

test('eventDispatcher() throws when the container returns an unexpected type for EventDispatcher', function (): void {
    // Kills line 127's InstanceOfToTrue -- eventDispatcher() is only
    // reached from the constructor's mimetype-icon fallback branch (a
    // non-picture extension with no representative_ext). Same "never
    // legitimately happens through Kernel::boot()'s own public API"
    // reasoning as the other 3 collaborator guards' own sibling tests
    // above, so the whole construction has to run inside the override.
    // The theme-conf provider must be seeded INSIDE the override's own
    // closure -- srcImageTestSetThemeConfProvider() resolves
    // CurrentThemeConfProvider from whichever container is currently
    // installed, so seeding it before withWrongTypeFor() rebuilds the
    // container would seed an instance the override then discards (see
    // the mimetype-icon test's own comment further above).
    expect(fn (): mixed => KernelContainerOverride::withWrongTypeFor(EventDispatcher::class, function (): void {
        srcImageTestSetThemeConfProvider(new SrcImageTestFakeThemeConfProvider('themes/default/icon/mimetypes/'));

        new SrcImage([
            'id' => 1,
            'path' => 'upload/2026/07/file.qqq',
            'file' => 'file.qqq',
        ]);
    }))->toThrow(RuntimeException::class, 'SrcImage: no EventDispatcher set (RequestBootstrap not run yet?)');
});

test('constructor normalizes rotation via modulo 4, not modulo 3 or modulo 5', function (): void {
    // Kills line 228's DecrementInteger (`% 3`) and IncrementInteger
    // (`% 5`) -- every existing rotation test elsewhere in this file uses
    // 1 or 2, and both happen to produce the SAME result under %3/%4/%5
    // (1 % 3 === 1 % 4 === 1 % 5 === 1, and 2 % 3 === 2 % 4 === 2 % 5 ===
    // 2), so none of them can tell the 3 moduli apart. rotation=4 is the
    // smallest value where they genuinely disagree: 4 % 4 = 0 (real code),
    // 4 % 3 = 1 (DecrementInteger mutant), 4 % 5 = 4 (IncrementInteger
    // mutant) -- all 3 distinguishable via an exact-value assertion on
    // $src->rotation alone.
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir() . '/piwigo-srcimage-test-rotation-modulo-4'));
    $src = new SrcImage([
        'id' => 1,
        'path' => 'upload/2026/07/photo.jpg',
        'file' => 'photo.jpg',
        'width' => 300,
        'height' => 200,
        'rotation' => 4,
    ]);

    expect($src->rotation)
        ->toBe(0);
    expect($src->getSize())
        ->toBe([300, 200]);
});

test('getSize() re-read skips the persistence call when the container returns an unexpected type for ImageRepository', function (): void {
    // Kills line 307's InstanceOfToTrue -- the "persists the real,
    // correctly-ordered width/height" test above always binds a REAL
    // ImageRepository, where the mutant's unconditional `if (true)`
    // happens to behave identically (its own instanceof check is true
    // there too, so that test can't tell the two branches apart). Only a
    // genuinely wrong-typed container binding (never legitimate through
    // Kernel::boot()'s own public API) does: the mutant would call
    // updateDimensions() on a plain stdClass and fatally error instead of
    // just returning the freshly re-read size. Paths::class has to be
    // explicitly rebound alongside ImageRepository::class here --
    // KernelContainerOverride::with() builds a brand new container with
    // no Paths::class binding of its own (Kernel::boot() is what supplies
    // that in the real world), so getPath() would otherwise resolve a
    // different root than the one the PNG below was written under.
    $root = sys_get_temp_dir() . '/piwigo-srcimage-test-' . bin2hex(random_bytes(8));
    srcImageTestMakePng($root . '/upload/2026/07/skip-persist.png', 41, 31);

    try {
        $size = KernelContainerOverride::with([
            Paths::class => Paths::fromRoot($root),
            ImageRepository::class => new stdClass(),
        ], function (): ?array {
            $src = new SrcImage([
                'id' => 1,
                'path' => 'upload/2026/07/skip-persist.png',
                'file' => 'skip-persist.png',
                'width' => null,
                'height' => null,
            ]);

            return $src->getSize();
        });

        expect($size)
            ->toBe([41, 31]);
    } finally {
        srcImageTestRrmdir($root);
    }
});
