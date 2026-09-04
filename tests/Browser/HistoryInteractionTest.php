<?php

declare(strict_types=1);

use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Api\Webpage;
use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * P49-A conversion of themes/admin/default/js/history.ts --
 * HistoryPageRendererTest.php already covers the form-rendering and
 * initial-search-on-load paths; this file covers two genuinely
 * interactive behaviors the conversion touched most: the "Action" filter
 * dropdown's `option:checked` read, and adding/removing a line's user as
 * a filter.
 *
 * `.date-start`/`.date-end`'s "change" listeners are native now (P49-B,
 * `vendor/widgets/datepicker.ts`) -- the native port's own `writeValue()`
 * dispatches a real, bubbling native "change" event on the visible
 * field (matching the original's own real
 * `this.$input.trigger("change")`), so a plain `addEventListener`
 * listener on the ancestor sees it, including on page load, and the
 * page's very first, unfiltered search still runs. Both tests below
 * (which pass through that exact path on page load) exercise it.
 *
 * A third interactive behavior, the per-row "..." options toggle, is
 * deliberately NOT covered by a live test here: page load fires two
 * independent searches (one from each of `.date-start`/`.date-end`'s own
 * datepicker-driven "change"), and since `activateLineOptions()`
 * re-`.on("click", ...)`s the same elements after every completed
 * search without ever unbinding, a single click on `.toggle-img-option`
 * fires however many handlers have accumulated by then and its net effect
 * (toggle vs. no-op) depends on that count's parity -- confirmed identical
 * (2 duplicate rows, a click with no visible effect) against the
 * unconverted, currently-passing original, so it is a pre-existing
 * quirk, not a conversion regression, and out of scope for a
 * translation-only campaign. `toggle()` itself is still exercised live at
 * this file's other two toggle sites (`#commentFilters`'s
 * `advancedFilters`, `switchMode`'s `#contentSelectMode` -- see
 * comments.ts's own finding, the same class of bug) plus the mutation
 * test run for this file.
 *
 * lineConstructor()'s own `attr(newLine, "id", String(id))` gives every
 * rendered `.search-line` a purely numeric `id` (a client-side
 * render-order index, not the database row id) -- faithful to the
 * original jQuery. Clicking anywhere inside such a row via
 * `$page->click()` crashes Playwright's OWN post-click introspection
 * (`Cannot read properties of undefined (reading 'includes')` inside its
 * internal `generateSelectorFor`/`cssFallback`), regardless of the
 * selector this test passes in -- it inspects the actual clicked
 * element's ancestor chain to build its own trace-friendly selector
 * afterwards, and a numeric-leading `id` breaks that the same way it
 * breaks a real CSS selector (the class of bug `escapeId()` exists for),
 * just inside Playwright's tooling instead of app code.
 * `historyInteractionClick()` below dispatches a real `.click()` via
 * script instead, sidestepping Playwright's action layer entirely; this
 * test already waits for the row to be present first, so the
 * actionability checks that layer would have done aren't needed.
 */
function historyInteractionInsertLine(int $userId, string $section): void
{
    $db = H::connect();
    $today = (new DateTime((string) getenv('PIWIGO_TEST_NOW')))->format('Y-m-d');
    H::dbQuery($db, sprintf(
        "INSERT INTO history (date, time, user_id, section) VALUES ('%s', '12:00:00', %d, '%s')",
        $today,
        $userId,
        H::dbEscape($db, $section),
    ));
    H::dbClose($db);
}

function historyInteractionInsertLineWithIp(int $userId, string $section, string $ip): void
{
    $db = H::connect();
    $today = (new DateTime((string) getenv('PIWIGO_TEST_NOW')))->format('Y-m-d');
    H::dbQuery($db, sprintf(
        "INSERT INTO history (date, time, user_id, section, IP) VALUES ('%s', '12:00:00', %d, '%s', '%s')",
        $today,
        $userId,
        H::dbEscape($db, $section),
        H::dbEscape($db, $ip),
    ));
    H::dbClose($db);
}

