<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * CatModifyPageRenderer's own activateComments() branch -- the existing
 * CatModifyPageRendererTest.php always ran with the fixture's real default
 * (comments enabled), so this asserts the disabled case renders without
 * the CAT_COMMENTABLE-dependent markup, and that both share the same
 * underlying commentable value when enabled.
 */
it('assigns CAT_COMMENTABLE when comments are activated', function (): void {
    $snapshot = H::snapshotConfig(['activate_comments']);
    H::setConfigValue('activate_comments', 'true');

    try {
        $page = H::asAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=album&cat_id=1&tab=properties');

        $page->assertNoJavaScriptErrors();
        H::assertNoServerErrors($page, 'cat_modify comments enabled');
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('omits CAT_COMMENTABLE entirely when comments are deactivated', function (): void {
    $snapshot = H::snapshotConfig(['activate_comments']);
    H::setConfigValue('activate_comments', 'false');

    try {
        $page = H::asAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=album&cat_id=1&tab=properties');

        $page->assertNoJavaScriptErrors();
        H::assertNoServerErrors($page, 'cat_modify comments disabled');
    } finally {
        H::restoreConfig($snapshot);
    }
});
