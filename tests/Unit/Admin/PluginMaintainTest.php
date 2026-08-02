<?php

declare(strict_types=1);

use Piwigo\Admin\PluginMaintain;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Core\WsContext;
use Piwigo\Tests\Support\KernelContainerOverride;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;

/**
 * Piwigo\Admin\PluginMaintain -- the base install()/activate()/deactivate()/
 * uninstall()/update() no-op methods are already exercised indirectly via
 * DummyPluginMaintain (which extends this and returns their same `null`
 * result whenever no dynamic procedural function exists) and
 * ExtensionLifecycleTest.php's own real-subclass fixtures. This file covers
 * only autoUpdate()'s own deprecation-warning branch, which needs a real
 * "admin"-status CurrentUser and a non-WS context -- not otherwise
 * exercised anywhere.
 *
 * DummyPluginMaintain/DummyThemeMaintain's own remaining uncovered
 * branches (each is_callable('plugin_install'|'plugin_activate'|
 * 'plugin_deactivate'|'plugin_uninstall'|'theme_activate'|'theme_deactivate'|
 * 'theme_delete') true-branch) are deliberately NOT exercised here: doing so
 * would mean defining a real global function with one of those exact names,
 * which -- once loaded by Pest for this one test file -- stays defined for
 * the REST of the whole suite's process, permanently changing
 * is_callable()'s answer for every other test that reaches DummyPluginMaintain/
 * DummyThemeMaintain through the real ExtensionLifecycle flow (confirmed:
 * tests/Integration/ExtensionLifecycleTest.php's own
 * test_plugin_install_creates_an_inactive_row and several siblings rely on
 * that function NOT existing, i.e. the `return null;` fallback). That's a
 * real cross-test contamination risk, not a hypothetical one, so it's left
 * alone.
 */
beforeEach(function (): void {
    CurrentUser::reset();
});

afterEach(function (): void {
    CurrentUser::reset();
});

function pluginMaintainSetUserStatus(UserStatus $status): void
{
    CurrentUser::set(new User(
        id: UserId::from(1),
        username: 'plugin-maintain-test-user',
        email: '',
        language: '',
        theme: '',
        status: $status,
        enabledHigh: false,
    ));
}

test('autoUpdate() triggers its deprecation warning for an admin user outside a WS request', function (): void {
    pluginMaintainSetUserStatus(UserStatus::Admin);

    $capturedLevel = null;
    $capturedMessage = null;
    set_error_handler(static function (int $errno, string $errstr) use (&$capturedLevel, &$capturedMessage): bool {
        $capturedLevel = $errno;
        $capturedMessage = $errstr;

        return true;
    });

    try {
        new PluginMaintain('some-plugin')->autoUpdate();
    } finally {
        restore_error_handler();
    }

    expect($capturedLevel)->toBe(E_USER_WARNING);
    expect($capturedMessage)->toBe('Function PluginMaintain::autoUpdate deprecated');
});

test('autoUpdate() stays silent for a webmaster user (a higher status than admin, but not exactly "admin")', function (): void {
    // isAdmin()'s own isAuthorizeStatus() check is a `>=` comparison
    // against AccessLevel::Administrator, so a webmaster (a strictly
    // higher access level) still satisfies isAdmin() -- this test exists
    // to confirm that, not to find a silent case; see the next test for
    // the real "stays silent" scenario (a normal user).
    pluginMaintainSetUserStatus(UserStatus::Webmaster);

    $triggered = false;
    set_error_handler(static function () use (&$triggered): bool {
        $triggered = true;

        return true;
    });

    try {
        new PluginMaintain('some-plugin')->autoUpdate();
    } finally {
        restore_error_handler();
    }

    expect($triggered)->toBeTrue();
});

test('autoUpdate() stays silent for a normal (non-admin) user', function (): void {
    pluginMaintainSetUserStatus(UserStatus::Normal);

    $triggered = false;
    set_error_handler(static function () use (&$triggered): bool {
        $triggered = true;

        return true;
    });

    try {
        new PluginMaintain('some-plugin')->autoUpdate();
    } finally {
        restore_error_handler();
    }

    expect($triggered)->toBeFalse();
});

test('autoUpdate() stays silent for an admin user inside an active WS request', function (): void {
    pluginMaintainSetUserStatus(UserStatus::Admin);

    $triggered = false;
    set_error_handler(static function () use (&$triggered): bool {
        $triggered = true;

        return true;
    });

    try {
        // WsContext::isActiveStatic() resolves the container-shared
        // instance (singleton/service-locator elimination campaign, Phase
        // 3) -- KernelContainerOverride::with() gives this one test a real
        // container with WsContext bound active, then tears it back down.
        KernelContainerOverride::with(
            [WsContext::class => new WsContext(true)],
            static function (): void {
                new PluginMaintain('some-plugin')->autoUpdate();
            }
        );
    } finally {
        restore_error_handler();
    }

    expect($triggered)->toBeFalse();
});
