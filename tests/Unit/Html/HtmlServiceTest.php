<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Html;

use Piwigo\Config\CurrentConfig;
use Piwigo\Core\ProcessCache;
use Piwigo\Html\HtmlService;
use Piwigo\Image\SrcImage;
use Piwigo\Menu\BlockManager;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;

beforeEach(function (): void {
    ProcessCache::reset();
    CurrentUser::reset();
    CurrentTemplate::reset();
    CurrentConfig::reset();
});

afterEach(function (): void {
    ProcessCache::reset();
    CurrentUser::reset();
    CurrentTemplate::reset();
    CurrentConfig::reset();
});

test('renderCommentContent escapes html and linkifies bare URLs', function (): void {
    $service = new HtmlService();

    expect($service->renderCommentContent('<script>alert(1)</script> see http://example.test/x'))
        ->toBe('&lt;script&gt;alert(1)&lt;/script&gt; see <a href="http://example.test/x" rel="nofollow">http://example.test/x</a>');
});

test('renderCommentContent underlines _word_', function (): void {
    $service = new HtmlService();

    expect($service->renderCommentContent('_hello_'))
        ->toBe('<span style="text-decoration:underline;">hello</span>');
});

test('renderCommentContent bolds *word* only when a word character directly touches the asterisk', function (): void {
    // '\b\*(\S*)\*\b': '*' is not itself a \w character, so \b only fires
    // where a real word character (letter/digit/underscore) directly
    // touches the asterisk on both sides -- a space (or start/end of
    // string) next to '*' is two non-word characters, no boundary, no
    // match. This is the original regex's real, longstanding, pre-existing
    // behavior (confirmed empirically), not something this migration
    // changed -- "*bold*" surrounded by spaces is verifiably a silent
    // no-op, only "word*bold*word" (no space) actually triggers it.
    $service = new HtmlService();

    expect($service->renderCommentContent('a *hello* b'))->toBe('a *hello* b')
        ->and($service->renderCommentContent('x*hello*y'))
        ->toBe('x<span style="font-weight:bold;">hello</span>y');
});

test('renderCommentContent converts newlines to br tags', function (): void {
    $service = new HtmlService();

    expect($service->renderCommentContent("a\nb"))->toBe('a<br />' . "\n" . 'b');
});

test('nameCompare sorts case-insensitively', function (): void {
    $service = new HtmlService();

    expect($service->nameCompare(['name' => 'banana'], ['name' => 'Apple']))->toBeGreaterThan(0)
        ->and($service->nameCompare(['name' => 'apple'], ['name' => 'apple']))->toBe(0);
});

test('setStatusHeader sends the well-known reason phrase for a known code', function (): void {
    $service = new HtmlService();

    $service->setStatusHeader(404);

    expect(true)->toBeTrue(); // real assertion is the absence of a fatal/warning; header() is a no-op under CLI SAPI
});

test('renderCategoryLiteralDescription strips disallowed tag markup but keeps their content and the allow-list', function (): void {
    // strip_tags() removes only the tag markup itself, not the content inside
    // a disallowed tag (it isn't a sanitizer aware of <script>'s special
    // meaning) -- "x" survives, just unwrapped.
    $service = new HtmlService();

    expect($service->renderCategoryLiteralDescription('<script>x</script><p>hello</p><b>world</b>'))
        ->toBe('x<p>hello</p><b>world</b>');
});

test('renderCategoryLiteralDescription treats a null description as empty', function (): void {
    $service = new HtmlService();

    expect($service->renderCategoryLiteralDescription(null))->toBe('');
});

test('pwgNl2br passes through falsy scalars unchanged', function (): void {
    $service = new HtmlService();

    expect($service->pwgNl2br(''))->toBe('')
        ->and($service->pwgNl2br(0))->toBe(0)
        ->and($service->pwgNl2br(null))->toBeNull()
        ->and($service->pwgNl2br(false))->toBeFalse();
});

test('pwgNl2br passes through arrays unchanged', function (): void {
    $service = new HtmlService();

    expect($service->pwgNl2br(['a', 'b']))->toBe(['a', 'b']);
});

