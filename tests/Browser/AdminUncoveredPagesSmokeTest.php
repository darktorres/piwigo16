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

/**
 * Closes ThemeSubController's 3rd, remaining branch (the real
 * `include_once $filename;`, line ~47): needs a theme with BOTH a
 * themeconf.inc.php (so ExtensionScanner::scan() finds it -- a pure
 * filesystem scan, `is_dir()` + `file_exists()` read via file(), never
 * `include`d itself, so its content only needs to parse via a plain
 * regex, not be valid PHP) AND its own admin/admin.inc.php, which
 * neither bundled theme ships (see the test above). Same throwaway-
 * fixture-under-the-live-root technique as the plugin fixture tests
 * above -- unlike ThemesInstalledPageRendererTest.php's own documented
 * reason for avoiding this (a real activate/deactivate/delete STATE
 * transition, visible to every other test that lists installed/active
 * themes), this never touches the `theme` config row or any DB table at
 * all -- ExtensionScanner::scan() is a pure filesystem read, so a
 * same-it()-scoped, finally-cleaned-up directory carries none of that
 * cross-test state risk.
 */
function themeSubThemesPath(): string
{
    return dirname(__DIR__, 2) . '/themes/';
}

function themeSubWriteFixtureTheme(string $themeId): void
{
    $dir = themeSubThemesPath() . $themeId;
    $adminDir = $dir . '/admin';
    if (! is_dir($adminDir)) {
        mkdir($adminDir, 0o777, true);
    }
    file_put_contents($dir . '/themeconf.inc.php', "<?php\n// Theme Name: " . $themeId . "\n");
    file_put_contents($adminDir . '/admin.inc.php', "<?php\necho '<!--CT_THEMESUB_INCLUDED-->';\n");
}

function themeSubRemoveFixtureTheme(string $themeId): void
{
    $dir = themeSubThemesPath() . $themeId;
    @unlink($dir . '/admin/admin.inc.php');
    if (is_dir($dir . '/admin')) {
        rmdir($dir . '/admin');
    }
    @unlink($dir . '/themeconf.inc.php');
    if (is_dir($dir)) {
        rmdir($dir);
    }
}

