<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

// The format-mode switch on admin.php?page=photos_add came out of an inline
// `onClick` in photos_add_direct.latte during the Latte-handler pass
// (docs/PLAN.md P49-A step 3). The URL it used to interpolate now rides on
// the label as `data-switch-format-mode-url`, because a real listener cannot
// read a template variable.
//
// It has never been observed running. The whole block is behind
// `n:if="$enableFormats and $can_upload"`, and `enable_formats` is off in
// the fixture, so it renders on no snapshot and no test has ever reached
// it -- the one part of that pass that was converted blind.

it('navigates and marks itself loading when the format-mode switch is clicked', function (): void {
    $snapshot = H::snapshotConfig(['enable_formats']);
    H::setConfigValue('enable_formats', 'true');

    try {
        $page = H::asAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=photos_add');

        // Without the config above this block does not render at all, which
        // is exactly why the handler was never exercised.
        $page->assertPresent('.format-mode-group-manager .switch');

        /** @var array{url: string, resolved: string, checked: bool, here: string, sliders: int} $before */
        $before = $page->script(<<<'JS'
        (() => {
            const label = document.querySelector('.format-mode-group-manager .switch');
            const box = document.getElementById('toggleFormatMode');

            const raw = label.getAttribute('data-switch-format-mode-url') || '';

            return {
                url: raw,
                // The attribute is relative (the renderer prefixes it with
                // getRootUrl(), which is empty here), so resolve it the way
                // location.replace() will.
                resolved: raw === '' ? '' : new URL(raw, location.href).href,
                checked: box !== null && box.checked,
                here: location.href,
                // The handler's own selector is page-wide, not scoped to
                // the switch that was clicked -- as the inline onClick it
                // replaced was. Counted so the assertion pins that rather
                // than assuming one.
                sliders: document.querySelectorAll('.switch .slider').length,
            };
        })()
        JS);

        // The attribute carries the destination; the handler reads it via
        // jQuery's `.data()`, which maps the dashed key straight through.
        expect($before['url'])->not->toBe('');
        expect($before['url'])->toContain('page=photos_add');

        // The loading class is added synchronously and then the page is
        // replaced, so it cannot be read after the fact. Record it in
        // sessionStorage, which survives a same-origin navigation.
        $page->script(<<<'JS'
        (() => {
            sessionStorage.removeItem('pwgFormatModeLoading');
            const label = document.querySelector('.format-mode-group-manager .switch');
            label.click();
            sessionStorage.setItem(
                'pwgFormatModeLoading',
                String(document.querySelectorAll('.switch .slider.loading').length)
            );
        })()
        JS);

        $timeoutMs = 10000;
        $startedAt = json_encode($before['here'], JSON_THROW_ON_ERROR);
        $page->script(<<<JS
        new Promise((resolve, reject) => {
            const deadline = Date.now() + {$timeoutMs};
            const check = () => {
                if (location.href !== {$startedAt}) {
                    return resolve(true);
                }
                if (Date.now() > deadline) {
                    return reject(new Error('Timed out waiting for the format-mode navigation'));
                }
                setTimeout(check, 100);
            };
            check();
        })
        JS);

        /** @var array{here: string, loading: string, checked: bool} $after */
        $after = $page->script(<<<'JS'
        (() => {
            const box = document.getElementById('toggleFormatMode');

            return {
                here: location.href,
                loading: sessionStorage.getItem('pwgFormatModeLoading') || '',
                checked: box !== null && box.checked,
            };
        })()
        JS);

        // It went where the attribute said, and it flipped the mode.
        expect($after['here'])->toBe($before['resolved']);
        expect($after['checked'])->toBe(! $before['checked']);

        // And it marked the sliders first -- the visible half of the
        // handler, and the half a navigation-only assertion would miss
        // entirely. Every `.switch .slider` on the page, not just this
        // one: `$(".switch .slider")` is unscoped, and there are three.
        expect($before['sliders'])->toBeGreaterThan(1);
        expect($after['loading'])->toBe((string) $before['sliders']);

        $page->assertNoJavaScriptErrors();
    } finally {
        H::restoreConfig($snapshot);
    }
});
