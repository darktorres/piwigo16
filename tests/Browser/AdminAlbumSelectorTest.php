<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

// The album selector is a ~950-line widget shared by seven admin entries
// (photo add, photo editor, album edit, both batch managers, the batch
// filter panel and the gallery search). It had no interaction coverage of
// any kind.
//
// Everything it does is invisible to the snapshot instruments: the popup is
// rendered hidden and empty, and every row inside it is fetched over the API
// and built from a template string after a click. Golden-html captures the
// empty shell; visual regression photographs the page with the popup closed.

/**
 * The popup fades in, and its rows arrive over the API, so both need
 * waiting on rather than racing.
 */
function albumSelectorOpen(object $page, string $trigger): void
{
    $page->click($trigger);

    $timeoutMs = 10000;
    $page->script(<<<JS
    new Promise((resolve, reject) => {
        const deadline = Date.now() + {$timeoutMs};
        const check = () => {
            const popup = document.getElementById('addLinkedAlbum');
            const rows = document.querySelectorAll('#searchResult .search-result-item');
            if (popup !== null && getComputedStyle(popup).display !== 'none' && rows.length > 0) {
                return resolve(true);
            }
            if (Date.now() > deadline) {
                return reject(new Error('Timed out waiting for the album selector to open and fill'));
            }
            setTimeout(check, 100);
        };
        check();
    })
    JS);
}


/**
 * Searches for an album by name and clicks its row, then waits for the
 * breadcrumb it adds.
 *
 * Searching rather than picking from the prefill list, and using a
 * purpose-made album rather than whatever is first: `add_related_category`
 * silently skips an album the photo already belongs to, so a pre-existing
 * breadcrumb would otherwise satisfy the assertion with the JS never having
 * run. The search path is also the one that binds `.search-result-item`
 * (`#loadFillResultEvent`); the prefill path binds the inner
 * `.prefill-results-item` instead.
 */
function albumSelectorAddByName(object $page, string $name): string
{
    /** @var int $before */
    $before = $page->script(
        "document.querySelectorAll('.related-categories-container .link-path').length"
    );

    $page->fill('#search-input-ab', $name);
    $page->script(
        "document.getElementById('search-input-ab').dispatchEvent(new KeyboardEvent('keyup', {bubbles: true}))"
    );

    $encoded = json_encode($name, JSON_THROW_ON_ERROR);
    $timeoutMs = 10000;
    $page->script(<<<JS
    new Promise((resolve, reject) => {
        const deadline = Date.now() + {$timeoutMs};
        const wanted = {$encoded};
        const check = () => {
            // `.not-rtl` marks a row built by the *search* renderer. The
            // prefill renderer builds a different shape and binds the
            // inner `.prefill-results-item` instead, so clicking its outer
            // row does nothing -- and the prefill list already contains
            // this album, which is exactly the trap.
            const row = Array.from(
                document.querySelectorAll('#searchResult .search-result-item')
            ).find(
                r => r.querySelector('.search-result-path.not-rtl') !== null &&
                    r.textContent.includes(wanted)
            );
            if (row !== undefined) {
                row.click();

                return resolve(true);
            }
            if (Date.now() > deadline) {
                return reject(new Error('Timed out waiting for the album search result'));
            }
            setTimeout(check, 100);
        };
        check();
    })
    JS);

    $page->script(<<<JS
    new Promise((resolve, reject) => {
        const deadline = Date.now() + {$timeoutMs};
        const check = () => {
            if (document.querySelectorAll('.related-categories-container .link-path').length > {$before}) {
                return resolve(true);
            }
            if (Date.now() > deadline) {
                return reject(new Error('Timed out waiting for the album breadcrumb to be added'));
            }
            setTimeout(check, 100);
        };
        check();
    })
    JS);

    /** @var string $text */
    $text = $page->script(<<<'JS'
    (() => {
        const paths = document.querySelectorAll('.related-categories-container .link-path');
        const last = paths[paths.length - 1];

        return last === undefined ? '' : last.textContent.trim();
    })()
    JS);

    return $text;
}

it('opens, prefills the album list from the API and closes again', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=photos_add');

    // Closed and empty as rendered.
    $page->assertMissing('#addLinkedAlbum');

    /** @var int $before */
    $before = $page->script(
        "document.querySelectorAll('#searchResult .search-result-item').length"
    );
    expect($before)->toBe(0);

    // `#btnPhotosAS` sits inside a `u-hidden` block whenever an album is
    // already selected, which the fixture always is. The pencil beside the
    // selected album is the reachable trigger.
    albumSelectorOpen($page, '#selectedAlbumEdit');

    /** @var array{rows: int, title: string, firstId: string, hasAdd: bool} $opened */
    $opened = $page->script(<<<'JS'
    (() => {
        const rows = document.querySelectorAll('#searchResult .search-result-item');
        const first = rows[0];

        return {
            rows: rows.length,
            title: document.getElementById('linkedModalTitle').textContent.trim(),
            firstId: first ? first.getAttribute('id') : '',
            hasAdd: first ? first.querySelector('.item-add') !== null : false,
        };
    })()
    JS);

    // Rows were built from the template and carry the album id the click
    // handler reads back off them.
    expect($opened['rows'])->toBeGreaterThan(0);
    expect($opened['firstId'])->not->toBe('');
    expect($opened['hasAdd'])->toBeTrue();

    // The modal title is written on open from the instance's own option.
    expect($opened['title'])->not->toBe('');

    $page->click('#closeAlbumPopIn');
    $page->assertMissing('#addLinkedAlbum');

    $page->assertNoJavaScriptErrors();
});

