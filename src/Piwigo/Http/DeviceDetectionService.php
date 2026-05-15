<?php

declare(strict_types=1);

namespace Piwigo\Http;

use Detection\MobileDetect;
use Piwigo\Config\Config;
use Piwigo\Core\BoolUtil;
use Piwigo\Session\SessionService;

/**
 * User-agent based device classification (desktop / mobile / tablet) and
 * the mobile-theme switching state. Decisions are cached in the session
 * so the User-Agent is only parsed once per session.
 */
final readonly class DeviceDetectionService
{
    public function __construct(
        private SessionService $sessionService,
    ) {
    }

    public function getDevice(): string
    {
        $rawDevice = $this->sessionService->getSessionVar('device', '');
        $device    = is_string($rawDevice) ? $rawDevice : '';
        if ($device === '') {
            // MobileDetect::isMobile() returns true for tablets too, so check tablet first.
            $detect = new MobileDetect();
            if ($detect->isTablet()) {
                $device = 'tablet';
            } elseif ($detect->isMobile()) {
                $device = 'mobile';
            } else {
                $device = 'desktop';
            }
            $this->sessionService->setSessionVar('device', $device);
        }
        return $device;
    }

    public function isMobileTheme(): bool
    {
        if (empty(Config::mobilTheme())) {
            return false;
        }
        if (isset($_GET['mobile'])) {
            /** @var mixed $mobileRaw */
            $mobileRaw     = $_GET['mobile'];
            $isMobileTheme = (is_string($mobileRaw) || is_int($mobileRaw) || is_float($mobileRaw))
                ? BoolUtil::fromMixed($mobileRaw)
                : false;
            $this->sessionService->setSessionVar('mobile_theme', $isMobileTheme);
        } else {
            $isMobileTheme = $this->sessionService->getSessionVar('mobile_theme');
        }
        if (is_null($isMobileTheme)) {
            $isMobileTheme = ($this->getDevice() === 'mobile');
            $this->sessionService->setSessionVar('mobile_theme', $isMobileTheme);
        }
        return (bool) $isMobileTheme;
    }
}
