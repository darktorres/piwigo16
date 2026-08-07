<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Image;

use RuntimeException;
use Exception;
use Piwigo\Tests\Support\EventDispatcherTestFactory;
use Error;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Image\ImageEntity;
use Piwigo\Db\Tables;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentPathsTestFactory;
use Piwigo\Core\CurrentThemeConfProvider;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Core\ThemeConfProviderInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Event\Picture\GetMimetypeLocation;
use Piwigo\Image\Event\GetSrcImageUrl;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\SrcImage;
use Piwigo\Tests\Support\KernelContainerOverride;

/**
 * Covers: the 3 static-setter-guarded accessors' not-set RuntimeException
 * (fatalError()/themeConf()/urlService()); the constructor's
 * representative_ext branch, its full mimetype-icon lookup (icon found,
 * icon missing -> falls back to 'unknown.png', and the ext==='svg'
 * special-case that falls back to the original path instead -- including
 * that path's own "still not a real image" Exception), and its
 * rotation-swap branch for width/height; get_path(); get_url()'s
 * mimetype-icon branch; and get_size()'s DIM_NOT_GIVEN fatalError() (both
 * with and without an installed HtmlRenderingInterface) plus its live
 * getimagesize() re-read.
 *
 * SrcImage resolves htmlRenderer, urlService, and imageRepository fresh
 * from the container on every call, with no independently-settable static
 * wrapper for any of the three -- a fake/real collaborator for them is
 * installed via KernelContainerOverride::with(), not direct Reflection
 * into a static property. themeConfProvider is the one exception:
 * CurrentThemeConfProvider is a real, dedicated, independently settable
 * wrapper (works whether or not Kernel is booted), so
 * srcImageTestSetThemeConfProvider() below still behaves like a direct
 * setter.
 */
function srcImageTestSetThemeConfProvider(?ThemeConfProviderInterface $provider): void
{
    if ($provider instanceof ThemeConfProviderInterface) {
        CurrentThemeConfProvider::current()->set($provider);
    } else {
        CurrentThemeConfProvider::current()->reset();
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
    CurrentConfigTestFactory::get()->reset();
    Kernel::reset();
    srcImageTestSetThemeConfProvider(null);
});

test('themeConf() throws a RuntimeException when no ThemeConfProviderInterface has been installed yet', function (): void {
    // SrcImage's constructor needs a booted Kernel (it reads
    // pictureExtensions()) -- boot it, but never install a theme-conf
    // provider, so themeConf()'s own check is still what throws.
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir() . '/piwigo-srcimage-test-themeconf-not-set'));
    srcImageTestSetThemeConfProvider(null);

    expect(fn () => new SrcImage([
        'id' => 1,
        'path' => 'upload/2026/07/doc.pdf',
        'file' => 'doc.pdf',
    ]))->toThrow(RuntimeException::class, 'SrcImage: no theme-conf provider set (Template not constructed yet?)');
});

test('urlService() throws a RuntimeException when no UrlServiceInterface has been installed yet', function (): void {
    // SrcImage's constructor needs a booted Kernel (it reads
    // pictureExtensions()) -- construct it under a real boot, then
    // reset before calling get_url() so urlService()'s own
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

    expect(fn () => $src->get_url())
        ->toThrow(RuntimeException::class, 'SrcImage: no URL service set (RequestBootstrap not run yet?)');
});

test('get_size() throws a RuntimeException carrying the untranslated message when dimensions are required but not provided and no HtmlRenderingInterface is installed', function (): void {
    // SrcImage's constructor needs a booted Kernel (it reads
    // pictureExtensions()) -- construct it under a real boot, then
    // reset before calling get_size() so fatalError()'s own
    // Kernel::isBooted() guard falls through to the plain, untranslated
    // RuntimeException, not the container's real HtmlRenderingInterface.
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir() . '/piwigo-srcimage-test-get-size-no-renderer'));
    $src = new SrcImage([
        'id' => 5,
        'path' => 'upload/2026/07/photo.jpg',
        'file' => 'photo.jpg',
    ]);
    Kernel::reset();

    expect(fn () => $src->get_size())
        ->toThrow(RuntimeException::class, 'SrcImage dimensions required but not provided');
});

