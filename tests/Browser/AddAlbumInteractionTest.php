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
 * `colorbox(trigger, ...)` is a real native call now too (P49-B,
 * `vendor/colorbox.ts`) -- `pwgAddAlbum` itself converted from a
 * `jQuery.fn` extension to a plain function in the same pass.
 * `cache.selectize(albumParent, ...)` (LocalStorageCache.ts's own
 * AbstractSelectizer) is a real native call now (P49-B group 6,
 * `vendor/selectize.ts`) -- the cache lookup below reads real state off
 * the rendered DOM (`select.value`, the widget's own `.item` markup)
 * rather than a since-removed `.selectize` instance property.
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

    $selected = H::scriptArray($page, <<<'JS'
        new Promise((resolve, reject) => {
            const deadline = Date.now() + 5000;
            const check = () => {
                const select = document.querySelector('select[name="move"]');
                const value = select ? select.value : null;
                const item = select
                    ? select.nextElementSibling.querySelector('.selectize-input [data-value]')
                    : null;
                if (value) {
                    return resolve({
                        value: value,
                        label: item ? item.textContent : null,
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
    if (! is_string($selected['value'] ?? null) || ! is_string($selected['label'] ?? null)) {
        throw new RuntimeException('unexpected selected shape: ' . var_export($selected, true));
    }

    expect($selected['label'])->toBe($newAlbumName);

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'addAlbum popup create-and-select');

    $db = H::connect();
    $row = H::fetchAssocOrFail($db, sprintf(
        "SELECT id FROM categories WHERE name = '%s'",
        H::dbEscape($db, $newAlbumName)
    ));
    H::dbClose($db);
    expect((string) $row['id'])->toBe($selected['value']);
});