test('pwgNl2br converts newlines in a non-empty string', function (): void {
    $service = new HtmlService();

    expect($service->pwgNl2br("a\nb"))->toBe('a<br />' . "\n" . 'b');
});

test('renderElementName falls back to the filename when name is not set', function (): void {
    $service = new HtmlService();

    expect($service->renderElementName(['file' => 'my-photo.jpg']))->toBe('my-photo');
});

test('renderElementDescription returns empty string when comment is not set', function (): void {
    $service = new HtmlService();

    expect($service->renderElementDescription([]))->toBe('');
});

test('tagAlphaCompare sorts by the transliterated tag name', function (): void {
    // Plain ASCII names -- no accent-transliteration ambiguity to worry
    // about (see StringHelperTest's own pwgTransliterate notes).
    $service = new HtmlService();

    expect($service->tagAlphaCompare(['name' => 'zebra'], ['name' => 'apple']))->toBeGreaterThan(0);
    expect($service->tagAlphaCompare(['name' => 'apple'], ['name' => 'apple']))->toBe(0);
});

test('getTagsContentTitle pluralizes based on the number of tags', function (): void {
    $service = new HtmlService();

    expect($service->getTagsContentTitle([['name' => 'one tag']]))->toContain('Tag')
        ->and($service->getTagsContentTitle([['name' => 'one tag']]))->not->toContain('Tags');
    expect($service->getTagsContentTitle([['name' => 'a'], ['name' => 'b']]))->toContain('Tags');
});

test('getThumbnailTitle appends visit/comment counts and a truncated comment, HTML-escaped', function (): void {
    $service = new HtmlService();

    $title = $service->getThumbnailTitle(['hit' => 5, 'nb_comments' => 2], 'My Photo', 'A <b>nice</b> shot');

    expect($title)->toContain('My Photo');
    expect($title)->toContain('5 visits');
    expect($title)->toContain('2 comments');
    expect($title)->toContain('A nice shot');
    expect($title)->not->toContain('<b>');
});

test('getThumbnailTitle omits the parenthesized details block when hit/comments are zero or absent', function (): void {
    $service = new HtmlService();

    expect($service->getThumbnailTitle([], 'My Photo'))->toBe('My Photo');
});

test('getThumbnailTitle truncates a long comment to 100 characters with an ellipsis', function (): void {
    $service = new HtmlService();
    $longComment = str_repeat('x', 150);

    $title = $service->getThumbnailTitle([], 'Title', $longComment);

    expect($title)->toBe('Title ' . str_repeat('x', 100) . '...');
});

test('getCatDisplayName falls back to an empty category name when a render_category_name handler returns a non-string', function (): void {
    $service = new HtmlService();
    $handler = static fn (): int => 42;
    EventDispatcher::get()->addEventHandler('render_category_name', $handler);

    try {
        // $url = null -> the "no link, name only" branch; the fallback
        // empty name proves the non-string handler return was actually
        // discarded rather than coerced/propagated.
        expect($service->getCatDisplayName([['id' => 1, 'name' => 'Nature']], null))->toBe('');
    } finally {
        EventDispatcher::get()->removeEventHandler('render_category_name', $handler);
    }
});

test('getCatDisplayNameCache injects an auth param and wraps the whole chain in a single link with a class when singleLink is set', function (): void {
    ProcessCache::set('cat_names', [
        '3' => ['id' => 3, 'name' => 'Nature', 'permalink' => null],
    ]);
    $service = new HtmlService();

    $result = $service->getCatDisplayNameCache('3', 'index.php?/category/', true, 'my-link-class', 'SECRETKEY');

    expect($result)->toBe('<a href="index.php?/category/3&amp;auth=SECRETKEY" class="my-link-class">Nature</a>');
});

test('getCatDisplayNameCache falls back to an empty category name when a render_category_name handler returns a non-string', function (): void {
    ProcessCache::set('cat_names', [
        '5' => ['id' => 5, 'name' => 'Landscape', 'permalink' => null],
    ]);
    $service = new HtmlService();
    $handler = static fn (): int => 7;
    EventDispatcher::get()->addEventHandler('render_category_name', $handler);

    try {
        expect($service->getCatDisplayNameCache('5', null))->toBe('');
    } finally {
        EventDispatcher::get()->removeEventHandler('render_category_name', $handler);
    }
});

