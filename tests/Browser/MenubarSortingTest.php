<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * P49-A conversion of themes/admin/default/js/menubar.ts. Neither existing
 * menubar test file drives this JS at all: MenubarPageRendererTest.php and
 * MenubarPageRendererSubmitTest.php's own "submit" test both POST the
 * hide_ and pos_ fields directly via H::adminPost(), bypassing the browser
 * entirely -- so the click-to-hide toggle and the sortable-order-to-pos_
 * write in the real submit handler had no live coverage before this.
 *
 * `.sortable(...)`/`.sortable("toArray")` stay jQuery (jQuery-UI, P49-B
 * group 4) -- only the DOM work around them (hide/show/css/on/addClass/
 * removeClass) converted.
 */
it('toggles menuLi_hidden when a hide_ checkbox is clicked, both ways', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=menubar');

    // Confirm a real, unhidden block to start from -- mbLinks is not
    // hidden by MenubarPageRendererSubmitTest's own fixture default.
    $hiddenBefore = $page->script(
        "document.getElementById('menu_mbLinks').classList.contains('menuLi_hidden')"
    );
    expect($hiddenBefore)
        ->toBeFalse();

    // The theme replaces the checkbox with a `font-checkbox` control, so it
    // is not directly clickable -- click the wrapping label instead.
    $page->click('label.font-checkbox:has(input[name="hide_mbLinks"])');
    $hiddenAfterCheck = $page->script(
        "document.getElementById('menu_mbLinks').classList.contains('menuLi_hidden')"
    );
    expect($hiddenAfterCheck)
        ->toBeTrue();

    // The theme replaces the checkbox with a `font-checkbox` control, so it
    // is not directly clickable -- click the wrapping label instead.
    $page->click('label.font-checkbox:has(input[name="hide_mbLinks"])');
    $hiddenAfterUncheck = $page->script(
        "document.getElementById('menu_mbLinks').classList.contains('menuLi_hidden')"
    );
    expect($hiddenAfterUncheck)
        ->toBeFalse();

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'menubar hide-checkbox toggle');
});

it('writes sequential pos_ values from the sortable widget\'s current order on submit', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=menubar');

    // Registered after menubar.ts's own submit listener (attached during
    // ready(), well before this page->script() call runs), so it fires
    // second for the same event -- menubar.ts's handler has already
    // written every pos_ input by the time this suppresses the real
    // navigation. Without it, clicking submit would POST the form and
    // navigate away before the assertions below could run.
    $page->script(
        "document.getElementById('menuOrdering').addEventListener('submit', (e) => e.preventDefault())"
    );

    $blockIds = $page->script(
        "Array.from(document.querySelectorAll('.menuUl > .menuLi')).map((li) => li.id.replace('menu_', ''))"
    );
    expect($blockIds)
        ->not->toBeEmpty();

    $page->click('button[name="submit"]');

    $positions = $page->script(
        "Array.from(document.querySelectorAll('.menuUl > .menuLi')).map((li) => "
        . "document.getElementsByName('pos_' + li.id.replace('menu_', ''))[0].value)"
    );

    // The widget's own DOM order, not a value carried over from an earlier
    // save -- 1-based and gap-free regardless of what each pos_ input held
    // on page load.
    expect($positions)
        ->toBe(array_map(
            static fn (int $i): string => (string) ($i + 1),
            array_keys($blockIds)
        ));

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'menubar submit repositioning');
});
