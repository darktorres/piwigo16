<?php

declare(strict_types=1);

use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Api\Webpage;
use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * P49-A conversion of themes/admin/default/js/common.ts -- shared across
 * every admin page whose real entry imports it (directly, or via an
 * `import "../common"`-only pages/*.ts wrapper). 0% prior live-interaction
 * coverage of `fontCheckbox()` (the first-party jQuery.fn plugin, wrapping
 * no library, converted in full -- "wraps: --" in docs/PLAN.md's own P49
 * plugin table) or the `.search-cancel`/`.search-input` toggle.
 *
 * `admin.php?page=albums` is the one template carrying both behaviours at
 * once: its own `albums.ts` imports `./common` already (for
 * jConfirm_confirm_options), and neither behaviour touches any of
 * albums.ts's own (still-jQuery) code -- confirmed no `search-input`/
 * `search-cancel` reference there at all, so this is common.ts's own
 * surface, uncontaminated by the file it happens to share a bundle with.
 *
 * `TemporaryState` (also common.ts) is not converted or tested here: it
 * stays jQuery-typed, deferred to whichever of its 2 real callers
 * (users/group_list.ts, tags.ts) converts first (see the class's own docblock).
 * `pwg_jconfirm_follow_href` stays jQuery (jquery-confirm, P49-B group 5).
 */

/**
 * Narrows a single font-checkbox row (`{value, checked, selected, icon}`)
 * decoded from H::scriptJson() -- shared by every test below that reads
 * this shape, since script()'s return is `mixed` however $page is typed.
 *
 * @return array{value: string, checked: bool, selected: bool, icon: string}
 */
function commonInteractionRow(mixed $row): array
{
    if (
        ! is_array($row)
        || ! is_string($row['value'] ?? null)
        || ! is_bool($row['checked'] ?? null)
        || ! is_bool($row['selected'] ?? null)
        || ! is_string($row['icon'] ?? null)
    ) {
        throw new RuntimeException('commonInteractionRow(): unexpected shape: ' . var_export($row, true));
    }

    return [
        'value' => $row['value'],
        'checked' => $row['checked'],
        'selected' => $row['selected'],
        'icon' => $row['icon'],
    ];
}

it('marks the checked radio\'s label "selected" and gives every other one the empty-circle icon on load', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=albums');

    $rows = H::scriptJson($page, <<<'JS'
        JSON.stringify(
            Array.from(document.querySelectorAll('label.font-checkbox'))
                .filter((label) => label.querySelector('input[name="order"]') !== null)
                .map((label) => ({
                    value: label.querySelector('input').value,
                    checked: label.querySelector('input').checked,
                    selected: label.classList.contains('selected'),
                    icon: label.querySelector('span').className,
                }))
        )
        JS);

    expect($rows)
        ->not->toBe([]);
    foreach ($rows as $rawRow) {
        $row = commonInteractionRow($rawRow);
        if ($row['checked']) {
            expect($row['selected'])->toBeTrue("row {$row['value']} should be selected");
            expect($row['icon'])->toBe('icon-dot-circled', "row {$row['value']}'s icon");
        } else {
            expect($row['selected'])->toBeFalse("row {$row['value']} should not be selected");
            expect($row['icon'])->toBe('icon-circle-empty', "row {$row['value']}'s icon");
        }
    }

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'common.ts fontCheckbox radio init');
});

it('moves "selected" and the icon to a newly-clicked radio in the same group, both directions', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=albums');

    // The "order" radio group lives inside `.cat-move-order-popin`, hidden
    // (`fadeOut`-ed) until `.order-root` is clicked -- still-jQuery,
    // untouched code, just the real way a user reaches this markup.
    $page->click('.order-root');

    $rowFor = static fn (Webpage|PendingAwaitablePage|AwaitableWebpage $page, string $value): array => commonInteractionRow(H::scriptJson(
        $page,
        "(() => { const input = document.querySelector('input[name=\"order\"][value=\"{$value}\"]'); const label = input.closest('label'); return JSON.stringify({ checked: input.checked, selected: label.classList.contains('selected'), icon: label.querySelector('span').className, value: '{$value}' }); })()"
    ));

    $initialAsc = $rowFor($page, 'name ASC');
    expect($initialAsc['selected'])->toBeTrue();
    expect($initialAsc['icon'])->toBe('icon-dot-circled');

    $page->click('label:has(input[name="order"][value="name DESC"])');

    $descAfterClick = $rowFor($page, 'name DESC');
    $ascAfterClick = $rowFor($page, 'name ASC');
    expect($descAfterClick['selected'])->toBeTrue();
    expect($descAfterClick['icon'])->toBe('icon-dot-circled');
    expect($ascAfterClick['selected'])->toBeFalse();
    expect($ascAfterClick['icon'])->toBe('icon-circle-empty');

    // Back the other way, to prove this isn't a one-shot init artifact.
    $page->click('label:has(input[name="order"][value="name ASC"])');

    $ascAfterSecondClick = $rowFor($page, 'name ASC');
    $descAfterSecondClick = $rowFor($page, 'name DESC');
    expect($ascAfterSecondClick['selected'])->toBeTrue();
    expect($ascAfterSecondClick['icon'])->toBe('icon-dot-circled');
    expect($descAfterSecondClick['selected'])->toBeFalse();
    expect($descAfterSecondClick['icon'])->toBe('icon-circle-empty');

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'common.ts fontCheckbox radio change');
});

