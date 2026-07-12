<?php

declare(strict_types=1);

use Piwigo\Csrf\CsrfService;

beforeEach(function (): void {
    // CsrfService reads global $conf directly, not Piwigo\Config\Config::
    // secretKey() -- see the class's own docblock for why (Config::$data is
    // never synced with the real, admin-configurable DB-persisted config
    // table during a live request).
    $GLOBALS['conf'] = ['secret_key' => 'test-secret-key'];
    unset($_REQUEST['pwg_token']);
});

// getToken()'s `session_id() === false` guard is preserved from the
// original get_pwg_token() as-is (not this migration's to remove), but is
// unreachable under PHP 8.5: confirmed empirically that session_id() only
// ever returns '' (no session started) or a real id string, never false --
// so there's no reachable case to exercise here.

test('getToken is stable for the same session id and secret key', function (): void {
    session_id('fixed-test-session-id');
    $service = new CsrfService();

    expect($service->getToken())->toBe($service->getToken());
});

test('getToken changes when the secret key changes', function (): void {
    session_id('fixed-test-session-id');
    $service = new CsrfService();
    $first = $service->getToken();

    $GLOBALS['conf'] = ['secret_key' => 'a-different-secret'];

    expect($service->getToken())->not->toBe($first);
});

test('check returns null when no token was submitted', function (): void {
    session_id('fixed-test-session-id');
    unset($_REQUEST['pwg_token']);

    expect(new CsrfService()->check())->toBeNull();
});

test('check returns true when the submitted token matches', function (): void {
    session_id('fixed-test-session-id');
    $service = new CsrfService();
    $_REQUEST['pwg_token'] = $service->getToken();

    expect($service->check())->toBeTrue();
});

test('check returns false when the submitted token does not match', function (): void {
    session_id('fixed-test-session-id');
    $_REQUEST['pwg_token'] = 'not-the-real-token';

    expect(new CsrfService()->check())->toBeFalse();
});
