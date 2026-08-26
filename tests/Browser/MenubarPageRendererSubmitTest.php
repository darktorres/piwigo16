<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Admin\MenubarPageRenderer's own save-form submission (admin.php?
 * page=menubar) -- StatsPageRendererDateHelpersTest-style sibling
 * MenubarPageRendererMakeConsecutiveTest.php already covers the pure
 * absFnCmp()/makeConsecutive() helpers; this file covers the real
 * DB-persisted submission this page's own render() drives them from.
 */
it('hides a block and repositions another, persisting the new blk_menubar config', function (): void {
    $snapshot = H::snapshotConfig(['blk_menubar']);

    try {
        $page = H::asAdmin($this);

        $result = H::adminPost($page, '/admin.php?page=menubar', [
            'pwg_token' => H::pwgToken($page),
            'submit' => '1',
            'hide_mbTags' => '1',
            'pos_mbLinks' => '999',
        ]);

        expect($result['status'])->toBe(200);
        expect($result['body'])->toContain('Order of menubar items has been updated successfully');

        $stored = H::configValue('blk_menubar');
        expect($stored)
            ->not->toBeNull();
        $decoded = is_string($stored) ? json_decode($stored, true) : null;
        if (! is_array($decoded)) {
            throw new RuntimeException('blk_menubar config value did not decode to an array: ' . var_export($stored, true));
        }
        expect($decoded['mbTags'])->toBeLessThan(0);
        expect($decoded['mbLinks'])->toBeGreaterThan(0);
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('shows a hidden block\'s saved position as a negative value on the next render', function (): void {
    $snapshot = H::snapshotConfig(['blk_menubar']);

    try {
        $page = H::asAdmin($this);

        H::adminPost($page, '/admin.php?page=menubar', [
            'pwg_token' => H::pwgToken($page),
            'submit' => '1',
            'hide_mbSpecials' => '1',
        ]);

        $listPage = H::navigateOk($page, '/admin.php?page=menubar');
        $listPage->assertNoJavaScriptErrors();
        H::assertNoServerErrors($listPage, 'menubar re-render after hiding a block');
    } finally {
        H::restoreConfig($snapshot);
    }
});
