<?php

declare(strict_types=1);

namespace Piwigo\Core;

use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Request\MobileThemeRequest;
use Piwigo\Db\SqlDialect;
use Piwigo\Session\SessionService;

/**
 * Device-type detection, stateless beyond the session state it reads and
 * writes through SessionService. getDevice() and mobileTheme() both take
 * SessionService (and CurrentConfig, for mobileTheme()) as explicit
 * params rather than resolving them internally -- their real callers
 * (Page\PageTailRenderer.php, Template\Template.php,
 * Bootstrap\RequestBootstrap.php) already have both in scope.
 */
final class DeviceHelper
{
    /**
     * return the device type: mobile, tablet or desktop
     */
    public static function getDevice(SessionService $sessionService): string
    {
        $device = $sessionService->getDeviceVar();

        if ($device === null) {
            // No UA-sniffing library (removed, no replacement — see
            // docs/REFERENCE.md's native-platform-first library policy): the
            // v17 responsive CSS (P32) removes the need for a separate mobile
            // theme via device detection. mobileTheme() still honors an
            // explicit ?mobile=1/0 override independent of this default.
            $device = 'desktop';
            $sessionService->setSessionVar('device', $device);
        }

        return $device;
    }

    /**
     * return true if mobile theme should be loaded
     */
    public static function mobileTheme(SessionService $sessionService, CurrentConfig $currentConfig): bool
    {

        // CurrentConfig::mobilTheme() is SCHEMA-typed 'string' only (never null/int/
        // float/bool/array) -- '' and '0' are the only two of empty()'s
        // falsy cases a string value can actually satisfy.
        $mobile_theme_conf = $currentConfig->mobilTheme();
        if ($mobile_theme_conf === '' || $mobile_theme_conf === '0') {
            return false;
        }

        $mobileThemeRequest = MobileThemeRequest::fromGlobals();
        if ($mobileThemeRequest->mobilePresent) {
            $is_mobile_theme = SqlDialect::getBoolean($mobileThemeRequest->mobileRaw);
            $sessionService->setSessionVar('mobile_theme', $is_mobile_theme);
        } else {
            $is_mobile_theme = $sessionService->getMobileThemeVar();
        }

        if ($is_mobile_theme === null) {
            $is_mobile_theme = (self::getDevice($sessionService) === 'mobile');
            $sessionService->setSessionVar('mobile_theme', $is_mobile_theme);
        }

        return $is_mobile_theme;
    }
}
