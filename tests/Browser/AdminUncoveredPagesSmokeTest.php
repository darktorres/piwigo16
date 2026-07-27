<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Content-asserting smoke tests for a batch of admin pages/controllers that
 * had zero test coverage across every suite: HelpPageRenderer/
 * HelpSubController (?page=help), LanguagesNewPageRenderer (?page=languages
 * &tab=new), PluginsNewPageRenderer (?page=plugins&tab=new),
 * ThemesNewPageRenderer (?page=themes&tab=new, dispatched by
 * ThemesSubController -- NOT the singular ThemeSubController below, a
 * completely different controller for a different page slug),
 * UpdatesExtPageRenderer (?page=updates&tab=ext, and its
 * ?page=plugins&tab=update sibling caller), MaintenanceEnvPageRenderer/
 * MaintenanceSysPageRenderer (?page=maintenance&tab=env/sys),
 * BatchManagerUnitPageRenderer (?page=batch_manager&mode=unit),
 * AdminPopuphelpController (admin/popuphelp.php), RatingUserSubController
 * (?page=rating_user), PluginSubController (?page=plugin), and
 * ThemeSubController (?page=theme&theme=..., the per-theme admin config
 * include -- singular "theme", distinct from the "themes" tab-dispatch
 * shell above; no real caller of the former appeared anywhere in this
 * project's test suite before this file).
 *
 * Unlike AdminExtendedSmokeTest.php's "clean (no errors)" checks, every
 * test here also asserts on real, specific rendered content -- verified by
 * hand against this exact fixture's live server (curl, logged in as
 * fixture_admin) before being written, not guessed from source reading
 * alone. Several of these pages' real content depends on this fork's own
 * unconditionally-unreachable PEM domain (AppInfo::DOMAIN ===
 * 'upstream.example.invalid', see that class's own docblock) --
 * "Connection to server unavailable." is therefore a real, deterministic,
 * always-reachable branch in this environment, not flaky network coverage.
 */

it('help page shows the default Add Photos section and its real translated content', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=help');

    $page->assertSee('Add Photos');
    $page->assertSee('Go to Direct Upload');
});

it('help page renders a non-default section when one is requested', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=help&section=permissions');

    $page->assertSee('Permissions on albums');
});

it('languages add-new tab reports the PEM server as unreachable', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=languages&tab=new');

    $page->assertSee('Add New Language');
    $page->assertSee('Connection to server unavailable.');
    $page->assertSee('There is no other language available.');
});

it('plugins add-new tab shows no plugins available without a false connection error', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=plugins&tab=new');

    $page->assertSee('There is no other plugin available.');
    // getVersionsToCheck() returning an empty list is documented as "nothing
    // to show", NOT a connection failure -- unlike languages_new/themes_new
    // (which call getServerExtensions() unconditionally), plugins_new only
    // calls it when versions_to_check isn't already empty, so this page
    // must never show the same error banner those two do.
    expect($page->content())->not->toContain('Connection to server unavailable.');
});

it('themes add-new tab reports the PEM server as unreachable', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=themes&tab=new');

    $page->assertSee('Add a new theme');
    $page->assertSee('Connection to server unavailable.');
    $page->assertSee('There is no other theme available.');
});

it('theme page rejects a theme id that ExtensionScanner never found on disk', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=theme&theme=zzz_nonexistent_theme');

    expect($page->content())->toContain('Invalid theme');
});

it('theme page reports a missing admin.inc.php for a real, scanned theme', function (): void {
    // 'default' is a genuine ExtensionScanner-recognized theme in this
    // fixture (themes/default/themeconf.inc.php exists), so it clears the
    // "theme not found" gate above and reaches the 2nd real branch:
    // neither bundled theme (default/standard_pages) ships its own
    // admin/admin.inc.php (confirmed via a real filesystem search), so
    // this is the furthest of ThemeSubController's 3 branches reachable
    // without a theme this fork doesn't ship.
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=theme&theme=default');

    expect($page->content())->toContain('Missing file')
        ->and($page->content())->toContain('default/admin/admin.inc.php');
});

it('updates ext tab checks every extension type and reports the PEM server as unreachable', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=updates&tab=ext');

    $page->assertSee('Extensions');
    $page->assertSee('Connection to server unavailable.');
});

it('plugins update tab restricts the shared updates-ext renderer to the plugin type', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=plugins&tab=update');

    // Reached via a different real caller than the ?page=updates&tab=ext
    // test above -- $types_to_check narrows to ExtensionType::Plugin only,
    // and the page's own ADMIN_PAGE_TITLE override applies afterward
    // ("Plugins", not "Updates").
    $page->assertSee('Check for updates');
    $page->assertSee('Connection to server unavailable.');
    expect($page->content())->toContain('<h1>Plugins');
});

it('maintenance env tab renders real server, database and version info', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=maintenance&tab=env');

    $page->assertSee('Operating system: Linux');
    $page->assertSee('MySQL:');
    $page->assertSee('PHP:');
});

it('maintenance sys tab renders the webmaster-only activity log table', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=maintenance&tab=sys');

    $page->assertSee('System Activities');
    $page->assertPresent('#activities-system');
});

it('maintenance sys ajax endpoint returns real activity log JSON instead of the HTML page', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=maintenance&tab=sys&method=pwg.activity_sys.getList');

    $content = $page->content();
    expect($content)->toContain('"object":"Core"')
        ->and($content)->toContain('"action":"Install"')
        ->and($content)->toContain('"data":[');
});

