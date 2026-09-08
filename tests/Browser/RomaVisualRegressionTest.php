<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * P52 verification gap: the main VisualRegressionTest.php suite (82
 * baselines) and GoldenHtmlSnapshotTest.php (91 fixtures) never render
 * `roma` -- every route in their shared table runs under whichever admin
 * theme the fixture DB's default happens to be (`clear`), so `roma` has
 * had zero automated pixel coverage. That gap mattered directly for
 * P52-D (see docs/PLAN.md): roma received 248 of the 260 real token
 * conversions in that campaign, none of which this file's baselines
 * existed to verify against at the time.
 *
 * A small, curated subset, not the full 82 -- enough to cover roma's
 * distinct CSS surface (its jQuery-UI widgets carry the largest `ui-*`
 * rule count of the three admin skins, per AdminRomaThemeTest.php's own
 * docblock) without duplicating the main suite's route-by-route coverage.
 *
 * MUST run in isolation, same reason as VisualRegressionTest.php: bundling
 * with CRUD-mutating tests drifts the sidebar's live counts.
 *
 * To (re)generate baselines:
 *   vendor/bin/pest tests/Browser/RomaVisualRegressionTest.php --update-snapshots
 */
it('renders admin-history under roma with the datepicker open', function (): void {
    $db = H::connect();
    $before = H::dbFetchAssoc($db, 'SELECT preferences FROM user_infos WHERE user_id = 1');
    $original = is_string($before['preferences'] ?? null) ? $before['preferences'] : '{}';

    try {
        $page = H::asAdmin($this);
        H::setSessionPreference($page, [
            'param' => 'admin_theme',
            'value' => 'roma',
        ]);
        H::truncateHistory();

        $page = H::navigateOk($page, '/admin.php?page=history');
        H::waitUntilHidden($page, '.loading');

        // Open the datepicker rather than just asserting `hasDatepicker`
        // is attached (AdminRomaThemeTest.php's own check) -- the open
        // calendar (.ui-datepicker) is roma's single largest CSS surface
        // (~25 rules, per docs/PLAN.md), none of it visible with the
        // field merely attached and unfocused. Not `#ui-datepicker-div`:
        // this first-party TS port (themes/default/js/vendor/widgets/
        // datepicker.ts's buildPopup()) never assigns that id at all,
        // only the `.ui-datepicker` class -- found live, the id-based
        // poll below never resolved because the element it was looking
        // for doesn't exist under that name any more.
        //
        // A first attempt at focusing used the broader `input.hasDatepicker`
        // selector, which matches BOTH the start and end fields --
        // Playwright's click() refuses an ambiguous locator outright
        // (strict-mode violation) rather than clicking either, so nothing
        // was ever focused. Scoped to the start field specifically here.
        $page->click('input[data-datepicker="start"]');
        $page->script(<<<JS
            new Promise((resolve, reject) => {
                const deadline = Date.now() + 5000;
                const check = () => {
                    const el = document.querySelector('.ui-datepicker');
                    if (el !== null && getComputedStyle(el).display !== 'none') return resolve(true);
                    if (Date.now() > deadline) return reject(new Error('datepicker never opened'));
                    setTimeout(check, 100);
                };
                check();
            })
            JS);

        $page->assertPresent('.ui-datepicker');
        $page->assertScreenshotMatches();
    } finally {
        H::dbQuery(
            $db,
            "UPDATE user_infos SET preferences = '" . H::dbEscape($db, $original) . "' WHERE user_id = 1"
        );
        H::dbClose($db);
        H::markSharedSessionDirty();
    }
})->group('visual-regression');

it('renders the batch manager under roma', function (): void {
    $db = H::connect();
    $before = H::dbFetchAssoc($db, 'SELECT preferences FROM user_infos WHERE user_id = 1');
    $original = is_string($before['preferences'] ?? null) ? $before['preferences'] : '{}';

    try {
        $page = H::asAdmin($this);
        H::setSessionPreference($page, [
            'param' => 'admin_theme',
            'value' => 'roma',
        ]);

        $page = H::navigateOk($page, '/admin.php?page=batch_manager&mode=unit&filter=prefilter-all_photos&display=2');
        H::assertNoServerErrors($page, 'admin-batch-unit-paged-first (roma)');
        $page->assertScreenshotMatches();
    } finally {
        H::dbQuery(
            $db,
            "UPDATE user_infos SET preferences = '" . H::dbEscape($db, $original) . "' WHERE user_id = 1"
        );
        H::dbClose($db);
        H::markSharedSessionDirty();
    }
})->group('visual-regression');