test('get_size() delegates the fatal message to the installed HtmlRenderingInterface instead of throwing RuntimeException directly', function (): void {
    $renderer = new SrcImageTestFatalRenderer();

    KernelContainerOverride::with([HtmlRenderingInterface::class => $renderer], function () use ($renderer): void {
        $src = new SrcImage([
            'id' => 5,
            'path' => 'upload/2026/07/photo.jpg',
            'file' => 'photo.jpg',
        ]);

        expect(fn () => $src->get_size())->toThrow(SrcImageTestFatalSignal::class);
        expect($renderer->lastMessage)->toBe('SrcImage dimensions required but not provided');
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

    expect($src->id)->toBe(7);
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

    expect($src->id)->toBe(0);
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

    expect($src->rel_path)->toBe('upload/2026/07/pwg_representative/doc.jpg');
    expect($src->is_original())->toBeFalse();
    expect($src->is_mimetype())->toBeFalse();
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

    expect($src->is_original())->toBeTrue();
    expect($src->rel_path)->toBe('upload/2026/07/photo.JPG');
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
    expect($rotated->rotation)->toBe(1);
    expect($rotated->has_size())->toBeTrue();
    expect($rotated->get_size())->toBe([200, 300]);

    $unrotated = new SrcImage([
        'id' => 2,
        'path' => 'upload/2026/07/photo.jpg',
        'file' => 'photo.jpg',
        'width' => 300,
        'height' => 200,
        'rotation' => 2,
    ]);
    expect($unrotated->rotation)->toBe(2);
    expect($unrotated->get_size())->toBe([300, 200]);
});

test('get_path() joins the current root with the resolved rel_path', function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir() . '/piwigo-srcimage-test-path-only'));

    $src = new SrcImage([
        'id' => 1,
        'path' => 'upload/2026/07/photo.jpg',
        'file' => 'photo.jpg',
    ]);

    expect($src->get_path())->toBe(CurrentPathsTestFactory::get()->root . 'upload/2026/07/photo.jpg');
});

test('constructor finds a real per-extension mimetype icon, and get_url() embellishes the root-relative icon url', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-srcimage-test-' . bin2hex(random_bytes(8));
    srcImageTestMakePng($root . '/themes/default/icon/mimetypes/zzz.png', 16, 12);

    try {
        KernelContainerOverride::with([
            Paths::class => Paths::fromRoot($root),
            UrlServiceInterface::class => new SrcImageTestFakeUrlService(),
        ], function (): void {
            // Must be seeded AFTER the container above is built -- CurrentThemeConfProvider::current()
            // resolves a different instance once Kernel is booted than the
            // pre-boot memoized fallback it returns beforehand.
            srcImageTestSetThemeConfProvider(new SrcImageTestFakeThemeConfProvider('themes/default/icon/mimetypes/'));

            $src = new SrcImage([
                'id' => 1,
                'path' => 'upload/2026/07/file.zzz',
                'file' => 'file.zzz',
            ]);

            expect($src->is_mimetype())->toBeTrue();
            expect($src->is_original())->toBeFalse();
            expect($src->rel_path)->toBe('themes/default/icon/mimetypes/zzz.png');
            expect($src->has_size())->toBeTrue();
            expect($src->get_size())->toBe([16, 12]);
            expect($src->get_url())->toBe('/root/themes/default/icon/mimetypes/zzz.png');
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

        expect($src->is_mimetype())->toBeTrue();
        expect($src->rel_path)->toBe('themes/default/icon/mimetypes/unknown.png');
        expect($src->get_size())->toBe([20, 10]);
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
        expect(fn () => new SrcImage([
            'id' => 1,
            'path' => 'upload/2026/07/vector.svg',
            'file' => 'vector.svg',
        ]))->toThrow(Exception::class, 'SrcImage: unable to read size of fallback icon upload/2026/07/vector.svg');
    } finally {
        srcImageTestRrmdir($root);
    }
});

test('get_size() re-reads real dimensions from disk when width/height columns are present but null (not yet metadata-synced)', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-srcimage-test-' . bin2hex(random_bytes(8));
    Kernel::boot(Paths::fromRoot($root));
    srcImageTestMakePng($root . '/upload/2026/07/synced-later.png', 33, 22);

    try {
        $src = new SrcImage([
            'id' => 1,
            'path' => 'upload/2026/07/synced-later.png',
            'file' => 'synced-later.png',
            'width' => null,
            'height' => null,
        ]);

        expect($src->has_size())->toBeFalse();
        expect($src->get_size())->toBe([33, 22]);
        expect($src->has_size())->toBeTrue();
    } finally {
        srcImageTestRrmdir($root);
    }
});

