<?php

declare(strict_types=1);

use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Paths;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\ThemeConfProviderInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Image\SrcImage;

/**
 * Piwigo\Image\SrcImage had zero dedicated test file. Covers: the 3
 * static-setter-guarded accessors' not-set RuntimeException (fatalError()/
 * themeConf()/urlService()); the constructor's representative_ext branch,
 * its full mimetype-icon lookup (icon found, icon missing -> falls back to
 * 'unknown.png', and the ext==='svg' special-case that falls back to the
 * original path instead -- including that path's own "still not a real
 * image" Exception), and its rotation-swap branch for width/height; get_path();
 * get_url()'s mimetype-icon branch; and get_size()'s DIM_NOT_GIVEN
 * fatalError() (both with and without an installed HtmlRenderingInterface)
 * plus its live getimagesize() re-read.
 */
function srcImageTestSetHtmlRenderer(?HtmlRenderingInterface $renderer): void
{
    new ReflectionProperty(SrcImage::class, 'htmlRenderer')->setValue(null, $renderer);
}

function srcImageTestSetThemeConfProvider(?ThemeConfProviderInterface $provider): void
{
    new ReflectionProperty(SrcImage::class, 'themeConfProvider')->setValue(null, $provider);
}

function srcImageTestSetUrlService(?UrlServiceInterface $service): void
{
    new ReflectionProperty(SrcImage::class, 'urlService')->setValue(null, $service);
}