it('renders the add-photos page under roma', function (): void {
    $db = H::connect();
    $before = H::dbFetchAssoc($db, 'SELECT preferences FROM user_infos WHERE user_id = 1');
    $original = is_string($before['preferences'] ?? null) ? $before['preferences'] : '{}';

    try {
        $page = H::asAdmin($this);
        H::setSessionPreference($page, [
            'param' => 'admin_theme',
            'value' => 'roma',
        ]);

        $page = H::navigateOk($page, '/admin.php?page=photos_add');
        H::assertNoServerErrors($page, 'admin-photos-add (roma)');
        $page->assertScreenshotMatches();
    } finally {
        H::dbQuery(
            $db,
            "UPDATE user_infos SET preferences = '" . H::dbEscape($db, $original) . "' WHERE user_id = 1"
        );
        H::dbClose($db);
        H::markSharedSessionDirty();
    }
})->group('visual-regression');

it('renders the photo editor under roma', function (): void {
    $db = H::connect();
    $before = H::dbFetchAssoc($db, 'SELECT preferences FROM user_infos WHERE user_id = 1');
    $original = is_string($before['preferences'] ?? null) ? $before['preferences'] : '{}';
    // freezeImageHits() has no restore counterpart (unlike
    // freezeGalleriesUrl()/galleriesUrl()) -- it permanently sets
    // `images`.hit, so this snapshots it manually. Found live:
    // admin-batch-unit-paged-first's own baseline shows image_id=1's hit
    // count too and expects 0 (its committed value); leaving this frozen
    // at 5 corrupted that later test in this same test:visual run, since
    // this file's tests happen to run before it alphabetically.
    $beforeHit = H::dbFetchAssoc($db, 'SELECT hit FROM images WHERE id = 1');
    $originalHit = is_numeric($beforeHit['hit'] ?? null) ? (int) $beforeHit['hit'] : 0;

    try {
        // Same hit-counter freeze as VisualRegressionTest.php's own
        // admin-photo-editor baseline -- the page shows "Visited N times".
        H::freezeImageHits(1, 5);

        $page = H::asAdmin($this);
        H::setSessionPreference($page, [
            'param' => 'admin_theme',
            'value' => 'roma',
        ]);

        $page = H::navigateOk($page, '/admin.php?page=photo-1');
        H::assertNoServerErrors($page, 'admin-photo-editor (roma)');
        $page->assertScreenshotMatches();
    } finally {
        // Theme restore first and unconditional -- see the jGrowl test's
        // own finally block below for why order matters here.
        H::dbQuery(
            $db,
            "UPDATE user_infos SET preferences = '" . H::dbEscape($db, $original) . "' WHERE user_id = 1"
        );
        H::markSharedSessionDirty();
        H::dbQuery($db, sprintf('UPDATE images SET hit = %d WHERE id = 1', $originalHit));
        H::dbClose($db);
    }
})->group('visual-regression');

it('renders the admin dashboard (sidebar/footer) under roma', function (): void {
    $db = H::connect();
    $before = H::dbFetchAssoc($db, 'SELECT preferences FROM user_infos WHERE user_id = 1');
    $original = is_string($before['preferences'] ?? null) ? $before['preferences'] : '{}';

    try {
        // Same accumulated-activity-row wipe as VisualRegressionTest.php's
        // own admin-dashboard baseline -- see that file's class docblock.
        H::truncateGuestActivity();

        $page = H::asAdmin($this);
        H::setSessionPreference($page, [
            'param' => 'admin_theme',
            'value' => 'roma',
        ]);

        $page = H::navigateOk($page, '/admin.php');
        H::assertNoServerErrors($page, 'admin-dashboard (roma)');
        $page->assertScreenshotMatches();
    } finally {
        H::dbQuery(
            $db,
            "UPDATE user_infos SET preferences = '" . H::dbEscape($db, $original) . "' WHERE user_id = 1"
        );
        H::dbClose($db);
        H::markSharedSessionDirty();
    }
})->group('visual-regression');

/**
 * Same self-contained fixture-plugin shape as
 * PluginsInstalledInteractionTest.php's PLUGINS_INTERACTION_FIXTURE_ID --
 * not reused across files (that file's helper functions are file-local by
 * convention here), a real PSR-4 class with inert lifecycle methods so
 * ExtensionLifecycle::performPluginAction() has a validated manifest to
 * activate.
 */
const ROMA_VR_PLUGIN_FIXTURE_ID = 'zz_roma_vr_plugin_fixture';

