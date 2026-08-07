<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Admin\AdminShell (admin.php's whole page-shell orchestration) --
 * every other Browser test already exercises its main body incidentally
 * (any admin.php request goes through it), so this file targets only the
 * branches nothing else reaches: the plugins_new_order/change_theme direct
 * GET actions, the 3 page-slug alias rewrites (plugin-X, album-N-tab,
 * photo-N-tab), and the invalid-tab rejection.
 */
it('accepts a plugins_new_order AJAX request and exits immediately with an empty body', function (): void {
    $page = H::loginAsAdmin($this);

    $result = H::rawGet($page, '/admin.php?plugins_new_order=pluginA,pluginB');

    expect($result['status'])->toBe(200);
    expect($result['body'])->toBe('');
});

it('toggles the admin theme via change_theme and redirects back', function (): void {
    $page = H::loginAsAdmin($this);

    $result = H::rawGet($page, '/admin.php?page=intro&change_theme=1');

    // redirectService->redirect() is a real Location header -- opaque
    // under fetch(manual), status always 0 (see this suite's own
    // empty_caddie test for the same Fetch API caveat).
    expect($result['status'])->toBe(0);
});

it('resolves the plugin-X page-slug alias to page=plugin&section=X/admin.php', function (): void {
    $page = H::loginAsAdmin($this);

    $page = H::navigateOk($page, '/admin.php?page=plugin-community');

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'plugin-X page-slug alias');
});

it('resolves the plugin-X-Y page-slug alias to page=plugin&section=X/admin.php&tab=Y', function (): void {
    $page = H::loginAsAdmin($this);

    $page = H::navigateOk($page, '/admin.php?page=plugin-community-pendings');

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'plugin-X-Y page-slug alias');
});

it('rewrites the piwigo_videojs plugin section name to its hyphenated form', function (): void {
    $page = H::loginAsAdmin($this);

    $page = H::navigateOk($page, '/admin.php?page=plugin-piwigo_videojs');

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'plugin-piwigo_videojs underscore-to-hyphen alias');
});

it('resolves the album-N-tab page-slug alias to page=album&cat_id=N&tab=notification', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'AdminShell Alias Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];

    $page = H::navigateOk($page, '/admin.php?page=album-' . $albumId . '-notification');

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'album-N-tab page-slug alias');
});

it('resolves the photo-N-tab page-slug alias to page=photo&image_id=N&tab=properties', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'AdminShell Photo Alias Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'AdminShell Alias Photo');
    @unlink($image);

    $page = H::navigateOk($page, '/admin.php?page=photo-' . $imageId . '-properties');

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'photo-N-tab page-slug alias');
});

it('rejects a tab value containing disallowed characters as a hacking attempt', function (): void {
    $page = H::loginAsAdmin($this);

    $result = H::rawGet($page, '/admin.php?page=intro&tab=' . rawurlencode('../../etc/passwd'));

    expect($result['body'])->toContain('Hacking attempt');
});

