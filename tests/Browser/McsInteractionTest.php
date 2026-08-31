<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * P49-A conversion of themes/default/js/mcs.ts -- the front-end
 * multi-criteria-search filter panel (550 jQuery expressions, the
 * largest file in this campaign; 2 jQuery-UI slider + 2 selectize
 * instances stay jQuery, each marked "Still jQuery" at its call site,
 * ported in P49-B groups 4 and 6).
 *
 * SearchFilterPanelRestoreTest.php already exercises this file's setup
 * pass (the synchronous `$(document).ready()` block that restores every
 * filter's UI state from `global_params`) in full, including two of the
 * three digit-leading-ID selector bugs this conversion found and fixed:
 * `label#<year>`-style lookups in the date_posted/date_created custom
 * date blocks (`querySelectorAll` throws a SyntaxError on an unquoted,
 * digit-leading ID that Sizzle tolerated).
 *
 * This test covers the third one, and the one thing the setup pass
 * doesn't reach: the mouse-driven interaction path. Opening a filter's
 * dropdown by clicking it, then removing an already-selected album chip,
 * exercises `remove_related_category()` -- `ab.remove_selected_album
 * (target.id)` -> `document.querySelector("#" + id)`, where `id` is the
 * album's own bare numeric id. Only a real click reaches this: the setup
 * pass only ever *adds* chips (`display_related_category()`), never
 * removes one.
 */
function mcsInteractionInsertSearchRow(int $albumId): string
{
    $db = H::connect();

    // UrlService's route parser only accepts this exact shape for a
    // saved-search identifier (`psk-YYYYMMDD-<10 alnum chars>`); anything
    // else falls through to its digit-extraction fallback and is treated
    // as a raw numeric search id instead, which 400s.
    $uuid = 'psk-' . date('Ymd') . '-' . substr(md5(uniqid('', true)), 0, 10);
    $rules = json_encode([
        'fields' => [
            'cat' => [
                'words' => [$albumId],
                'sub_inc' => false,
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    H::dbQuery($db, sprintf(
        "INSERT INTO search (rules, created_on, created_by, search_uuid, forked_from) VALUES ('%s', NOW(), NULL, '%s', NULL)",
        H::dbEscape($db, $rules),
        H::dbEscape($db, $uuid),
    ));
    H::dbClose($db);

    return $uuid;
}

it('opens the album filter dropdown and removes a selected album chip', function (): void {
    $page = H::asAdmin($this);

    $albumName = 'Mcs Interaction Album ' . uniqid();
    $album = H::createCategory($page, [
        'name' => $albumName,
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }

    $albumId = (int) $album['id'];
    $uuid = mcsInteractionInsertSearchRow($albumId);

    $page = H::navigateOk($page, '/index.php?/search/' . $uuid);
    H::assertNoServerErrors($page, 'mcs.ts album filter interaction (initial load)');
    $page->assertNoJavaScriptErrors();

    // mcs.ts's setup pass runs synchronously inside $(document).ready();
    // the spinner is hidden partway through it and set by mcs.ts alone,
    // the same signal SearchFilterPanelRestoreTest.php's own wait uses.
    $page->script(<<<'JS'
        new Promise((resolve, reject) => {
            const deadline = Date.now() + 10000;
            const check = () => {
                const spinner = document.querySelector('.filter-spinner');
                if (spinner !== null && getComputedStyle(spinner).display === 'none') {
                    return resolve(true);
                }
                if (Date.now() > deadline) {
                    return reject(new Error('Timed out waiting for mcs.ts to run its filter-panel setup'));
                }
                setTimeout(check, 100);
            };
            check();
        })
        JS);

    $chipCountBefore = H::scriptInt(
        $page,
        "document.querySelectorAll('.selected-categories-container .breadcrumb-item').length",
    );
    expect($chipCountBefore)
        ->toBe(1);

    // The chip lives inside the dropdown (.filter-album-form), which is
    // display:none until opened -- click the badge icon to open it (not
    // the container itself, which would also match the dropdown's own
    // "click outside to close" chrome once open).
    $page->click('.filter-album .filter-icon');

    $page->script(<<<'JS'
        new Promise((resolve, reject) => {
            const deadline = Date.now() + 5000;
            const check = () => {
                const form = document.querySelector('.filter-album-form');
                if (form !== null && getComputedStyle(form).display !== 'none') {
                    return resolve(true);
                }
                if (Date.now() > deadline) {
                    return reject(new Error('filter-album dropdown never opened'));
                }
                setTimeout(check, 100);
            };
            check();
        })
        JS);

    $page->click('.selected-categories-container .remove-item');

    $page->script(<<<'JS'
        new Promise((resolve, reject) => {
            const deadline = Date.now() + 5000;
            const check = () => {
                if (document.querySelectorAll('.selected-categories-container .breadcrumb-item').length === 0) {
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
    H::assertNoServerErrors($page, 'mcs.ts album filter interaction (chip removal)');
});
