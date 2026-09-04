<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * P49-A conversion of themes/admin/default/js/batchManagerGlobal.ts --
 * the file this campaign deliberately converted last, its own documented
 * module-cycle exception (see that file's and batch_manager/global.ts's
 * own leading comments, now resolved).
 *
 * Covers the one thing neither BatchManagerGlobalInteractionTest.php nor
 * BatchManagerSubControllerTest.php reaches: the "associate album" chip
 * flow (select_album_action()/remove_album_action()). Among this
 * campaign's several AlbumSelector consumers, this one is unique --
 * the chip's own `id` attribute is the album's bare numeric id
 * (`select_album_action()` writes it directly, unlike mcs.ts's or
 * albums.ts's own chips), and removing it builds `"#" + id_album` into a
 * selector. Digit-leading, so it needs escapeId() under native
 * querySelector, where Sizzle tolerated it unescaped. Nothing but a real
 * click on the remove icon reaches that code path.
 */
function bmgAssociateInsertCaddie(int $userId, int $imageId): void
{
    $db = H::connect();
    $sql = $db instanceof mysqli
        ? 'INSERT INTO caddie (user_id, element_id) VALUES (%d, %d) ON DUPLICATE KEY UPDATE user_id = user_id'
        : 'INSERT INTO caddie (user_id, element_id) VALUES (%d, %d) ON CONFLICT DO NOTHING';
    H::dbQuery($db, sprintf($sql, $userId, $imageId));
    H::dbClose($db);
}

it('associates an album via the chip flow, then removes it', function (): void {
    $page = H::asAdmin($this);

    $albumName = 'Batch Global Associate Album ' . uniqid();
    $sourceAlbum = H::createCategory($page, [
        'name' => 'Batch Global Associate Source ' . uniqid(),
    ]);
    if (! is_numeric($sourceAlbum['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($sourceAlbum, true));
    }
    H::createCategory($page, [
        'name' => $albumName,
    ]);

    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, (int) $sourceAlbum['id'], 'Batch Global Associate Photo');
    @unlink($image);
    // page=batch_manager defaults to the caddie prefilter, and the
    // "Action" panel (the `select[name=selectAction]` this test drives)
    // does not render at all when the current photo set is empty -- same
    // setup BatchManagerGlobalInteractionTest.php's own select-all/none
    // test uses to reach it.
    bmgAssociateInsertCaddie(1, $imageId);

    try {
        $page = H::navigateOk($page, '/admin.php?page=batch_manager');

        // The Action panel only renders once at least one photo is
        // selected, not merely present in the current set -- same
        // #selectAll BatchManagerGlobalInteractionTest.php's own
        // select-all/none test uses.
        $page->click('#selectAll');

        // Reveal the "associate" action panel -- same mechanism
        // BatchManagerGlobalInteractionTest.php's own dropdown test uses.
        $page->script(
            "document.querySelector('select[name=selectAction]').value = 'associate'; " .
            "document.querySelector('select[name=selectAction]').dispatchEvent(new Event('change', {bubbles: true}))",
        );

        $page->click('#associate_as');

        $timeoutMs = 10000;

        // The popup fades in and its rows arrive over the API, so both need
        // waiting on rather than racing (same pattern AdminAlbumSelectorTest
        // uses for every other AlbumSelector consumer).
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

        $page->fill('#search-input-ab', $albumName);
        $page->script(
            "document.getElementById('search-input-ab').dispatchEvent(new KeyboardEvent('keyup', {bubbles: true}))",
        );

        $encoded = json_encode($albumName, JSON_THROW_ON_ERROR);
        $page->script(<<<JS
        new Promise((resolve, reject) => {
            const deadline = Date.now() + {$timeoutMs};
            const wanted = {$encoded};
            const check = () => {
                const row = Array.from(
                    document.querySelectorAll('#searchResult .search-result-item'),
                ).find((r) => r.textContent.includes(wanted));
                if (row !== undefined) {
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

        // Attribute form, not `#<id>`: album ids are numbers, and `#7`
        // is not a valid CSS selector (AdminAlbumSelectorTest's own
        // established pattern for this same widget). A real Playwright
        // click, not a synthetic `.click()` call, matches how every
        // other AlbumSelector consumer's own interaction test drives it.
        $rowId = H::scriptString($page, <<<JS
        Array.from(document.querySelectorAll('#searchResult .search-result-item'))
            .find((r) => r.textContent.includes({$encoded}))
            .getAttribute('id')
        JS);
        $page->click("#searchResult .search-result-item[id='{$rowId}']");

        // select_album_action() closes the popup and appends a chip whose own
        // id is the album's bare numeric id -- the shape remove_album_action()
        // has to look back up.
        $page->script(<<<JS
        new Promise((resolve, reject) => {
            const deadline = Date.now() + {$timeoutMs};
            const check = () => {
                if (document.querySelectorAll('.selected-associate-item').length > 0) {
                    return resolve(true);
                }
                if (Date.now() > deadline) {
                    return reject(new Error('album chip was never added'));
                }
                setTimeout(check, 100);
            };
            check();
        })
        JS);

        $chipCountAfterAdd = H::scriptInt(
            $page,
            "document.querySelectorAll('.selected-associate-item').length",
        );
        expect($chipCountAfterAdd)
            ->toBe(1);

        $page->click('.selected-associate-action .remove-associate');

        $page->script(<<<JS
        new Promise((resolve, reject) => {
            const deadline = Date.now() + {$timeoutMs};
            const check = () => {
                if (document.querySelectorAll('.selected-associate-item').length === 0) {
                    return resolve(true);
                }
                if (Date.now() > deadline) {
                    return reject(new Error('album chip was never removed'));
                }
                setTimeout(check, 100);
            };
            check();
        })
        JS);

        $page->assertNoJavaScriptErrors();
        H::assertNoServerErrors($page, 'batchManagerGlobal.ts associate-album chip flow');
    } finally {
        $db = H::connect();
        H::dbQuery($db, sprintf('DELETE FROM caddie WHERE element_id = %d', $imageId));
        H::dbClose($db);
    }
});