it('closes on Escape but not on other keys', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=photos_add');

    // `#btnPhotosAS` sits inside a `u-hidden` block whenever an album is
    // already selected, which the fixture always is. The pencil beside the
    // selected album is the reachable trigger.
    albumSelectorOpen($page, '#selectedAlbumEdit');

    // The keyup handler is bound to the document under this instance's own
    // namespace, and it checks the popup is actually on screen first.
    $page->script(
        "document.dispatchEvent(new KeyboardEvent('keyup', {key: 'a', bubbles: true}))"
    );
    $page->assertVisible('#addLinkedAlbum');

    $page->script(
        "document.dispatchEvent(new KeyboardEvent('keyup', {key: 'Escape', bubbles: true}))"
    );
    $page->assertMissing('#addLinkedAlbum');

    $page->assertNoJavaScriptErrors();
});

it('searches albums by name and reports the count', function (): void {
    $db = H::connect();

    try {
        $album = H::dbFetchAssoc(
            $db,
            'SELECT id, name FROM categories ORDER BY id LIMIT 1'
        );
        $albumName = (string) $album['name'];
    } finally {
        H::dbClose($db);
    }

    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=photos_add');

    // `#btnPhotosAS` sits inside a `u-hidden` block whenever an album is
    // already selected, which the fixture always is. The pencil beside the
    // selected album is the reachable trigger.
    albumSelectorOpen($page, '#selectedAlbumEdit');

    // Typing runs a fresh API search and replaces the prefilled list. The
    // handler is on keyup, so dispatch one rather than relying on fill().
    $page->fill('#search-input-ab', $albumName);
    $page->script(
        "document.getElementById('search-input-ab').dispatchEvent(new KeyboardEvent('keyup', {bubbles: true}))"
    );

    $timeoutMs = 10000;
    $page->script(<<<JS
    new Promise((resolve, reject) => {
        const deadline = Date.now() + {$timeoutMs};
        const check = () => {
            const limit = document.querySelector('.limitReached');
            if (limit !== null && /\\d/.test(limit.textContent)) {
                return resolve(true);
            }
            if (Date.now() > deadline) {
                return reject(new Error('Timed out waiting for the album search to report a count'));
            }
            setTimeout(check, 100);
        };
        check();
    })
    JS);

    /** @var array{rows: int, count: string, cancelShown: bool} $searched */
    $searched = $page->script(<<<'JS'
    (() => {
        const cancel = document.querySelector('.search-cancel-linked-album');

        return {
            rows: document.querySelectorAll('#searchResult .search-result-item').length,
            count: document.querySelector('.limitReached').textContent.trim(),
            cancelShown: cancel !== null && getComputedStyle(cancel).display !== 'none',
        };
    })()
    JS);

    expect($searched['rows'])->toBeGreaterThan(0);
    expect($searched['count'])->toMatch('/\d/');

    // The clear button is shown only while the box has something in it.
    expect($searched['cancelShown'])->toBeTrue();

    $page->assertNoJavaScriptErrors();
});

it('selects an album and hands it back to the page', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=photos_add');

    // `#btnPhotosAS` sits inside a `u-hidden` block whenever an album is
    // already selected, which the fixture always is. The pencil beside the
    // selected album is the reachable trigger.
    albumSelectorOpen($page, '#selectedAlbumEdit');

    /** @var string $firstId */
    $firstId = $page->script(
        "document.querySelector('#searchResult .search-result-item').getAttribute('id')"
    );

    // Attribute form, not `#{$firstId}`: album ids are numbers, and `#1`
    // is not a valid CSS selector.
    $page->click("#searchResult .search-result-item[id='{$firstId}']");

    // Choosing an album closes the popup and reports the choice back
    // through the instance's own selectAlbum callback.
    $page->assertMissing('#addLinkedAlbum');

    // The choice is reported back through the instance's own selectAlbum
    // callback, which on this page swaps the "choose an album" prompt for
    // the selected-album block and enables the uploader.
    /** @var array{promptHidden: bool, selectedShown: bool, label: string} $reported */
    $reported = $page->script(<<<'JS'
    (() => {
        const prompt = document.getElementById('addPhotosAS');
        const selected = document.getElementById('selectedAlbum');
        const label = document.getElementById('selectedAlbumName');

        return {
            promptHidden: prompt === null || getComputedStyle(prompt).display === 'none',
            selectedShown: selected !== null && getComputedStyle(selected).display !== 'none',
            label: label === null ? '' : label.textContent.trim(),
        };
    })()
    JS);

    expect($reported['promptHidden'])->toBeTrue();
    expect($reported['selectedShown'])->toBeTrue();

    // The label now names the album. It used to come out empty:
    // photos_add_direct built it from `album.full_name_with_admin_links`,
    // a field CategoryListController deliberately dropped in the rewrite,
    // so the read was always undefined. Three other admin entries had the
    // same dead read -- cat_modify blanked, picture_modify and
    // batchManagerUnit interpolated the literal "undefined" into a
    // `.link-path` span. All four now use `fullname`, the same breadcrumb
    // already HTML-stripped by the server.
    expect($reported['label'])->not->toBe('');
    expect($reported['label'])->not->toContain('undefined');

    $page->assertNoJavaScriptErrors();
});

