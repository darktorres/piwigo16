<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * P49-A conversion of themes/admin/default/js/configuration/sizes.ts --
 * 0% live-interaction coverage before this (no existing Browser test file
 * for this page at all).
 *
 * `.restore-settings-button`'s `.pwg_jconfirm_follow_href()` stays jQuery
 * (jquery-confirm, P49-B group 5).
 */
it('toggles the original-size resize fields based on the checkbox', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=configuration&section=sizes');

    $display = static fn (): string => H::scriptString(
        $page,
        "getComputedStyle(document.getElementById('sizeEdit-original')).display"
    );

    $initiallyChecked = H::scriptBool(
        $page,
        "document.querySelector('[name=original_resize]').checked"
    );

    if ($initiallyChecked) {
        expect($display())
            ->not->toBe('none');
    } else {
        expect($display())
            ->toBe('none');
    }

    $page->click('label.font-checkbox:has(input[name=original_resize])');

    if ($initiallyChecked) {
        expect($display())
            ->toBe('none');
    } else {
        expect($display())
            ->not->toBe('none');
    }

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'configuration_sizes original-resize toggle');
});

it('opens a size\'s edit row and hides its own open link', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=configuration&section=sizes');

    // The edit link itself sits inside a `.sizeDetails` span, hidden by
    // default (configuration_sizes.css) until #showDetails is clicked --
    // its own computed `display` stays "inline" regardless (an ancestor's
    // display:none doesn't change a descendant's own specified value), so
    // offsetParent (real layout visibility) is what actually distinguishes
    // "rendered" from "not" here.
    $page->click('#showDetails');

    $sizeName = H::scriptString(
        $page,
        "document.querySelector('a.sizeEditOpen').id.replace('sizeEditOpen-', '')"
    );

    $rowDisplay = static fn (): string => H::scriptString(
        $page,
        "getComputedStyle(document.getElementById('sizeEdit-{$sizeName}')).display"
    );
    $linkVisible = static fn (): bool => H::scriptBool(
        $page,
        "document.getElementById('sizeEditOpen-{$sizeName}').offsetParent !== null"
    );

    expect($rowDisplay())
        ->toBe('none');
    expect($linkVisible())
        ->toBeTrue();

    $page->click('#sizeEditOpen-' . $sizeName);

    expect($rowDisplay())
        ->not->toBe('none');
    expect($linkVisible())
        ->toBeFalse();

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'configuration_sizes sizeEditOpen toggle');
});

it('swaps the width/height labels when the crop checkbox is toggled', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=configuration&section=sizes');

    // The edit link is inside a `.sizeDetails` span, hidden until this is
    // clicked -- see the sizeEditOpen test's own docblock for why.
    $page->click('#showDetails');

    // The crop checkbox only exists for a size that isn't forced square
    // (`n:if="!$d->mustSquare"`) -- scope to a row that actually has one.
    $rawSizeName = $page->script(<<<'JS'
        (() => {
            const cb = document.querySelector('.sizeEditForm .cropToggle');
            const row = cb ? cb.closest('tr[id^="sizeEdit-"]') : null;
            return row ? row.id.replace('sizeEdit-', '') : null;
        })()
        JS);
    expect($rawSizeName)
        ->not->toBeNull();
    if (! is_string($rawSizeName)) {
        throw new RuntimeException('expected a string sizeName, got: ' . var_export($rawSizeName, true));
    }
    $sizeName = $rawSizeName;

    $page->click('#sizeEditOpen-' . $sizeName);

    $labels = static function () use ($page, $sizeName): array {
        $result = H::scriptArray($page, <<<JS
            (() => {
                const row = document.getElementById('sizeEdit-{$sizeName}');
                return {
                    width: row.querySelector('.sizeEditWidth').textContent,
                    height: row.querySelector('.sizeEditHeight').textContent,
                };
            })()
            JS);
        if (! is_string($result['width'] ?? null) || ! is_string($result['height'] ?? null)) {
            throw new RuntimeException('unexpected labels shape: ' . var_export($result, true));
        }

        return [
            'width' => $result['width'],
            'height' => $result['height'],
        ];
    };

    $wasChecked = H::scriptBool(
        $page,
        "document.querySelector('#sizeEdit-{$sizeName} .cropToggle').checked"
    );

    $page->click('#sizeEdit-' . $sizeName . ' label.font-checkbox:has(.cropToggle)');

    $after = $labels();
    if ($wasChecked) {
        expect($after['width'])->toContain('Maximum width');
        expect($after['height'])->toContain('Maximum height');
    } else {
        expect($after['width'])->toContain('Width');
        expect($after['height'])->toContain('Height');
    }

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'configuration_sizes crop-toggle label swap');
});

it('shows every size\'s details and hides the showDetails link', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=configuration&section=sizes');

    $anySizeDetailsVisible = H::scriptBool(
        $page,
        "Array.from(document.querySelectorAll('.sizeDetails')).some((el) => getComputedStyle(el).display !== 'none')"
    );
    expect($anySizeDetailsVisible)
        ->toBeFalse();

    $page->click('#showDetails');

    $allSizeDetailsVisible = H::scriptBool(
        $page,
        "Array.from(document.querySelectorAll('.sizeDetails')).every((el) => getComputedStyle(el).display !== 'none')"
    );
    expect($allSizeDetailsVisible)
        ->toBeTrue();

    $showDetailsVisibility = H::scriptString(
        $page,
        "getComputedStyle(document.getElementById('showDetails')).visibility"
    );
    expect($showDetailsVisibility)
        ->toBe('hidden');

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'configuration_sizes showDetails');
});
