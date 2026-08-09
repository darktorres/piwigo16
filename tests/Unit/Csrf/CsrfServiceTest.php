<?php

declare(strict_types=1);

use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Csrf\CsrfService;

// A literal, hardcoded id here (e.g. 'fixed-test-session-id') collides at
// the OS level: /var/lib/php/sessions is a single machine-wide directory
// shared across every git worktree of this repo, and PHP's file session
// handler flock()s the session file for the id's lifetime. Two concurrent
// processes (this file's own test run racing a sibling worktree's
// identical test file) both calling session_start() with the same
// literal id genuinely block each other -- confirmed live, a ~350s stall
// on an unrelated test elsewhere in the same sequential run that happened
// to inherit this file's leftover session_id() value. uniqid() gives each
// process its own value, so no two concurrent runs anywhere on the
// machine can ever collide; memoized so every call within this one
// process's run still agrees (test below computes its own expected hash
// from the same id). str_replace() strips uniqid()'s own '.' separator
// (present whenever more_entropy is true) -- confirmed live, PHP's
// session_start() itself rejects a session id containing anything
// outside A-Z/a-z/0-9/'-'/',' ("Session ID is too long or contains
// illegal characters"), a real warning surfaced by
// Tests\Unit\Http\Middleware\SessionMiddlewareTest inheriting this
// file's leftover session_id() value in the same sequential/parallel
// worker process.
function csrfTestSessionId(): string
{
    /** @var string|null $id */
    static $id = null;
    $id ??= str_replace('.', '-', uniqid('csrf-test-', true));

    return $id;
}

beforeEach(function (): void {
    CurrentConfigTestFactory::get()->setSecretKey('test-secret-key');
    unset($_REQUEST['pwg_token']);
});

afterEach(function (): void {
    CurrentConfigTestFactory::get()->reset();
});

// getToken()'s `session_id() === false` guard is preserved from the
// original get_pwg_token() as-is (not this migration's to remove), but is
// unreachable under PHP 8.5: confirmed empirically that session_id() only
// ever returns '' (no session started) or a real id string, never false --
// so there's no reachable case to exercise here.
//
// Confirmed-equivalent: line 63's FalseToTrue (`$session_id === true`
// instead of `=== false`). Since session_id() never returns a bool at
// all (only a string), neither comparison can ever be true -- the guard
// is unreachable either way, for the exact same reason above. Confirmed
// live: the full suite in this file passes identically with the
// mutation applied.

test('getToken is stable for the same session id and secret key', function (): void {
    session_id(csrfTestSessionId());
    $service = new CsrfService(CurrentConfigTestFactory::get());

    expect($service->getToken())->toBe($service->getToken());
});

// [SEC-11] Regression test: getToken() must use sha256, not md5. Verified by
// recomputing the expected HMAC directly rather than just checking the
// output length, matching EphemeralKeyServiceTest's precedent for the same
// SEC-27/SEC-28 fix.
test('getToken uses sha256, not md5', function (): void {
    session_id(csrfTestSessionId());
    CurrentConfigTestFactory::get()->setSecretKey('test-secret-key');

    $expected = hash_hmac('sha256', csrfTestSessionId(), 'test-secret-key');

    expect(new CsrfService(CurrentConfigTestFactory::get())->getToken())->toBe($expected);
});

test('getToken changes when the secret key changes', function (): void {
    session_id(csrfTestSessionId());
    $service = new CsrfService(CurrentConfigTestFactory::get());
    $first = $service->getToken();

    CurrentConfigTestFactory::get()->setSecretKey('a-different-secret');

    expect($service->getToken())->not->toBe($first);
});

test('check returns null when no token was submitted', function (): void {
    session_id(csrfTestSessionId());
    unset($_REQUEST['pwg_token']);

    expect(new CsrfService(CurrentConfigTestFactory::get())->check())->toBeNull();
});

test('check returns true when the submitted token matches', function (): void {
    session_id(csrfTestSessionId());
    $service = new CsrfService(CurrentConfigTestFactory::get());
    $_REQUEST['pwg_token'] = $service->getToken();

    expect($service->check())->toBeTrue();
});

test('check returns false when the submitted token does not match', function (): void {
    session_id(csrfTestSessionId());
    $_REQUEST['pwg_token'] = 'not-the-real-token';

    expect(new CsrfService(CurrentConfigTestFactory::get())->check())->toBeFalse();
});
