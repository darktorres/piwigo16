<?php

declare(strict_types=1);

namespace Piwigo\Core;

use Piwigo\Session\SessionService;

/**
 * P23 batch 8d: device-type detection relocated from
 * include/functions.inc.php -- no natural existing class home, stateless
 * beyond the session it reads/writes through SessionService.
 */
final class DeviceHelper
{
    /**
     * return the device type: mobile, tablet or desktop
     */
    public static function getDevice(): string
    {
        $device = SessionService::get()->getSessionVar('device');

        if (! is_string($device)) {
            // No UA-sniffing library (removed, no replacement — see
            // docs/adr/0021-native-platform-first-library-policy.md): the v17
            // responsive CSS (P30) removes the need for a separate mobile theme
            // via device detection. mobileTheme() still honors an explicit
            // ?mobile=1/0 override independent of this default.
            $device = 'desktop';
            SessionService::get()->setSessionVar('device', $device);
        }

        return $device;
    }

    /**
     * return true if mobile theme should be loaded
     */
    public static function mobileTheme(): bool
    {

        // CurrentConfig::mobilTheme() is SCHEMA-typed 'string' only (never null/int/
        // float/bool/array) -- '' and '0' are the only two of empty()'s
        // falsy cases a string value can actually satisfy.
        $mobile_theme_conf = \Piwigo\Config\CurrentConfig::mobilTheme();
        if ($mobile_theme_conf === '' || $mobile_theme_conf === '0') {
            return false;
        }

        $mobileThemeRequest = Request\MobileThemeRequest::fromGlobals();
        if ($mobileThemeRequest->mobilePresent) {
            $is_mobile_theme = \Piwigo\Db\SqlDialect::getBoolean($mobileThemeRequest->mobileRaw);
            SessionService::get()->setSessionVar('mobile_theme', $is_mobile_theme);
        } else {
            $session_mobile_theme = SessionService::get()->getSessionVar('mobile_theme');
            $is_mobile_theme = is_bool($session_mobile_theme) ? $session_mobile_theme : null;
        }

        if ($is_mobile_theme === null) {
            $is_mobile_theme = (self::getDevice() === 'mobile');
            SessionService::get()->setSessionVar('mobile_theme', $is_mobile_theme);
        }

        return $is_mobile_theme;
    }
}
