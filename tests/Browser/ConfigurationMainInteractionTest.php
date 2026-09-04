<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * P49-A conversion of themes/admin/default/js/configuration/main.ts -- 0%
 * live-interaction coverage before this (no existing Browser test file for
 * this page at all).
 *
 * `.tiptip-with-img` and `.themeBoxes a` are both real native calls now
 * (tipTip and colorbox, P49-B).
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
    $checked = static fn (): bool => H::scriptBool(
        $page,
        "document.querySelector('input[name=rate]').checked"
    );
    $display = static fn (): string => H::scriptString(
        $page,
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

    $rowCount = static fn (): int => H::scriptInt(
        $page,
        "document.querySelectorAll('#order_filters select').length"
    );
    $before = $rowCount();

    $page->click('#order_filters .addFilter');

    expect($rowCount())
        ->toBe($before + 1);

    // The newly-added row's own select is reset to no selection (val("")),
    // not a copy of the row it was cloned from.
    $lastSelectValue = H::scriptString(
        $page,
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
    $rowCount = H::scriptInt(
        $page,
        "document.querySelectorAll('#order_filters select').length"
    );
    if ($rowCount < 2) {
        $page->click('#order_filters .addFilter');
    }

    $chosenValue = H::scriptString($page, <<<'JS'
        (() => {
            const select = document.querySelector('#order_filters select');
            const option = Array.from(select.options).find((o) => !o.disabled);
            select.value = option.value;
            select.dispatchEvent(new Event('change', { bubbles: true }));
            return option.value;
        })()
        JS);

    $disabledElsewhere = H::scriptBool($page, <<<JS
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

    $themeCount = H::scriptInt(
        $page,
        "document.querySelectorAll('.themeSelect').length"
    );
    if ($themeCount < 2) {
        Assert::markTestSkipped('Needs at least 2 mail themes to prove the swap, not just the initial state.');
    }

    $page->click('.themeSelect:last-of-type label.font-checkbox');

    $result = H::scriptArray($page, <<<'JS'
        ({
            defaultCount: document.querySelectorAll('.themeSelect.themeDefault').length,
            lastIsDefault: document.querySelector('.themeSelect:last-of-type').classList.contains('themeDefault'),
        })
        JS);
    if (! is_int($result['defaultCount'] ?? null) || ! is_bool($result['lastIsDefault'] ?? null)) {
        throw new RuntimeException('unexpected result shape: ' . var_export($result, true));
    }

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
    $emailFilterVisible = H::scriptBool(
        $page,
        "document.getElementById('email_admin_on_new_user').offsetParent !== null"
    );
    if (! $emailFilterVisible) {
        $page->click('label.font-checkbox:has(input[name=allow_user_registration])');
    }
    $notifyChecked = H::scriptBool(
        $page,
        "document.querySelector('input[name=email_admin_on_new_user]').checked"
    );
    if (! $notifyChecked) {
        $page->click('label.font-checkbox:has(input[name=email_admin_on_new_user])');
    }

    $display = static fn (): string => H::scriptString(
        $page,
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

/**
 * `.help-popin` (`admin.latte`'s own page-wide help icon, registered by
 * `AdminShellView`) is colorbox's one real ajax/HTML-fallback call site
 * (P49-B) -- every other real call site is `photo` mode or `inline`
 * mode, both covered elsewhere (`PhotosAddApplicationsInteractionTest.
 * php`, `AddAlbumInteractionTest.php`). No prior test, jQuery-based or
 * not, ever drove this fetch-and-inject path.
 */
it('loads the help content into the popup via ajax', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=configuration');

    $page->click('a.help-popin');

    $loaded = H::scriptJson($page, <<<'JS'
        new Promise((resolve, reject) => {
            const deadline = Date.now() + 5000;
            const check = () => {
                // `#cboxLoadingOverlay`'s own `style.display` is set to
                // "none" in exactly one place: the tail of `prep()`'s own
                // reveal callback, the same callback that sets the title
                // -- `#cboxLoadedContent` is appended (with its real
                // content already inside) well before that reveal step,
                // so polling for its content instead races the callback
                // and reads the title before it's set.
                const settled = document.getElementById('cboxLoadingOverlay').style.display === 'none';
                if (settled) {
                    const loaded = document.getElementById('cboxLoadedContent');
                    return resolve(JSON.stringify({
                        title: document.getElementById('cboxTitle').textContent,
                        hasContent: loaded.textContent.trim().length > 0,
                        visible: getComputedStyle(document.getElementById('colorbox')).display !== 'none',
                    }));
                }
                if (Date.now() > deadline) {
                    return reject(new Error('colorbox never loaded the help content'));
                }
                setTimeout(check, 50);
            };
            check();
        })
        JS);

    expect($loaded['title'])->toBe('Help');
    expect($loaded['hasContent'])->toBeTrue();
    expect($loaded['visible'])->toBeTrue();

    // No assertNoServerErrors() here on purpose: it scans the *live* DOM
    // (Playwright's own page.content()), which now includes the ajax-
    // fetched help article's real prose, injected verbatim via
    // colorbox's own `innerHTML` -- `language/en_UK/help/configuration.
    // html`'s own real documentation text includes the literal sentence
    // "Notice: false by default." (describing a config default's own
    // value), a false positive against the helper's generic `/\bNotice:
    // \s/` server-error pattern, confirmed by fetching the endpoint
    // directly. Real app chrome, not an uncaught PHP notice.
    $page->assertNoJavaScriptErrors();
});
