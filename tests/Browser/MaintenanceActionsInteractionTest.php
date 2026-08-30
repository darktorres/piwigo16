<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * P49-A conversion of themes/admin/default/js/maintenance_actions.ts -- 0%
 * live-interaction coverage before this (MaintenanceActionsPageRendererTest.php
 * only ever asserts the rendered page or drives raw POSTs).
 *
 * `.pwg_jconfirm_follow_href()` stays jQuery (jquery-confirm, P49-B
 * group 5).
 */
it('toggles a size-check icon and its data-selected attribute on click, both ways', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=maintenance');

    // Scoped to the second check, not the first: the first has its own
    // extra "select everything" behaviour covered by the sibling test
    // below, and this one should NOT trigger that.
    $selected = static fn (): mixed => $page->script(
        "document.querySelectorAll('.delete-size-check')[1].getAttribute('data-selected')"
    );
    $iconVisible = static fn (): mixed => $page->script(
        "document.querySelectorAll('.delete-size-check')[1].querySelector('i').offsetParent !== null"
    );

    expect($selected())
        ->toBe('0');
    expect($iconVisible())
        ->toBeFalse();

    $page->click('.delete-size-check:nth-of-type(2)');
    expect($selected())
        ->toBe('1');
    expect($iconVisible())
        ->toBeTrue();

    $page->click('.delete-size-check:nth-of-type(2)');
    expect($selected())
        ->toBe('0');
    expect($iconVisible())
        ->toBeFalse();

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'maintenance_actions size-check toggle');
});

it('selects (or clears) every size-check when the first one is toggled', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=maintenance');

    $allSelected = static fn (): mixed => $page->script(
        "Array.from(document.querySelectorAll('.delete-size-check')).every((el) => el.getAttribute('data-selected') === '1')"
    );
    $checkCount = $page->script(
        "document.querySelectorAll('.delete-size-check').length"
    );
    expect($checkCount)
        ->toBeGreaterThan(1);

    $page->click('.delete-size-check:first-of-type');

    expect($allSelected())
        ->toBeTrue();

    $page->click('.delete-size-check:first-of-type');

    expect($allSelected())
        ->toBeFalse();
    $noneSelected = $page->script(
        "Array.from(document.querySelectorAll('.delete-size-check')).every((el) => el.getAttribute('data-selected') === '0')"
    );
    expect($noneSelected)
        ->toBeTrue();

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'maintenance_actions first-check select-all');
});

it('shows the delete-sizes link with a type= href once a size is checked, and hides it again', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=maintenance');

    $linkVisible = static fn (): mixed => $page->script(
        "document.querySelector('.delete-sizes').offsetParent !== null"
    );
    $linkHref = static fn (): mixed => $page->script(
        "document.querySelector('.delete-sizes').getAttribute('href')"
    );

    expect($linkVisible())
        ->toBeFalse();

    $page->click('.delete-size-check:nth-of-type(2)');

    expect($linkVisible())
        ->toBeTrue();
    expect($linkHref())
        ->toContain('action=derivatives');
    expect($linkHref())
        ->toContain('type=');

    $page->click('.delete-size-check:nth-of-type(2)');

    expect($linkVisible())
        ->toBeFalse();
    expect($linkHref())
        ->toBe('');

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'maintenance_actions delete-sizes link');
});