/**
 * Confirmed-equivalent: line 173's TernaryNegated (the `is_string(...)
 * ? null : $infos['file']` inversion for $file) and line 175's
 * UnwrapStrtolower (`strtolower(StringHelper::getExtension($file))`).
 * $file's ONLY use anywhere in this class is feeding
 * `$infos['file_ext'] = ...` -- and $infos['file_ext'] is itself never
 * read again, by this class or any real caller ($infos is a local
 * constructor parameter, never stored or returned). Both mutations are
 * therefore dead code, not just untested. Live sed-verified both
 * independently against the full suite too.
 *
 * Also confirmed-equivalent: line 213's, line 222's, and line 294's
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

    expect($src->rel_path)->toBe('pjpg');
});

test('constructor throws when a get_mimetype_location handler returns something other than a GetMimetypeLocation instance', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-srcimage-test-' . bin2hex(random_bytes(8));
    Kernel::boot(Paths::fromRoot($root));
    srcImageTestSetThemeConfProvider(new SrcImageTestFakeThemeConfProvider('themes/default/icon/mimetypes/'));
    srcImageTestMakePng($root . '/themes/default/icon/mimetypes/zzz.png', 16, 12);

    $handler = static fn (): int => 42;
    EventDispatcherTestFactory::get()->addEventHandler(GetMimetypeLocation::class, $handler);

    try {
        expect(fn () => new SrcImage([
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
        expect(fn () => new SrcImage([
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
    // of correctly leaving $this->size null for get_size()'s own lazy
    // disk re-read.
    $root = sys_get_temp_dir() . '/piwigo-srcimage-test-' . bin2hex(random_bytes(8));
    Kernel::boot(Paths::fromRoot($root));
    srcImageTestMakePng($root . '/upload/2026/07/width-only.png', 55, 44);

    try {
        $src = new SrcImage([
            'id' => 1,
            'path' => 'upload/2026/07/width-only.png',
            'file' => 'width-only.png',
            'width' => 300,
        ]);

        expect($src->has_size())->toBeFalse();
        expect($src->get_size())->toBe([55, 44]);
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

    expect($src->get_size())->toBe([150, 0]);
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

    expect($src->get_size())->toBe([0, 90]);
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

    expect($src->rotation)->toBe(0);
    expect($src->get_size())->toBe([300, 200]);
});

test('get_url() for a real original image requests part "e" without download, through the non-mimetype branch', function (): void {
    // Kills line 270's TernaryNegated (is_original() ? 'e' : 'r') and
    // line 271's FalseToTrue (the literal `false` $download argument) --
    // every sibling get_url() test above only exercises the mimetype-icon
    // branch, never this one.
    $fakeUrlService = new SrcImageTestFakeUrlService();

    KernelContainerOverride::with([UrlServiceInterface::class => $fakeUrlService], function () use ($fakeUrlService): void {
        $src = new SrcImage([
            'id' => 7,
            'path' => 'upload/2026/07/photo.jpg',
            'file' => 'photo.jpg',
        ]);

        expect($src->is_original())->toBeTrue();
        expect($src->get_url())->toBe('/action/7/e');
        expect($fakeUrlService->lastActionUrlArgs)->toBe([7, 'e', false]);
    });
});

test('get_url() for a real representative image requests part "r"', function (): void {
    // Kills line 270's TernaryNegated from the other direction.
    $fakeUrlService = new SrcImageTestFakeUrlService();

    KernelContainerOverride::with([UrlServiceInterface::class => $fakeUrlService], function () use ($fakeUrlService): void {
        $src = new SrcImage([
            'id' => 8,
            'path' => 'upload/2026/07/doc.pdf',
            'file' => 'doc.pdf',
            'representative_ext' => 'jpg',
        ]);

        expect($src->is_original())->toBeFalse();
        expect($src->get_url())->toBe('/action/8/r');
        expect($fakeUrlService->lastActionUrlArgs)->toBe([8, 'r', false]);
    });
});

test('get_url() throws when a get_src_image_url handler returns something other than a GetSrcImageUrl instance', function (): void {
    // Every sibling non-mimetype get_url() test above has NO handler
    // registered for GetSrcImageUrl, so dispatchChange() returns the
    // pre-filter url unchanged (already a string), never reaching
    // dispatchChange()'s own instanceof enforcement.
    $fakeUrlService = new SrcImageTestFakeUrlService();

    KernelContainerOverride::with([UrlServiceInterface::class => $fakeUrlService], function (): void {
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

            expect(static fn () => $src->get_url())
                ->toThrow(Error::class, 'must return an instance of');
        } finally {
            EventDispatcherTestFactory::get()->removeEventHandler(GetSrcImageUrl::class, $handler);
        }
    });
});

test('get_size() persists the real, correctly-ordered width/height back onto the image repository', function (): void {
    // Kills line 300's 4 index-swap mutations (DecrementInteger/
    // IncrementInteger on $size[0]/$size[1]) -- a real image with
    // DISTINCT width/height (anti-transposition) and a real
    // ImageRepository persisting the call proves both arguments land in
    // their own correct column, not swapped or duplicated.
    $conn = DbConnection::build();
    $repo = EntityManagerFactory::build($conn)->getRepository(ImageEntity::class);
    expect($repo)->toBeInstanceOf(ImageRepository::class);

    $conn->createQueryBuilder()
        ->insert(Tables::images())
        ->values(['file' => ':file', 'path' => ':path'])
        ->setParameter('file', 'update-dimensions.jpg')
        ->setParameter('path', 'upload/2026/07/update-dimensions.jpg')
        ->executeStatement();
    $imageId = (int) $conn->lastInsertId();

    $root = sys_get_temp_dir() . '/piwigo-srcimage-test-' . bin2hex(random_bytes(8));
    srcImageTestMakePng($root . '/upload/2026/07/update-dimensions.jpg', 77, 55);

    try {
        // ImageRepository::class bound directly to this real, manually-built
        // repository instance -- get_size()'s own container resolve must
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

            expect($src->get_size())->toBe([77, 55]);
        });

        $row = $conn->fetchAssociative('SELECT width, height FROM ' . Tables::images() . " WHERE id = {$imageId}");
        expect($row)->toBe(['width' => 77, 'height' => 55]);
    } finally {
        $conn->executeStatement('DELETE FROM ' . Tables::images() . ' WHERE id = ?', [$imageId]);
        srcImageTestRrmdir($root);
    }
});
