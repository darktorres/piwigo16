<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * P49-A conversion of themes/admin/default/js/admin.ts's own
 * `lightAccordion` -- the #menubar sidebar accordion every admin page
 * renders. No existing test drives it: it has no golden-html/VR signal at
 * all (a snapshot captures the page at rest, after the accordion's own
 * ready-time setup already ran, so it can't tell "opened by the active
 * section on load" apart from "opened because a click handler is broken
 * and everything stayed open"). Was a jQuery plugin (`$.fn.lightAccordion`,
 * one real call site); is now a plain function, since nothing else ever
 * called it -- the ambient `LightAccordionOptions` type moved out of
 * build/jquery-plugins.d.ts along with it.
 */
it('opens exactly one menubar section at a time, closing the previous one', function (): void {
    $page = H::asAdmin($this);

    // Deterministic baseline regardless of which section active_menu
    // opens by default: click Photos first, then assert Albums replaces
    // it, not merely that Albums opened.
    $page->click('#menubar dl:nth-of-type(1) dt');
    $page->script(<<<'JS'
        new Promise((resolve, reject) => {
            const deadline = Date.now() + 5000;
            const check = () => {
                const dd = document.querySelector('#menubar dl:nth-of-type(1) dd');
                if (getComputedStyle(dd).display !== 'none') {
                    return resolve(true);
                }
                if (Date.now() > deadline) {
                    return reject(new Error('Photos section never opened'));
                }
                setTimeout(check, 100);
            };
            check();
        })
        JS);

    $page->click('#menubar dl:nth-of-type(2) dt');

    $result = H::scriptArray($page, <<<'JS'
        new Promise((resolve, reject) => {
            const deadline = Date.now() + 5000;
            const check = () => {
                const photos = document.querySelector('#menubar dl:nth-of-type(1) dd');
                const albums = document.querySelector('#menubar dl:nth-of-type(2) dd');
                if (
                    getComputedStyle(albums).display !== 'none'
                    && getComputedStyle(photos).display === 'none'
                ) {
                    return resolve({
                        photosDisplay: getComputedStyle(photos).display,
                        albumsDisplay: getComputedStyle(albums).display,
                    });
                }
                if (Date.now() > deadline) {
                    return reject(new Error(
                        'Albums never replaced Photos -- photos display: '
                        + getComputedStyle(photos).display
                        + ', albums display: '
                        + getComputedStyle(albums).display
                    ));
                }
                setTimeout(check, 100);
            };
            check();
        })
        JS);

    if (! is_string($result['photosDisplay'] ?? null) || ! is_string($result['albumsDisplay'] ?? null)) {
        throw new RuntimeException('unexpected result shape: ' . var_export($result, true));
    }

    expect($result['photosDisplay'])->toBe('none');
    expect($result['albumsDisplay'])->not->toBe('none');

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'menubar accordion click-to-switch');
});
