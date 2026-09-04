<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

it('shows a fatal error when synchronization is disabled', function (): void {
    $snapshot = H::snapshotConfig(['enable_synchronization']);
    H::setConfigValue('enable_synchronization', 'false');

    try {
        $page = H::asAdmin($this);

        $result = H::rawGet($page, '/admin.php?page=site_manager');

        expect($result['body'])->toContain('synchronization is disabled');
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('renders the site list including the real fixture site', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=site_manager');

    $page->assertSee('galleries');
    $page->assertNoJavaScriptErrors();
});

it('rejects a remote galleries_url as unsupported', function (): void {
    $page = H::asAdmin($this);
    $token = H::pwgToken($page);

    $result = H::adminPost($page, '/admin.php?page=site_manager', [
        'pwg_token' => $token,
        'submit' => '1',
        'galleries_url' => 'http://example.test/remote-gallery/',
    ]);

    expect($result['body'])->toContain('remote sites not supported');
});

it('rejects creating a site whose directory already exists as a registered site', function (): void {
    $page = H::asAdmin($this);
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
    $page = H::asAdmin($this);
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
        $page = H::asAdmin($this);
        $token = H::pwgToken($page);

        $createResult = H::adminPost($page, '/admin.php?page=site_manager', [
            'pwg_token' => $token,
            'submit' => '1',
            'galleries_url' => 'galleries/' . $dirName,
        ]);
        expect($createResult['status'])->toBe(200);
        expect($createResult['body'])->toContain('created');

        $db = H::connect();
        $row = H::dbFetchAssoc($db, sprintf("SELECT id FROM sites WHERE galleries_url LIKE '%%%s%%'", H::dbEscape($db, $dirName)));
        expect($row)
            ->not->toBeNull();
        $siteId = is_array($row) ? (int) $row['id'] : 0;
        expect($siteId)
            ->toBeGreaterThan(0);

        $listPage = H::navigateOk($page, '/admin.php?page=site_manager');
        // The source HTML entity-encodes the href as "&amp;", but a CSS
        // attribute selector matches the DOM's own decoded attribute value
        // (a literal "&"), not the markup's escaped form -- confirmed live.
        $listPage->assertPresent('a[href*="page=site_update&site=' . $siteId . '"]');

        $deleteResult = H::rawGet($page, '/admin.php?page=site_manager&action=delete&site=' . $siteId . '&pwg_token=' . $token);
        expect($deleteResult['status'])->toBe(200);
        expect($deleteResult['body'])->toContain('deleted');

        $afterRow = H::dbFetchAssoc($db, sprintf('SELECT id FROM sites WHERE id = %d', $siteId));
        H::dbClose($db);
        expect($afterRow)
            ->toBeNull();
    } finally {
        if (is_dir($absoluteDir)) {
            rmdir($absoluteDir);
        }
    }
});

it('rejects a submission without a valid CSRF token', function (): void {
    $page = H::asAdmin($this);

    $result = H::adminPost($page, '/admin.php?page=site_manager', [
        'submit' => '1',
        'galleries_url' => 'galleries/csrf-should-not-create-' . uniqid(),
    ]);

    expect($result['status'])->toBe(400);
});

/**
 * site/manager.ts's own "create a new site" toggle, the file's only
 * behaviour outside the delete-confirm plugin. Converted off jQuery in
 * P49-A, and this is what makes that conversion checkable: the form starts
 * hidden by a CSS class (`u-hidden`), not an inline style, so `show()` has
 * to notice the stylesheet is what is hiding it and write an inline
 * display of its own -- the exact case jQuery's show() handles and a naive
 * `style.display = ""` does not.
 *
 * Neither golden-html nor a pixel baseline can see this: both capture the
 * page at rest, before any click.
 */
it('reveals the create-site form and hides its own trigger on click', function (): void {
    $snapshot = H::snapshotConfig(['enable_synchronization']);
    H::setConfigValue('enable_synchronization', 'true');

    try {
        $page = H::asAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=site_manager');

        // Computed display rather than an assertVisible()/assertMissing()
        // pair: both nodes stay in the DOM throughout, and what the
        // conversion changes is precisely which of them the browser is
        // laying out. This is jQuery's own `:visible` test.
        $visible = static fn (string $selector): string => sprintf(
            'getComputedStyle(document.querySelector(%s)).display',
            json_encode($selector, JSON_THROW_ON_ERROR)
        );

        expect($page->script($visible('#showCreateSite')))
            ->not->toBe('none');
        expect($page->script($visible('#createSite')))
            ->toBe('none');

        $page->click('#showCreateSite a');

        expect($page->script($visible('#showCreateSite')))
            ->toBe('none');
        expect($page->script($visible('#createSite')))
            ->not->toBe('none');
        $page->assertNoJavaScriptErrors();
    } finally {
        H::restoreConfig($snapshot);
    }
});
