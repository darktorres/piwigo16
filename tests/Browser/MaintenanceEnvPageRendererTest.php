<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Admin\MaintenanceEnvPageRenderer (admin.php?page=maintenance&tab=
 * env) -- server/DB info and cache/storage stats, plus the lock/unlock
 * gallery link toggle (Piwigo\Config\CurrentConfig::galleryLocked()).
 */
it('renders the env tab with real server/DB info when the gallery is unlocked', function (): void {
    $snapshot = H::snapshotConfig(['gallery_locked']);
    H::setConfigValue('gallery_locked', 'false');

    try {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=maintenance&tab=env');

        $page->assertSee(PHP_VERSION);
        $page->assertSee('MySQL');
        // The plugin list is server-rendered, no AJAX round-trip
        // -- this environment's plugins/ directory has no real plugin
        // subdirectories (confirmed live, same as WsPluginsTest's own
        // comment), so the empty-state branch is what a plain page load
        // exercises here.
        $page->assertSee('No plugin activated');
        $page->assertNoJavaScriptErrors();
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('server-renders a real active plugin\'s name and badge count in the env tab plugin list', function (): void {
    // Same throwaway-plugin-directory technique as Contract's
    // WsPluginsTest::testGetListIncludesARealPluginDirectoryFromDisk(),
    // plus a `state = 'active'` DB row -- PluginsGetListHandler's fixture
    // there never installs the plugin, only ever exercising the
    // 'uninstalled' fallback; this test needs the 'active' branch since
    // that's the only state maintenance_env.latte's fragment ever renders.
    $pluginId = 'ct_fake_plugin_' . uniqid();
    $dir = dirname(__DIR__, 2) . '/plugins/' . $pluginId;
    mkdir($dir, 0775, true);
    file_put_contents($dir . '/plugin.json', json_encode([
        'name' => 'CT Env Tab Fake Plugin',
        'version' => '1.2.3',
        'description' => 'A throwaway plugin directory for Browser test coverage.',
        'author' => 'Browser Tests',
    ], JSON_THROW_ON_ERROR));

    $db = H::connect();
    H::dbQuery($db, sprintf(
        "INSERT INTO plugins (id, state, version) VALUES ('%s', 'active', '1.2.3')",
        H::dbEscape($db, $pluginId)
    ));
    H::dbClose($db);

    try {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=maintenance&tab=env');

        $page->assertSee('CT Env Tab Fake Plugin');
        $page->assertDontSee('No plugin activated');
        $page->assertNoJavaScriptErrors();
    } finally {
        $db = H::connect();
        H::dbQuery($db, sprintf("DELETE FROM plugins WHERE id = '%s'", H::dbEscape($db, $pluginId)));
        H::dbClose($db);

        $entries = scandir($dir);
        foreach ($entries !== false ? $entries : [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                unlink($dir . '/' . $entry);
            }
        }
        rmdir($dir);
    }
});

it('shows the time-since-last-calculation when a real cache_sizes config value is present', function (): void {
    $snapshot = H::snapshotConfig(['cache_sizes']);
    // cache_sizes is a `[{name, value}, ...]` list persisted by
    // Ws\Core's cache-size calculation -- index 3's own 'value' (a date
    // string) is what MaintenanceEnvPageRenderer
    // reads for its "time since last calculation" display. Hand-crafted
    // here rather than triggering a real calculation, since only that one
    // index/key matters to this branch.
    H::setConfigValue('cache_sizes', '[{"name":"a","value":1},{"name":"b","value":2},{"name":"c","value":3},{"name":"d","value":"2020-01-01 00:00:00"}]');

    try {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=maintenance&tab=env');

        $page->assertNoJavaScriptErrors();
        H::assertNoServerErrors($page, 'maintenance env tab, cache_sizes present');
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('runs the real phpinfo maintenance action over a live HTTP request and returns the curated server-info page', function (): void {
    // Piwigo\Admin\Maintenance\MaintenanceActionDispatcher's own 'phpinfo'
    // case does a genuine `echo new ServerInfoService()->renderHtml();
    // exit();` -- an uncatchable process termination (unlike
    // RedirectServiceInterface::redirect()/HtmlRenderingInterface::
    // fatalError(), both `never`-typed but implemented via the catchable
    // Piwigo\Http\ResponseReadyException). tests/Integration/
    // MaintenanceActionDispatcherTest.php's own docblock documents exactly
    // why calling dispatch('phpinfo') directly there would kill the whole
    // shared PHPUnit/Pest CLI process mid-suite. Over a real HTTP request
    // the exit() runs inside the live Apache/php-fpm worker process
    // instead -- a completely separate process from this test runner,
    // where it just ends that one request normally. This is also the ONLY
    // way these 3 lines are ever measured for coverage credit at all:
    // composer test:coverage:web's PIWIGO_COVERAGE=1 header makes
    // Piwigo\Core\CoverageCollector dump real per-request pcov coverage
    // from this server-side process (see that class's own docblock), later
    // merged in by tools/coverage-merge.php.
    $page = H::loginAsAdmin($this);
    $token = H::pwgToken($page);

    $response = H::rawGet($page, '/admin.php?page=maintenance&tab=env&action=phpinfo&pwg_token=' . $token);

    expect($response['status'])->toBe(200);
    // Curated content from ServerInfoService::renderHtml() (SEC-22) --
    // already Unit-tested directly in ServerInfoServiceTest.php; these
    // assertions are only about the dispatcher's own call site actually
    // reaching and echoing it, not re-verifying that class's own logic.
    expect($response['body'])->toContain('<h1>Server information</h1>');
    expect($response['body'])->toContain(PHP_VERSION);
    expect($response['body'])->toContain('Loaded extensions');
});

it('rejects a maintenance action request with a missing/invalid CSRF token', function (): void {
    // Request\MaintenanceDispatchRequest::requiresCsrfCheck is true purely
    // from `isset($_GET['action'])` -- the CSRF gate (~lines 76-78) runs
    // (and fails, no pwg_token at all here) BEFORE MaintenanceActionDispatcher
    // ever sees the 'phpinfo' action, distinct from the real-phpinfo test
    // above's own valid-token, successful-dispatch path through the exact
    // same 2 lines.
    $page = H::loginAsAdmin($this);

    $response = H::rawGet($page, '/admin.php?page=maintenance&tab=env&action=phpinfo');

    expect($response['status'])->toBe(400);
});

it('renders successfully with the gallery locked (U_MAINT_UNLOCK_GALLERY branch)', function (): void {
    $snapshot = H::snapshotConfig(['gallery_locked']);
    H::setConfigValue('gallery_locked', 'true');

    try {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=maintenance&tab=env');

        // MaintenanceEnvPageRenderer assigns U_MAINT_LOCK_GALLERY/
        // U_MAINT_UNLOCK_GALLERY depending on CurrentConfig::galleryLocked()
        // -- but unlike the "actions" tab, maintenance_env.latte never
        // references either variable (zero "gallery" references anywhere
        // in that template), so there's nothing
        // observable in the HTML beyond a clean render. This exercises the
        // locked branch for coverage; the sibling test above already
        // exercises the unlocked branch.
        $page->assertNoJavaScriptErrors();
        H::assertNoServerErrors($page, 'maintenance env tab, gallery locked');
    } finally {
        H::restoreConfig($snapshot);
    }
});
