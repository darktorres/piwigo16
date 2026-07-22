<?php

declare(strict_types=1);

use Piwigo\Config\Config;
use Piwigo\Config\ConfigLoader;
use Piwigo\Db\DbConnection;
use Piwigo\Session\SessionRepository;
use Piwigo\Session\SessionService;

// Doctrine DBAL connections are lazy -- they don't actually connect until
// the first query runs. Every test below only exercises SessionService
// methods that never touch the repository's DB-backed methods, so a real
// SessionRepository/Connection pair can be constructed here without a live
// database (confirmed empirically: an unreachable db_host never triggers a
// connection attempt for these specific call paths).
function makeSessionService(): SessionService
{
    Config::reset();
    ConfigLoader::applyDefaults();
    Config::override('db_host', 'unit-test-should-never-connect.invalid');

    return new SessionService(new SessionRepository(DbConnection::build()));
}

beforeEach(function (): void {
    unset($_SESSION);
});

test('generateKey returns a string of the requested length', function (): void {
    $service = makeSessionService();

    expect(strlen($service->generateKey(20)))->toBe(20)
        ->and(strlen($service->generateKey(5)))->toBe(5);
});

test('generateKey throws for a size below 1', function (): void {
    $service = makeSessionService();

    $service->generateKey(0);
})->throws(InvalidArgumentException::class);

test('generateKey never contains + or /', function (): void {
    $service = makeSessionService();

    for ($i = 0; $i < 20; $i++) {
        $key = $service->generateKey(32);
        expect($key)->not->toContain('+')->not->toContain('/');
    }
});

test('sessionOpen and sessionClose always return true', function (): void {
    $service = makeSessionService();

    expect($service->sessionOpen())->toBeTrue()
        ->and($service->sessionClose())->toBeTrue();
});

test('getRemoteAddrSessionHash returns empty string when session_use_ip_address is off', function (): void {
    $service = makeSessionService();
    Config::override('session_use_ip_address', false);

    expect($service->getRemoteAddrSessionHash())->toBe('');
});

test('getRemoteAddrSessionHash hashes only the first two octets of an ipv4 REMOTE_ADDR when enabled', function (): void {
    // '%02X%02X' against a 4-element explode() only consumes the first two
    // octets -- this is the original get_remote_addr_session_hash()'s real,
    // long-standing behavior (also present unchanged in the reference
    // implementation), not something this migration should silently widen.
    $service = makeSessionService();
    Config::override('session_use_ip_address', true);
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

    expect($service->getRemoteAddrSessionHash())->toBe('7F00');
});

test('getRemoteAddrSessionHash returns empty string for an ipv6 REMOTE_ADDR', function (): void {
    $service = makeSessionService();
    Config::override('session_use_ip_address', true);
    $_SERVER['REMOTE_ADDR'] = '::1';

    expect($service->getRemoteAddrSessionHash())->toBe('');
});

test('setSessionVar/getSessionVar/unsetSessionVar round-trip through $_SESSION', function (): void {
    $service = makeSessionService();
    $_SESSION = [];

    expect($service->setSessionVar('foo', 'bar'))->toBeTrue()
        ->and($service->getSessionVar('foo'))->toBe('bar');

    expect($service->unsetSessionVar('foo'))->toBeTrue()
        ->and($service->getSessionVar('foo', 'gone'))->toBe('gone');
});

test('setSessionVar/unsetSessionVar return false when no session is active', function (): void {
    $service = makeSessionService();
    unset($_SESSION);

    expect($service->setSessionVar('foo', 'bar'))->toBeFalse()
        ->and($service->unsetSessionVar('foo'))->toBeFalse();
});

test('getSessionVar returns the default when unset', function (): void {
    $service = makeSessionService();
    $_SESSION = [];

    expect($service->getSessionVar('missing', 'fallback'))->toBe('fallback');
});

test('get/set/reset manage the shared singleton', function (): void {
    $original = SessionService::get();
    $replacement = makeSessionService();

    SessionService::set($replacement);
    expect(SessionService::get())->toBe($replacement);

    SessionService::reset();
    expect(SessionService::get())->not->toBe($replacement);

    SessionService::set($original);
});
