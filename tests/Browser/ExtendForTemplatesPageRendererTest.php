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

        // One of template-extension/distributed/samples/'s 4 real .latte
        // files (see AdminUiHelperTest's own exact-listing test) -- a real
        // filesystem-discovered extension, not a fixture. AdminUiHelper::
        // getExtents() strips the leading directory prefix entirely (no
        // leading slash) -- a leading-slash value doesn't
        // strict-match the real discovered path, so the renderer's own
        // "Clearing" step immediately unsets it again even though it did
        // get persisted to config.
        $result = H::adminPost($page, '/admin.php?page=extend_for_templates', [
            'pwg_token' => H::pwgToken($page),
            'submit' => '1',
            'reptpl' => ['distributed/samples/my-picture.latte'],
            'original' => ['about.latte'],
            'url' => ['category'],
            'bound' => ['----------'],
        ]);

        expect($result['status'])->toBe(200);
        expect($result['body'])->toContain('Templates configuration has been recorded');

        $page = H::navigateOk($page, '/admin.php?page=extend_for_templates');
        $page->assertSee('distributed/samples/my-picture.latte');
        $page->assertNoJavaScriptErrors();
    } finally {
        H::restoreConfig($snapshot);
    }
});

// The "saves a real template replacement" test above always posts a real
// 'url' value ('category'), so it never exercises the '----------' ->
// 'N/A' normalization for url_keyword specifically (only for bound_tpl,
// whose own '----------' it does post). This isolates that branch.
it('normalizes a "----------" url keyword to N/A in the persisted replacement', function (): void {
    $snapshot = H::snapshotConfig(['extents_for_templates']);

    try {
        $page = H::loginAsAdmin($this);

        $result = H::adminPost($page, '/admin.php?page=extend_for_templates', [
            'pwg_token' => H::pwgToken($page),
            'submit' => '1',
            'reptpl' => ['distributed/samples/my-header.latte'],
            'original' => ['about.latte'],
            'url' => ['----------'],
            'bound' => ['----------'],
        ]);

        expect($result['status'])->toBe(200);
        expect($result['body'])->toContain('Templates configuration has been recorded');

        $raw = H::configValue('extents_for_templates');
        expect($raw)
            ->not->toBeNull();
        $decoded = json_decode((string) $raw, true);
        if (! is_array($decoded) || ! is_array($decoded['distributed/samples/my-header.latte'] ?? null)) {
            throw new RuntimeException('expected a real replacement entry, got: ' . var_export($decoded, true));
        }
        // [handle, url_keyword, bound_tpl] -- index 1 is url_keyword.
        expect($decoded['distributed/samples/my-header.latte'][1])->toBe('N/A');
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
            'reptpl' => ['distributed/samples/my-thumbnails.latte'],
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
        '/no-longer-on-disk.latte' => ['about.latte', 'category', 'N/A'],
    ]);
    if ($extents === false) {
        throw new RuntimeException('json_encode failed for the extents_for_templates config value');
    }
    H::setConfigValue('extents_for_templates', $extents);

    try {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=extend_for_templates');

        $page->assertDontSee('/no-longer-on-disk.latte');
        $page->assertNoJavaScriptErrors();
    } finally {
        H::restoreConfig($snapshot);
    }
});
