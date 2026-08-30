<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * P49-A conversion of themes/admin/default/js/configuration_main.ts -- 0%
 * live-interaction coverage before this (no existing Browser test file for
 * this page at all).
 *
 * `.tiptip-with-img` and `.themeBoxes a` stay jQuery (tipTip and colorbox,
 * P49-B groups 2 and 3).
 */
it('toggles rate_anonymous based on the rate checkbox, in both directions', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=configuration');

    // #rate_anonymous has no server-side hidden class at all (unlike its
    // sibling email_admin_on_new_user_filter block) -- its visibility
    // comes entirely from this file's own toggle() call at load time.
    // Clicked twice (both directions), not once: a handler that always
    // forces the same state regardless of the checkbox would still pass a
    // single-click assertion whenever that forced state happens to match
    // the one transition being tested.
    $checked = static fn (): mixed => $page->script(
        "document.querySelector('input[name=rate]').checked"
    );
    $display = static fn (): mixed => $page->script(
        "getComputedStyle(document.getElementById('rate_anonymous')).display"
    );

    $expectDisplay = static function (bool $shouldBeVisible) use ($display): void {
        if ($shouldBeVisible) {
            expect($display())
                ->not->toBe('none');
        } else {
            expect($display())
                ->toBe('none');
        }
    };

    $wasChecked = $checked();
    $expectDisplay($wasChecked);

    $page->click('label.font-checkbox:has(input[name=rate])');
    expect($checked())
        ->toBe(! $wasChecked);
    $expectDisplay(! $wasChecked);

    $page->click('label.font-checkbox:has(input[name=rate])');
    expect($checked())
        ->toBe($wasChecked);
    $expectDisplay($wasChecked);

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'configuration_main rate/rate_anonymous toggle');
});

it('adds and removes an order-by filter row', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=configuration');

    $rowCount = static fn (): mixed => $page->script(
        "document.querySelectorAll('#order_filters select').length"
    );
    $before = $rowCount();

    $page->click('#order_filters .addFilter');

    expect($rowCount())
        ->toBe($before + 1);

    // The newly-added row's own select is reset to no selection (val("")),
    // not a copy of the row it was cloned from.
    $lastSelectValue = $page->script(
        "Array.from(document.querySelectorAll('#order_filters select')).pop().value"
    );
    expect($lastSelectValue)
        ->toBe('');

    $page->click('#order_filters span.filter:last-of-type .removeFilter');

    expect($rowCount())
        ->toBe($before);

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'configuration_main order_filters add/remove');
});

it('disables an order-by option in other rows once it is chosen in one', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=configuration');

    // Guarantee at least 2 rows to compare across.
    $rowCount = $page->script(
        "document.querySelectorAll('#order_filters select').length"
    );
    if ($rowCount < 2) {
        $page->click('#order_filters .addFilter');
    }

    $chosenValue = $page->script(<<<'JS'
        (() => {
            const select = document.querySelector('#order_filters select');
            const option = Array.from(select.options).find((o) => !o.disabled);
            select.value = option.value;
            select.dispatchEvent(new Event('change', { bubbles: true }));
            return option.value;
        })()
        JS);

    $disabledElsewhere = $page->script(<<<JS
        Array.from(document.querySelectorAll('#order_filters select'))
            .slice(1)
            .every((select) => {
                const option = select.querySelector('option[value="{$chosenValue}"]');
                return option === null || option.disabled;
            })
        JS);
    expect($disabledElsewhere)
        ->toBeTrue();

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'configuration_main order_filters disable-duplicate');
});

it('highlights the selected mail theme', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=configuration');

    $themeCount = $page->script(
        "document.querySelectorAll('.themeSelect').length"
    );
    if ($themeCount < 2) {
        $this->markTestSkipped('Needs at least 2 mail themes to prove the swap, not just the initial state.');
    }

    $page->click('.themeSelect:last-of-type label.font-checkbox');

    $result = $page->script(<<<'JS'
        ({
            defaultCount: document.querySelectorAll('.themeSelect.themeDefault').length,
            lastIsDefault: document.querySelector('.themeSelect:last-of-type').classList.contains('themeDefault'),
        })
        JS);

    expect($result['defaultCount'])->toBe(1);
    expect($result['lastIsDefault'])->toBeTrue();

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'configuration_main mail theme highlight');
});

it('shows the group-options block when "group" is selected for the new-user email filter', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=configuration');

    // The filter radios only render once "email admins on new user" is
    // checked (email_admin_on_new_user_filter's own u-hidden guard) --
    // make sure that path is open first.
    $emailFilterVisible = $page->script(
        "document.getElementById('email_admin_on_new_user').offsetParent !== null"
    );
    if (! $emailFilterVisible) {
        $page->click('label.font-checkbox:has(input[name=allow_user_registration])');
    }
    $notifyChecked = $page->script(
        "document.querySelector('input[name=email_admin_on_new_user]').checked"
    );
    if (! $notifyChecked) {
        $page->click('label.font-checkbox:has(input[name=email_admin_on_new_user])');
    }

    $display = static fn (): mixed => $page->script(
        "getComputedStyle(document.getElementById('email_admin_on_new_user_filter_group_options')).display"
    );

    $page->click('label.font-checkbox:has(input[name=email_admin_on_new_user_filter][value=group])');
    expect($display())
        ->not->toBe('none');

    $page->click('label.font-checkbox:has(input[name=email_admin_on_new_user_filter][value=all])');
    expect($display())
        ->toBe('none');

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'configuration_main email-filter group-options toggle');
});