it('theme page includes a real admin.inc.php for a theme that ships one', function (): void {
    $themeId = 'ct-themesub-active-' . uniqid();
    themeSubWriteFixtureTheme($themeId);

    try {
        $page = H::loginAsAdmin($this);

        // H::rawGet(), not navigateOk()+content() -- confirmed live:
        // ThemeSubController::handle() echoes this marker via include_once
        // *before* the surrounding admin template's own <!DOCTYPE html> is
        // ever emitted, which really is present in the raw HTTP response
        // body, but Playwright's page.content() returns
        // document.documentElement.outerHTML (a DOM serialization), not
        // the raw response -- a comment positioned before <html> is a
        // sibling of the <html> element in the parsed document, not a
        // descendant, so outerHTML never includes it. rawGet()'s in-browser
        // fetch().text() reads the real, unparsed response body instead.
        $result = H::rawGet($page, '/admin.php?page=theme&theme=' . $themeId);
        expect($result['status'])->toBe(200);
        expect($result['body'])->toContain('CT_THEMESUB_INCLUDED');
    } finally {
        themeSubRemoveFixtureTheme($themeId);
    }
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

/**
 * Closes the `$help_content === false` fallback (~line 95): a
 * well-formed (charset-valid) `page` value with no matching
 * `help/<page>.html` under any real/parent/force_fallback('en_UK')/
 * default language -- distinct from the "rejects invalid characters" test
 * above, which never even reaches Lang::load() at all.
 */
it('admin popuphelp falls back to empty content for a well-formed but nonexistent help topic', function (): void {
    $page = H::loginAsAdmin($this);
    // H::rawGet() (not navigateOk()+content()): Playwright's own content()
    // always DOM-normalizes a navigation into a full <html><head>
    // </head><body>...</body></html> document even for a truly empty
    // response body (confirmed by the "content_only output" test above's
    // own docblock, for the same underlying reason) -- rawGet() reads the
    // real, raw HTTP response body instead, needed for an exact-empty
    // assertion.
    $result = H::rawGet($page, '/admin/popuphelp.php?page=zzz_nonexistent_help_topic&output=content_only');

    expect($result['status'])->toBe(200);
    expect($result['body'])->toBe('');
});

/**
 * Closes the `get_popup_help_content` hook's own non-string-return
 * fallback (~line 100) -- EventDispatcher::triggerChange()'s pass-through
 * default (no handler registered) always returns $help_content unchanged,
 * already a string, so this needs a REAL plugin registering the hook,
 * same throwaway-fixture-plugin technique as the PluginSubController
 * tests above.
 */
it('admin popuphelp fatal-errors when a real get_popup_help_content hook returns something other than a GetPopupHelpContent instance', function (): void {
    $pluginId = 'ct-popuphelp-hook-' . uniqid();
    $dir = pluginSubPluginsPath() . $pluginId;
    if (! is_dir($dir)) {
        mkdir($dir, 0o777, true);
    }
    file_put_contents($dir . '/main.inc.php', <<<'PHP'
    <?php

    declare(strict_types=1);

    /*
    Plugin Name: Admin Popuphelp Test -- get_popup_help_content Hook
    Version: 1.0.0
    Description: Test-only fixture plugin (tests/Browser/AdminUncoveredPagesSmokeTest.php).
    */

    \Piwigo\PluginConfig\EventDispatcher::get()->addEventHandler(
        \Piwigo\Event\Admin\GetPopupHelpContent::class,
        static fn (): mixed => null
    );
    PHP);
    $db = pluginSubDb();
    $prefix = pluginSubDbPrefix();
    $db->query(sprintf(
        "INSERT INTO %splugins (id, state, version) VALUES ('%s', 'active', '1.0.0')",
        $prefix,
        $db->real_escape_string($pluginId)
    ));
    $db->close();

    try {
        $page = H::loginAsAdmin($this);
        // dispatchChange()'s own fail-loud contract now throws \Error
        // instead of gracefully falling back to '' -- display_errors is
        // off site-wide, so the HTTP status is the only reliable signal
        // (see this suite's own established convention for this).
        $result = H::rawGet($page, '/admin/popuphelp.php?page=cat_options&output=content_only');

        expect($result['status'])->toBe(500);
    } finally {
        $db = pluginSubDb();
        $db->query(sprintf("DELETE FROM %splugins WHERE id = '%s'", $prefix, $db->real_escape_string($pluginId)));
        $db->close();
        pluginSubRemoveFixturePlugin($pluginId);
    }
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

/**
 * Both tests below need a plugin PluginLoader::loadPlugins() actually
 * loads (a real `plugins` DB row with state='active' AND a matching
 * plugins/<id>/main.inc.php on disk -- LoadedPlugins::get() only gets
 * populated for a plugin satisfying both), reaching PluginSubController's
 * own remaining 2 branches (line ~42 onward: an active plugin whose
 * requested section file does/doesn't exist) that neither test above can
 * reach (both use a plugin id LoadedPlugins never even contains). Same
 * throwaway-fixture-under-the-live-plugins-root technique
 * PluginsInstalledPageRendererTest.php's own docblock already establishes
 * (single it()-scoped, sequential Browser suite, cleaned up in a finally
 * block).
 */
function pluginSubDbPrefix(): string
{
    $prefix = getenv('PIWIGO_DB_PREFIX');

    return $prefix !== false ? $prefix : 'piwigo_';
}

function pluginSubDb(): mysqli
{
    return new mysqli(
        (string) getenv('PIWIGO_DB_HOST'),
        (string) getenv('PIWIGO_DB_USER'),
        (string) getenv('PIWIGO_DB_PASSWORD'),
        (string) getenv('PIWIGO_DB_BASE')
    );
}

function pluginSubPluginsPath(): string
{
    return dirname(__DIR__, 2) . '/plugins/';
}

function pluginSubWriteFixturePlugin(string $pluginId): void
{
    $dir = pluginSubPluginsPath() . $pluginId;
    if (! is_dir($dir)) {
        mkdir($dir, 0o777, true);
    }
    file_put_contents($dir . '/main.inc.php', "<?php\n");
    file_put_contents($dir . '/ct_settings.php', "<?php\necho '<!--CT_PLUGINSUB_INCLUDED-->';\n");
}

function pluginSubRemoveFixturePlugin(string $pluginId): void
{
    // Real file_exists() guards, not a bare @unlink() -- @ only suppresses
    // the ini display_errors output, not Pest/PHPUnit's own conversion of
    // a real E_WARNING into a test WARNING outcome (confirmed live: some
    // callers of this helper never write ct_settings.php at all, e.g. the
    // "admin popuphelp" test, which only writes main.inc.php).
    $dir = pluginSubPluginsPath() . $pluginId;
    if (file_exists($dir . '/main.inc.php')) {
        unlink($dir . '/main.inc.php');
    }
    if (file_exists($dir . '/ct_settings.php')) {
        unlink($dir . '/ct_settings.php');
    }
    if (is_dir($dir)) {
        rmdir($dir);
    }
}

it('plugin page includes a real settings file from an active on-disk plugin', function (): void {
    // AdminShellRequest::fromArrays()'s own 'section' validation (top-level,
    // ahead of PluginSectionRequest's plugin-specific check) is a faithful
    // port of legacy Piwigo's own /^[a-z]+[a-z_\/-]*(\.php)?$/i -- no
    // digits anywhere in the value. A bare uniqid() plugin id would trip
    // it (real 401/hacking-attempt page, confirmed live), so the digits
    // get mapped to letters here instead of dropping uniqueness.
    $pluginId = 'ct-pluginsub-active-' . strtr(uniqid(), '0123456789', 'abcdefghij');
    pluginSubWriteFixturePlugin($pluginId);
    $db = pluginSubDb();
    $prefix = pluginSubDbPrefix();
    $db->query(sprintf(
        "INSERT INTO %splugins (id, state, version) VALUES ('%s', 'active', '1.0')",
        $prefix,
        $db->real_escape_string($pluginId)
    ));
    $db->close();

    try {
        $page = H::loginAsAdmin($this);

        // H::rawGet(), not navigateOk()+content() -- confirmed live:
        // PluginSubController::handle() echoes this marker via
        // include_once *before* the surrounding admin template's own
        // <!DOCTYPE html> is ever emitted, which really is present in the
        // raw HTTP response body, but Playwright's page.content() returns
        // document.documentElement.outerHTML (a DOM serialization), not
        // the raw response -- a comment positioned before <html> is a
        // sibling of the <html> element in the parsed document, not a
        // descendant, so outerHTML never includes it. rawGet()'s in-browser
        // fetch().text() reads the real, unparsed response body instead.
        $result = H::rawGet($page, '/admin.php?page=plugin&section=' . $pluginId . '/ct_settings.php');
        expect($result['status'])->toBe(200);
        expect($result['body'])->toContain('CT_PLUGINSUB_INCLUDED');
    } finally {
        $db = pluginSubDb();
        $db->query(sprintf("DELETE FROM %splugins WHERE id = '%s'", $prefix, $db->real_escape_string($pluginId)));
        $db->close();
        pluginSubRemoveFixturePlugin($pluginId);
    }
});

it('plugin page fatal-errors on a missing section file for a real active plugin', function (): void {
    // Same reasoning as the plugin-page-includes test above -- 'section'
    // must stay digit-free.
    $pluginId = 'ct-pluginsub-missing-' . strtr(uniqid(), '0123456789', 'abcdefghij');
    pluginSubWriteFixturePlugin($pluginId);
    $db = pluginSubDb();
    $prefix = pluginSubDbPrefix();
    $db->query(sprintf(
        "INSERT INTO %splugins (id, state, version) VALUES ('%s', 'active', '1.0')",
        $prefix,
        $db->real_escape_string($pluginId)
    ));
    $db->close();

    try {
        $page = H::loginAsAdmin($this);
        // ct_missing.php is deliberately never written to disk.
        $page = H::navigateOk($page, '/admin.php?page=plugin&section=' . $pluginId . '/ct_missing.php');

        expect($page->content())->toContain('Missing file')
            ->and($page->content())->toContain($pluginId . '/ct_missing.php');
    } finally {
        $db = pluginSubDb();
        $db->query(sprintf("DELETE FROM %splugins WHERE id = '%s'", $prefix, $db->real_escape_string($pluginId)));
        $db->close();
        pluginSubRemoveFixturePlugin($pluginId);
    }
});