it('shows the search-cancel icon while the search box has text, and clears it back to hidden on click', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=albums');

    $cancelVisible = static fn (Webpage|PendingAwaitablePage|AwaitableWebpage $page): bool => H::scriptBool(
        $page,
        "document.querySelector('.search-cancel').offsetParent !== null"
    );
    $searchValue = static fn (Webpage|PendingAwaitablePage|AwaitableWebpage $page): string => H::scriptString(
        $page,
        "document.querySelector('.search-input').value"
    );

    expect($cancelVisible($page))
        ->toBeFalse();

    $page->fill('#cat_search_input', 'some album');

    expect($cancelVisible($page))
        ->toBeTrue();

    $page->click('.search-cancel');

    expect($cancelVisible($page))
        ->toBeFalse();
    expect($searchValue($page))
        ->toBe('');

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'common.ts search-cancel toggle');
});

it('fontCheckbox() does not double-process a radio nested under two overlapping .font-checkbox matches', function (): void {
    // configuration_main.latte's mail-theme picker wraps
    // `<label class="font-checkbox">` radios inside an outer
    // `<div class="themeBoxes font-checkbox">` -- the exact nested shape
    // that broke on this file's first pass (fontCheckbox() called once
    // per matched .font-checkbox element double-toggled the icon on any
    // radio under both, cancelling back to its untouched state). Confirmed
    // against a real regression on this page's own VR baseline before the
    // fix (document-root single call, see fontCheckbox()'s own docblock).
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=configuration');

    $rowFor = static fn (Webpage|PendingAwaitablePage|AwaitableWebpage $page, string $value): array => commonInteractionRow(H::scriptJson(
        $page,
        "(() => { const input = document.querySelector('input[name=\"mail_theme\"][value=\"{$value}\"]'); const label = input.closest('label.font-checkbox'); return JSON.stringify({ checked: input.checked, selected: label.classList.contains('selected'), icon: label.querySelector('span').className, value: '{$value}' }); })()"
    ));

    $clear = $rowFor($page, 'clear');
    $dark = $rowFor($page, 'dark');

    expect($clear['checked'])->toBeTrue();
    expect($clear['selected'])->toBeTrue();
    expect($clear['icon'])->toBe('icon-dot-circled');
    expect($dark['checked'])->toBeFalse();
    expect($dark['selected'])->toBeFalse();
    expect($dark['icon'])->toBe('icon-circle-empty');

    $page->click('label:has(input[name="mail_theme"][value="dark"])');

    $clearAfter = $rowFor($page, 'clear');
    $darkAfter = $rowFor($page, 'dark');
    expect($darkAfter['selected'])->toBeTrue();
    expect($darkAfter['icon'])->toBe('icon-dot-circled');
    expect($clearAfter['selected'])->toBeFalse();
    expect($clearAfter['icon'])->toBe('icon-circle-empty');

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'common.ts fontCheckbox nested-container regression');
});

it('does not touch a plain input[type=radio] outside any .font-checkbox ancestor', function (): void {
    // cat_list.latte's `.AlbumViewSelector` (displayCompact/displayLine/
    // displayTile) is a plain radio group with no `.font-checkbox` ancestor
    // at all -- confirmed a real regression when fontCheckbox() was scoped
    // to `document` without the `.font-checkbox` selector prefix (see the
    // function's own docblock): every one of these icons wrongly gained
    // "icon-dot-circled"/"icon-circle-empty", corrupting the view-mode
    // switcher's real icons (icon-th-large/icon-th-list/icon-pause). The
    // corrupted element isn't necessarily one of those spans -- the input's
    // `previousElementSibling` for a mid-group radio is the PRECEDING
    // radio's own `<label>` wrapper, which is what wrongly received both
    // classes under the mutation this test guards against -- so check every
    // element's className inside the container, not just the icon spans.
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=cat_list');

    $classes = H::scriptJson(
        $page,
        "JSON.stringify(Array.from(document.querySelectorAll('.AlbumViewSelector *')).map((el) => el.className))"
    );

    expect($classes)
        ->not->toBe([]);
    foreach ($classes as $className) {
        if (! is_string($className)) {
            throw new RuntimeException('expected a className string, got: ' . var_export($className, true));
        }
        expect($className)
            ->not->toContain('icon-dot-circled');
        expect($className)
            ->not->toContain('icon-circle-empty');
    }

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'common.ts fontCheckbox scoping regression');
});
