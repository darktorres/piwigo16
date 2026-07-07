<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

it('admin login and dashboard load', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php');
    $page->assertSee('Piwigo Administration');
    $page->assertNoJavaScriptErrors();
});

it('admin albums page loads', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=albums');
    $page->assertNoJavaScriptErrors();
});
