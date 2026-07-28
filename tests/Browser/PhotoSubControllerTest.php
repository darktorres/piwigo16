<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Controller\Admin\PhotoSubController (admin.php?page=photo, tab
 * dispatch) -- had no dedicated test file. PictureModifyPageRendererTest
 * already drives the default 'properties' tab through this exact shell,
 * but PictureCoiPageRendererTest/PictureFormatsPageRendererTest reach
 * their own renderers through their OWN direct top-level page slugs
 * (page=picture_coi / a formats-specific route, see those renderers' own
 * docblocks: "additionally directly reachable as their own top-level
 * ?page= slugs") -- never through page=photo&tab=coi / tab=formats, so
 * this shell's own 'coi'/formats-fallback dispatch branches had zero
 * coverage. Fixture image 1 (in category 1) is real, pre-existing data --
 * no upload needed for a read-only tab-render check.
 */
it('dispatches to the coi tab renderer via page=photo&tab=coi', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=photo&image_id=1&tab=coi');
    $page->assertNoJavaScriptErrors();
});

it('dispatches to the formats tab renderer via page=photo&tab=formats when formats are enabled', function (): void {
    $page = H::loginAsAdmin($this);
    $snapshot = H::snapshotConfig(['enable_formats']);

    try {
        H::setConfigValue('enable_formats', 'true');

        $page = H::navigateOk($page, '/admin.php?page=photo&image_id=1&tab=formats');
        $page->assertNoJavaScriptErrors();
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('falls back to the properties tab for an unknown tab value', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=photo&image_id=1&tab=not-a-real-tab');
    $page->assertNoJavaScriptErrors();
});
