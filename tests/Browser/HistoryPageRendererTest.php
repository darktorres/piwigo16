<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Admin\HistoryPageRenderer (admin.php?page=history) -- renders the
 * filter FORM only; the actual history line listing was fetched
 * client-side via `pwg.history.search` (out of this class's scope, see its
 * own docblock) -- P27 deliberately never ported that WS method (its own
 * handler builds admin-page HTML strings/ALL_CAPS Latte-template keys and
 * writes a display cookie, needing a dedicated redesign rather than a
 * mechanical port), so `history.js`'s own AJAX call to it now 404s since
 * the WS layer itself was deleted; the redesign is still open, tracked in
 * the plan file, not silently fixed here. This suite covers only the
 * form's own filter-echo/default-date/valid-vs-invalid-user_id branches,
 * unaffected either way.
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
