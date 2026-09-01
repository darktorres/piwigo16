<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * P49-A conversion of themes/admin/default/js/user_list.ts -- by far the
 * largest file converted this campaign (3378 lines, 6 jQuery-UI slider
 * instances, 3 selectize instances, a dozen `pop_in: Element` helper
 * functions threaded through the whole add/edit user flow).
 *
 * jQuery-UI's slider widget, selectize, tipTip, and jquery-confirm (all
 * mentioned here as "still jQuery" when this test was first written)
 * are all real native ports now (P49-B); the file's own last remaining
 * real jQuery usage (7 bare `.trigger("change")` calls, no widget
 * behind any of them) was converted to `dom.ts`'s own native `trigger()`
 * in P49-C, see that test below for the real bug it fixed.
 *
 * This one live end-to-end test (add a user through the popup, edit it,
 * delete it) exercises the three highest-risk conversions at once: the
 * `pop_in: Element` parameter threading through fill_user_edit_*() (was
 * `pop_in: JQuery`), the badge counters' `+(htmlOf(...) ?? "0")` integer
 * parsing (was jQuery's own looser `+$(...).html()`), and jquery-confirm
 * interop around the fully-converted delete_user()/badge-decrement code.
 */
it('adds a user through the popup, edits it, then deletes it', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=user_list');

    $badgeBefore = H::scriptInt($page, "parseInt(document.querySelector('.badge-number').textContent)");

    $username = 'ul_interaction_' . uniqid();
    $email = $username . '@example.test';
    $encodedUsername = json_encode($username, JSON_THROW_ON_ERROR);

    $page->click('.add-user-button');
    $page->fill('.AddUserLabelUsername input', $username);
    $page->fill('.AddUserLabelEmail input', $email);
    // Status defaults to "normal" (fill_new_user()'s own default), so no
    // password fields are required.
    $page->click('.AddUserSubmit');

    $timeoutMs = 10000;
    $page->script(<<<JS
        new Promise((resolve, reject) => {
            const deadline = Date.now() + {$timeoutMs};
            const check = () => {
                if (getComputedStyle(document.getElementById('AddUserSuccessContainer')).display !== 'none') {
                    return resolve(true);
                }
                if (Date.now() > deadline) return reject(new Error('add-user success view never showed'));
                setTimeout(check, 100);
            };
            check();
        })
        JS);

    $badgeAfterAdd = H::scriptInt($page, "parseInt(document.querySelector('.badge-number').textContent)");
    expect($badgeAfterAdd)
        ->toBe($badgeBefore + 1);

    $db = H::connect();
    $row = H::dbFetchAssoc($db, sprintf("SELECT id FROM users WHERE username = '%s'", $username));
    H::dbClose($db);
    if (! is_array($row) || ! isset($row['id'])) {
        throw new RuntimeException('newly added user not found in the database: ' . var_export($row, true));
    }
    $userId = (int) $row['id'];

    // #AddUserSuccess lives in the page header, not inside the #AddUser
    // modal, so it's behind the still-open modal until closed.
    $page->click('#AddUserButton');

    // Edit the just-created row via the header's "edit-now" shortcut --
    // exercises fill_user_edit_summary/properties/preferences/update()
    // all pre-filling from the real, freshly-fetched user.
    $page->click('#AddUserSuccess .edit-now');

    $page->script(<<<JS
        new Promise((resolve, reject) => {
            const deadline = Date.now() + {$timeoutMs};
            const check = () => {
                const el = document.querySelector('#UserList .user-property-username span:first-child');
                if (el !== null && el.textContent.trim() === {$encodedUsername}) return resolve(true);
                if (Date.now() > deadline) return reject(new Error('edit popup never showed the new user'));
                setTimeout(check, 100);
            };
            check();
        })
        JS);

    $newEmail = 'updated_' . $email;
    $page->fill('#UserList .user-property-email input', $newEmail);
    $page->click('#UserList .update-user-button');

    $page->script(<<<JS
        new Promise((resolve, reject) => {
            const deadline = Date.now() + {$timeoutMs};
            const check = () => {
                const el = document.querySelector('#UserList .update-user-success');
                if (el !== null && getComputedStyle(el).display !== 'none') return resolve(true);
                if (Date.now() > deadline) return reject(new Error('user update never succeeded'));
                setTimeout(check, 100);
            };
            check();
        })
        JS);

    $db = H::connect();
    $updated = H::dbFetchAssoc($db, sprintf('SELECT mail_address FROM users WHERE id = %d', $userId));
    H::dbClose($db);
    expect($updated['mail_address'] ?? null)->toBe($newEmail);

    // Delete it via the confirm dialog.
    $page->click('#UserList .delete-user-button');

    $page->script(<<<JS
        new Promise((resolve, reject) => {
            const deadline = Date.now() + {$timeoutMs};
            const check = () => {
                if (document.querySelector('.jconfirm .btn-red') !== null) return resolve(true);
                if (Date.now() > deadline) return reject(new Error('delete confirm dialog never appeared'));
                setTimeout(check, 100);
            };
            check();
        })
        JS);
    $page->script("document.querySelector('.jconfirm .btn-red').click()");

    $page->script(<<<JS
        new Promise((resolve, reject) => {
            const deadline = Date.now() + {$timeoutMs};
            const check = () => {
                if (parseInt(document.querySelector('.badge-number').textContent) === {$badgeBefore}) return resolve(true);
                if (Date.now() > deadline) return reject(new Error('badge count never went back down'));
                setTimeout(check, 100);
            };
            check();
        })
        JS);

    $db = H::connect();
    $stillThere = H::dbFetchAssoc($db, sprintf('SELECT id FROM users WHERE id = %d', $userId));
    H::dbClose($db);
    expect($stillThere)
        ->toBeNull();

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'user_list add/edit/delete flow');
});

