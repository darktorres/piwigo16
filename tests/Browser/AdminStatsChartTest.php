<?php

declare(strict_types=1);

use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Api\Webpage;
use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

// The stats page is a canvas. Golden-html sees the server-rendered
// `<canvas>` element and its `data-*` payload; visual regression sees the
// pixels but accepts whatever is there as the baseline. Neither can tell a
// drawn chart from an empty one, and the failure mode this file is most
// exposed to produces exactly that.
//
// Six `data-*` attributes on #data hold JSON objects. jQuery's `.data()`
// parses a brace-wrapped attribute into an object; `dataset` hands back the
// raw string. Reaching for `dataset` throws nothing -- `Object.keys()` on a
// string returns its character indices -- so the chart would render from
// hundreds of garbage points with no error anywhere. Hence a test that
// asserts the canvas was actually painted and repaints when the data
// selection changes.

/**
 * Chart.js animates its first draw and resizes the canvas as it goes, which
 * moves the labels underneath. Clicking one before that settles fails on
 * Playwright's stability check, not on anything in the page -- so every test
 * here waits for the first paint before touching a control.
 */
function statsWaitForFirstPaint(Webpage|PendingAwaitablePage|AwaitableWebpage $page, int $timeoutMs = 10000): void
{
    $page->script(<<<JS
    new Promise((resolve, reject) => {
        const deadline = Date.now() + {$timeoutMs};
        const check = () => {
            const canvas = document.getElementById('stat-graph');
            if (canvas !== null) {
                // Compared against a blank canvas of identical size, so this
                // cannot pass on an untouched element.
                const blank = document.createElement('canvas');
                blank.width = canvas.width;
                blank.height = canvas.height;
                if (canvas.toDataURL() !== blank.toDataURL()) {
                    return resolve(true);
                }
            }
            if (Date.now() > deadline) {
                return reject(new Error('Timed out waiting for the chart to be drawn'));
            }
            setTimeout(check, 100);
        };
        check();
    })
    JS);
}

/**
 * Chart.js animates, so the repaint lands after the click returns.
 */
function statsWaitForRepaint(Webpage|PendingAwaitablePage|AwaitableWebpage $page, string $before, int $timeoutMs = 10000): void
{
    $encoded = json_encode($before, JSON_THROW_ON_ERROR);

    $page->script(<<<JS
    new Promise((resolve, reject) => {
        const deadline = Date.now() + {$timeoutMs};
        const before = {$encoded};
        const check = () => {
            const canvas = document.getElementById('stat-graph');
            if (canvas !== null && canvas.toDataURL() !== before) {
                return resolve(true);
            }
            if (Date.now() > deadline) {
                return reject(new Error('Timed out waiting for the chart to repaint'));
            }
            setTimeout(check, 100);
        };
        check();
    })
    JS);
}

it('paints the chart from the JSON payload and repaints on a new selection', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=stats');

    statsWaitForFirstPaint($page);

    /** @var string $painted */
    $painted = $page->script("document.getElementById('stat-graph').toDataURL()");

    // Switching the data type feeds a different series to the same chart.
    // The axes alone differ between them, so the canvas must change.
    $page->click('.stat-data-selector label[data-value="months"]');
    statsWaitForRepaint($page, $painted);

    $page->assertNoJavaScriptErrors();
});

it('moves the selection off the unavailable ranges when compare mode is turned on', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=stats');

    // Compare mode has no hourly or daily series, so those two selectors are
    // greyed and the selection is pushed to Year -- but only when one of
    // them was the active choice. That conditional is the whole of the
    // handler's state juggling and is invisible to every snapshot.
    statsWaitForFirstPaint($page);

    // Day is the server-rendered default, and it cannot be clicked into
    // place here even if it were not: the admin theme sets `pointer-events:
    // none` on `input:checked + label`, so the active selector is
    // deliberately inert. Assert the precondition instead of establishing
    // it -- if the default ever moves, this says so rather than passing on
    // a scenario that no longer exercises the branch.

    /** @var array{unavailable: int, days: bool, hours: bool, years: bool} $before */
    $before = $page->script(<<<'JS'
    (() => ({
        unavailable: document.querySelectorAll('.stat-data-selector label.unavailable').length,
        days: document.getElementById('days-selector').checked,
        hours: document.getElementById('hours-selector').checked,
        years: document.getElementById('years-selector').checked,
    }))()
    JS);

    expect($before['unavailable'])->toBe(0);
    expect($before['days'])->toBeTrue();

    $page->click('.stat-compare-mode label.switch');

    /** @var array{unavailable: int, days: bool, hours: bool, years: bool} $after */
    $after = $page->script(<<<'JS'
    (() => ({
        unavailable: document.querySelectorAll('.stat-data-selector label.unavailable').length,
        days: document.getElementById('days-selector').checked,
        hours: document.getElementById('hours-selector').checked,
        years: document.getElementById('years-selector').checked,
    }))()
    JS);

    // Both the Hour and the Day label, reached through a `+ label` sibling
    // selector over a two-selector list.
    expect($after['unavailable'])->toBe(2);
    expect($after['days'])->toBeFalse();
    expect($after['hours'])->toBeFalse();
    expect($after['years'])->toBeTrue();

    // Turning it back off releases both labels again.
    $page->click('.stat-compare-mode label.switch');

    /** @var int $released */
    $released = $page->script(
        "document.querySelectorAll('.stat-data-selector label.unavailable').length"
    );

    expect($released)
        ->toBe(0);

    $page->assertNoJavaScriptErrors();
});