function romaVisualRegressionPluginFixtureCreate(): string
{
    $dir = dirname(__DIR__, 2) . '/plugins/' . ROMA_VR_PLUGIN_FIXTURE_ID;
    if (! is_dir($dir . '/src') && ! mkdir($dir . '/src', 0o777, true) && ! is_dir($dir . '/src')) {
        throw new RuntimeException('could not create the fixture plugin directory: ' . $dir);
    }

    $namespace = 'PiwigoTestFixture\\RomaVisualRegression' . bin2hex(random_bytes(6));

    $manifest = [
        'id' => ROMA_VR_PLUGIN_FIXTURE_ID,
        'name' => 'Roma Visual Regression Fixture Plugin',
        'version' => '1.0.0',
        'description' => 'Browser-test fixture for RomaVisualRegressionTest.php (P52).',
        'author' => 'piwigo-tests',
        'license' => 'MIT',
        'minPiwigo' => '16.3.0',
        'main' => $namespace . '\\Plugin',
        'autoload' => [
            'psr-4' => [
                $namespace . '\\' => 'src/',
            ],
        ],
    ];

    file_put_contents($dir . '/plugin.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    file_put_contents($dir . '/src/Plugin.php', <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        use Piwigo\\PluginConfig\\ExtensionContext;
        use Piwigo\\PluginConfig\\ExtensionInterface;

        final class Plugin implements ExtensionInterface
        {
            public function boot(ExtensionContext \$context): void {}
            public function install(): void {}
            public function activate(): void {}
            public function deactivate(): void {}
            public function uninstall(): void {}
            public function update(string \$oldVersion, string \$newVersion): void {}

            public function subscribedEvents(): array
            {
                return [];
            }
        }

        PHP);

    return $dir;
}

function romaVisualRegressionPluginFixtureRemove(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    if (is_file($dir . '/src/Plugin.php')) {
        unlink($dir . '/src/Plugin.php');
    }
    if (is_dir($dir . '/src')) {
        rmdir($dir . '/src');
    }
    if (is_file($dir . '/plugin.json')) {
        unlink($dir . '/plugin.json');
    }

    rmdir($dir);
}