/**
 * Real, previously-hidden bug found live during P49-C's own
 * `jQuery(...).trigger("change")` -> native `trigger()` conversion:
 * `select[name=selectAction]`'s own real "change" listener (hiding
 * `#applyActionBlock`, registered via `dom.ts`'s own `on()`) always
 * uses a real `addEventListener` -- jQuery's own `.trigger()` can't
 * invoke a native method for "change" (unlike "click"/"focus"/
 * "submit"), so it falls back to replaying only handlers in jQuery's
 * own internal registry, never reaching a listener registered outside
 * it. `selectionMode()`'s own former `jQuery(...).trigger("change")`
 * call reset the `<select>`'s own value to "-1" but never actually hid
 * the panel -- confirmed live against the unmodified branch before
 * fixing it here. No prior test exercised this reset path at all.
 */
it('resets the bulk-action panel when selection mode is toggled off', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=user_list');

    // `#permitActionUserList` needs both selection mode on (the
    // checkbox is visually hidden by the switch's own CSS, so its own
    // wrapping `<label class="switch">` is the real click target) AND
    // at least one real user selected (`update_selection_content()`'s
    // own real gate) before it becomes visible at all.
    $page->click('.selection-mode-group-manager label.switch');
    $page->click('#selectAllPage');

    $page->select('#permitActionUserList select[name="selectAction"]', 'status');

    $blockVisibleAfterSelect = H::scriptBool(
        $page,
        "getComputedStyle(document.getElementById('applyActionBlock')).display !== 'none'",
    );
    expect($blockVisibleAfterSelect)
        ->toBeTrue();

    // Toggling the same switch back off is what used to leave the
    // panel visibly stuck open.
    $page->click('.selection-mode-group-manager label.switch');

    $blockVisibleAfterToggle = H::scriptBool(
        $page,
        "getComputedStyle(document.getElementById('applyActionBlock')).display !== 'none'",
    );
    $selectValueAfterToggle = H::scriptString(
        $page,
        "document.querySelector('#permitActionUserList select[name=selectAction]').value",
    );

    expect($blockVisibleAfterToggle)
        ->toBeFalse();
    expect($selectValueAfterToggle)
        ->toBe('-1');

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'user_list selectionMode reset');
});
