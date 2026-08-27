<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

// Both pages here render fully server-side, so golden-html and visual
// regression pass over them whether or not their JavaScript works at all.
// Everything these two entries do -- an AJAX refresh that rewrites four
// groups of values, and three conditional show/hide branches -- happens only
// after a click or a change event, which no snapshot instrument reaches.

it('refreshes the cache size and clears the spinner it started', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=maintenance');

    // The spin class is added on click and removed when the response lands,
    // so a broken request leaves it spinning forever. Assert both ends.
    $page->assertPresent('.refresh-cache-size');
    $page->click('.refresh-cache-size');

    $timeoutMs = 10000;
    $page->script(<<<JS
    new Promise((resolve, reject) => {
        const deadline = Date.now() + {$timeoutMs};
        const check = () => {
            const value = document.querySelector('.cache-size-value');
            // "%s MB" with a real two-decimal number substituted in: the
            // server-rendered placeholder never looks like this.
            if (value !== null && /\\d+\\.\\d{2}/.test(value.textContent)) {
                return resolve(true);
            }
            if (Date.now() > deadline) {
                return reject(new Error('Timed out waiting for the cache size to be filled in'));
            }
            setTimeout(check, 100);
        };
        check();
    })
    JS);

    /** @var array{spinners: int, lastCalculated: string, titled: int} $state */
    $state = $page->script(<<<'JS'
    (() => {
        const titled = Array.from(
            document.querySelectorAll('.delete-check-container > .delete-size-check')
        ).filter(node => /\d+\.\d{2}/.test(node.title));

        return {
            spinners: document.querySelectorAll('.animate-spin').length,
            lastCalculated: (document.querySelector('.cache-lastCalculated-value') || {}).textContent || '',
            titled: titled.length,
        };
    })()
    JS);

    // The spinner is cleared by a selector-wide removeClass, so a leftover
    // here means the success path never ran to the end.
    expect($state['spinners'])->toBe(0);
    expect($state['lastCalculated'])->not->toBe('');

    // `.children('.delete-size-check')` is direct children only. Each one
    // gets a per-size title written from the response, which is the part
    // that would silently vanish if the traversal were widened to
    // descendants or narrowed to the first container.
    expect($state['titled'])->toBeGreaterThan(0);

    $page->assertNoJavaScriptErrors();
});

it('shows the custom watermark position details only for the custom position', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=configuration&section=watermark');

    // The stored position decides the initial state, so read it rather than
    // assuming: this test must not depend on which radio the fixture leaves
    // selected.
    /** @var bool $startsCustom */
    $startsCustom = $page->script(
        "document.querySelector(\"input[name='w[position]']:checked\").value === 'custom'"
    );

    if ($startsCustom) {
        $page->assertVisible('#positionCustomDetails');
    } else {
        $page->assertMissing('#positionCustomDetails');
    }

    // The radios themselves are visually replaced by common.ts's
    // fontCheckbox widget, so the clickable control is the wrapping label.
    // Clicking it is the real user path: the label checks its input and the
    // browser fires the change event this file listens for.
    $page->click('label.custom-position-label');
    $page->assertVisible('#positionCustomDetails');

    $page->click("#watermarkPositionBox label:nth-of-type(2)");
    $page->assertMissing('#positionCustomDetails');

    // The add/select watermark panels swap, a single toggle() over a
    // two-selector set -- so exactly one is visible at a time. There is one
    // opener inside each panel, which is why the handler binds to every
    // `.addWatermarkOpen` rather than to the first: the second one is what
    // swaps back, and it only exists inside the panel it closes.
    $page->assertVisible('#selectWatermark');
    $page->assertMissing('#addWatermark');

    $page->click('#selectWatermark .addWatermarkOpen');
    $page->assertVisible('#addWatermark');
    $page->assertMissing('#selectWatermark');

    $page->click('#addWatermark .addWatermarkOpen');
    $page->assertVisible('#selectWatermark');
    $page->assertMissing('#addWatermark');

    $page->assertNoJavaScriptErrors();
});
