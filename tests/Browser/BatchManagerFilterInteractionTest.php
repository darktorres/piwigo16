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
 * widgets its sibling filters depend on. `.pwgDoubleSlider()` (jQuery-UI
 * slider wrapper, P49-B group 4) and selectize (group 6) stay jQuery;
 * neither is touched by anything asserted here.
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
