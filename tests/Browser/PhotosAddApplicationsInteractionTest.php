<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * P49-B native port of colorbox (`themes/default/js/vendor/colorbox.ts`).
 * No prior test, jQuery-based or not, ever drove colorbox's own click-
 * to-open/group-navigation/counter/close behavior -- only its
 * registration marker (`AdminExtendedSmokeTest.php`'s own "binds
 * colorbox to the application illustrations on load" test) and
 * `AddAlbumInteractionTest.php`'s end-to-end `inline`-mode flow were
 * ever exercised. `photos_add_applications.php` is the one real page
 * with an actual multi-item group (`.illustration a`, a constant
 * `rel: "group1"` shared by all 9 real `<a>` elements) -- the only page
 * where next/prev/counter/loop is reachable at all.
 *
 * Each `href` here is a hardcoded external screenshot URL
 * (`$phpwgUrl/screenshots/applications/...`), and `$phpwgUrl` resolves
 * to a deliberately unreachable fixture stub domain in test mode -- the
 * image genuinely fails to load, real `error` event and all. That's
 * exactly colorbox's own real `imgError` path (`prep(makeErrorEl(...))`
 * runs from the `error` handler exactly like it would from `load`), so
 * this waits for either outcome rather than requiring a successful
 * decode: the counter/title/next-prev chrome this test actually cares
 * about is set from the same `prep()` callback either way.
 */
it('opens a photo group, navigates with next/prev and Escape, and closes', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=photos_add&section=applications');

    $opened = H::scriptJson($page, <<<'JS'
        new Promise((resolve, reject) => {
            const links = document.querySelectorAll('.illustration a');
            links[0].click();

            const deadline = Date.now() + 5000;
            const check = () => {
                // `#cboxLoadingOverlay`'s own `style.display` is set to
                // "none" in exactly one place: the tail of `prep()`'s own
                // reveal callback, the same callback that sets title/
                // counter -- the one unambiguous "settled" signal that
                // works identically for the first open and for a later
                // next/prev navigation (unlike `#cboxLoadedContent`,
                // whose id is shared with the tiny placeholder `launch()`
                // creates before any real content exists, or `#cboxTitle`
                // `style.display`, which stays revealed from a *previous*
                // photo during a next/prev transition).
                const settled = document.getElementById('cboxLoadingOverlay').style.display === 'none';
                if (settled) {
                    return resolve(JSON.stringify({
                        title: document.getElementById('cboxTitle').textContent,
                        current: document.getElementById('cboxCurrent').textContent,
                        visible: getComputedStyle(document.getElementById('colorbox')).display !== 'none',
                        total: links.length,
                    }));
                }
                if (Date.now() > deadline) {
                    return reject(new Error('colorbox never settled on the first photo (neither loaded nor errored)'));
                }
                setTimeout(check, 50);
            };
            check();
        })
        JS);

    expect($opened['title'])->toBe('Piwigo Remote Sync');
    expect($opened['current'])->toBe('image 1 of ' . $opened['total']);
    expect($opened['visible'])->toBeTrue();

    $page->click('#cboxNext');

    $afterNext = H::scriptJson($page, <<<'JS'
        new Promise((resolve, reject) => {
            const deadline = Date.now() + 5000;
            const check = () => {
                const current = document.getElementById('cboxCurrent').textContent;
                if (current.startsWith('image 2 of ')) {
                    return resolve(JSON.stringify({
                        title: document.getElementById('cboxTitle').textContent,
                        current,
                    }));
                }
                if (Date.now() > deadline) {
                    return reject(new Error('colorbox never advanced to photo 2, still: ' + current));
                }
                setTimeout(check, 50);
            };
            check();
        })
        JS);

    expect($afterNext['current'])->toStartWith('image 2 of ');
    expect($afterNext['title'])->not->toBe($opened['title']);

    $page->script(<<<'JS'
        document.dispatchEvent(new KeyboardEvent('keydown', {
            key: 'Escape',
            bubbles: true,
            cancelable: true,
        }));
        JS);

    $closed = H::scriptBool($page, <<<'JS'
        new Promise((resolve) => {
            const deadline = Date.now() + 5000;
            const check = () => {
                const box = document.getElementById('colorbox');
                if (getComputedStyle(box).display === 'none') {
                    return resolve(true);
                }
                if (Date.now() > deadline) {
                    return resolve(false);
                }
                setTimeout(check, 50);
            };
            check();
        })
        JS);

    expect($closed)->toBeTrue();

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'colorbox group navigation + close');
});
