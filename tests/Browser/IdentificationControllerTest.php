<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Controller\IdentificationController (identification.php) -- had
 * no dedicated test file despite being driven, incidentally, by nearly
 * every other Browser test through H::loginAsAdmin()'s own real form
 * submission (see that helper's own implementation). That gives the
 * *successful* login path enormous incidental line coverage already;
 * this file targets the controller's own distinct branches
 * H::loginAsAdmin() never reaches: a failed login attempt (the
 * 'login_form_error' branch) and the already-authenticated redirect
 * guard.
 */
it('shows an error and re-renders the form for invalid credentials', function (): void {
    $page = H::visitPwg($this, '/identification.php');
    H::assertNoServerErrors($page, 'identification page');

    $page = $page
        ->fill('username', H::ADMIN_USER)
        ->fill('password', 'definitely-the-wrong-password')
        ->click('login');

    H::assertNoServerErrors($page, 'post-failed-login page');
    $page->assertSee('Invalid username or password');
    // Still on the login form, not redirected to the gallery -- the
    // logout link (only present once actually authenticated) must be
    // absent.
    $page->assertNotPresent('a[href*="act=logout"]');
});

it('redirects an already-authenticated session away from the login form', function (): void {
    $page = H::loginAsAdmin($this);

    H::rawWebpage($page)->navigate(H::baseUrl() . '/identification.php');
    H::assertNoServerErrors($page, 'identification page while already logged in');

    // AccessControl::isAGuest() being false redirects straight to the
    // gallery home -- the login form itself must never render.
    $page->assertNotPresent('input[name="username"]');
});