/**
 * Installs GeoIpLookupServiceTest.php's own MaxMind test-data fixture at
 * the real path GeoIpLookupService::databasePathFor() resolves for this
 * worktree, so `.IP`'s hover tooltip has a real match to render --
 * without this, `GeoIpLookupService::isAvailable()` is false and neither
 * the tooltip nor history.latte's own attribution line renders at all.
 * Removed again in the caller's `finally`, matching golden-html/VR's own
 * existing baselines, which were captured with no database installed.
 */
function historyInteractionInstallGeoIpFixture(): string
{
    $destination = dirname(__DIR__, 2) . '/_data/geoip/dbip-city-lite.mmdb';
    if (! is_dir(dirname($destination))) {
        mkdir(dirname($destination), 0o777, true);
    }
    copy(dirname(__DIR__) . '/Fixtures/GeoIp/GeoIP2-City-Test.mmdb', $destination);

    return $destination;
}

function historyInteractionRemoveGeoIpFixture(string $destination): void
{
    @unlink($destination);
}

function historyInteractionWaitForLoad(Webpage|PendingAwaitablePage|AwaitableWebpage $page): void
{
    // Wait for `.pagination-item-container` to be populated, not merely for
    // the row to exist: the row is appended inside the ajax `success`
    // callback, but `activateLineOptions()` is only re-run (rebinding a
    // fresh `.toggle-img-option` listener onto that new row -- `on()`
    // without a delegated selector only binds to elements present at call
    // time) afterwards, in `.done()`, alongside updatePagination(). Waiting
    // for the row alone raced ahead of that rebind and clicked a row with
    // no listener yet.
    $page->script(<<<'JS'
        new Promise((resolve, reject) => {
            const deadline = Date.now() + 8000;
            const check = () => {
                if (
                    document.querySelectorAll('.tab .search-line').length > 0 &&
                    document.querySelector('.pagination-item-container').childElementCount > 0
                ) {
                    return resolve(true);
                }
                if (Date.now() > deadline) return reject(new Error('history lines never loaded'));
                setTimeout(check, 100);
            };
            check();
        })
        JS);
}

function historyInteractionClick(Webpage|PendingAwaitablePage|AwaitableWebpage $page, string $selector): void
{
    $page->script("document.querySelector('" . $selector . "').click()");
}

/**
 * Same reasoning as historyInteractionClick()'s own docblock note above
 * (lineConstructor()'s numeric-leading row `id` crashes Playwright's own
 * post-action selector introspection) -- $page->hover() hits the
 * identical crash, so this dispatches a real `mouseenter` via script
 * instead, which setupGeoIpHover()'s native `on(ipEl, "mouseenter", ...)`
 * listener sees exactly the same as a real one.
 */
function historyInteractionHover(Webpage|PendingAwaitablePage|AwaitableWebpage $page, string $selector): void
{
    $page->script("document.querySelector('" . $selector . "').dispatchEvent(new MouseEvent('mouseenter', {bubbles: true}))");
}

it('filters lines by the Action dropdown, reading option:checked', function (): void {
    $page = H::asAdmin($this);
    // Login itself logs a real history row -- wipe AFTER logging in (same
    // ordering HistoryPageRendererTest's own truncateHistory() call
    // uses), then insert one row with a NULL image_type -- the
    // repository's own `none` bucket, included under "Visited" (view
    // actions) but not "Downloaded" (image_type high/other only) -- see
    // HistoryRepository::search()'s own $imageTypes docblock.
    H::truncateHistory();
    historyInteractionInsertLine(1, 'list');

    $page = H::navigateOk($page, '/admin.php?page=history');
    historyInteractionWaitForLoad($page);
    $page->assertSee('fixture_admin');

    // Page load itself races two independent searches (see this file's own
    // docblock) -- settle before adding a third of our own, or a late
    // straggler from the initial pair can overwrite this dropdown's own
    // filtered result with its unfiltered one.
    $page->script('new Promise((resolve) => setTimeout(resolve, 1000))');

    $page->script(
        "document.querySelector('.elem-type-select').value = 'downloaded'; " .
        "document.querySelector('.elem-type-select').dispatchEvent(new Event('change', {bubbles: true}))",
    );

    $page->script(<<<'JS'
        new Promise((resolve, reject) => {
            const deadline = Date.now() + 5000;
            const check = () => {
                if (document.querySelector('.noResults').offsetParent !== null) return resolve(true);
                if (Date.now() > deadline) return reject(new Error('never filtered down to No results'));
                setTimeout(check, 100);
            };
            check();
        })
        JS);

    expect($page->script("document.querySelectorAll('.tab .search-line').length"))
        ->toBe(0);

    // Switching back to "Visited" brings the "none"-typed row back.
    $page->script(
        "document.querySelector('.elem-type-select').value = 'visited'; " .
        "document.querySelector('.elem-type-select').dispatchEvent(new Event('change', {bubbles: true}))",
    );
    historyInteractionWaitForLoad($page);
    $page->assertSee('fixture_admin');

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'history Action dropdown filter');
});

