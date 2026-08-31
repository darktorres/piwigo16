<?php

declare(strict_types=1);

use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Api\Webpage;
use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * P49-A conversion of themes/admin/default/js/batchManagerFilter.ts -- 0%
 * prior live-interaction coverage (BatchManagerUnitPageRendererTest.php/
 * BatchManagerGlobalPageRendererTest.php only ever POST real filter
 * values directly, never exercise the addFilter/removeFilter dropdown UI
 * itself).
 *
 * `filter_dimension` is the filter used for the enable/remove flow: it has
 * no selectize/AlbumSelector involvement (unlike category/tags), so it
 * isolates this file's own converted DOM logic from the still-jQuery
 * selectize widgets its sibling filters depend on (P49-B group 6, not
 * yet ported). `pwgDoubleSlider()` (`themes/admin/default/js/
 * doubleSlider.ts`) -- also `filter_dimension`'s own, wrapping jQuery
 * UI's slider widget -- is a native port too now (P49-B group 4); the
 * preset-button test below is real new coverage for it, not just a
 * regression check: no prior test, jQuery-based or not, ever drove any
 * of this page's sliders.
 *
 * `/admin.php?page=batch_manager&mode=unit` always starts with
 * `filter_prefilter` active (a default prefilter, confirmed live -- not
 * this file's own concern) -- so the "no filter selected" restore branch
 * of filter_disable() is not reachable from this page and isn't asserted.
 */
it('opens the add-filter dropdown on click and closes it on an outside click', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=batch_manager&mode=unit');

    $waitFor = static function (Webpage|PendingAwaitablePage|AwaitableWebpage $page, string $condition, string $failMessage): void {
        $page->script(
            <<<JS
            new Promise((resolve, reject) => {
                const deadline = Date.now() + 5000;
                const check = () => {
                    if ({$condition}) {
                        return resolve(true);
                    }
                    if (Date.now() > deadline) {
                        return reject(new Error('{$failMessage}'));
                    }
                    setTimeout(check, 100);
                };
                check();
            })
            JS
            ,
        );
    };
    $isOpen = static fn (Webpage|PendingAwaitablePage|AwaitableWebpage $page): mixed => $page->script(
        "document.querySelector('.addFilter-dropdown').offsetParent !== null"
    );

    expect($isOpen($page))
        ->toBeFalse();

    $page->click('.addFilter-button');
    // slideToggle() animates -- wait for the dropdown to actually settle
    // open before clicking elsewhere, rather than racing the animation.
    $waitFor($page, "document.querySelector('.addFilter-dropdown').offsetParent !== null", 'dropdown never opened');

    $page->click('#filterList');
    $waitFor($page, "document.querySelector('.addFilter-dropdown').offsetParent === null", 'dropdown never closed on outside click');

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'batchManagerFilter add-filter dropdown toggle');
});

it('enables the dimension filter from the dropdown, then removes it', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=batch_manager&mode=unit');

    $filterVisible = static fn (Webpage|PendingAwaitablePage|AwaitableWebpage $page): mixed => $page->script(
        "document.getElementById('filter_dimension').offsetParent !== null"
    );
    $linkDisabled = static fn (Webpage|PendingAwaitablePage|AwaitableWebpage $page): mixed => $page->script(
        "document.querySelector('#addFilter a[data-value=\"filter_dimension\"]').classList.contains('disabled')"
    );
    $checkboxChecked = static fn (Webpage|PendingAwaitablePage|AwaitableWebpage $page): mixed => $page->script(
        "document.querySelector('input[name=\"filter_dimension_use\"]').checked"
    );

    expect($filterVisible($page))
        ->toBeFalse();
    expect($linkDisabled($page))
        ->toBeFalse();
    expect($checkboxChecked($page))
        ->toBeFalse();

    $page->click('.addFilter-button');
    $page->click('#addFilter a[data-value="filter_dimension"]');

    expect($filterVisible($page))
        ->toBeTrue();
    expect($linkDisabled($page))
        ->toBeTrue();
    expect($checkboxChecked($page))
        ->toBeTrue();

    $page->click('#filter_dimension .removeFilter');

    expect($filterVisible($page))
        ->toBeFalse();
    expect($linkDisabled($page))
        ->toBeFalse();
    expect($checkboxChecked($page))
        ->toBeFalse();

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'batchManagerFilter enable/remove dimension filter');
});

