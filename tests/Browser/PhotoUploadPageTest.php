<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

it('upload photo page loads via admin route', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=photos_add&tab=direct');
    $page->assertNoJavaScriptErrors();
});

it('photos_add page loads without errors', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=photos_add');
    $page->assertNoJavaScriptErrors();
});
