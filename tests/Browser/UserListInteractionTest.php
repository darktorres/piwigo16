<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * P49-A conversion of themes/admin/default/js/user_list.ts -- by far the
 * largest file converted this campaign (3378 lines, 6 jQuery-UI slider
 * instances, 3 selectize instances, a dozen `pop_in: Element` helper
 * functions threaded through the whole add/edit user flow).
 *
 * Stays jQuery, each marked at its call site: jQuery-UI's slider widget
 * (P49-B group 4, every `.slider(...)` call/read), selectize (P49-B
 * group 6, the 3 group-picker instances), tipTip (P49-B group 2), and
 * jquery-confirm (P49-B group 5, the delete-user confirm).
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
