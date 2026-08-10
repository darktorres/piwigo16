<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * UserActivityPageRenderer (admin.php?page=user_activity, dispatched via
 * Controller/Admin/UserActivitySubController.php) -- 0% coverage before
 * this file.
 *
 * The activity log itself grows every time any Browser test logs in as
 * fixture_admin (each real login writes a new 'user'/'login' activity row),
 * so this file deliberately avoids asserting on activity *counts* --
 * instead it targets the "additional filter" branch (?photo=/?album=/?group=),
 * whose resolved name comes straight from static fixture rows (images/
 * categories/groups) and is unaffected by how many logins have
 * accumulated.
 *
 * The `! is_string($filter_value)` "[Hacking attempt]" guard inside the
 * additional-filters loop (render()'s own ~L141-144) is NOT exercised by
 * any test here, deliberately: $filter_value comes from
 * UserActivityRequest::filterValue(), which only ever returns null or a
 * value that already passed InputValidator::validate(..., $isArray: false,
 * ValidationPattern::ID) in UserActivityRequest::fromArray() -- that
 * validate() call itself fatal-errors (a *different* call site, inside
 * InputValidator) on anything not_scalar (e.g. the classic `?album[]=1`
 * PHP array-parameter-pollution vector), and ValidationPattern::ID
 * (`/^\d+$/`) only matches digit strings. Real `$_GET` values are also
 * always strings natively (PHP's own querystring parser never produces a
 * bare int/float/bool). So by the time render()'s own loop body reads
 * $filter_value, it is either null (hasFilter() already false, loop body
 * never entered) or a digit-only string -- `! is_string(...)` is
 * unreachable through any real HTTP request. render() only ever
 * constructs UserActivityRequest via ::fromGlobals() (no injection point
 * for a hand-crafted non-string, is_scalar $source value either), so
 * there's no way to reach this branch even at the Integration tier.
 *
 * render()'s own `$additional_filter_keys = ['photo', 'album', 'group'];`
 * (a bare pure-literal array assignment) and the loop's own `break;` right
 * after a filter resolves both execute on every "additional-filter" test
 * below ('resolves the album/photo/group additional-filter...', 'prioritizes
 * the photo filter...') -- if either still shows as an uncovered line, that
 * is the documented OPcache constant-array-folding coverage artifact (see
 * tests/Browser/CatListPageRendererTest.php's own docblock for the same
 * note against a sibling class), not a real gap.
 */
it('streams a CSV export via type=download_logs instead of rendering the normal page', function (): void {
    $page = H::loginAsAdmin($this);

    $result = H::rawGet($page, '/admin.php?page=user_activity&type=download_logs');

    expect($result['status'])->toBe(200);
    // The header row is written first, unconditionally -- real fixture_admin
    // login activity (guaranteed present: every Browser test's own
    // loginAsAdmin() call writes one) means at least one real data row
    // follows it too.
    expect($result['body'])->toContain('User;ID_User;Object;Object_ID;Action;Date;Hour;IP_Address;Details');
    expect($result['body'])->toContain('fixture_admin');
    expect($result['body'])->toContain('login');
});

it('resolves the album additional-filter to its real fixture name', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=user_activity&album=1');
    $page->assertNoJavaScriptErrors();

    $page->assertPresent('.icon-folder-open');
    $page->assertSeeIn('.icon-folder-open', 'Sample Album');
});

it('resolves the photo additional-filter to its real fixture name', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=user_activity&photo=1');
    $page->assertNoJavaScriptErrors();

    $page->assertPresent('.icon-picture');
    $page->assertSeeIn('.icon-picture', 'Photo 1');
});

it('resolves the group additional-filter to its real fixture name', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=user_activity&group=1');
    $page->assertNoJavaScriptErrors();

    $page->assertPresent('.icon-group');
    $page->assertSeeIn('.icon-group', 'Editors');
});

// Priority order: the additional-filters loop checks 'photo' before 'album'
// before 'group' and stops at the first one present -- passing both photo
// and album must resolve to the photo, not the album.
it('prioritizes the photo filter over the album filter when both are present', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=user_activity&photo=1&album=2');
    $page->assertNoJavaScriptErrors();

    $page->assertPresent('.icon-picture');
    $page->assertSeeIn('.icon-picture', 'Photo 1');
    $page->assertMissing('.icon-folder-open');
});

// Edge/error condition: a filter value referencing a non-existent row calls
// fatalError() (HTTP 500, rendered inline) rather than silently ignoring it.
it('shows a fatal error for a non-existent album filter id', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=user_activity&album=999999');

    $page->assertSee('album #999999 does not exist');
});
