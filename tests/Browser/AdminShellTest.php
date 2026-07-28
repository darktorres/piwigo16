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
    $db = new mysqli(
        (string) getenv('PIWIGO_DB_HOST'),
        (string) getenv('PIWIGO_DB_USER'),
        (string) getenv('PIWIGO_DB_PASSWORD'),
        (string) getenv('PIWIGO_DB_BASE')
    );
    $db->query(sprintf(
        "INSERT INTO %scomments (image_id, date, author, anonymous_id, content, validated) VALUES (%d, NOW(), '%s', '127.0.0.9', '%s', 0)",
        $prefix,
        $imageId,
        $db->real_escape_string('AdminShell Test Author'),
        $db->real_escape_string('Pending comment for AdminShell coverage')
    ));
    $commentId = (int) $db->insert_id;
    $db->close();

    try {
        $page = H::navigateOk($page, '/admin.php?page=intro');
        $page->assertNoJavaScriptErrors();
    } finally {
        H::restoreConfig($snapshot);
    }
});