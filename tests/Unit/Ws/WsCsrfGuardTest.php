<?php

declare(strict_types=1);

use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Csrf\CsrfService;
use Piwigo\Ws\WsCsrfGuard;
use Piwigo\Ws\WsErrorResponse;

/**
 * Piwigo\Ws\WsCsrfGuard -- the WS layer's own CSRF check, split out of
 * the former WsHelper god-class (P25 Stage 1 step 6). Called from 41
 * handlers.
 */
function wsCsrfGuardTestSubject(): WsCsrfGuard
{
    $currentConfig = new CurrentConfig();

    return new WsCsrfGuard(new CsrfService($currentConfig));
}

beforeEach(function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
});

afterEach(function (): void {
    Kernel::reset();
});

// Same uniqid()-based, per-process session id rationale as
// Piwigo\Tests\Unit\Csrf\CsrfServiceTest.php's own csrfTestSessionId() --
// avoids the shared /var/lib/php/sessions file-lock collision across
// concurrent worktree test runs a literal hardcoded id would risk.
// wsCsrfGuardTestSubject() builds its own internal CsrfService from a fresh
// CurrentConfig (secretKey='' default, never overridden there), so the
// "real" token for a given session id is reproduced here the same way
// Piwigo\Tests\Unit\Csrf\CsrfServiceTest.php recomputes its own expected
// hash directly, rather than needing a handle on WsCsrfGuard's own
// internal CsrfService instance.
function wsCsrfGuardTestSessionId(): string
{
    /** @var string|null */
    static $id = null;
    $id ??= str_replace('.', '-', uniqid('wscsrfguard-test-', true));

    return $id;
}

function wsCsrfGuardTestRealToken(): string
{
    return hash_hmac('sha256', wsCsrfGuardTestSessionId(), '');
}

// PHP refuses to change session_id() once a session is already active --
// under the full parallel suite (not this file run in isolation), an
// earlier test in the same worker process can leave a real session open
// (e.g. anything routing through Http\Middleware\SessionMiddleware,
// which calls session_start()), so session_id() below would otherwise
// silently no-op and every test in this section would check against a
// stale id instead of wsCsrfGuardTestSessionId()'s own value.
function wsCsrfGuardTestSetSessionId(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    session_id(wsCsrfGuardTestSessionId());
}

test('checkSecurityToken accepts a matching submitted token', function (): void {
    wsCsrfGuardTestSetSessionId();
    $guard = wsCsrfGuardTestSubject();

    expect($guard->checkSecurityToken(wsCsrfGuardTestRealToken()))
        ->toBeNull();
});

test('checkSecurityToken rejects a mismatched submitted token, required defaults to true', function (): void {
    wsCsrfGuardTestSetSessionId();
    $guard = wsCsrfGuardTestSubject();

    $result = $guard->checkSecurityToken('not-the-real-token');

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(403)
            ->and($result->message())
            ->toBe('Invalid security token');
    }
});

test('checkSecurityToken rejects a null submitted token when required (the default)', function (): void {
    wsCsrfGuardTestSetSessionId();
    $guard = wsCsrfGuardTestSubject();

    expect($guard->checkSecurityToken(null))
        ->toBeInstanceOf(WsErrorResponse::class);
});

test('checkSecurityToken allows a null submitted token through when explicitly not required', function (): void {
    wsCsrfGuardTestSetSessionId();
    $guard = wsCsrfGuardTestSubject();

    expect($guard->checkSecurityToken(null, required: false))
        ->toBeNull();
});

test('checkSecurityToken still rejects a mismatched token even when not required', function (): void {
    wsCsrfGuardTestSetSessionId();
    $guard = wsCsrfGuardTestSubject();

    expect($guard->checkSecurityToken('not-the-real-token', required: false))
        ->toBeInstanceOf(WsErrorResponse::class);
});

test('checkSecurityToken uses the given custom message instead of the default', function (): void {
    wsCsrfGuardTestSetSessionId();
    $guard = wsCsrfGuardTestSubject();

    $result = $guard->checkSecurityToken(null, message: 'a custom translated message');

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->message())
            ->toBe('a custom translated message');
    }
});