it('adds a line\'s user as a filter, then removes it', function (): void {
    $page = H::asAdmin($this);
    H::truncateHistory();
    historyInteractionInsertLine(1, 'list');

    $page = H::navigateOk($page, '/admin.php?page=history');
    historyInteractionWaitForLoad($page);
    $page->assertSee('fixture_admin');

    expect($page->script("document.querySelectorAll('.filter-container .filter-item:not(.hide)').length"))
        ->toBe(0);

    historyInteractionClick($page, '.tab .search-line .user-name');

    $page->script(<<<'JS'
        new Promise((resolve, reject) => {
            const deadline = Date.now() + 5000;
            const check = () => {
                if (document.querySelectorAll('.filter-container .filter-item:not(.hide)').length > 0) return resolve(true);
                if (Date.now() > deadline) return reject(new Error('filter chip never appeared'));
                setTimeout(check, 100);
            };
            check();
        })
        JS);
    $page->assertSeeIn('.filter-container', 'fixture_admin');

    $page->click('.filter-container .filter-item:not(.hide) .remove-filter');

    $page->script(<<<'JS'
        new Promise((resolve, reject) => {
            const deadline = Date.now() + 5000;
            const check = () => {
                if (document.querySelectorAll('.filter-container .filter-item:not(.hide)').length === 0) return resolve(true);
                if (Date.now() > deadline) return reject(new Error('filter chip never removed'));
                setTimeout(check, 100);
            };
            check();
        })
        JS);

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'history add/remove user filter');
});

it('shows a geolocation tooltip when hovering a line\'s IP', function (): void {
    $geoIpFixture = historyInteractionInstallGeoIpFixture();

    try {
        $page = H::asAdmin($this);
        H::truncateHistory();
        // A real, known match in GeoIP2-City-Test.mmdb (London, England,
        // United Kingdom) -- GeoIpLookupServiceTest.php's own primary
        // fixture IP.
        historyInteractionInsertLineWithIp(1, 'list', '81.2.69.142');

        $page = H::navigateOk($page, '/admin.php?page=history');
        historyInteractionWaitForLoad($page);
        $page->assertSee('fixture_admin');

        // Page load races two independent searches (this file's own
        // docblock) -- settle before hovering, same wait the Action-
        // dropdown test above uses, or the second search's own fresh
        // row can replace the one this test already dispatched a
        // "mouseenter" on.
        $page->script('new Promise((resolve) => setTimeout(resolve, 1000))');

        // setupGeoIpHover() binds per-row from lineConstructor() itself
        // (see history.ts's own docblock on that function for the real,
        // pre-existing `.IP`-vs-`.user-ip` mismatch this conversion
        // found and fixed) -- a lost binding here shows up as the
        // tooltip never appearing.
        historyInteractionHover($page, '.tab .search-line .user-ip');

        $page->script(<<<'JS'
            new Promise((resolve, reject) => {
                const deadline = Date.now() + 8000;
                const check = () => {
                    const content = document.getElementById('tiptip_content');
                    if (content !== null && content.textContent.includes('London')) {
                        return resolve(true);
                    }
                    if (Date.now() > deadline) return reject(new Error('geoip tooltip never appeared'));
                    setTimeout(check, 100);
                };
                check();
            })
            JS);

        expect(H::scriptString($page, "document.getElementById('tiptip_content').textContent"))
            ->toContain('London, England, United Kingdom');

        $page->assertNoJavaScriptErrors();
        H::assertNoServerErrors($page, 'history IP geolocation tooltip');
    } finally {
        historyInteractionRemoveGeoIpFixture($geoIpFixture);
    }
});