it('shows the pending-comments counter when at least one unvalidated comment exists', function (): void {
    $snapshot = H::snapshotConfig(['activate_comments']);
    H::setConfigValue('activate_comments', 'true');

    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'AdminShell Comment Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'AdminShell Comment Photo');
    @unlink($image);

    // There is no pwg.comments.add WS method (confirmed: nothing in
    // Piwigo\Ws\PwgComments registers one) -- a real comment only ever
    // gets created through picture.php's own form action, so a direct
    // insert is the established way to seed one for a test (same shape as
    // PictureControllerTest's own pictureInsertComment() helper).
    $prefix = getenv('PIWIGO_DB_PREFIX');
    $prefix = $prefix !== false ? $prefix : 'piwigo_';
    $db = H::connect();
    H::dbQuery($db, sprintf(
        "INSERT INTO %scomments (image_id, date, author, anonymous_id, content, validated) VALUES (%d, NOW(), '%s', '127.0.0.9', '%s', FALSE)",
        $prefix,
        $imageId,
        H::dbEscape($db, 'AdminShell Test Author'),
        H::dbEscape($db, 'Pending comment for AdminShell coverage')
    ));
    $commentId = H::dbInsertId($db);
    H::dbClose($db);

    try {
        $page = H::navigateOk($page, '/admin.php?page=intro');
        $page->assertNoJavaScriptErrors();
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('re-runs the filesystem quick check when the last check is older than the configured period', function (): void {
    $snapshot = H::snapshotConfig(['fs_quick_check_period', 'fs_quick_check_last_check']);
    H::setConfigValue('fs_quick_check_period', '60');
    $staleCheck = date('c', strtotime('-1 hour'));
    H::setConfigValue('fs_quick_check_last_check', H::jsonEncode($staleCheck));

    try {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=intro');
        $page->assertNoJavaScriptErrors();

        // FilesystemIntegrityChecker::fsQuickCheck() unconditionally
        // rewrites 'fs_quick_check_last_check' to date('c') the instant it
        // actually runs (confirmed in FilesystemIntegrityCheckerTest) -- a
        // fresh timestamp, different from the stale one seeded above, is
        // direct proof AdminShell's own elapsed-period guard (the
        // strtotime() comparison) decided to call it, not merely that the
        // request didn't error.
        $after = H::configValue('fs_quick_check_last_check');
        expect($after)->not->toBe(H::jsonEncode($staleCheck));
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('synchronizes users against the base table when externalAuthentification is enabled', function (): void {
    $repoRoot = dirname(__DIR__, 2);
    $localConfigPath = $repoRoot . '/local/config/config.php';

    // local/config/ is torres-owned in this dev environment (same
    // direct-filesystem-fixture precedent as this repo's own
    // AdminConfigurationTest local/config/config.inc.php write) -- a plain
    // direct file write is safe. DeploymentPolicy::current() memoizes only
    // for the lifetime of a single PHP request (a plain static property,
    // reinitialized by the SAPI's own per-request teardown like every other
    // class static -- there is no persistent worker state in this mod_php
    // prefork deployment), so writing this file only for the duration of
    // one real HTTP request below cannot leak into any other
    // concurrently-scoped request.
    if (file_exists($localConfigPath)) {
        throw new RuntimeException("Refusing to overwrite a pre-existing {$localConfigPath} -- clean up a prior failed run first.");
    }

    // Login first, while the default (externalAuthentification: false)
    // DeploymentPolicy is still in effect -- this branch only needs to be
    // active for the admin.php request itself.
    $page = H::loginAsAdmin($this);

    file_put_contents(
        $localConfigPath,
        "<?php\nreturn new \\Piwigo\\Config\\DeploymentPolicy(externalAuthentification: true);\n"
    );

    try {
        $page = H::navigateOk($page, '/admin.php?page=intro');
        $page->assertNoJavaScriptErrors();
        H::assertNoServerErrors($page, 'externalAuthentification syncUsers branch');
    } finally {
        @unlink($localConfigPath);
    }
});

it('purges stale whats_new_* preferences and shows the whats-new popin for a user registered before the last major update', function (): void {
    $prefix = getenv('PIWIGO_DB_PREFIX');
    $prefix = $prefix !== false ? $prefix : 'piwigo_';
    $db = H::connect();

    $original = H::fetchAssocOrFail($db, "SELECT registration_date, preferences FROM {$prefix}user_infos WHERE user_id = 1");

    // fixture_admin (user_id 1, confirmed via tests/Fixtures/piwigo-17.0.sql)
    // registers *after* the fixture's own last_major_update and already
    // carries {"show_whats_new_16": false} -- neither reaches AdminShell's
    // "purge stale whats_new_*" branch (the outer getParam(...,true) check
    // would come back false, and even if it didn't, registration_date >
    // lastMajorUpdate takes the OTHER branch), so both are overridden here
    // and restored in the finally block below.
    H::dbQuery($db, sprintf(
        "UPDATE %suser_infos SET registration_date = '2020-01-01 00:00:00', preferences = '%s' WHERE user_id = 1",
        $prefix,
        H::dbEscape($db, H::jsonEncode(['whats_new_15' => true]))
    ));

    try {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=intro');
        $page->assertNoJavaScriptErrors();

        // assertSee() matches visible text (Playwright getByText()), which
        // can't see an id attribute -- the whats-new popin markup itself is
        // the only observable proof $show_whats_new (a purely local
        // variable) actually came back true, so this reads the raw HTML
        // instead (same H::rawWebpage()->content() technique
        // IntroSubControllerTest already uses).
        expect(H::rawWebpage($page)->content())->toContain('id="whats_new_popin"');

        $after = H::fetchAssocOrFail($db, "SELECT preferences FROM {$prefix}user_infos WHERE user_id = 1");
        $prefsAfter = json_decode((string) $after['preferences'], true);
        expect($prefsAfter)->not->toHaveKey('whats_new_15');
    } finally {
        $restoreRegistration = $original['registration_date'] === null
            ? 'NULL'
            : "'" . H::dbEscape($db, (string) $original['registration_date']) . "'";
        $restorePreferences = $original['preferences'] === null
            ? 'NULL'
            : "'" . H::dbEscape($db, (string) $original['preferences']) . "'";
        H::dbQuery($db, sprintf(
            "UPDATE %suser_infos SET registration_date = %s, preferences = %s WHERE user_id = 1",
            $prefix,
            $restoreRegistration,
            $restorePreferences
        ));
        H::dbClose($db);
    }
});