it('renders the .AddPluginSuccess jGrowl-adjacent marker under roma', function (): void {
    // Not a jGrowl toast itself (those are transient, ~2.5s, and would be
    // gone by the time assertScreenshotMatches() fires) -- .AddPluginSuccess
    // is the *persistent* per-row marker P52-D actually converted (color
    // #0a0/bg #c2f5c2 in admin/default, redefined via custom properties in
    // roma), left visible in the DOM after activation succeeds. This is the
    // only route in this file that exercises P52-D's plugin-notification
    // token conversions at all.
    $dir = romaVisualRegressionPluginFixtureCreate();
    $id = ROMA_VR_PLUGIN_FIXTURE_ID;

    $db = H::connect();
    $before = H::dbFetchAssoc($db, 'SELECT preferences FROM user_infos WHERE user_id = 1');
    $original = is_string($before['preferences'] ?? null) ? $before['preferences'] : '{}';

    try {
        $page = H::asAdmin($this);
        H::setSessionPreference($page, [
            'param' => 'admin_theme',
            'value' => 'roma',
        ]);

        $page = H::navigateOk($page, '/admin.php?page=plugins&tab=installed');
        $page->script(<<<JS
            new Promise((resolve, reject) => {
                const deadline = Date.now() + 8000;
                const check = () => {
                    if (document.getElementById('{$id}') !== null) return resolve(true);
                    if (Date.now() > deadline) return reject(new Error('plugin row never rendered'));
                    setTimeout(check, 100);
                };
                check();
            })
            JS);

        $page->assertPresent('#' . $id . '.plugin-inactive');
        $page->click('#' . $id . ' label.switch');

        $page->script(<<<JS
            new Promise((resolve, reject) => {
                const deadline = Date.now() + 5000;
                const check = () => {
                    const marker = document.querySelector('#{$id} .AddPluginSuccess');
                    if (marker !== null && getComputedStyle(marker).display !== 'none') return resolve(true);
                    if (Date.now() > deadline) return reject(new Error('plugin activation never succeeded'));
                    setTimeout(check, 100);
                };
                check();
            })
            JS);

        $page->assertPresent('#' . $id . ' .AddPluginSuccess');
        $page->assertNoJavaScriptErrors();

        // installed.ts's own success handler calls fadeOut(marker, 3000)
        // immediately after showing it -- a real, continuously-running
        // opacity animation (themes/default/js/vendor/utils/dom.ts's
        // runEffect(), a requestAnimationFrame loop) starting the instant
        // it appears, not a fixed 3s-later cutoff. assertScreenshotMatches()
        // capturing wherever that animation happens to be mid-flight made
        // this baseline genuinely flaky (found live: three separate runs
        // caught three different opacity levels of the same fade). The
        // activation flow above already proved the real behavior.
        //
        // A plain inline-style override (`marker.style.setProperty(...,
        // 'important')`) doesn't survive this: runEffect() rewrites
        // `el.style.opacity` on every animation frame, and a fresh
        // non-important inline write always replaces whatever importance
        // the previous one carried -- confirmed live, the marker was still
        // gone after that attempt. An author-stylesheet rule is a
        // different cascade origin: its `!important` beats a later
        // non-important INLINE write too, and keeps winning every frame
        // the animation keeps rewriting that inline value, so it survives
        // for as long as this page stays open.
        $page->script(<<<JS
            const style = document.createElement('style');
            style.textContent = '#{$id} .AddPluginSuccess { opacity: 1 !important; display: flex !important; }';
            document.head.appendChild(style);
            JS);
        $page->assertScreenshotMatches();

        // Uninstall via the exact same fetch call installed.ts's own
        // uninstallPlugin() makes (api/v1/plugins/{id}/actions/perform,
        // action=uninstall -- ExtensionLifecycle::performPluginAction()
        // auto-deactivates first) rather than deleting only the plugin
        // directory: leaving the DB "installed" row behind with no
        // matching files corrupts every later test in this same
        // composer test:visual run with a real, visible "THIS PLUGIN IS
        // MISSING BUT IT IS INSTALLED" error card -- found live, on
        // admin-plugins-installed's own baseline. `pwgToken` isn't a
        // window global (installed.ts imports it from a module-scoped
        // source, confirmed live -- a first attempt reading window.pwgToken
        // got a 403) -- H::pwgToken() gets the real token the same way
        // H::apiFetch() does internally, via GET /api/v1/session.
        $csrfToken = H::pwgToken($page);
        $page->script(<<<JS
            fetch('api/v1/plugins/{$id}/actions/perform', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': '{$csrfToken}' },
                body: JSON.stringify({ action: 'uninstall' }),
            }).then((r) => {
                if (!r.ok) throw new Error('uninstall failed with status ' + r.status);
            })
            JS);
    } finally {
        // admin_theme restore goes FIRST and unconditionally -- a real
        // mysqli_sql_exception from the activity-log cleanup below once
        // aborted this finally block right after this line, leaving
        // admin_theme=roma live for every other test in the same
        // composer test:visual run (all 82 failed as a result, found
        // live). Whatever else in this block might throw, the session's
        // own theme preference restores first.
        H::dbQuery(
            $db,
            "UPDATE user_infos SET preferences = '" . H::dbEscape($db, $original) . "' WHERE user_id = 1"
        );
        H::markSharedSessionDirty();

        // Install/activate/uninstall all log a real `activity` row
        // (ExtensionLifecycle::performPluginAction() ->
        // ActivityService::record('system', ActivitySystem::Plugin, ...),
        // details carries plugin_id) -- found live, corrupting
        // admin-maintenance-sys's own baseline (its activity table listing)
        // for the rest of this same composer test:visual run otherwise.
        H::dbQuery(
            $db,
            "DELETE FROM activity WHERE object = 'system' AND details LIKE '%" .
                H::dbEscape($db, ROMA_VR_PLUGIN_FIXTURE_ID) . "%'"
        );

        // Defensive, unconditional fallback for the API-based uninstall
        // above: that call sits in the `try` block *after*
        // assertScreenshotMatches(), so a real assertion failure (this
        // marker has its own bug, tracked separately) throws before the
        // uninstall ever runs, skipping straight to this `finally`. The
        // plugin directory still gets removed below either way, so the
        // DB is left with an "installed" row pointing at files that no
        // longer exist -- confirmed live, this exact "THIS PLUGIN IS
        // MISSING BUT IT IS INSTALLED" error card corrupted
        // admin-plugins-installed's own baseline for the rest of the
        // same composer test:visual run. A raw DELETE here needs no
        // page/session/CSRF state to still be valid, unlike the fetch-
        // based uninstall, so it cleans up even when everything above it
        // already threw.
        //
        // `plugin_migrations` first: `plugins`.`id` is the parent side of
        // `fk_plugin_migrations_plugin_id` (ON DELETE RESTRICT, not
        // CASCADE) -- confirmed live, deleting `plugins` first throws
        // "Cannot delete or update a parent row" and this whole `finally`
        // block aborts right there, skipping the theme/activity cleanup
        // that runs after it too.
        H::dbQuery(
            $db,
            "DELETE FROM plugin_migrations WHERE plugin_id = '" . H::dbEscape($db, ROMA_VR_PLUGIN_FIXTURE_ID) . "'"
        );
        H::dbQuery(
            $db,
            "DELETE FROM plugins WHERE id = '" . H::dbEscape($db, ROMA_VR_PLUGIN_FIXTURE_ID) . "'"
        );
        H::dbClose($db);
        romaVisualRegressionPluginFixtureRemove($dir);
    }
})->group('visual-regression');
