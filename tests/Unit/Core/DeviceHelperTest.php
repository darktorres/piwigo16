<?php

declare(strict_types=1);

use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Core\DeviceHelper;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionService;

/**
 * getDeviceVar()/getMobileThemeVar()/setSessionVar() read/write $_SESSION
 * directly (no real session_start() needed in a CLI test process).
 * getDevice()/mobileTheme() take SessionService/CurrentConfig as explicit
 * params, so this file builds a real SessionService directly -- its
 * SessionRepository is unused by either method here, only needs a live DB
 * connection, available via tests/bootstrap.php's env loading.
 */
function deviceHelperTestSessionService(): SessionService
{
    return new SessionService(EntityManagerFactory::build(DbConnection::build())->getRepository(SessionEntity::class), CurrentConfigTestFactory::get());
}

beforeEach(function (): void {
    $_SESSION = [];
    unset($_GET['mobile']);
});

afterEach(function (): void {
    $_SESSION = [];
    unset($_GET['mobile']);
});

test('getDevice defaults to desktop and persists it to the session when unset', function (): void {
    expect(DeviceHelper::getDevice(deviceHelperTestSessionService()))->toBe('desktop');
    expect($_SESSION['pwg_device'])->toBe('desktop');
});

test('getDevice returns an existing session value as-is', function (): void {
    $_SESSION['pwg_device'] = 'tablet';

    expect(DeviceHelper::getDevice(deviceHelperTestSessionService()))->toBe('tablet');
});

test('mobileTheme is false when the mobile_theme config is empty or "0", without touching the session', function (): void {
    CurrentConfigTestFactory::get()->mobileTheme = '';
    expect(DeviceHelper::mobileTheme(deviceHelperTestSessionService(), CurrentConfigTestFactory::get()))->toBeFalse();
    expect($_SESSION)->toBe([]);

    CurrentConfigTestFactory::get()->mobileTheme = '0';
    expect(DeviceHelper::mobileTheme(deviceHelperTestSessionService(), CurrentConfigTestFactory::get()))->toBeFalse();
    expect($_SESSION)->toBe([]);
});

test('mobileTheme falls back to getDevice() === "mobile" when no GET override or session value exists', function (): void {
    CurrentConfigTestFactory::get()->mobileTheme = 'mobile';

    // getDevice() itself defaults to "desktop" with an empty session.
    expect(DeviceHelper::mobileTheme(deviceHelperTestSessionService(), CurrentConfigTestFactory::get()))->toBeFalse();
    expect($_SESSION['pwg_mobile_theme'])->toBeFalse();
});

test('mobileTheme returns an existing session value directly, without calling getDevice()', function (): void {
    CurrentConfigTestFactory::get()->mobileTheme = 'mobile';
    $_SESSION['pwg_mobile_theme'] = true;

    expect(DeviceHelper::mobileTheme(deviceHelperTestSessionService(), CurrentConfigTestFactory::get()))->toBeTrue();
    // getDevice() was never called -- no 'pwg_device' session key was written.
    expect(isset($_SESSION['pwg_device']))->toBeFalse();
});

test('mobileTheme honors a ?mobile= GET override, persisting it to the session', function (): void {
    CurrentConfigTestFactory::get()->mobileTheme = 'mobile';

    $_GET['mobile'] = '1';
    expect(DeviceHelper::mobileTheme(deviceHelperTestSessionService(), CurrentConfigTestFactory::get()))->toBeTrue();
    expect($_SESSION['pwg_mobile_theme'])->toBeTrue();

    $_SESSION = [];
    $_GET['mobile'] = '0';
    expect(DeviceHelper::mobileTheme(deviceHelperTestSessionService(), CurrentConfigTestFactory::get()))->toBeFalse();
});
