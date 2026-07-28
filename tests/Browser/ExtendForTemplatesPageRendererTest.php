<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Admin\ExtendForTemplatesPageRenderer (admin.php?page=
 * extend_for_templates) -- already GET-tested by AdminExtendedSmokeTest;
 * this file exercises the real save-form submission (the isSubmitted
 * branch, ~140 lines) which persists a real template-replacement mapping
 * via ConfigService::confUpdateParam('extents_for_templates', ...).
 */
it('saves a real template replacement and echoes it back on the next render', function (): void {
    $snapshot = H::snapshotConfig(['extents_for_templates']);

    try {
        $page = H::loginAsAdmin($this);

        // One of template-extension/distributed/samples/'s 4 real .tpl
        // files (see AdminUiHelperTest's own exact-listing test) -- a real
        // filesystem-discovered extension, not a fixture. AdminUiHelper::
        // getExtents() strips the leading directory prefix entirely (no
        // leading slash, confirmed live) -- a leading-slash value doesn't
        // strict-match the real discovered path, so the renderer's own
        // "Clearing" step immediately unsets it again even though it did
        // get persisted to config.
        $result = H::adminPost($page, '/admin.php?page=extend_for_templates', [
            'pwg_token' => H::pwgToken($page),
            'submit' => '1',
            'reptpl' => ['distributed/samples/my-picture.tpl'],
            'original' => ['about.tpl'],
            'url' => ['category'],
            'bound' => ['----------'],
        ]);

        expect($result['status'])->toBe(200);
        expect($result['body'])->toContain('Templates configuration has been recorded');

        $page = H::navigateOk($page, '/admin.php?page=extend_for_templates');
        $page->assertSee('distributed/samples/my-picture.tpl');
        $page->assertNoJavaScriptErrors();
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('skips a malformed replacement row (non-string field) without erroring', function (): void {
    $snapshot = H::snapshotConfig(['extents_for_templates']);

    try {
        $page = H::loginAsAdmin($this);

        $result = H::adminPost($page, '/admin.php?page=extend_for_templates', [
            'pwg_token' => H::pwgToken($page),
            'submit' => '1',
            'reptpl' => ['distributed/samples/my-thumbnails.tpl'],
            'original' => [['not-a-string']],
            'url' => ['category'],
            'bound' => ['----------'],
        ]);

        expect($result['status'])->toBe(200);
        expect($result['body'])->not->toContain('Fatal error');
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('drops a stale replacement whose replacer file no longer exists on disk', function (): void {
    $snapshot = H::snapshotConfig(['extents_for_templates']);
    $extents = json_encode([
        '/no-longer-on-disk.tpl' => ['about', 'category', 'N/A'],
    ]);
    if ($extents === false) {
        throw new RuntimeException('json_encode failed for the extents_for_templates config value');
    }
    H::setConfigValue('extents_for_templates', $extents);

    try {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=extend_for_templates');

        $page->assertDontSee('/no-longer-on-disk.tpl');
        $page->assertNoJavaScriptErrors();
    } finally {
        H::restoreConfig($snapshot);
    }
});