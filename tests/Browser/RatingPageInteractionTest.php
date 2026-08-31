<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * P49-A conversion of themes/admin/default/js/rating.ts. Neither existing
 * RatingPageRendererTest.php test drives this file's own JS at all -- they
 * only assert the page renders. New here: the delegated trash-icon delete
 * (a real ajax round trip, fade, and row removal) and the album-filter
 * show/hide toggle.
 *
 * That second one is the interesting one: `vendor/selectize.ts`'s own
 * `triggerChange()` (P49-B group 6) dispatches a real native "change"
 * event on the underlying `<select>` for every value change (including
 * the removeAlbumFilter click's own `.clear()`), which rating.ts's own
 * `select[name=cat]` "change" binding (now a plain `on()`, no longer
 * jQuery-bound) picks up. This test caught a real bug during that
 * conversion: `syncOriginalSelect()` originally tried to toggle
 * `.selected` on pre-existing `<option>` children, but a Cache-backed
 * `<select>` (this one included) starts with none -- real selectize.js
 * instead *regenerates* the `<option>` list from the current items on
 * every change, which is what fixed it.
 */
it('deletes a rating via the trash icon, fading and removing its row', function (): void {
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Rating Interaction Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Rating Interaction Photo');
    @unlink($image);

    $db = H::connect();
    H::dbQuery($db, sprintf(
        "INSERT INTO rate (user_id, element_id, anonymous_id, rate, date) VALUES (1, %d, '', 5, CURRENT_DATE)",
        $imageId
    ));
    H::dbClose($db);

    try {
        $page = H::navigateOk($page, '/admin.php?page=rating');

        $selector = 'a.icon-trash[data-image-id="' . $imageId . '"]';
        $page->assertPresent($selector);

        $page->click($selector);

        // The delete is a real ajax round trip -- poll until the row is
        // actually gone rather than assume the click alone is enough.
        $page->script(<<<JS
            new Promise((resolve, reject) => {
                const deadline = Date.now() + 5000;
                const check = () => {
                    if (document.querySelector('{$selector}') === null) {
                        return resolve(true);
                    }
                    if (Date.now() > deadline) {
                        return reject(new Error('rating row was never removed'));
                    }
                    setTimeout(check, 100);
                };
                check();
            })
            JS);

        $page->assertNoJavaScriptErrors();
        H::assertNoServerErrors($page, 'rating trash-icon delete');

        $remaining = H::connect();
        $count = H::dbFetchAssoc($remaining, sprintf('SELECT COUNT(*) AS c FROM rate WHERE element_id = %d', $imageId));
        H::dbClose($remaining);
        expect(is_array($count) ? (int) $count['c'] : null)->toBe(0);
    } finally {
        $cleanup = H::connect();
        H::dbQuery($cleanup, sprintf('DELETE FROM rate WHERE element_id = %d', $imageId));
        H::dbClose($cleanup);
    }
});

it('shows the album-filter clear button when a cat= filter is active, and hides it again when cleared', function (): void {
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Rating Interaction Filter Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];

    $page = H::navigateOk($page, '/admin.php?page=rating&cat=' . $albumId);

    // LocalStorageCache's own this.get(callback) (AbstractSelectizer's
    // _selectize()) loads asynchronously, so the data-value preselection
    // -- and the selectize "change" it fires once applied -- lands after
    // this page has already returned control here; poll rather than
    // assert synchronously.
    $page->script(<<<'JS'
        new Promise((resolve, reject) => {
            const deadline = Date.now() + 5000;
            const check = () => {
                if (getComputedStyle(document.getElementById('removeAlbumFilter')).display !== 'none') {
                    return resolve(true);
                }
                if (Date.now() > deadline) {
                    return reject(new Error('removeAlbumFilter never showed for the cat= filter'));
                }
                setTimeout(check, 100);
            };
            check();
        })
        JS);

    $page->click('#removeAlbumFilter');

    $page->script(<<<'JS'
        new Promise((resolve, reject) => {
            const deadline = Date.now() + 5000;
            const check = () => {
                if (getComputedStyle(document.getElementById('removeAlbumFilter')).display === 'none') {
                    return resolve(true);
                }
                if (Date.now() > deadline) {
                    return reject(new Error('removeAlbumFilter never hid after clearing the selectize value'));
                }
                setTimeout(check, 100);
            };
            check();
        })
        JS);

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'rating album-filter clear');
});
