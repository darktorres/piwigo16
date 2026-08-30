<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * P49-A conversion of themes/admin/default/js/addAlbum.ts's own
 * `jQuery.fn.pwgAddAlbum` -- 0% live-interaction coverage before this;
 * BatchManagerSubControllerTest.php/BatchManagerGlobalPageRendererTest.php
 * both drive the surrounding batch-manager page but never open this
 * popup.
 *
 * Stays jQuery on purpose: `this.colorbox(...)` (colorbox is $.fn.colorbox,
 * P49-B group 3, and `this` must be a real JQuery object for it) and
 * `cache.selectize(jQuery(albumParent), ...)` (LocalStorageCache.ts's own
 * AbstractSelectizer, P49-B group 6, whose internals need a JQuery target).
 *
 * Found live during this conversion, fixed in the same commit: reading the
 * selectize cache via `data(target, "cache")` (the native helper) instead
 * of `jQuery(target).data("cache")` made `cache` always come back
 * undefined and threw `jQuery.error("pwgAddAlbum: missing categories
 * cache")` on every real page load -- an uncaught, opaque "Script error."
 * with no stack trace, since it fired inside batchManagerGlobal.ts's own
 * `ready()` callback, a file this conversion never touched. Caught by
 * re-running the EXISTING (not new) BatchManagerSubControllerTest.php
 * suite, not by writing a new test first -- LocalStorageCache.ts's own
 * `$target.data("cache", this)` write is a jQuery-internal cache entry,
 * a different store entirely from what our data()/setData() read and
 * write (see project_p49_jquery_trigger_native_listener_gap memory).
 */
it('creates a new album through the add-album popup and selects it in the move dropdown', function (): void {
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'AddAlbum Popup Source Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    $image = H::makeTestImage(uniqid());
    H::uploadPhotoViaApi($image, $albumId, 'AddAlbum Popup Source Photo');
    @unlink($image);

    // A brand-new, untagged photo always matches the "no_tag" prefilter,
    // which is what actually populates the batch manager's current photo
    // set -- with none selected (the default caddie prefilter, always
    // empty in this fixture) the whole Action panel, including the "move"
    // dropdown and the add-album trigger, never renders at all.
    $result = H::adminPost($page, '/admin.php?page=batch_manager', [
        'pwg_token' => H::pwgToken($page),
        'submitFilter' => '1',
        'filter_prefilter_use' => '1',
        'filter_prefilter' => 'no_tag',
    ]);
    expect($result['status'])->toBe(200);
    H::markSharedSessionDirty();

    $page = H::navigateOk($page, '/admin.php?page=batch_manager');
    $page->click('#selectAll');
    $page->select('select[name="selectAction"]', 'move');
    $page->click('[data-add-album="move"]');

    $page->script(<<<'JS'
        new Promise((resolve, reject) => {
            const deadline = Date.now() + 5000;
            const check = () => {
                const input = document.querySelector('[name=category_name]');
                if (input && input.offsetParent !== null) {
                    return resolve(true);
                }
                if (Date.now() > deadline) {
                    return reject(new Error('addAlbumForm never became visible'));
                }
                setTimeout(check, 100);
            };
            check();
        })
        JS);

    $newAlbumName = 'AddAlbum Created Via Popup ' . uniqid();
    $page->fill('[name=category_name]', $newAlbumName);
    $page->click('.albumCreationButton');

    $selected = $page->script(<<<'JS'
        new Promise((resolve, reject) => {
            const deadline = Date.now() + 5000;
            const check = () => {
                const select = document.querySelector('select[name="move"]');
                const value = select && select.selectize ? select.selectize.getValue() : null;
                if (value) {
                    return resolve({
                        value: value,
                        label: select.selectize.options[value]
                            ? select.selectize.options[value].fullname
                            : null,
                    });
                }
                if (Date.now() > deadline) {
                    return reject(new Error(
                        'move selectize never got a real value, still: ' + JSON.stringify(value)
                    ));
                }
                setTimeout(check, 100);
            };
            check();
        })
        JS);

    expect($selected['label'])->toBe($newAlbumName);

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'addAlbum popup create-and-select');

    $db = H::connect();
    $row = H::fetchAssocOrFail($db, sprintf(
        "SELECT id FROM categories WHERE name = '%s'",
        H::dbEscape($db, $newAlbumName)
    ));
    H::dbClose($db);
    expect((string) $row['id'])->toBe((string) $selected['value']);
});
