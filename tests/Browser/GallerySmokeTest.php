<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

it('loads the gallery home page without errors', function (): void {
    $page = H::gotoOk($this, '/index.php');
    $page->assertNoJavaScriptErrors();
});

it('renders the identification (login) form', function (): void {
    $page = H::gotoOk($this, '/identification.php');
    $page->assertPresent('input.login[name="username"]');
    $page->assertPresent('form[name="login_form"] input[name="password"]');
    $page->assertPresent('form[name="login_form"] input[name="login"]');
    $page->assertNoJavaScriptErrors();
});
