<?php

declare(strict_types=1);

use Piwigo\Config\CurrentConfig;
use Piwigo\Core\DeviceHelper;

/**
 * Piwigo\Core\DeviceHelper -- had zero dedicated coverage (see
 * /home/torres/.claude/plans/piped-enchanting-spark.md, Wave 1).
 * getSessionVar()/setSessionVar() read/write $_SESSION directly (no real
 * session_start() needed in a CLI test process), and SessionService::get()
 * only needs a live DB connection to construct its (unused-by-these-2-
 * methods) SessionRepository -- available here the same way
 * ExtendedDomainAccessorTest's DB-backed accessors are, via
 * tests/bootstrap.php's env loading.
 */
beforeEach(function (): void {
    $_SESSION = [];
    unset($_GET['mobile']);
});

afterEach(function (): void {
    $_SESSION = [];
    unset($_GET['mobile']);
});

test('getDevice defaults to desktop and persists it to the session when unset', function (): void {
    expect(DeviceHelper::getDevice())->toBe('desktop');
    expect($_SESSION['pwg_device'])->toBe('desktop');
});

test('getDevice returns an existing session value as-is', function (): void {
    $_SESSION['pwg_device'] = 'tablet';

    expect(DeviceHelper::getDevice())->toBe('tablet');
});

test('mobileTheme is false when the mobile_theme config is empty or "0", without touching the session', function (): void {
    CurrentConfig::setMobilTheme('');
    expect(DeviceHelper::mobileTheme())->toBeFalse();
    expect($_SESSION)->toBe([]);

    CurrentConfig::setMobilTheme('0');
    expect(DeviceHelper::mobileTheme())->toBeFalse();
    expect($_SESSION)->toBe([]);
});

test('mobileTheme falls back to getDevice() === "mobile" when no GET override or session value exists', function (): void {
    CurrentConfig::setMobilTheme('mobile');

    // getDevice() itself defaults to "desktop" with an empty session.
    expect(DeviceHelper::mobileTheme())->toBeFalse();
    expect($_SESSION['pwg_mobile_theme'])->toBeFalse();
});

test('mobileTheme returns an existing session value directly, without calling getDevice()', function (): void {
    CurrentConfig::setMobilTheme('mobile');
    $_SESSION['pwg_mobile_theme'] = true;

    expect(DeviceHelper::mobileTheme())->toBeTrue();
    // getDevice() was never called -- no 'pwg_device' session key was written.
    expect(isset($_SESSION['pwg_device']))->toBeFalse();
});

test('mobileTheme honors a ?mobile= GET override, persisting it to the session', function (): void {
    CurrentConfig::setMobilTheme('mobile');

    $_GET['mobile'] = '1';
    expect(DeviceHelper::mobileTheme())->toBeTrue();
    expect($_SESSION['pwg_mobile_theme'])->toBeTrue();

    $_SESSION = [];
    $_GET['mobile'] = '0';
    expect(DeviceHelper::mobileTheme())->toBeFalse();
});
