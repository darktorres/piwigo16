<?php

declare(strict_types=1);

namespace Piwigo\Core;

use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Request\MobileThemeRequest;
use Piwigo\Db\SqlDialect;
use Piwigo\Session\SessionService;

/**
 * P23 batch 8d: device-type detection relocated from
 * include/functions.inc.php -- no natural existing class home, stateless
 * beyond the session it reads/writes through SessionService.
 *
 * Singleton/service-locator elimination campaign, Phase 9: purely static,
 * no instance/constructor to receive CurrentConfig via constructor
 * injection through -- getDevice()/mobileTheme() originally read via the
 * CurrentConfig::current() transitional bridge, matching FilesystemHelper's
 * own former "no wrapper needed" precedent (see Phase 12D below for why
 * that no longer holds here).
 *
 * Phase 12 sub-phase 12D: SessionService::get() closed -- the "outside
 * this campaign's own scope" classification (Phase 4) turned out to be
 * stale, never revisited: both real callers (Page\PageTailRenderer.php,
 * Template\Template.php) already have an instance/static context capable
 * of threading a real SessionService through, so both methods now take it
 * as an explicit param (NOCTOR shape) instead of resolving the shim
 * internally. CurrentConfig::current() closed the same pass too --
 * mobileTheme()'s own 2 real callers (Page\PageTailRenderer.php,
 * Bootstrap\RequestBootstrap.php) already had a real CurrentConfig in
 * scope right next to their own mobileTheme() call site.
 */
final class DeviceHelper
{
    /**
     * return the device type: mobile, tablet or desktop
     */
    public static function getDevice(SessionService $sessionService): string
    {
        $device = $sessionService->getSessionVar('device');

        if (! is_string($device)) {
            // No UA-sniffing library (removed, no replacement — see
            // docs/REFERENCE.md's ADR-0021): the v17
            // responsive CSS (P33) removes the need for a separate mobile theme
            // via device detection. mobileTheme() still honors an explicit
            // ?mobile=1/0 override independent of this default.
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
            $session_mobile_theme = $sessionService->getSessionVar('mobile_theme');
            $is_mobile_theme = is_bool($session_mobile_theme) ? $session_mobile_theme : null;
        }

        if ($is_mobile_theme === null) {
            $is_mobile_theme = (self::getDevice($sessionService) === 'mobile');
            $sessionService->setSessionVar('mobile_theme', $is_mobile_theme);
        }

        return $is_mobile_theme;
    }
}
