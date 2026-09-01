<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * P49-A conversion of themes/admin/default/js/picture_modify.ts -- 0%
 * live-interaction coverage before this (PictureModifyPageRendererTest.php
 * only ever asserts the rendered page or drives raw POSTs).
 *
 * `.selectize()`, jquery-confirm's own `confirm()`, and `colorbox()` are
 * real native calls now (P49-B, `vendor/jconfirm.ts`/`vendor/
 * selectize.ts`/`vendor/colorbox.ts`). `.pwgDatepicker()` still stays
 * jQuery.
 */
it('removes a linked album, updating the badge and showing the orphan message', function (): void {
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Picture Modify Related Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    $image = H::makeTestImage(uniqid());
    // A fresh upload into exactly one album gives this image exactly one
    // real image_category row -- one relatedCategory, one .remove-item.
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Picture Modify Related Photo');
    @unlink($image);

    $page = H::navigateOk($page, '/admin.php?page=photo-' . $imageId);

    $badge = static fn (): mixed => $page->script(
        "document.querySelector('.linked-albums-badge').textContent.trim()"
    );
    $orphanText = static fn (): mixed => $page->script(
        "document.querySelector('.orphan-photo').textContent"
    );

    expect($badge())
        ->toBe('1');
    expect($orphanText())
        ->toBe('');

    $page->click('.remove-item');

    $page->script(<<<'JS'
        new Promise((resolve, reject) => {
            const deadline = Date.now() + 5000;
            const check = () => {
                if (document.querySelector('.linked-albums-badge').textContent.trim() === '0') {
                    return resolve(true);
                }
                if (Date.now() > deadline) {
                    return reject(new Error('badge never updated to 0'));
                }
                setTimeout(check, 100);
            };
            check();
        })
        JS);

    expect($badge())
        ->toBe('0');
    expect($orphanText())
        ->not->toBe('');

    $badgeIsRed = $page->script(
        "document.querySelector('.linked-albums-badge').classList.contains('badge-red')"
    );
    $addItemHighlighted = $page->script(
        "document.querySelector('.add-item').classList.contains('highlight')"
    );
    expect($badgeIsRed)
        ->toBeTrue();
    expect($addItemHighlighted)
        ->toBeTrue();

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'picture_modify remove related album');
});

it('shows a delete-confirmation dialog', function (): void {
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Picture Modify Delete Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Picture Modify Delete Photo');
    @unlink($image);

    $page = H::navigateOk($page, '/admin.php?page=photo-' . $imageId);

    $page->click('#action-delete-picture');

    $page->assertPresent('.jconfirm');
    $dialogText = $page->script("document.querySelector('.jconfirm').textContent");
    expect($dialogText)
        ->toContain('Are you sure?');

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'picture_modify delete-confirm dialog');
});

it('creates a brand-new tag via the selectize control, typing it and pressing Enter', function (): void {
    // Real, mutation-verified coverage of `vendor/selectize.ts`'s own
    // `create: true` + Enter-to-create flow (P49-B group 6) -- no prior
    // test, jQuery-based or not, ever drove selectize's search input or
    // its keyboard handling at all, only its zero-state render or direct
    // API/DOM state.
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Picture Modify New Tag Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Picture Modify New Tag Photo');
    @unlink($image);

    $page = H::navigateOk($page, '/admin.php?page=photo-' . $imageId);

    $newTagName = 'Fresh Tag ' . uniqid();
    $inputSelector = 'select[name="tags[]"] + .selectize-control input';

    $page->fill($inputSelector, $newTagName);

    $page->script(<<<'JS'
        new Promise((resolve, reject) => {
            const deadline = Date.now() + 5000;
            const check = () => {
                if (document.querySelector('select[name="tags[]"] + .selectize-control .selectize-dropdown [data-create]') !== null) {
                    return resolve(true);
                }
                if (Date.now() > deadline) {
                    return reject(new Error('create row never appeared for the typed tag name'));
                }
                setTimeout(check, 100);
            };
            check();
        })
        JS);

    $page->keys($inputSelector, 'Enter');

    // Real `updateOriginalInput()` behavior, faithfully ported: a
    // regenerated `<option>` carries the selected `value` only, no text
    // content -- the tag's real display name lives in the widget's own
    // rendered `.item` chip, not the underlying `<select>`.
    $result = H::scriptArray($page, <<<'JS'
        new Promise((resolve, reject) => {
            const deadline = Date.now() + 5000;
            const check = () => {
                const select = document.querySelector('select[name="tags[]"]');
                const item = select.nextElementSibling.querySelector('.selectize-input [data-value]');
                const chip = item ? item.querySelector('.remove') : null;
                if (select.options.length > 0 && select.options[0].selected && chip !== null) {
                    return resolve({
                        optionCount: select.options.length,
                        itemText: item.textContent,
                        hasRemoveButton: true,
                    });
                }
                if (Date.now() > deadline) {
                    return reject(new Error('new tag chip never landed in the real <select>'));
                }
                setTimeout(check, 100);
            };
            check();
        })
        JS);

    expect($result['optionCount'])
        ->toBe(1);
    expect($result['itemText'])
        ->toContain($newTagName);
    expect($result['hasRemoveButton'])
        ->toBeTrue();

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'picture_modify create tag via selectize Enter');
});