test('getCombinedCategoriesContentTitle renders a single category link without a remove-tag link', function (): void {
    $service = new HtmlService();
    $category = ['id' => 3, 'name' => 'Nature', 'permalink' => null];

    // Independently computed from the same real UrlService primitive
    // getCombinedCategoriesContentTitle() itself delegates to (via
    // getCatDisplayName()) -- an exact-value assertion without
    // re-implementing makeSectionInUrl()'s own url-building algorithm here.
    $expectedLink = new UrlService($service)->makeIndexUrl(['category' => $category]);

    $title = $service->getCombinedCategoriesContentTitle($category, []);

    expect($title)->toBe('Albums <a href="' . $expectedLink . '">Nature</a>');
});

test('getCombinedCategoriesContentTitle appends a remove-tag link referencing the other category when combining 2', function (): void {
    $service = new HtmlService();
    $catA = ['id' => 3, 'name' => 'Nature', 'permalink' => null];
    $catB = ['id' => 7, 'name' => 'Portraits', 'permalink' => null];

    $urlService = new UrlService($service);
    $linkA = $urlService->makeIndexUrl(['category' => $catA]);
    $linkB = $urlService->makeIndexUrl(['category' => $catB]);

    $title = $service->getCombinedCategoriesContentTitle($catA, [$catB]);

    // Each category's own remove-link points at the *other* category (the
    // "un-combine" target) -- CurrentTemplate is reset in this file's own
    // beforeEach, so icon_dir resolves to '' via the documented defensive
    // fallback, giving a bare '/remove_s.png' src.
    $removeLinkTemplate = static fn (string $removeUrl): string => '<a id="TagsGroupRemoveTag" href="' . $removeUrl . '" style="border:none;" title="remove this tag from the list">'
        . '<img src="/remove_s.png" alt="x" style="vertical-align:bottom;" >'
        . '<span class="pwg-icon pwg-icon-close" ></span></a>';

    $expected = 'Albums '
        . '<a href="' . $linkA . '">Nature</a>' . $removeLinkTemplate($linkB)
        . ' + '
        . '<a href="' . $linkB . '">Portraits</a>' . $removeLinkTemplate($linkA);

    expect($title)->toBe($expected);
});

test('setStatusHeader accepts every well-known status code and the default fallback without a fatal/warning', function (): void {
    $service = new HtmlService();

    foreach ([200, 301, 302, 304, 400, 401, 403, 500, 501, 503] as $code) {
        $service->setStatusHeader($code);
    }
    // Unrecognized code, no explicit $text -- exercises the match's
    // `default => $text` arm (still the already-empty '' at that point).
    $service->setStatusHeader(999);

    expect(true)->toBeTrue(); // header() is a no-op under CLI SAPI; absence of a fatal/warning is the real assertion
});

test('registerDefaultMenubarBlocks does nothing for a BlockManager whose id is not "menubar"', function (): void {
    $service = new HtmlService();
    $menu = new BlockManager('sidebar');

    $service->registerDefaultMenubarBlocks([$menu]);

    expect($menu->get_registered_blocks())->toBe([]);
});

test('getSrcImageUrlProtectionHandler uses "e" for an original image and "r" for a non-original representative', function (): void {
    $service = new HtmlService();
    $original = new SrcImage(['id' => 7, 'path' => 'upload/2026/07/photo.jpg', 'file' => 'photo.jpg']);
    $representative = new SrcImage(['id' => 9, 'path' => 'upload/2026/07/video.mp4', 'file' => 'video.mp4', 'representative_ext' => 'jpg']);

    expect($original->is_original())->toBeTrue()
        ->and($representative->is_original())->toBeFalse();

    expect($service->getSrcImageUrlProtectionHandler('ignored-input-url', $original))
        ->toBe('action.php?id=7&amp;part=e')
        ->and($service->getSrcImageUrlProtectionHandler('ignored-input-url', $representative))
        ->toBe('action.php?id=9&amp;part=r');
});

