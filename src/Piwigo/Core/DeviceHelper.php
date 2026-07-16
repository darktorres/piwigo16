<?php

declare(strict_types=1);

namespace Piwigo\Core;

use Piwigo\Session\SessionService;

/**
 * P23 batch 8d: device-type detection relocated from
 * include/functions.inc.php -- no natural existing class home, stateless
 * beyond the session it reads/writes through SessionService.
 *
 * `\Piwigo\Db\MysqliDb::getBoolean()` (bare call inside mobileTheme()) stays a free function --
 * lives in include/dblayer/functions_mysqli.inc.php, relocate-only in
 * batch 8f (finding 2), not becoming a class method.
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
        /** @var array<string, mixed> $conf */
        global $conf;

        $mobile_theme_conf = $conf['mobile_theme'] ?? null;
        if ($mobile_theme_conf === null || $mobile_theme_conf === '' || $mobile_theme_conf === 0
            || $mobile_theme_conf === 0.0 || $mobile_theme_conf === '0' || $mobile_theme_conf === false
            || $mobile_theme_conf === []) {
            return false;
        }

        if (isset($_GET['mobile'])) {
            $is_mobile_theme = \Piwigo\Db\MysqliDb::getBoolean($_GET['mobile']);
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
