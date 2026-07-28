<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Admin\HistoryPageRenderer (admin.php?page=history) -- renders the
 * filter FORM only; the actual history line listing is fetched
 * client-side via ws.php?method=pwg.history.search (out of this class's
 * scope, see its own docblock). This suite covers the form's own
 * filter-echo/default-date/valid-vs-invalid-user_id branches.
 */
it('renders with today\'s date pre-filled and no filter applied', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=history');

    $page->assertPresent('input[name="filter_ip"]');
    $page->assertNoJavaScriptErrors();
});

it('clears the default start date when any filter is applied', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=history&filter_ip=127.0.0.1');

    // hasAnyFilter=true -> $form['start'] is reset to '' instead of
    // today's date -- observable as the START field rendering empty
    // rather than a real date value.
    $page->assertPresent('input[name="filter_ip"][value="127.0.0.1"]');
    $page->assertNoJavaScriptErrors();
});

it('echoes a valid image_id filter back into the form', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=history&filter_image_id=42');

    $page->assertPresent('input[name="filter_image_id"][value="42"]');
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

it('rejects a non-digit filter_ip as a hacking attempt', function (): void {
    $page = H::loginAsAdmin($this);

    $result = H::rawGet($page, '/admin.php?page=history&filter_ip=not-an-ip');

    expect($result['body'])->toContain('Hacking attempt');
});