function srcImageTestSetImageRepository(?\Piwigo\Image\ImageRepository $repo): void
{
    new ReflectionProperty(SrcImage::class, 'imageRepository')->setValue(null, $repo);
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

final class SrcImageTestFatalSignal extends \Exception
{
}

final class SrcImageTestFatalRenderer implements HtmlRenderingInterface
{
    public ?string $lastMessage = null;

    #[\Override]
    public function getCatDisplayName(array $catInformations, ?string $url = ''): string
    {
        return '';
    }

    #[\Override]
    public function getCatDisplayNameCache(
        string $uppercats,
        ?string $url = '',
        bool $singleLink = false,
        ?string $linkClass = null,
        ?string $authKey = null,
    ): string {
        return '';
    }

    #[\Override]
    public function nameCompare(array $a, array $b): int
    {
        return 0;
    }

    #[\Override]
    public function tagAlphaCompare(array $a, array $b): int
    {
        return 0;
    }

    #[\Override]
    public function accessDenied(RedirectServiceInterface $redirectService): never
    {
        throw new SrcImageTestFatalSignal('accessDenied');
    }

    #[\Override]
    public function badRequest(RedirectServiceInterface $redirectService, string $msg, ?string $alternateUrl = null): never
    {
        throw new SrcImageTestFatalSignal('badRequest');
    }

    #[\Override]
    public function pageNotFound(RedirectServiceInterface $redirectService, ?string $msg, ?string $alternateUrl = null): never
    {
        throw new SrcImageTestFatalSignal('pageNotFound');
    }

    #[\Override]
    public function fatalError(string $msg, ?string $title = null, bool $showTrace = true): never
    {
        $this->lastMessage = $msg;

        throw new SrcImageTestFatalSignal('fatalError:' . $msg);
    }

    #[\Override]
    public function getTagsContentTitle(array $tags): string
    {
        return '';
    }

    #[\Override]
    public function getCombinedCategoriesContentTitle(?array $category, array $combinedCategories): string
    {
        return '';
    }

    #[\Override]
    public function setStatusHeader(int $code, string $text = ''): void {}

    #[\Override]
    public function renderElementName(array $info): string
    {
        return '';
    }

    #[\Override]
    public function renderElementDescription(array $info, string $param = ''): string
    {
        return '';
    }

    #[\Override]
    public function getThumbnailTitle(array $info, string $title, string $comment = ''): string
    {
        return '';
    }
}

final class SrcImageTestFakeThemeConfProvider implements ThemeConfProviderInterface
{
    public function __construct(private readonly string $mimeIconDir) {}

    #[\Override]
    public function themeConf(string $key): string
    {
        return $key === 'mime_icon_dir' ? $this->mimeIconDir : '';
    }
}

/**
 * getRootUrl() returns a fixed, non-empty prefix and embellishUrl() is the
 * identity function, so get_url() assertions below can check the exact
 * concatenation.
 */
final class SrcImageTestFakeUrlService implements UrlServiceInterface
{
    #[\Override]
    public function getRootUrl(): string
    {
        return '/root/';
    }

    #[\Override]
    public function getAbsoluteRootUrl(bool $withScheme = true): string
    {
        throw new \LogicException('not used');
    }

    #[\Override]
    public function addUrlParams(string $url, array $params, string $argSeparator = '&amp;'): string
    {
        throw new \LogicException('not used');
    }

    #[\Override]
    public function makeIndexUrl(array $params = []): string
    {
        throw new \LogicException('not used');
    }

    #[\Override]
    public function duplicateIndexUrl(array $redefined = [], array $removed = []): string
    {
        throw new \LogicException('not used');
    }

    #[\Override]
    public function duplicatePictureUrl(array $redefined = [], array $removed = []): string
    {
        throw new \LogicException('not used');
    }

    #[\Override]
    public function makePictureUrl(array $params): string
    {
        throw new \LogicException('not used');
    }

    #[\Override]
    public function parseSectionUrl(array $tokens, &$nextToken, RedirectServiceInterface $redirectService): array
    {
        throw new \LogicException('not used');
    }

    #[\Override]
    public function parseWellKnownParamsUrl(array $tokens, int &$i): array
    {
        throw new \LogicException('not used');
    }

    #[\Override]
    public function getActionUrl($id, $whatPart, bool $download): string
    {
        throw new \LogicException('not used by the mimetype-icon branch');
    }

    #[\Override]
    public function getElementUrl(array $elementInfo): string
    {
        throw new \LogicException('not used');
    }

    #[\Override]
    public function setMakeFullUrl(): void {}

    #[\Override]
    public function unsetMakeFullUrl(): void {}

    #[\Override]
    public function embellishUrl(string $url): string
    {
        return $url;
    }

    #[\Override]
    public function getGalleryHomeUrl(): string
    {
        throw new \LogicException('not used');
    }

    #[\Override]
    public function getQueryStringDiff(array $rejects = [], bool $escape = true): string
    {
        throw new \LogicException('not used');
    }

    #[\Override]
    public function urlIsRemote(string $url): bool
    {
        return false;
    }

    #[\Override]
    public function getUserFavorites(): array
    {
        throw new \LogicException('not used');
    }
}

beforeEach(function (): void {
    CurrentConfig::reset();
});

afterEach(function (): void {
    CurrentConfig::reset();
    CurrentPaths::reset();
    srcImageTestSetHtmlRenderer(null);
    srcImageTestSetThemeConfProvider(null);
    srcImageTestSetUrlService(null);
    srcImageTestSetImageRepository(null);
});

test('themeConf() throws a RuntimeException when no ThemeConfProviderInterface has been installed yet', function (): void {
    srcImageTestSetThemeConfProvider(null);

    expect(fn () => new SrcImage([
        'id' => 1,
        'path' => 'upload/2026/07/doc.pdf',
        'file' => 'doc.pdf',
    ]))->toThrow(\RuntimeException::class, 'SrcImage: no theme-conf provider set (Template not constructed yet?)');
});

test('urlService() throws a RuntimeException when no UrlServiceInterface has been installed yet', function (): void {
    srcImageTestSetUrlService(null);

    $src = new SrcImage([
        'id' => 1,
        'path' => 'upload/2026/07/photo.jpg',
        'file' => 'photo.jpg',
    ]);

    expect(fn () => $src->get_url())
        ->toThrow(\RuntimeException::class, 'SrcImage: no URL service set (RequestBootstrap not run yet?)');
});

test('get_size() throws a RuntimeException carrying the untranslated message when dimensions are required but not provided and no HtmlRenderingInterface is installed', function (): void {
    srcImageTestSetHtmlRenderer(null);

    $src = new SrcImage([
        'id' => 5,
        'path' => 'upload/2026/07/photo.jpg',
        'file' => 'photo.jpg',
    ]);

    expect(fn () => $src->get_size())
        ->toThrow(\RuntimeException::class, 'SrcImage dimensions required but not provided');
});

test('get_size() delegates the fatal message to the installed HtmlRenderingInterface instead of throwing RuntimeException directly', function (): void {
    $renderer = new SrcImageTestFatalRenderer();
    srcImageTestSetHtmlRenderer($renderer);

    $src = new SrcImage([
        'id' => 5,
        'path' => 'upload/2026/07/photo.jpg',
        'file' => 'photo.jpg',
    ]);

    expect(fn () => $src->get_size())->toThrow(SrcImageTestFatalSignal::class);
    expect($renderer->lastMessage)->toBe('SrcImage dimensions required but not provided');
});

test('constructor builds a pwg_representative path when the extension is not a picture extension but a representative_ext is given', function (): void {
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

test('constructor swaps width/height for an odd rotation code but not for an even one', function (): void {
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
    CurrentPaths::set(Paths::fromRoot(sys_get_temp_dir() . '/piwigo-srcimage-test-path-only'));

    $src = new SrcImage([
        'id' => 1,
        'path' => 'upload/2026/07/photo.jpg',
        'file' => 'photo.jpg',
    ]);

    expect($src->get_path())->toBe(CurrentPaths::get()->root . 'upload/2026/07/photo.jpg');
});

test('constructor finds a real per-extension mimetype icon, and get_url() embellishes the root-relative icon url', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-srcimage-test-' . bin2hex(random_bytes(8));
    CurrentPaths::set(Paths::fromRoot($root));
    srcImageTestSetThemeConfProvider(new SrcImageTestFakeThemeConfProvider('themes/default/icon/mimetypes/'));
    srcImageTestSetUrlService(new SrcImageTestFakeUrlService());
    srcImageTestMakePng($root . '/themes/default/icon/mimetypes/zzz.png', 16, 12);

    try {
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
    } finally {
        srcImageTestRrmdir($root);
    }
});

test('constructor falls back to the shared unknown.png icon when no icon exists for the extension', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-srcimage-test-' . bin2hex(random_bytes(8));
    CurrentPaths::set(Paths::fromRoot($root));
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
    CurrentPaths::set(Paths::fromRoot($root));
    srcImageTestSetThemeConfProvider(new SrcImageTestFakeThemeConfProvider('themes/default/icon/mimetypes/'));
    mkdir($root . '/upload/2026/07', 0o777, true);
    file_put_contents($root . '/upload/2026/07/vector.svg', 'not-a-real-image-payload');

    try {
        expect(fn () => new SrcImage([
            'id' => 1,
            'path' => 'upload/2026/07/vector.svg',
            'file' => 'vector.svg',
        ]))->toThrow(\Exception::class, 'SrcImage: unable to read size of fallback icon upload/2026/07/vector.svg');
    } finally {
        srcImageTestRrmdir($root);
    }
});

test('get_size() re-reads real dimensions from disk when width/height columns are present but null (not yet metadata-synced)', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-srcimage-test-' . bin2hex(random_bytes(8));
    CurrentPaths::set(Paths::fromRoot($root));
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
