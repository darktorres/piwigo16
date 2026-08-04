<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use Piwigo\Admin\Extensions\CoreUpdateService;
use Piwigo\Admin\Extensions\ZipExtractor;
use Piwigo\Admin\PiwigoInfosSender;
use Piwigo\Core\UniqueExecLock;
use Piwigo\Page\PageTailRenderer;

/**
 * The page-footer orchestration of the deleted include/page_tail.php (P23
 * sub-batch 8f-5), ported verbatim: the "check for Piwigo updates"
 * notification block, then the PageTailRenderer render itself.
 *
 * Lives in Bootstrap (L4) for the same reason the deleted seam existed at
 * all: the update check constructs Piwigo\Admin\Extensions\CoreUpdateService
 * and the renderer needs the concrete Piwigo\Admin\PiwigoInfosSender behind its
 * Piwigo\Core\TelemetrySenderInterface constructor param — both
 * L4Integration, which PageTailRenderer (L3Presentation) may not reach
 * (confirmed via a real deptrac violation when tried; see
 * PageTailRenderer's own docblock). Bootstrap shares L4 with
 * Admin/Controller, so this is the violation-free single home — same
 * "whole orchestration lives where the highest-layer dependency does"
 * reasoning as UserBootstrap.
 *
 * Every former `include PHPWG_ROOT_PATH . 'include/page_tail.php';` site
 * (the P22 controllers, admin.php, redirect_html()) calls
 * PageTail::render() instead; the request-start instant the seam captures
 * into `global $t2` is read here from PageState (Legacy Coupling
 * Retirement Track A gap-fill batch G5), so call sites need no bootstrap
 * variable of their own.
 */
final class PageTail
{
    public static function render(): void
    {
        self::checkForUpdates();

        // P23 batch 8f-4: PageTailRenderer (L3) receives the telemetry
        // sender through Piwigo\Core\TelemetrySenderInterface -- this class
        // (L4) is the one place the concrete L4 implementation gets
        // constructed. Legacy Coupling Retirement Phase 4c: UrlServiceInterface
        // is wired the same way, see PageTailRenderer's own docblock.
        new PageTailRenderer(new PiwigoInfosSender(self::currentLogger(), self::imageStdParams(), self::currentConfigService()->get(), self::installationStats(), self::activityService(), self::userService(), self::imageService(), self::urlService()), self::urlService(), \Piwigo\PluginConfig\EventDispatcher::get(), self::pageState(), self::currentTemplate())
            ->render(self::pageState()->requestStart);
    }

    /**
     * Legacy Coupling Retirement Workstream D: the non-echoing sibling of
     * render() -- same update-check orchestration, but returns the fully
     * rendered page instead of sending it to the browser. For controllers
     * returning a real PSR-7 Response instead of echoing directly.
     */
    public static function renderToString(): string
    {
        self::checkForUpdates();

        return new PageTailRenderer(new PiwigoInfosSender(self::currentLogger(), self::imageStdParams(), self::currentConfigService()->get(), self::installationStats(), self::activityService(), self::userService(), self::imageService(), self::urlService()), self::urlService(), \Piwigo\PluginConfig\EventDispatcher::get(), self::pageState(), self::currentTemplate())
            ->renderToString(self::pageState()->requestStart);
    }

