<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * P49-A conversion of themes/admin/default/js/batchManagerUnit.ts --
 * AdminAlbumSelectorTest.php's own "names the album in the unit batch
 * manager..." test already covers the AlbumSelector/add_related_category
 * flow end to end. What neither that test nor
 * BatchManagerUnitPageRendererTest.php's form-submission tests cover is
 * this file's own JS-side per-fieldset scoping: the page repeats
 * `id="name"`/`id="author"`/etc. once per photo (each real `<fieldset
 * id="picture-{id}">` on the page), so every handler here has to resolve
 * *which* fieldset a given input/button belongs to via `.closest(
 * "fieldset")` + `data("image_id")` before touching anything -- exactly
 * the class of bug a single-photo test can't see, and exactly what broke
 * during this conversion (see the "input, textarea" handler's own
 * comment in the source: querySelectorAll("input, textarea") also
 * matches the unrelated album-selector popup's search box, which has no
 * enclosing fieldset at all, and dom.ts's data() throws on a null
 * element where jQuery's own empty-set `.data()` quietly returned
 * `undefined`).
 *
 * jquery-confirm, colorbox, and selectize are all real native calls now
 * (P49-B) -- selectize's own `triggerChange()` dispatches a real native
 * "change" event, so the `<select>` change listener is native too.
 * `pwgDatepicker` still stays jQuery, marked at its own call site,
 * along with the one "change" listener on `input[data-datepicker]`
 * that must stay a jQuery registration: pwgDatepicker signals via
 * jQuery's own `.trigger("change")`, which never reaches a native
 * listener.
 */
it('scopes the unsaved/save flow to the edited photo, not any other photo on the page', function (): void {
    $page = H::asAdmin($this);

    $album = H::createCategory($page, [
        'name' => 'Batch Unit Interaction Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];

    $imageA = H::makeTestImage(uniqid());
    $imageIdA = H::uploadPhotoViaApi($imageA, $albumId, 'Batch Unit Interaction Photo A');
    @unlink($imageA);

    $imageB = H::makeTestImage(uniqid());
    $imageIdB = H::uploadPhotoViaApi($imageB, $albumId, 'Batch Unit Interaction Photo B');
    @unlink($imageB);

    $filterResult = H::adminPost($page, '/admin.php?page=batch_manager', [
        'pwg_token' => H::pwgToken($page),
        'submitFilter' => '1',
        'filter_category_use' => '1',
        'filter_category' => (string) $albumId,
    ]);
    expect($filterResult['status'])->toBe(200);

    $page = H::navigateOk($page, '/admin.php?page=batch_manager&mode=unit');

    $page->assertPresent('#picture-' . $imageIdA);
    $page->assertPresent('#picture-' . $imageIdB);

    // Neither photo starts out flagged unsaved.
    expect($page->script(
        "getComputedStyle(document.querySelector('#picture-{$imageIdA} .local-unsaved-badge')).display",
    ))->toBe('none');
    expect($page->script(
        "getComputedStyle(document.querySelector('#picture-{$imageIdB} .local-unsaved-badge')).display",
    ))->toBe('none');

    $newName = 'Batch Unit Interaction Photo A Renamed ' . uniqid();
    $page->fill('#picture-' . $imageIdA . ' #name', $newName);
    // jQuery's own "input" delivers on a real native input event too, so a
    // native dispatch (via fill()) reaches the listener either way here --
    // no `.trigger()` asymmetry on this particular event type.
    $page->script(
        "document.querySelector('#picture-{$imageIdA} #name').dispatchEvent(new Event('input', {bubbles: true}))",
    );

    // Only photo A is flagged -- confirms the click/input handlers resolve
    // each element's OWN enclosing fieldset rather than the first one on
    // the page, or all of them, or none.
    expect($page->script(
        "getComputedStyle(document.querySelector('#picture-{$imageIdA} .local-unsaved-badge')).display",
    ))->toBe('block');
    expect($page->script(
        "getComputedStyle(document.querySelector('#picture-{$imageIdB} .local-unsaved-badge')).display",
    ))->toBe('none');

    $page->click('#picture-' . $imageIdA . ' .action-save-picture');

    $timeoutMs = 10000;
    $page->script(<<<JS
        new Promise((resolve, reject) => {
            const deadline = Date.now() + {$timeoutMs};
            const check = () => {
                const badge = document.querySelector('#picture-{$imageIdA} .local-success-badge');
                if (badge !== null && getComputedStyle(badge).display !== 'none') return resolve(true);
                if (Date.now() > deadline) return reject(new Error('save never succeeded'));
                setTimeout(check, 100);
            };
            check();
        })
        JS);

    expect($page->script(
        "getComputedStyle(document.querySelector('#picture-{$imageIdA} .local-unsaved-badge')).display",
    ))->toBe('none');
    // Untouched throughout.
    expect($page->script(
        "getComputedStyle(document.querySelector('#picture-{$imageIdB} .local-unsaved-badge')).display",
    ))->toBe('none');

    $db = H::connect();
    $row = H::dbFetchAssoc($db, sprintf('SELECT name FROM images WHERE id = %d', $imageIdA));
    H::dbClose($db);
    expect($row['name'] ?? null)->toBe($newName);

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'batchManagerUnit per-fieldset save flow');
});
