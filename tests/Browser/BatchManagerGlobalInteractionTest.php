<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * P49-A conversion of themes/admin/default/js/batch_manager/global.ts --
 * BatchManagerGlobalPageRendererTest.php/BatchManagerSubControllerTest.php
 * already cover every server-side submission branch; this file covers
 * the client-side-only behaviors the conversion touched: the action
 * dropdown revealing its panel and toggling `#applyActionBlock`/
 * `#confirmDel`, and Select All/None updating `.thumbSelected`.
 *
 * `#applyAction` carries two independent click listeners -- one bound
 * here, one in batchManagerGlobal.ts -- and both are now native
 * `addEventListener` registrations (batchManagerGlobal.ts converted
 * last, its own documented P49-A module-cycle exception, which is what
 * had kept this file's own listener jQuery-bound: the old trigger side
 * needed it). Not exercised here: both handlers' own bodies reach into
 * `derivatives`/`progress_start`/`getDerivativeUrls`/`AjaxQueue`
 * (`themes/default/js/vendor/utils/ajaxQueue.ts`, ported off jquery.ajaxmanager
 * in P49-B group 2), which is exactly what
 * BatchManagerSubControllerTest.php's real form-submission tests already
 * cover end to end.
 */
function bmgInsertCaddie(int $userId, int $imageId): void
{
    $db = H::connect();
    $sql = $db instanceof mysqli
        ? 'INSERT INTO caddie (user_id, element_id) VALUES (%d, %d) ON DUPLICATE KEY UPDATE user_id = user_id'
        : 'INSERT INTO caddie (user_id, element_id) VALUES (%d, %d) ON CONFLICT DO NOTHING';
    H::dbQuery($db, sprintf($sql, $userId, $imageId));
    H::dbClose($db);
}

it('reveals the delete action panel and toggles applyActionBlock/confirmDel visibility', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=batch_manager');

    // Not asserted against a fixed initial state: the select's own
    // starting value (and so applyActionBlock/confirmDel's starting
    // visibility) depends on session state this test does not control
    // (BatchManagerGlobalPageRenderer remembers the last-used filter).
    // What the conversion actually has to get right is the delta between
    // two real selections, which is what's asserted below.
    $page->script(
        "document.querySelector('select[name=selectAction]').value = 'author'; " .
        "document.querySelector('select[name=selectAction]').dispatchEvent(new Event('change', {bubbles: true}))",
    );
    expect($page->script("getComputedStyle(document.getElementById('action_author')).display"))
        ->not->toBe('none');
    expect($page->script("getComputedStyle(document.getElementById('action_delete')).display"))
        ->toBe('none');
    expect($page->script("getComputedStyle(document.getElementById('confirmDel')).visibility"))
        ->toBe('hidden');

    $page->script(
        "document.querySelector('select[name=selectAction]').value = 'delete'; " .
        "document.querySelector('select[name=selectAction]').dispatchEvent(new Event('change', {bubbles: true}))",
    );
    expect($page->script("getComputedStyle(document.getElementById('action_delete')).display"))
        ->not->toBe('none');
    expect($page->script("getComputedStyle(document.getElementById('action_author')).display"))
        ->toBe('none');
    expect($page->script("getComputedStyle(document.getElementById('applyActionBlock')).display"))
        ->not->toBe('none');
    expect($page->script("getComputedStyle(document.getElementById('confirmDel')).visibility"))
        ->toBe('visible');

    // Switching back to a non-delete action hides confirmDel again.
    $page->script(
        "document.querySelector('select[name=selectAction]').value = 'author'; " .
        "document.querySelector('select[name=selectAction]').dispatchEvent(new Event('change', {bubbles: true}))",
    );
    expect($page->script("getComputedStyle(document.getElementById('action_delete')).display"))
        ->toBe('none');
    expect($page->script("getComputedStyle(document.getElementById('action_author')).display"))
        ->not->toBe('none');
    expect($page->script("getComputedStyle(document.getElementById('confirmDel')).visibility"))
        ->toBe('hidden');

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'batch_manager_global action dropdown');
});

