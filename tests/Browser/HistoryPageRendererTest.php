<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Admin\HistoryPageRenderer (admin.php?page=history) -- renders the
 * filter FORM only; the actual history line listing is fetched
 * client-side via `GET /api/v1/history/search`
 * ({@see \Piwigo\Controller\Api\History\HistorySearchController}). Most
 * of this suite still only covers the form's own filter-echo/default-
 * date/valid-vs-invalid-user_id branches; the 2 tests at the bottom
 * exercise the results endpoint itself directly.
 */
it('renders with today\'s date pre-filled and no filter applied', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=history');

    // START/END are hidden inputs (not "filter_ip"/"filter_image_id",
    // which don't exist as real form fields at all -- ip/image_id are
    // only ever echoed into the "current_param" JS object, confirmed
    // live via raw curl) -- both default to Env::now() (frozen by
    // PIWIGO_TEST_NOW) when no filter is applied.
    $today = new DateTime((string) getenv('PIWIGO_TEST_NOW'))
        ->format('Y-m-d');
    $page->assertPresent('input[name="start"][value="' . $today . '"]');
    $page->assertPresent('input[name="end"][value="' . $today . '"]');
    $page->assertNoJavaScriptErrors();
});

it('clears the default start date when any filter is applied', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=history&filter_ip=127.0.0.1');

    // hasAnyFilter=true -> $form['start'] is reset to '' instead of
    // today's date -- observable as the START field rendering empty
    // rather than a real date value -- confirmed live via raw curl.
    $page->assertPresent('input[name="start"][value=""]');
    // The ip filter itself is only echoed into the "current_param" JS
    // object (a <script> tag, not a visible/DOM-attribute value), so a
    // raw-content check is the correct way to confirm it round-tripped.
    expect(H::rawWebpage($page)->content())->toContain('ip: "127.0.0.1"');
    $page->assertNoJavaScriptErrors();
});

it('echoes a valid image_id filter back into the form', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=history&filter_image_id=42');

    expect(H::rawWebpage($page)->content())->toContain('image_id: "42"');
});

it('resolves a real filter_user_id to its username', function (): void {
    $page = H::loginAsAdmin($this);
    // user 1 is the real fixture_admin account (see this suite's own
    // fixture-shape memory notes).
    $page = H::navigateOk($page, '/admin.php?page=history&filter_user_id=1');

    $page->assertSee('fixture_admin');
});

it('resets an unresolvable filter_user_id back to -1 without erroring', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=history&filter_user_id=999999');

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'history unresolvable filter_user_id');
});

it('rejects a non-digit filter_ip as an invalid request parameter', function (): void {
    $page = H::loginAsAdmin($this);

    $result = H::rawGet($page, '/admin.php?page=history&filter_ip=not-an-ip');

    expect($result['body'])->toContain('Invalid request parameter');
});

it('GET /api/v1/history/search returns real JSON instead of the old ws.php 404', function (): void {
    $page = H::loginAsAdmin($this);
    H::truncateHistory();

    $result = H::rawGet($page, '/api/v1/history/search?pageNumber=0');

    expect($result['status'])->toBe(200);
    $decoded = json_decode($result['body'], true);
    if (! is_array($decoded)) {
        throw new RuntimeException('GET /api/v1/history/search returned no JSON object: ' . $result['body']);
    }
    expect($decoded)
        ->toMatchArray([
            'lines' => [],
            'pageNumber' => 0,
            'maxPage' => 1,
        ]);
    expect($decoded['summary'])->toMatchArray([
        'nbLines' => 0,
        'nbUsers' => 0,
        'nbGuests' => 0,
        'members' => [],
    ]);
});

it('renders an empty results table without hanging the loading spinner', function (): void {
    $page = H::loginAsAdmin($this);
    // See VisualRegressionTest.php's own class docblock -- wipe the table
    // this page queries AFTER logging in (login itself logs a history
    // row) and BEFORE navigating there (its JS fires the search AJAX
    // call on document.ready), so the default (today, unfiltered) search
    // is deterministically empty.
    H::truncateHistory();

    $page = H::navigateOk($page, '/admin.php?page=history');
    H::waitUntilHidden($page, '.loading');

    $page->assertSee('No results');
    $page->assertNoJavaScriptErrors();
});
