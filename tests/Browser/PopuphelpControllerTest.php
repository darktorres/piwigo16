<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Controller\PopuphelpController (front-end popuphelp.php, distinct
 * from admin/popuphelp.php) -- renders a single help topic's real HTML
 * content from language/<lang>/help/<page>.html (falls back to
 * AppInfo::DEFAULT_LANGUAGE = en_UK, same as AboutController's about.html
 * fallback -- see language/en_UK/help/maintenance.html, a real, existing
 * help topic in this fixture).
 */
it('renders a real, existing help topic\'s content', function (): void {
    $page = H::gotoOk($this, '/popuphelp.php?page=maintenance');

    $page->assertTitleContains('Piwigo help');
    $page->assertPresent('body#thePopuphelpPage');

    // language/en_UK/help/maintenance.html's own real, specific text -- not
    // just "some content rendered".
    $page->assertSee('To optimise page generation time Piwigo uses cached information.');
});

it('renders the page shell with empty help content for a well-formed but unknown page', function (): void {
    $page = H::gotoOk($this, '/popuphelp.php?page=zzz_does_not_exist_zzz');

    $page->assertTitleContains('Piwigo help');

    // Lang::load() returns false for a page with no matching file in any
    // fallback language; PopuphelpController collapses that to '' rather
    // than erroring -- the page shell (title h2 + the "close this window"
    // markers) still renders, with no 2nd <h2> from a real help file's own
    // heading, unlike the 'maintenance' case above.
    $html = H::rawWebpage($page)->content();
    expect(substr_count($html, '<h2>'))
        ->toBe(1);
    $page->assertPresent('#closeLink');
});

it('rejects a page param with invalid characters with a 400 response', function (): void {
    // /^[a-z_]*$/ rejects digits/uppercase/path separators -- a real
    // path-traversal-shaped value exercises the guard exactly as designed.
    expect(H::httpStatus('popuphelp.php?page=../../etc/passwd'))->toBe(400);
    expect(H::httpBody('popuphelp.php?page=../../etc/passwd'))->toBe('Request rejected: invalid page parameter');
});

it('rejects a missing page param with a 400 response', function (): void {
    // $rawPage is null when the GET param is absent entirely -- !is_string()
    // fails the guard the same way a malformed string does.
    expect(H::httpStatus('popuphelp.php'))->toBe(400);
    expect(H::httpBody('popuphelp.php'))->toBe('Request rejected: invalid page parameter');
});