it('selects and deselects the page\'s thumbnails via Select All / Select None', function (): void {
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Batch Global Interaction Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, (int) $album['id'], 'Batch Global Interaction Photo');
    @unlink($image);
    // page=batch_manager defaults to the caddie prefilter (see
    // BatchManagerSubControllerTest.php's own "renders the global tab
    // with no filter" test) -- the simplest deterministic way to put
    // exactly one real photo on this page.
    bmgInsertCaddie(1, $imageId);

    try {
        $page = H::navigateOk($page, '/admin.php?page=batch_manager');
        $page->script(<<<'JS'
            new Promise((resolve, reject) => {
                const deadline = Date.now() + 8000;
                const check = () => {
                    if (document.querySelectorAll('.thumbnails li').length > 0) return resolve(true);
                    if (Date.now() > deadline) return reject(new Error('thumbnails never loaded'));
                    setTimeout(check, 100);
                };
                check();
            })
            JS);

        expect($page->script("document.querySelectorAll('.thumbnails li.thumbSelected').length"))
            ->toBe(0);

        $page->click('#selectAll');
        expect($page->script("document.querySelectorAll('.thumbnails li.thumbSelected').length"))
            ->toBe(1);
        expect($page->script("document.querySelectorAll('.thumbnails input[type=checkbox]:checked').length"))
            ->toBe(1);

        $page->click('#selectNone');
        expect($page->script("document.querySelectorAll('.thumbnails li.thumbSelected').length"))
            ->toBe(0);
        expect($page->script("document.querySelectorAll('.thumbnails input[type=checkbox]:checked').length"))
            ->toBe(0);

        $page->assertNoJavaScriptErrors();
        H::assertNoServerErrors($page, 'batch_manager_global select all/none');
    } finally {
        $db = H::connect();
        H::dbQuery($db, sprintf('DELETE FROM caddie WHERE element_id = %d', $imageId));
        H::dbClose($db);
    }
});

/**
 * P51-N converted these 4 links off `href="javascript:selectGenerateDerivAll()"`-
 * style pseudo-protocol hrefs/`window.X = X` exposure onto real
 * `href="#"` + a real `click` listener (`e.preventDefault()` +
 * the same underlying `selectGenerateDerivAll`/etc. function). No real
 * click coverage existed for any of the 4 before this test --
 * BatchManagerSubControllerTest.php's own generate_derivatives
 * assertion submits the form directly, never through this UI.
 */
it('selects/deselects every generate-derivatives and delete-derivatives checkbox via the All/None links', function (): void {
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Batch Global Derivatives Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, (int) $album['id'], 'Batch Global Derivatives Photo');
    @unlink($image);
    // #permitAction (and so the #action fieldset/selectAction dropdown
    // these 4 links live under) only renders once $selection is
    // non-empty -- the caddie prefilter's own contents, same setup as
    // the Select All/None test above, not anything client-side checkbox
    // state controls.
    bmgInsertCaddie(1, $imageId);

    try {
        $page = H::navigateOk($page, '/admin.php?page=batch_manager');
        $page->script(<<<'JS'
            new Promise((resolve, reject) => {
                const deadline = Date.now() + 8000;
                const check = () => {
                    if (document.querySelectorAll('.thumbnails li').length > 0) return resolve(true);
                    if (Date.now() > deadline) return reject(new Error('thumbnails never loaded'));
                    setTimeout(check, 100);
                };
                check();
            })
            JS);

        // #permitAction (and so the #action fieldset these 4 links live
        // under) only renders once a photo is actually checked, not
        // merely present in the filtered set -- confirmed live via a
        // screenshot showing "No photos selected, 1 photos in current
        // set" with the Action panel still on its forbidAction message.
        $page->click('#selectAll');
        $page->script(<<<'JS'
            new Promise((resolve, reject) => {
                const deadline = Date.now() + 8000;
                const check = () => {
                    if (getComputedStyle(document.getElementById('permitAction')).display !== 'none') return resolve(true);
                    if (Date.now() > deadline) return reject(new Error('permitAction never showed after selectAll'));
                    setTimeout(check, 100);
                };
                check();
            })
            JS);

        $page->script(
            "document.querySelector('select[name=selectAction]').value = 'generate_derivatives'; " .
            "document.querySelector('select[name=selectAction]').dispatchEvent(new Event('change', {bubbles: true}))",
        );

        $page->click('.js-select-generate-deriv-all');
        expect($page->script(
            "Array.from(document.querySelectorAll('#action_generate_derivatives input[type=checkbox]')).every((el) => el.checked)",
        ))->toBeTrue();

        $page->click('.js-select-generate-deriv-none');
        expect($page->script(
            "Array.from(document.querySelectorAll('#action_generate_derivatives input[type=checkbox]')).some((el) => el.checked)",
        ))->toBeFalse();

        $page->script(
            "document.querySelector('select[name=selectAction]').value = 'delete_derivatives'; " .
            "document.querySelector('select[name=selectAction]').dispatchEvent(new Event('change', {bubbles: true}))",
        );

        $page->click('.js-select-del-deriv-all');
        expect($page->script(
            "Array.from(document.querySelectorAll('#action_delete_derivatives input[name=\"del_derivatives_type[]\"]')).every((el) => el.checked)",
        ))->toBeTrue();

        $page->click('.js-select-del-deriv-none');
        expect($page->script(
            "Array.from(document.querySelectorAll('#action_delete_derivatives input[name=\"del_derivatives_type[]\"]')).some((el) => el.checked)",
        ))->toBeFalse();

        $page->assertNoJavaScriptErrors();
        H::assertNoServerErrors($page, 'batch_manager_global derivative select all/none');
    } finally {
        $db = H::connect();
        H::dbQuery($db, sprintf('DELETE FROM caddie WHERE element_id = %d', $imageId));
        H::dbClose($db);
    }
});