it('drags the widths slider min handle inward, then snaps it back via the choice button', function (): void {
    // Every fixture photo `H::makeTestImage()` makes defaults to the
    // same 200px width, which collapses `[data-slider=widths]`'s own
    // real value list (`FilesizeFilterOptions`-style: every *distinct*
    // width present) down to one point -- nowhere real to drag or reset
    // to. 3 real, differently-sized uploads give it real range.
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Batch Filter Widths Slider Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    foreach ([150, 300, 500] as $width) {
        $image = H::makeTestImage(uniqid(), $width);
        H::uploadPhotoViaApi($image, $albumId, 'Widths Slider Photo ' . $width);
        @unlink($image);
    }

    $page = H::navigateOk($page, '/admin.php?page=batch_manager&mode=unit');

    // `pwgDoubleSlider()` runs unconditionally at page load for all 4
    // dimension sliders (`batchManagerFilter.ts`'s own `ready()`), so
    // `[data-slider=widths]` is already a real, live slider here --
    // only its *container* (`#filter_dimension`) is hidden until
    // enabled, and a hidden element can't be clicked/dragged for real.
    $page->click('.addFilter-button');
    $page->click('#addFilter a[data-value="filter_dimension"]');

    $bounds = H::scriptJson(
        $page,
        <<<'JS'
        JSON.stringify((() => {
            const button = document.querySelector('[data-slider=widths] .slider-choice.dimension-cancel');
            return { min: button.getAttribute('data-min'), max: button.getAttribute('data-max') };
        })())
        JS
    );
    if (! is_string($bounds['min'] ?? null) || ! is_string($bounds['max'] ?? null)) {
        throw new RuntimeException('unexpected bounds shape: ' . var_export($bounds, true));
    }
    // A fresh session selects the full real range by default -- the one
    // real precondition this test needs to prove anything: without it,
    // both the drag and the reset below could be silent no-ops. Only
    // the lower bound is pinned to this test's own upload (150, real
    // and unique enough not to collide) -- the upper bound and the
    // real value list's own length depend on whatever *other* photos
    // already exist site-wide (this slider aggregates every distinct
    // width in the whole install, not just this test's own album), so
    // the drag below targets a real midpoint by fraction, not a
    // hardcoded value.
    expect($bounds['min'])->toBe('150');

    // Drag the min handle (the slider's first `.ui-slider-handle`) to
    // the track's own midpoint -- a real move off the 150 bound as long
    // as at least one other distinct width exists between 150 and
    // whatever the real max is (guaranteed here: this test's own 300
    // and 500 uploads).
    $page->script(<<<'JS'
        (() => {
            const track = document.querySelector('[data-slider=widths] .slider-slider');
            const handle = track.querySelector('.ui-slider-handle');
            const rect = track.getBoundingClientRect();
            const x = rect.left + rect.width / 2;
            const y = rect.top + rect.height / 2;
            const down = { clientX: x, clientY: y, bubbles: true };
            handle.dispatchEvent(new MouseEvent('mousedown', down));
            document.body.dispatchEvent(new MouseEvent('mousemove', down));
            document.body.dispatchEvent(new MouseEvent('mouseup', down));
        })()
        JS);

    $afterDrag = H::scriptString($page, "document.querySelector('[data-slider=widths] [data-input=min]').value");
    expect($afterDrag)
        ->not->toBe('150');

    // The "Reset" preset button (`.slider-choice.dimension-cancel`)
    // drives `pwgDoubleSlider()`'s own `.slider("values", i, ...)`
    // setter path -- a distinct code path from the drag above (which
    // exercises the slider's own real mousedown/mousemove handling
    // instead), with its own `change`-callback wiring to prove.
    $page->click('[data-slider=widths] .slider-choice.dimension-cancel');

    $afterReset = H::scriptString($page, "document.querySelector('[data-slider=widths] [data-input=min]').value");
    // Only provable because the drag above actually moved it off 150
    // first: if the setter's own `change` callback silently didn't
    // fire, this would still read 300, not fall back to the drag's own
    // value by coincidence.
    expect($afterReset)
        ->toBe('150');

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'batchManagerFilter widths slider drag+reset');
});

it('fades the quick-search help modal in and out', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=batch_manager&mode=unit');

    // .help-popin-search lives inside #filter_search, itself u-hidden
    // until the search filter is enabled -- reach it the real way, via
    // the add-filter dropdown, rather than asserting on a hidden element.
    $page->click('.addFilter-button');
    $page->click('#addFilter a[data-value="filter_search"]');

    $modalVisible = static fn (Webpage|PendingAwaitablePage|AwaitableWebpage $page): mixed => $page->script(
        "getComputedStyle(document.getElementById('modalQuickSearch')).display !== 'none'"
    );

    expect($modalVisible($page))
        ->toBeFalse();

    $page->click('.help-popin-search');

    $page->script(<<<'JS'
        new Promise((resolve, reject) => {
            const deadline = Date.now() + 5000;
            const check = () => {
                if (getComputedStyle(document.getElementById('modalQuickSearch')).display !== 'none') {
                    return resolve(true);
                }
                if (Date.now() > deadline) {
                    return reject(new Error('modalQuickSearch never faded in'));
                }
                setTimeout(check, 100);
            };
            check();
        })
        JS);

    $page->click('#closeModalQuickSearch');

    $page->script(<<<'JS'
        new Promise((resolve, reject) => {
            const deadline = Date.now() + 5000;
            const check = () => {
                if (getComputedStyle(document.getElementById('modalQuickSearch')).display === 'none') {
                    return resolve(true);
                }
                if (Date.now() > deadline) {
                    return reject(new Error('modalQuickSearch never faded out'));
                }
                setTimeout(check, 100);
            };
            check();
        })
        JS);

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'batchManagerFilter quick-search modal');
});