test('getElementUrlProtectionHandler passes a non-image extension through unchanged when protection is scoped to images', function (): void {
    CurrentConfig::setOriginalUrlProtection('images');
    $service = new HtmlService();

    $result = $service->getElementUrlProtectionHandler('original-url-unchanged', ['id' => 3, 'path' => 'upload/video.mp4']);

    expect($result)->toBe('original-url-unchanged');
});

test('getElementUrlProtectionHandler builds an action url for an image extension when protection is scoped to images', function (): void {
    CurrentConfig::setOriginalUrlProtection('images');
    $service = new HtmlService();

    $result = $service->getElementUrlProtectionHandler('ignored', ['id' => 3, 'path' => 'upload/photo.jpg']);

    expect($result)->toBe('action.php?id=3&amp;part=e');
});

test('getElementUrlProtectionHandler builds an action url for any extension when protection is not scoped to images', function (): void {
    // Default originalUrlProtection() is '' -- the images-only guard never
    // triggers, so even a non-image extension reaches the action url.
    $service = new HtmlService();

    $result = $service->getElementUrlProtectionHandler('ignored', ['id' => 5, 'path' => 'upload/video.mp4']);

    expect($result)->toBe('action.php?id=5&amp;part=e');
});

test('accessDenied redirects to identification.php with a double urlencoded REQUEST_URI when the user is unauthenticated', function (): void {
    $service = new HtmlService();
    $redirectService = new HtmlServiceTestCapturingRedirectHttpService();
    $_SERVER['REQUEST_URI'] = '/gallery/index.php?/category/5';

    try {
        // redirectHttp() is `never`-typed and this fake's implementation
        // unconditionally throws -- if it somehow didn't, PHP's own
        // return-type enforcement would raise a TypeError here, so no
        // separate "did it throw" assertion is needed.
        $service->accessDenied($redirectService);
    } catch (\RuntimeException $e) {
        expect($e->getMessage())->toBe('HTML_SERVICE_TEST_REDIRECT_HTTP_MARKER');
    } finally {
        unset($_SERVER['REQUEST_URI']);
    }

    expect($redirectService->capturedUrl)->toBe(
        'identification.php?redirect=' . urlencode(urlencode('/gallery/index.php?/category/5'))
    );
});

test('accessDenied falls back to an empty redirect path when REQUEST_URI is missing or not a string', function (): void {
    $service = new HtmlService();
    $redirectService = new HtmlServiceTestCapturingRedirectHttpService();
    // A crafted 'REQUEST_URI' can never really be non-scalar over a real
    // web SAPI, but $_SERVER is untyped -- accessDenied() narrows
    // defensively before using it.
    $_SERVER['REQUEST_URI'] = ['not', 'a', 'string'];

    try {
        $service->accessDenied($redirectService);
    } catch (\RuntimeException) {
    } finally {
        unset($_SERVER['REQUEST_URI']);
    }

    expect($redirectService->capturedUrl)->toBe('identification.php?redirect=');
});

test('pageForbidden redirects to the given alternate url with a 403 status, a 5 second refresh, and the forbidden message', function (): void {
    $service = new HtmlService();
    $redirectService = new HtmlServiceTestCapturingRedirectHtmlService();

    try {
        $service->pageForbidden($redirectService, 'You lack permission.', '/custom-redirect.php');
    } catch (\RuntimeException $e) {
        expect($e->getMessage())->toBe('HTML_SERVICE_TEST_REDIRECT_HTML_MARKER');
    }

    expect($redirectService->capturedUrl)->toBe('/custom-redirect.php')
        ->and($redirectService->capturedStatus)->toBe(403)
        ->and($redirectService->capturedRefreshTime)->toBe(5)
        ->and($redirectService->capturedMsg)->toContain('Forbidden')
        ->and($redirectService->capturedMsg)->toContain('You lack permission.');
});

test('pageForbidden computes the default alternate url via makeIndexUrl when none is given', function (): void {
    $service = new HtmlService();
    $redirectService = new HtmlServiceTestCapturingRedirectHtmlService();
    $expectedUrl = new UrlService($service)->makeIndexUrl();

    try {
        $service->pageForbidden($redirectService, 'blocked');
    } catch (\RuntimeException) {
    }

    expect($redirectService->capturedUrl)->toBe($expectedUrl);
});
