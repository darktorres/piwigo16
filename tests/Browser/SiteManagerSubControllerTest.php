<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Controller\Admin\SiteManagerSubController (admin.php?page=
 * site_manager) -- multi-site (multi local-gallery-root) management. Real
 * site creation needs a real, on-disk directory under the live app root
 * (file_exists() is checked relative to the Apache worker's own CWD, not
 * redirectable via CurrentPaths like most other tests in this suite) --
 * galleries/ is this environment's own real, already-used site-storage
 * root (see the fixture's own site 1 row), so a uniquely-named throwaway
 * subdirectory there is the safe, cleanable equivalent of every other
 * "create then delete via the app's own action" test in this suite.
 */
function siteManagerDbPrefix(): string
{
    $prefix = getenv('PIWIGO_DB_PREFIX');

    return $prefix !== false ? $prefix : 'piwigo_';
}

it('shows a fatal error when synchronization is disabled', function (): void {
    $snapshot = H::snapshotConfig(['enable_synchronization']);
    H::setConfigValue('enable_synchronization', 'false');

    try {
        $page = H::loginAsAdmin($this);

        $result = H::rawGet($page, '/admin.php?page=site_manager');

        expect($result['body'])->toContain('synchronization is disabled');
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('renders the site list including the real fixture site', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=site_manager');

    $page->assertSee('galleries');
    $page->assertNoJavaScriptErrors();
});

it('rejects a remote galleries_url as unsupported', function (): void {
    $page = H::loginAsAdmin($this);
    $token = H::pwgToken($page);

    $result = H::adminPost($page, '/admin.php?page=site_manager', [
        'pwg_token' => $token,
        'submit' => '1',
        'galleries_url' => 'http://example.test/remote-gallery/',
    ]);

    expect($result['body'])->toContain('remote sites not supported');
});

it('rejects creating a site whose directory already exists as a registered site', function (): void {
    $page = H::loginAsAdmin($this);
    $token = H::pwgToken($page);

    $result = H::adminPost($page, '/admin.php?page=site_manager', [
        'pwg_token' => $token,
        'submit' => '1',
        'galleries_url' => 'galleries',
    ]);

    expect($result['status'])->toBe(200);
    expect($result['body'])->toContain('This site already exists');
});

it('rejects creating a site whose directory does not exist on disk', function (): void {
    $page = H::loginAsAdmin($this);
    $token = H::pwgToken($page);
    $missingDir = 'galleries/does-not-exist-' . uniqid();

    $result = H::adminPost($page, '/admin.php?page=site_manager', [
        'pwg_token' => $token,
        'submit' => '1',
        'galleries_url' => $missingDir,
    ]);

    expect($result['status'])->toBe(200);
    expect($result['body'])->toContain('Directory does not exist');
});

it('creates a new site for a real, existing directory, then deletes it via the CSRF-gated delete action', function (): void {
    $dirName = 'test-site-manager-' . uniqid();
    $absoluteDir = __DIR__ . '/../../galleries/' . $dirName;
    mkdir($absoluteDir, 0o777, true);

    try {
        $page = H::loginAsAdmin($this);
        $token = H::pwgToken($page);

        $createResult = H::adminPost($page, '/admin.php?page=site_manager', [
            'pwg_token' => $token,
            'submit' => '1',
            'galleries_url' => 'galleries/' . $dirName,
        ]);
        expect($createResult['status'])->toBe(200);
        expect($createResult['body'])->toContain('created');

        $db = new mysqli(
            (string) getenv('PIWIGO_DB_HOST'),
            (string) getenv('PIWIGO_DB_USER'),
            (string) getenv('PIWIGO_DB_PASSWORD'),
            (string) getenv('PIWIGO_DB_BASE')
        );
        $prefix = siteManagerDbPrefix();
        $result = $db->query(sprintf(
            "SELECT id FROM %ssites WHERE galleries_url LIKE '%%%s%%'",
            $prefix,
            $db->real_escape_string($dirName)
        ));
        $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
        expect($row)->not->toBeNull();
        $siteId = is_array($row) ? (int) $row['id'] : 0;
        expect($siteId)->toBeGreaterThan(0);

        $listPage = H::navigateOk($page, '/admin.php?page=site_manager');
        // The source HTML entity-encodes the href as "&amp;", but a CSS
        // attribute selector matches the DOM's own decoded attribute value
        // (a literal "&"), not the markup's escaped form -- confirmed live.
        $listPage->assertPresent('a[href*="page=site_update&site=' . $siteId . '"]');

        $deleteResult = H::rawGet($page, '/admin.php?page=site_manager&action=delete&site=' . $siteId . '&pwg_token=' . $token);
        expect($deleteResult['status'])->toBe(200);
        expect($deleteResult['body'])->toContain('deleted');

        $afterDelete = $db->query(sprintf('SELECT id FROM %ssites WHERE id = %d', $prefix, $siteId));
        $afterRow = $afterDelete instanceof mysqli_result ? $afterDelete->fetch_assoc() : null;
        $db->close();
        expect($afterRow)->toBeNull();
    } finally {
        if (is_dir($absoluteDir)) {
            rmdir($absoluteDir);
        }
    }
});

it('rejects a submission without a valid CSRF token', function (): void {
    $page = H::loginAsAdmin($this);

    $result = H::adminPost($page, '/admin.php?page=site_manager', [
        'submit' => '1',
        'galleries_url' => 'galleries/csrf-should-not-create-' . uniqid(),
    ]);

    expect($result['status'])->toBe(400);
});