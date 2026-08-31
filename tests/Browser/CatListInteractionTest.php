<?php

declare(strict_types=1);

use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Api\Webpage;
use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * P49-A conversion of themes/admin/default/js/cat_list.ts --
 * CatListPageRendererTest.php only covers the server-rendered album list
 * itself; this file covers the client-side-only behaviors the conversion
 * touched: the compact/line/tile view switch (each its own heavy
 * `.css({...})` restyle plus class churn) and the add-album input-mode
 * reveal/cancel.
 *
 * `$.cookie(...)` converted to `cookie()`/`setCookie()`
 * (`themes/default/js/vendor/cookie.ts`) in P49-B group 2.
 */
it('switches between compact/line/tile views, restyling categoryBox and its classes', function (): void {
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Cat List Interaction Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }

    $page = H::navigateOk($page, '/admin.php?page=cat_list');
    $page->assertPresent('.categoryBox');

    $page->click('label[for=displayLine]');
    expect($page->script("document.querySelector('.categoryBox').classList.contains('line_cat')"))
        ->toBeTrue();
    expect($page->script("document.querySelector('.categoryBox').classList.contains('tile_cat')"))
        ->toBeFalse();
    expect($page->script("getComputedStyle(document.querySelector('.categoryBox')).minWidth"))
        ->toBe('90%');

    $page->click('label[for=displayCompact]');
    expect($page->script("document.querySelector('.categoryBox').classList.contains('line_cat')"))
        ->toBeFalse();
    expect($page->script("document.querySelector('.categoryBox').classList.contains('tile_cat')"))
        ->toBeFalse();
    expect($page->script("getComputedStyle(document.querySelector('.categoryBox')).minWidth"))
        ->toBe('250px');

    $page->click('label[for=displayTile]');
    expect($page->script("document.querySelector('.categoryBox').classList.contains('tile_cat')"))
        ->toBeTrue();
    expect($page->script("getComputedStyle(document.querySelector('.categoryBox')).minWidth"))
        ->toBe('220px');

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'cat_list view switch');
});

it('hovering a categoryBox in line view recolors it and marks its icon span', function (): void {
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Cat List Hover Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }

    $page = H::navigateOk($page, '/admin.php?page=cat_list');
    $page->click('label[for=displayLine]');
    $page->assertPresent('.categoryBox.line_cat');

    $iconSpanHovered = static fn (Webpage|PendingAwaitablePage|AwaitableWebpage $page): mixed => $page->script(
        "document.querySelector('.categoryBox .albumTop .albumIcon span').classList.contains('albumIconLineHover')",
    );

    expect($iconSpanHovered($page))
        ->toBeFalse();

    $page->script(
        "document.querySelector('.categoryBox').dispatchEvent(new MouseEvent('mouseenter', {bubbles: false}))",
    );
    expect($iconSpanHovered($page))
        ->toBeTrue();
    expect($page->script("getComputedStyle(document.querySelector('.categoryBox')).backgroundColor"))
        ->toBe('rgb(255, 215, 173)');

    $page->script(
        "document.querySelector('.categoryBox').dispatchEvent(new MouseEvent('mouseleave', {bubbles: false}))",
    );
    expect($iconSpanHovered($page))
        ->toBeFalse();
    expect($page->script("getComputedStyle(document.querySelector('.categoryBox')).backgroundColor"))
        ->toBe('rgb(250, 250, 250)');

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'cat_list line-view hover');
});

it('reveals the add-album input mode on click, and cancels back out of it', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=cat_list');

    expect($page->script("document.querySelector('.addAlbum').classList.contains('input-mode')"))
        ->toBeFalse();

    // A single click bubbles from .addAlbumHead up to .addAlbum's own
    // click listener (bound directly on .addAlbum, not delegated) --
    // matching the original jQuery handler's identical bubbling, this one
    // click both focuses the name field and flips input-mode on.
    $page->click('.addAlbumHead');
    expect($page->script('document.activeElement.name'))
        ->toBe('virtual_name');
    expect($page->script("document.querySelector('.addAlbum').classList.contains('input-mode')"))
        ->toBeTrue();

    $page->click('.cancelAddAlbum');
    expect($page->script("document.querySelector('.addAlbum').classList.contains('input-mode')"))
        ->toBeFalse();

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'cat_list add-album input mode');
});

it('persists the view-mode cookie across a real page reload', function (): void {
    // cat_list.ts's own setCookie() (themes/default/js/vendor/cookie.ts,
    // ported off jquery.cookie in P49-B group 2) writes the cookie the
    // SERVER reads back (CookieService, src/Piwigo/Auth/CookieService.php)
    // to decide which radio is checked on load. CatListPageRendererTest.php's
    // own coverage of that server-side read only ever writes the cookie by
    // hand (a raw `document.cookie = ...` string already matching the
    // format PHP expects) -- this is the other half: a real click ->
    // setCookie() -> a real second page load, round-tripping through
    // setCookie()'s own encoding rather than a hand-written stand-in for it.
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=cat_list');

    $page->click('label[for=displayLine]');
    expect($page->script("document.querySelector('.categoryBox').classList.contains('line_cat')"))
        ->toBeTrue();

    $page = H::navigateOk($page, '/admin.php?page=cat_list');

    expect($page->script("document.getElementById('displayLine').checked"))
        ->toBeTrue();

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'cat_list view-mode cookie round trip');
});