it('batch manager unit mode shows the caddie prefilter active by default with an empty caddie', function (): void {
    // Not assertSee('No filter, add one'): BatchManagerSubController::
    // resolveSessionFilter() defaults an unset/empty session filter to
    // `['prefilter' => 'caddie']` (confirmed via a real screenshot -- the
    // rendered page shows "Predefined filter: Caddie" / "Empty caddie" /
    // "List 0", not an unfiltered state), so a fresh navigation here
    // ALWAYS has a real, active filter -- the .noFilter div's "No filter,
    // add one" text is genuinely CSS `display:none` by default
    // (themes/admin/default/theme.css) and is only ever toggled visible
    // by JS after the user removes every filter client-side, a state a
    // plain GET request can never reach. batch_manager_filter.inc.tpl's
    // own "Empty caddie" link, by contrast, is server-rendered WITHOUT
    // any display:none precisely when $filter.prefilter === 'caddie' (the
    // real default here), so it's the correct, deterministic signal for
    // "the default caddie prefilter is active and empty".
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=batch_manager&mode=unit');

    expect($page->content())->toContain('<h1>Batch Manager');
    $page->assertSee('Empty caddie');
});

it('batch manager unit mode renders the real per-photo edit grid for a category filter', function (): void {
    // '?filter=cat-1' is BatchManagerSubController::resolveSessionFilter()'s
    // own real URL-filter token shape (parsed by the "url filter" branch,
    // one of the 2 distinct paths -- form POST vs URL token -- that both
    // populate $_SESSION['bulk_manager_filter']), set here to reach
    // BatchManagerUnitPageRenderer's `count($cat_elements_id) > 0` branch:
    // the ~300-line per-image loop (thumbnail/name/tags/linked-album
    // rendering), never exercised by the empty-filter test above.
    // Category 1 ("Sample Album") holds fixture images 1-3 (Photo
    // 1/2/3, files fixture-photo-1..3.jpg) per this fixture's own
    // piwigo_image_category rows -- verified against this exact URL's
    // real rendered output before writing this assertion, not guessed.
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=batch_manager&mode=unit&filter=cat-1');

    $content = $page->content();
    expect($content)->toContain('fixture-photo-1.jpg')
        ->and($content)->toContain('fixture-photo-2.jpg')
        ->and($content)->toContain('fixture-photo-3.jpg')
        // Proves the related-categories JOIN (getCatDisplayNameCache())
        // ran for a real row, not just that 3 filenames happened to print.
        ->and($content)->toContain('Sample Album');
});

it('admin popuphelp renders real help content inside the popup page chrome', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin/popuphelp.php?page=cat_options');

    $page->assertPresent('body#thePopuphelpPage');

    // Not assertSee(): themes/admin/default/theme.css's own global `h2 {
    // ... display: none; }` rule (confirmed via a real screenshot -- the
    // heading takes up zero rendered height, "Options management for..."
    // starts right at the top of the viewport) makes this exact heading
    // genuinely CSS-invisible in the real rendered page, so
    // isVisible()-gated assertSee() fails deterministically (100%
    // reproducible in isolation, unrelated to system load) even though
    // the correct help content is really present in the DOM -- confirmed
    // by direct comparison against an authenticated curl fetch of this
    // same URL, which returns this exact heading in the raw HTML. A
    // content() check verifies the real, intended thing (the correct
    // help topic loaded) without depending on this unrelated theme quirk.
    expect($page->content())->toContain('<h2>Album options</h2>');
});

it('admin popuphelp content_only output returns the bare help fragment with no page chrome', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin/popuphelp.php?page=cat_options&output=content_only');

    $content = $page->content();
    expect($content)->toContain('<h2>Album options</h2>')
        // Not ->not->toContain('<body'): Playwright's content() serializes
        // the browser's PARSED DOM, not the raw HTTP response bytes --
        // Chromium always normalizes ANY top-level navigation (even to a
        // body-less fragment) into a full <html><head></head><body>...
        // document, so this page ALWAYS shows a <body> tag in content()
        // regardless of what AdminPopuphelpController actually returned
        // (confirmed via a direct authenticated curl fetch of this same
        // URL: the real raw response is the bare fragment with no
        // <html>/<body> at all). 'thePopuphelpPage' is the real,
        // source-level distinguishing signal instead: the controller only
        // sets that body id -- via PageState::current()->setBodyId(...)
        // ahead of the full PageHeaderRenderer/PageTail chrome -- on the
        // non-content_only branch, so its absence here proves the "no
        // page chrome" claim this test is actually named for.
        ->and($content)->not->toContain('thePopuphelpPage');
});

it('admin popuphelp rejects a page parameter with invalid characters', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin/popuphelp.php?page=INVALID');

    expect($page->content())->toContain('Hacking attempt!');
});

it('rating by user page renders its own tab and ratings table', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=rating_user');

    $page->assertSee('Users');
    $page->assertPresent('#rateTable');
    $page->assertSee('Average rate');
});

it('plugin page rejects a well-formed but inactive plugin id', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=plugin&section=nonexistent_fixture_plugin/foo');

    expect($page->content())->toContain('Invalid URL - plugin nonexistent_fixture_plugin not active');
});

it('plugin page rejects a section with fewer than 2 slash-separated segments', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=plugin&section=onlyonesegment');

    expect($page->content())->toContain('[Hacking attempt] the input parameter "section" is not valid');
});