it('expands a sub-album row in place', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=photos_add');

    albumSelectorOpen($page, '#selectedAlbumEdit');

    /** @var string $parentId */
    $parentId = $page->script(
        "(document.querySelector('#searchResult .display-subcat') || {}).id || ''"
    );

    // Only albums that actually have children get the expand arrow.
    expect($parentId)->not->toBe('');

    // This is the path that builds selectors out of the album id --
    // `#<id>.display-subcat` and `#<id>.search-result-item`. Album ids are
    // numbers, so those are invalid CSS and `querySelectorAll` throws on
    // them unescaped, where jQuery's own engine accepted them. Nothing else
    // in this file reaches that code.
    $page->click("#searchResult .display-subcat[id='{$parentId}']");

    $timeoutMs = 10000;
    $page->script(<<<JS
    new Promise((resolve, reject) => {
        const deadline = Date.now() + {$timeoutMs};
        const check = () => {
            const box = document.querySelector('[id="subcat-{$parentId}"]');
            if (box !== null && box.querySelectorAll('.search-result-item').length > 0) {
                return resolve(true);
            }
            if (Date.now() > deadline) {
                return reject(new Error('Timed out waiting for the sub-album rows'));
            }
            setTimeout(check, 100);
        };
        check();
    })
    JS);

    /** @var array{children: int, open: bool, margin: string} $expanded */
    $expanded = $page->script(<<<JS
    (() => {
        const box = document.querySelector('[id="subcat-{$parentId}"]');
        const arrow = document.querySelector('#searchResult .display-subcat[id="{$parentId}"]');
        const child = box.querySelector('.search-result-item');

        return {
            children: box.querySelectorAll('.search-result-item').length,
            open: arrow.classList.contains('open'),
            margin: child === null ? '' : child.style.marginLeft,
        };
    })()
    JS);

    expect($expanded['children'])->toBeGreaterThan(0);
    expect($expanded['open'])->toBeTrue();

    // Children are indented by reading the parent row's own computed
    // margin and adding to it, which is the other id-built selector.
    expect($expanded['margin'])->not->toBe('');

    $page->assertNoJavaScriptErrors();
});

// The other two consumers interpolate the album's breadcrumb straight into
// markup rather than into an element's text, so a missing field showed up as
// the literal string "undefined" on the page rather than as a blank. Both
// pages are covered here because the field they read was the same dead one.

it('names the album in the photo editor rather than writing "undefined"', function (): void {
    $page = H::asAdmin($this);

    // A fresh album, so the photo is definitely not already in it.
    $albumName = 'Album Selector Editor Album ' . uniqid();
    H::createCategory($page, ['name' => $albumName]);

    $page = H::navigateOk($page, '/admin.php?page=photo-1');

    albumSelectorOpen($page, '.linked-albums.add-item');

    $text = albumSelectorAddByName($page, $albumName);

    expect($text)->toContain($albumName);
    expect($text)->not->toBe('undefined');

    $page->assertNoJavaScriptErrors();
});

it('names the album in the unit batch manager rather than writing "undefined"', function (): void {
    $page = H::asAdmin($this);

    // The unit batch manager renders per-photo fieldsets only for photos in
    // the current filter, and the default prefilter (the caddie) is empty --
    // so the page carries no album control at all until a filter is set.
    // Same setup BatchManagerUnitPageRendererTest uses to reach it.
    $album = H::createCategory($page, [
        'name' => 'Album Selector Batch Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    $image = H::makeTestImage(uniqid());
    H::uploadPhotoViaApi($image, $albumId, 'Album Selector Batch Photo');
    @unlink($image);

    $filterResult = H::adminPost($page, '/admin.php?page=batch_manager', [
        'pwg_token' => H::pwgToken($page),
        'submitFilter' => '1',
        'filter_category_use' => '1',
        'filter_category' => (string) $albumId,
    ]);
    expect($filterResult['status'])->toBe(200);

    $page = H::navigateOk($page, '/admin.php?page=batch_manager&mode=unit');

    // A second album, one the photo is not already in.
    $targetName = 'Album Selector Batch Target ' . uniqid();
    H::createCategory($page, ['name' => $targetName]);

    albumSelectorOpen($page, '.linked-albums.add-item');

    $text = albumSelectorAddByName($page, $targetName);

    expect($text)->toContain($targetName);
    expect($text)->not->toBe('undefined');

    $page->assertNoJavaScriptErrors();
});
