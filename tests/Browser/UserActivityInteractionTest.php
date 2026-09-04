<?php

declare(strict_types=1);

use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Api\Webpage;
use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * P49-A conversion of themes/admin/default/js/users/activity.ts -- 0% prior
 * JS coverage (UserActivityPageRendererTest.php only ever asserts the
 * server-rendered "additional filter" resolution and the CSV export
 * route, never the client-side activity-list build/pagination/filter-
 * panel JS this file owns).
 *
 * A real login through this suite's own auth flow can NEVER produce the
 * row this test needs: ActivityService::record()'s own `$performedBy`
 * read happens before AuthService::login() updates CurrentUser/$_SESSION
 * to the just-authenticated identity, so every real 'user'/'login' row's
 * performedBy is whoever the request started as (guest) -- confirmed
 * live, every real login logged during a full suite run shows
 * performedByUsername "guest", never "fixture_admin". The fixture's own
 * two 'user'/'login' rows for fixture_admin (activity_id 3/4 in
 * tests/Fixtures/piwigo-17.0.sql) are written directly via SQL, bypassing
 * that code path entirely -- this test's real precondition is that ONE
 * of those two rows is still within the page's own
 * ACTIVITY_DISPLAY_PAGE_SIZE (100) most-recent rows, which a long
 * full-suite run's own accumulated activity eventually pushes past.
 * Seeding a fresh row the same way the fixture does (not through a real
 * login) is the only way to make this deterministic.
 *
 * `.selectize()` (user/action filter dropdowns) is a real native call now
 * (P49-B group 6, `vendor/selectize.ts`) -- this test still only reads
 * its own rendered `.selectize-input`/`.item[data-value]` markup, same as
 * before.
 */
it('renders at least the real login activity line with the expected login classes and username', function (): void {
    $db = H::connect();
    H::dbQuery($db, "INSERT INTO activity (object, object_id, action, performed_by, session_idx, ip_address, occured_on, user_id) VALUES ('user', 1, 'login', 1, 'user_activity_interaction_test', '::1', NOW(), 1)");
    H::dbClose($db);

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

    $decoded = H::scriptJson($page, <<<'JS'
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
    if (! is_bool($decoded['tabTitleVisible'] ?? null) || ! is_bool($decoded['noResultVisible'] ?? null)) {
        throw new RuntimeException('unexpected state shape: ' . var_export($decoded, true));
    }
    $loginLine = $decoded['loginLine'] ?? null;
    if (
        ! is_array($loginLine)
        || ! is_string($loginLine['actionType'] ?? null)
        || ! is_string($loginLine['actionSection'] ?? null)
        || ! is_string($loginLine['userName'] ?? null)
    ) {
        throw new RuntimeException('unexpected loginLine shape: ' . var_export($loginLine, true));
    }

    expect($decoded['tabTitleVisible'])
        ->toBeTrue();
    expect($decoded['noResultVisible'])
        ->toBeFalse();
    expect($loginLine['actionType'])
        ->toContain('icon-purple');
    expect($loginLine['actionSection'])
        ->toContain('icon-user-1');
    expect($loginLine['userName'])
        ->toBe('fixture_admin');

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'user_activity login line render');
});

it('shows the real user count in the page title badge', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=user_activity');

    $badgeText = H::scriptString(
        $page,
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

    $waitForDisplay = static function (Webpage|PendingAwaitablePage|AwaitableWebpage $page, string $expected, string $failMessage): void {
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

    $sleep = static function (Webpage|PendingAwaitablePage|AwaitableWebpage $page, int $ms): void {
        $page->script("new Promise((resolve) => setTimeout(resolve, {$ms}))");
    };

    expect(H::scriptString($page, "getComputedStyle(document.getElementById('activityMoreFiltersContent')).display"))
        ->toBe('none');

    $page->click('#activityMoreFilters');
    $waitForDisplay($page, 'flex', 'filters panel never opened');

    expect(H::scriptBool($page, "document.getElementById('activityMoreFilters').classList.contains('extend-padding')"))
        ->toBeTrue();

    // slideToggle()'s own completion callback (which releases the
    // `toggleTriggered` guard the click handler checks) fires at the end
    // of the animation's default duration, well after `display` itself
    // already flipped to "flex" -- wait it out before clicking again, or
    // the second click is a silent no-op against a still-triggered guard.
    $sleep($page, 600);

    $page->click('#activityMoreFilters');
    $waitForDisplay($page, 'none', 'filters panel never closed');

    expect(H::scriptBool($page, "document.getElementById('activityMoreFilters').classList.contains('extend-padding')"))
        ->toBeFalse();

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'user_activity more-filters panel toggle');
});
