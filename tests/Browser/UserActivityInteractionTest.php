<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * P49-A conversion of themes/admin/default/js/user_activity.ts -- 0% prior
 * JS coverage (UserActivityPageRendererTest.php only ever asserts the
 * server-rendered "additional filter" resolution and the CSV export
 * route, never the client-side activity-list build/pagination/filter-
 * panel JS this file owns).
 *
 * Every real login (every `H::asAdmin()` call in this whole suite) writes
 * a real 'user'/'login' activity row, so `lineConstructor()`'s own client
 * merge-and-render is exercised for real on every test here -- no
 * synthetic fixture needed, and no assertion depends on an exact row
 * count (the fixture grows across the whole suite's run).
 *
 * `.selectize()` (user/action filter dropdowns, P49-B group 6) stays
 * jQuery; only the DOM work around it (reading its own rendered
 * `.selectize-input`/`.item[data-value]` markup) converted.
 */
it('renders at least the real login activity line with the expected login classes and username', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=user_activity');

    // get_user_activity() fetches asynchronously (a real network round
    // trip) -- the `#-1` template row is the only `.line` present until it
    // resolves, so wait for a second real row rather than racing it.
    $page->script(<<<'JS'
        new Promise((resolve, reject) => {
            const deadline = Date.now() + 8000;
            const check = () => {
                if (document.querySelectorAll('.tab .line').length > 1) {
                    return resolve(true);
                }
                if (Date.now() > deadline) {
                    return reject(new Error('activity lines never loaded'));
                }
                setTimeout(check, 200);
            };
            check();
        })
        JS);

    $state = $page->script(<<<'JS'
        JSON.stringify({
            tabTitleVisible: document.querySelector('.tab-title').offsetParent !== null,
            noResultVisible: document.querySelector('.activity-noresult').offsetParent !== null,
            // Several login rows can exist (other sessions, guest visits
            // elsewhere in the shared fixture) -- find fixture_admin's own,
            // rather than assuming the first icon-key row is it.
            loginLine: (() => {
                const icons = Array.from(document.querySelectorAll('.tab .action-icon.icon-key'));
                for (const icon of icons) {
                    const line = icon.closest('.line');
                    const userName = line.querySelector('.user-name').textContent.trim();
                    if (userName === 'fixture_admin') {
                        return {
                            actionType: line.querySelector('.action-type').className,
                            actionSection: line.querySelector('.action-section').className,
                            userName: userName,
                        };
                    }
                }
                return null;
            })(),
        })
        JS);
    $state = json_decode($state, true);

    expect($state['tabTitleVisible'])
        ->toBeTrue();
    expect($state['noResultVisible'])
        ->toBeFalse();
    expect($state['loginLine'])
        ->not->toBeNull();
    expect($state['loginLine']['actionType'])
        ->toContain('icon-purple');
    expect($state['loginLine']['actionSection'])
        ->toContain('icon-user-1');
    expect($state['loginLine']['userName'])
        ->toBe('fixture_admin');

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'user_activity login line render');
});

it('shows the real user count in the page title badge', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=user_activity');

    $badgeText = $page->script(
        "document.querySelector('h1 .badge-number').textContent"
    );

    expect($badgeText)
        ->toMatch('/^\d+$/');

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'user_activity badge-number');
});

it('opens and closes the "more filters" panel on click', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=user_activity');

    $waitForDisplay = static function (mixed $page, string $expected, string $failMessage): void {
        $page->script(
            <<<JS
            new Promise((resolve, reject) => {
                const deadline = Date.now() + 5000;
                const check = () => {
                    if (getComputedStyle(document.getElementById('activityMoreFiltersContent')).display === '{$expected}') {
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

    $sleep = static function (mixed $page, int $ms): void {
        $page->script("new Promise((resolve) => setTimeout(resolve, {$ms}))");
    };

    expect($page->script("getComputedStyle(document.getElementById('activityMoreFiltersContent')).display"))
        ->toBe('none');

    $page->click('#activityMoreFilters');
    $waitForDisplay($page, 'flex', 'filters panel never opened');

    expect($page->script("document.getElementById('activityMoreFilters').classList.contains('extend-padding')"))
        ->toBeTrue();

    // slideToggle()'s own completion callback (which releases the
    // `toggleTriggered` guard the click handler checks) fires at the end
    // of the animation's default duration, well after `display` itself
    // already flipped to "flex" -- wait it out before clicking again, or
    // the second click is a silent no-op against a still-triggered guard.
    $sleep($page, 600);

    $page->click('#activityMoreFilters');
    $waitForDisplay($page, 'none', 'filters panel never closed');

    expect($page->script("document.getElementById('activityMoreFilters').classList.contains('extend-padding')"))
        ->toBeFalse();

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'user_activity more-filters panel toggle');
});