    /**
     * Resolves the container-shared instance -- PiwigoInfosSender lives
     * outside `Bootstrap/`, so this is called from here rather than
     * resolving `Kernel::container()` directly (singleton/service-locator
     * elimination campaign, Phase 2).
     */
    private static function currentLogger(): \Piwigo\Core\CurrentLogger
    {
        $currentLogger = \Piwigo\Core\Kernel::container()->get(\Piwigo\Core\CurrentLogger::class);
        if (! $currentLogger instanceof \Piwigo\Core\CurrentLogger) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Core\CurrentLogger::class);
        }

        return $currentLogger;
    }

    /**
     * Same reasoning as currentLogger() above -- PiwigoInfosSender is
     * constructed manually below, outside `Bootstrap/`, so this is called
     * from here rather than resolving `Kernel::container()` directly
     * (singleton/service-locator elimination campaign, Phase 4).
     */
    private static function imageStdParams(): \Piwigo\Image\ImageStdParams
    {
        $imageStdParams = \Piwigo\Core\Kernel::container()->get(\Piwigo\Image\ImageStdParams::class);
        if (! $imageStdParams instanceof \Piwigo\Image\ImageStdParams) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Image\ImageStdParams::class);
        }

        return $imageStdParams;
    }

    /**
     * Same reasoning as currentLogger()/imageStdParams() above -- PageState
     * is only read here for its requestStart property, outside `Bootstrap/`'s
     * own manual-construction call sites, so this is called from here
     * rather than resolving `Kernel::container()` directly (singleton/
     * service-locator elimination campaign, Phase 4).
     */
    private static function pageState(): \Piwigo\Core\PageState
    {
        $pageState = \Piwigo\Core\Kernel::container()->get(\Piwigo\Core\PageState::class);
        if (! $pageState instanceof \Piwigo\Core\PageState) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Core\PageState::class);
        }

        return $pageState;
    }

    /**
     * Same reasoning as currentLogger()/imageStdParams()/pageState() above
     * -- CoreUpdateService is constructed manually below, outside
     * `Bootstrap/`'s own manual-construction call sites (singleton/
     * service-locator elimination campaign, Phase 5).
     */
    private static function currentTemplate(): \Piwigo\Template\CurrentTemplate
    {
        $currentTemplate = \Piwigo\Core\Kernel::container()->get(\Piwigo\Template\CurrentTemplate::class);
        if (! $currentTemplate instanceof \Piwigo\Template\CurrentTemplate) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Template\CurrentTemplate::class);
        }

        return $currentTemplate;
    }

    private static function currentConfigService(): \Piwigo\Config\CurrentConfigService
    {
        $currentConfigService = \Piwigo\Core\Kernel::container()->get(\Piwigo\Config\CurrentConfigService::class);
        if (! $currentConfigService instanceof \Piwigo\Config\CurrentConfigService) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Config\CurrentConfigService::class);
        }

        return $currentConfigService;
    }

    private static function urlService(): \Piwigo\Core\UrlServiceInterface
    {
        $urlService = \Piwigo\Core\Kernel::container()->get(\Piwigo\Core\UrlServiceInterface::class);
        if (! $urlService instanceof \Piwigo\Core\UrlServiceInterface) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Core\UrlServiceInterface::class);
        }

        return $urlService;
    }

    /**
     * Same reasoning as currentLogger()/imageStdParams()/pageState()/
     * currentTemplate() above -- CoreUpdateService/PiwigoInfosSender are
     * constructed manually below, outside `Bootstrap/`'s own manual-
     * construction call sites (singleton/service-locator elimination
     * campaign, Phase 6).
     */
    private static function activityService(): \Piwigo\Activity\ActivityService
    {
        $activityService = \Piwigo\Core\Kernel::container()->get(\Piwigo\Activity\ActivityService::class);
        if (! $activityService instanceof \Piwigo\Activity\ActivityService) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Activity\ActivityService::class);
        }

        return $activityService;
    }

    private static function installationStats(): \Piwigo\Admin\InstallationStats
    {
        $installationStats = \Piwigo\Core\Kernel::container()->get(\Piwigo\Admin\InstallationStats::class);
        if (! $installationStats instanceof \Piwigo\Admin\InstallationStats) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Admin\InstallationStats::class);
        }

        return $installationStats;
    }

    private static function userService(): \Piwigo\Users\UserService
    {
        $userService = \Piwigo\Core\Kernel::container()->get(\Piwigo\Users\UserService::class);
        if (! $userService instanceof \Piwigo\Users\UserService) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Users\UserService::class);
        }

        return $userService;
    }

    private static function imageService(): \Piwigo\Image\ImageService
    {
        $imageService = \Piwigo\Core\Kernel::container()->get(\Piwigo\Image\ImageService::class);
        if (! $imageService instanceof \Piwigo\Image\ImageService) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Image\ImageService::class);
        }

        return $imageService;
    }

    private static function mailService(): \Piwigo\Mail\MailService
    {
        $mailService = \Piwigo\Core\Kernel::container()->get(\Piwigo\Mail\MailService::class);
        if (! $mailService instanceof \Piwigo\Mail\MailService) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Mail\MailService::class);
        }

        return $mailService;
    }

    private static function checkForUpdates(): void
    {
        // ----------------------------------------------- update notification
        $update_notify_check_period = \Piwigo\Config\CurrentConfig::updateNotifyCheckPeriod();
        if ($update_notify_check_period > 0) {
            $check_for_updates = false;

            $update_notify_last_check = \Piwigo\Config\CurrentConfig::updateNotifyLastCheck() ?? null;
            $update_notify_last_check = is_string($update_notify_last_check) ? $update_notify_last_check : null;

            if ($update_notify_last_check !== null) {
                if (strtotime($update_notify_last_check) < strtotime($update_notify_check_period . ' seconds ago')) {
                    $check_for_updates = true;
                }
            } else {
                $check_for_updates = true;
            }

            if ($check_for_updates) {
                $exec_id = UniqueExecLock::begins('check_for_updates');
                if ($exec_id !== false) {
                    new CoreUpdateService(new ZipExtractor(), new RedirectService(self::userService()), self::urlService(), self::currentConfigService()->get(), \Piwigo\Core\CurrentPaths::get(), self::pageState(), self::currentTemplate(), self::activityService(), self::userService(), self::mailService())
                        ->notifyPiwigoNewVersions();

                    UniqueExecLock::ends('check_for_updates');
                }
            }
        }
    }
}
