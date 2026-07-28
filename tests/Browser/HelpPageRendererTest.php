<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Admin\HelpPageRenderer (admin.php?page=help) -- a read-only
 * documentation page. Tabsheet::select() silently falls back to the first
 * registered section for any unrecognized ?section value (see
 * HelpSectionRequest's own docblock: this is Tabsheet's real gate, not
 * something this page validates itself), so an arbitrary/bogus section
 * name is safe rather than a hacking-attempt rejection.
 */
it('renders the default "add_photos" help section', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=help');

    $page->assertSee('Add Photos');
    $page->assertNoJavaScriptErrors();
});

it('renders the "permissions" help section when requested', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=help&section=permissions');

    $page->assertSee('Permissions');
    $page->assertNoJavaScriptErrors();
});

it('renders the "groups" help section when requested', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=help&section=groups');

    $page->assertSee('Groups');
    $page->assertNoJavaScriptErrors();
});

it('falls back to the first registered section for an unrecognized section name', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=help&section=not-a-real-section');

    // Tabsheet::select() falls back to the first added sheet (add_photos)
    // rather than erroring on the unrecognized value.
    $page->assertSee('Add Photos');
    $page->assertNoJavaScriptErrors();
});

it('shows the English online-documentation message for an en_UK admin user', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=help');

    $page->assertSee('Check the online documentation');
});