<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * P49-C conversion of themes/admin/default/js/ratings/user.ts's own
 * dataTable()/tooltip() pair (`vendor/dataTable.ts`/`vendor/tooltip.ts`,
 * ported off datatables.net and jQuery UI's own tooltip widget). Neither
 * had any live JS coverage before this: RatingUserPageRendererTest.php's
 * own tests only ever assert the server-rendered page (or, for the one
 * delete test, the row-removal side effect of `oTable.row(tr).remove()
 * .draw()` -- never the sorting/filtering/pagination/tooltip machinery
 * dataTable()/tooltip() themselves are responsible for).
 */
it('cycles a sortable column through asc, desc, and back to original order', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=rating_user&f_min_rates=0');

    $original = H::scriptArray(
        $page,
        "Array.from(document.querySelectorAll('#rateTable tbody tr .usr')).map((td) => td.textContent)",
    );
    expect(count($original))
        ->toBeGreaterThanOrEqual(3);

    $page->click('#rateTable thead th.dtc_user');
    $ascending = H::scriptArray(
        $page,
        "Array.from(document.querySelectorAll('#rateTable tbody tr .usr')).map((td) => td.textContent)",
    );
    $ascClass = H::scriptString($page, "document.querySelector('#rateTable thead th.dtc_user').className");
    $sorted = $original;
    sort($sorted);
    expect($ascending)
        ->toBe($sorted);
    expect($ascClass)
        ->toBe('dtc_user sorting_asc');

    $page->click('#rateTable thead th.dtc_user');
    $descending = H::scriptArray(
        $page,
        "Array.from(document.querySelectorAll('#rateTable tbody tr .usr')).map((td) => td.textContent)",
    );
    $descClass = H::scriptString($page, "document.querySelector('#rateTable thead th.dtc_user').className");
    expect($descending)
        ->toBe(array_reverse($sorted));
    expect($descClass)
        ->toBe('dtc_user sorting_desc');

    $page->click('#rateTable thead th.dtc_user');
    $unsorted = H::scriptArray(
        $page,
        "Array.from(document.querySelectorAll('#rateTable tbody tr .usr')).map((td) => td.textContent)",
    );
    $unsortedClass = H::scriptString($page, "document.querySelector('#rateTable thead th.dtc_user').className");
    expect($unsorted)
        ->toBe($original);
    expect($unsortedClass)
        ->toBe('dtc_user sorting');

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'rating_user sort cycle');
});

it('filters rows by the search box and updates the info text', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=rating_user&f_min_rates=0');

    $page->fill('.dtBar input[type="search"]', 'power');

    $rows = H::scriptArray(
        $page,
        "Array.from(document.querySelectorAll('#rateTable tbody tr .usr')).map((td) => td.textContent)",
    );
    expect($rows)
        ->toBe(['power_user']);

    $info = H::scriptString($page, "document.querySelector('.dtBar .dataTables_info').textContent");
    expect($info)
        ->toBe('Showing 1 to 1 of 1 entries (filtered from 3 total entries)');

    $page->fill('.dtBar input[type="search"]', 'no-such-user-anywhere');
    $emptyRows = H::scriptArray(
        $page,
        "Array.from(document.querySelectorAll('#rateTable tbody tr')).map(() => true)",
    );
    expect($emptyRows)
        ->toBe([]);

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'rating_user filter');
});

