<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * P49-A conversion of themes/admin/default/js/group_list.ts.
 *
 * Stays jQuery, each marked at its call site: $.confirm()/$.alert()
 * (jquery-confirm, P49-B group 5) and selectize() (P49-B group 6, both
 * the search-box selectize control and UsersCache's own selectize()
 * wrapper).
 *
 * New instance of the requestSubmit() finding first made in tags.ts:
 * the rename form's own validate-icon click handler calls
 * `.trigger("submit")` (here: `find(groupBox, ".group-rename
 * form")[0].requestSubmit()`), since `HTMLFormElement.submit()` does
 * not fire a "submit" event by spec and would never reach the native
 * "submit" listener.
 *
 * setupDefaultActions()'s own `.unbind("click")` calls are translated to
 * dom.ts's `off(target, "click")` with no handler argument, which (like
 * jQuery's unbind) removes every click listener dom.ts's own on() has
 * ever registered on that element, not just one particular closure.
 *
 * Every `[data-id=...]`/`[value=...]`-style attribute selector built
 * from an interpolated group id was rewritten quoted, same risk class
 * already fixed in tags.ts/batchManagerUnit.ts.
 */
it('renames a group via the checkmark button, then deletes it via the selection panel', function (): void {
    $page = H::asAdmin($this);

    $group = H::createGroup($page, [
        'name' => 'Group List Interaction ' . uniqid(),
    ]);
    $groupId = $group['id'] ?? null;
    if (! is_numeric($groupId)) {
        throw new RuntimeException('createGroup did not return a numeric id: ' . var_export($group, true));
    }
    $groupId = (int) $groupId;

    $page = H::navigateOk($page, '/admin.php?page=group_list');

    $page->assertPresent('#group-' . $groupId);

    // Rename via the pencil icon + checkmark (requestSubmit(), not Enter).
    $newName = 'Group List Interaction Renamed ' . uniqid();
    $page->click('#group-' . $groupId . ' .Group-name .icon-pencil');
    $page->fill('#group-' . $groupId . ' .group_name-editable', $newName);
    $page->click('#group-' . $groupId . ' .group-rename .validate');

    $timeoutMs = 10000;
    $encodedName = json_encode($newName, JSON_THROW_ON_ERROR);
    $page->script(<<<JS
        new Promise((resolve, reject) => {
            const deadline = Date.now() + {$timeoutMs};
            const check = () => {
                const el = document.querySelector('#group-{$groupId} #group_name');
                if (el !== null && el.textContent.trim() === {$encodedName}) return resolve(true);
                if (Date.now() > deadline) return reject(new Error('rename never landed'));
                setTimeout(check, 100);
            };
            check();
        })
        JS);

    // TemporaryState's own reverse() undid the rename form's loading state.
    expect($page->script(
        "document.querySelector('#group-{$groupId} .group-rename .validate').classList.contains('icon-ok')",
    ))->toBeTrue();

    // Enable selection mode (a toggle switch, off by default -- .Group-
    // checkbox is itself only shown once .in-selection-mode elements are
    // revealed), then select the group via its checkbox, exercising the
    // quoted data-id selectors in toogleSelection()/updateSelectionPanel(),
    // then delete via the selection panel's own confirm-then-delete flow.
    $page->click('.selection-mode-group-manager label.switch');
    $page->click('#group-' . $groupId . ' .Group-checkbox label');

    $page->script(<<<JS
        new Promise((resolve, reject) => {
            const deadline = Date.now() + {$timeoutMs};
            const check = () => {
                if (document.querySelector('.DeleteGroupList div[data-id="{$groupId}"]') !== null) return resolve(true);
                if (Date.now() > deadline) return reject(new Error('selection panel never showed the group'));
                setTimeout(check, 100);
            };
            check();
        })
        JS);

    $page->click('#DeleteSelectionMode');
    $page->click('#ConfirmGroupAction .ConfirmDeleteButton');

    $page->script(<<<JS
        new Promise((resolve, reject) => {
            const deadline = Date.now() + {$timeoutMs};
            const check = () => {
                if (document.querySelector('#group-{$groupId}') === null) return resolve(true);
                if (Date.now() > deadline) return reject(new Error('group was never removed'));
                setTimeout(check, 100);
            };
            check();
        })
        JS);

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'group_list rename/delete flow');
});
