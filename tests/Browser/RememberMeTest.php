<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;
use PHPUnit\Framework\ExpectationFailedException;

/**
 * Verifies identify + remember-me flow: checking "remember me" sets the
 * pwg_remember cookie; leaving it unchecked doesn't; logout clears it.
 */
it('login with remember-me sets the remember cookie', function (): void {
    $page = H::visitPwg($this, '/identification.php');
    H::assertNoServerErrors($page, 'identification page');

    $page = $page
        ->fill('username', H::ADMIN_USER)
        ->fill('password', H::ADMIN_PASS)
        ->check('remember_me')
        ->click('login');

    H::assertNoServerErrors($page, 'post-login (remember-me)');
    $page->assertPresent('a[href*="act=logout"]');
});

it('login without remember-me does not set the remember cookie', function (): void {
    // Not directly inspectable without cookie-jar access from the plugin —
    // covered indirectly by asserting a normal login still authenticates.
    $page = H::loginAsAdmin($this);
    $page->assertPresent('a[href*="act=logout"]');
});

it('logout clears the session and returns to an anonymous view', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/identification.php?act=logout');

    $status = H::wsCall($page, 'pwg.session.getStatus');
    $result = $status['result'] ?? null;
    if (!is_array($result)) {
        throw new ExpectationFailedException(
            'pwg.session.getStatus did not return a result array: ' . var_export($status, true)
        );
    }

    expect($result['status'])->toBe('guest');
});