it('shows a title-based tooltip on hover and removes it on mouseleave', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=rating_user&f_min_rates=0');

    // The date column's own real `title="First: ..."` attribute -- the
    // synchronous content() branch (`if (t) return t;`), not the async
    // GeoIP one.
    $page->hover('#rateTable tbody tr:first-child td[title]');

    $tooltipVisible = H::scriptBool($page, "document.querySelector('.ui-tooltip') !== null");
    expect($tooltipVisible)
        ->toBeTrue();

    $tooltipText = H::scriptString($page, "document.querySelector('.ui-tooltip').textContent");
    expect($tooltipText)
        ->toStartWith('First: ');

    // The native title attribute must be blanked while the custom
    // tooltip is open, or the browser's own native tooltip would show
    // alongside it.
    $titleWhileOpen = H::scriptString(
        $page,
        "document.querySelector('#rateTable tbody tr td[title]').getAttribute('title')",
    );
    expect($titleWhileOpen)
        ->toBe('');

    // A real `$page->hover()` onto some other, unambiguous element would
    // work too, but this page has 2 real `<h1>`s (the shared admin
    // header's own brand heading, and this page's "Rating" heading) --
    // dispatching "mouseleave" directly is simpler and exercises the
    // exact same native listener `vendor/tooltip.ts`'s own `openTooltip()`
    // binds on the target.
    $page->script(
        "document.querySelector('#rateTable tbody tr:first-child td[title]')"
        . ".dispatchEvent(new MouseEvent('mouseleave', {bubbles: true}))",
    );

    $tooltipGone = H::scriptBool($page, "document.querySelector('.ui-tooltip') === null");
    expect($tooltipGone)
        ->toBeTrue();

    $titleRestored = H::scriptString(
        $page,
        "document.querySelector('#rateTable tbody tr td[title]').getAttribute('title')",
    );
    expect($titleRestored)
        ->toStartWith('First: ');

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'rating_user title tooltip');
});

/**
 * Same real MaxMind test-data fixture HistoryInteractionTest.php's own
 * geolocation-tooltip test installs, at the same real path
 * GeoIpLookupService::databasePathFor() resolves for this worktree.
 * `67.43.156.0/24` is that fixture's own real country-only (Bhutan, no
 * city/region) network block -- covers every address in it, so appending
 * the content() callback's own fixed ".1" suffix to a 3-octet
 * `anonymous_id` of "67.43.156" reconstructs "67.43.156.1", a real
 * deterministic hit, unlike `81.2.69.142`'s own single-address (`/32`)
 * entry the history conversion uses, which no `<3 octets>+".1"`
 * reconstruction could ever land on.
 */
function ratingUserInteractionInstallGeoIpFixture(): string
{
    $destination = dirname(__DIR__, 2) . '/_data/geoip/dbip-city-lite.mmdb';
    if (! is_dir(dirname($destination))) {
        mkdir(dirname($destination), 0o777, true);
    }
    copy(dirname(__DIR__) . '/Fixtures/GeoIp/GeoIP2-City-Test.mmdb', $destination);

    return $destination;
}

function ratingUserInteractionRemoveGeoIpFixture(string $destination): void
{
    @unlink($destination);
}

it('shows an async GeoIP tooltip for an anonymous rater\'s usr cell', function (): void {
    $geoIpFixture = ratingUserInteractionInstallGeoIpFixture();

    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Rating User Tooltip Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Rating User Tooltip Photo');
    @unlink($image);

    $anonymousId = '67.43.156';
    $db = H::connect();
    H::dbQuery($db, sprintf(
        "INSERT INTO rate (user_id, element_id, anonymous_id, rate, date) VALUES (2, %d, '%s', 4, CURRENT_DATE)",
        $imageId,
        H::dbEscape($db, $anonymousId),
    ));
    H::dbClose($db);

    try {
        $page = H::navigateOk($page, '/admin.php?page=rating_user&f_min_rates=0');
        $page->assertSee($anonymousId);

        $page->hover('tr:has-text("(' . $anonymousId . ')") td.usr');

        $page->script(<<<'JS'
            new Promise((resolve, reject) => {
                const deadline = Date.now() + 5000;
                const check = () => {
                    const tooltip = document.querySelector('.ui-tooltip-content');
                    if (tooltip !== null && tooltip.textContent.includes('Bhutan')) {
                        return resolve(true);
                    }
                    if (Date.now() > deadline) return reject(new Error('geoip tooltip never appeared'));
                    setTimeout(check, 100);
                };
                check();
            })
            JS);

        expect(H::scriptString($page, "document.querySelector('.ui-tooltip-content').textContent"))
            ->toContain('Bhutan');

        $page->assertNoJavaScriptErrors();
        H::assertNoServerErrors($page, 'rating_user geoip tooltip');
    } finally {
        ratingUserInteractionRemoveGeoIpFixture($geoIpFixture);
        $cleanup = H::connect();
        H::dbQuery($cleanup, sprintf('DELETE FROM rate WHERE element_id = %d', $imageId));
        H::dbClose($cleanup);
    }
});
