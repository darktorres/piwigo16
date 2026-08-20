<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use LogicException;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\Extensions\CoreUpdateService;
use Piwigo\Admin\Extensions\ZipExtractor;
use Piwigo\Admin\InstallationStats;
use Piwigo\Admin\PiwigoInfosSender;
use Piwigo\Asset\ViteManifest;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Cache\ExtensionUpdateCachePool;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\Kernel;
use Piwigo\Core\LayoutState;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\RequestMetrics;
use Piwigo\Core\UniqueExecLock;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Image\ImageService;
use Piwigo\Image\ImageStdParams;
use Piwigo\Mail\MailService;
use Piwigo\Page\PageTailRenderer;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Renderer;
use Piwigo\Users\UserService;

/**
 * The page-footer orchestration: the "check for Piwigo updates"
 * notification block, then the PageTailRenderer render itself.
 *
 * Lives in Bootstrap (L4) because the update check constructs
 * Piwigo\Admin\Extensions\CoreUpdateService and the renderer needs the
 * concrete Piwigo\Admin\PiwigoInfosSender behind its
 * Piwigo\Core\TelemetrySenderInterface constructor param — both
 * L4Integration, which PageTailRenderer (L3Presentation) may not reach
 * (see PageTailRenderer's own docblock). Bootstrap shares L4 with
 * Admin/Controller, so this is the violation-free home for the whole
 * orchestration — same reasoning as UserBootstrap.
 *
 * Callers reach this via PageTail::prepareContext(); the request-start
 * instant is read here from RequestMetrics, so call sites need no
 * bootstrap variable of their own.
 */
final class PageTail
{
    /**
     * The update-check orchestration plus `PageTailRenderer::prepareContext()`.
     * Every real caller (P41, docs/PLAN.md) builds the same ambient
     * footer context this returns before rendering its own page-specific
     * `View` through `Renderer::render()` and `Template::finalizeHtml()`
     * in one shot.
     */
    public static function prepareContext(): void
    {
        self::checkForUpdates();
        self::renderer()
            ->prepareContext(self::requestMetrics()->requestStart);
    }

    /**
     * PageTailRenderer (L3) receives the telemetry sender through
     * Piwigo\Core\TelemetrySenderInterface -- this class (L4) is the one
     * place the concrete L4 implementation gets constructed.
     * UrlServiceInterface is wired the same way; see PageTailRenderer's
     * own docblock. A fresh instance every call (never cached) -- matches
     * this class's own pre-existing per-call construction shape.
     */
    private static function renderer(): PageTailRenderer
    {
        return new PageTailRenderer(self::accessLevelChecker(), new PiwigoInfosSender(RequestBootstrap::lang(), self::currentLogger(), self::imageStdParams(), self::currentConfigService()->get(), self::installationStats(), self::activityService(), self::userService(), self::imageService(), self::urlService(), RequestBootstrap::currentConfig(), self::paths(), RequestBootstrap::currentUser(), self::eventDispatcher(), RequestBootstrap::entityManager()), self::urlService(), self::eventDispatcher(), self::requestMetrics(), self::currentTemplate(), RequestBootstrap::currentConfig(), RequestBootstrap::sessionService(), RequestBootstrap::entityManager(), self::viteManifest());
    }

    /**
     * Resolves the container-shared instance -- PiwigoInfosSender lives
     * outside `Bootstrap/`, so this is called from here rather than
     * resolving `Kernel::container()` directly.
     */
    private static function currentLogger(): CurrentLogger
    {
        $currentLogger = Kernel::container()->get(CurrentLogger::class);
        if (! $currentLogger instanceof CurrentLogger) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentLogger::class);
        }

        return $currentLogger;
    }

    /**
     * Resolves the container-shared instance -- this class already has
     * direct Kernel::container() access (arch-tested to Bootstrap/ only).
     */
    private static function paths(): Paths
    {
        $paths = Kernel::container()->get(Paths::class);
        if (! $paths instanceof Paths) {
            throw new LogicException('Container returned an unexpected type for ' . Paths::class);
        }

        return $paths;
    }

    /**
     * Same reasoning as paths() above -- PageTailRenderer is constructed
     * manually below, outside `Bootstrap/`.
     */
    private static function viteManifest(): ViteManifest
    {
        $viteManifest = Kernel::container()->get(ViteManifest::class);
        if (! $viteManifest instanceof ViteManifest) {
            throw new LogicException('Container returned an unexpected type for ' . ViteManifest::class);
        }

        return $viteManifest;
    }

    /**
     * Same reasoning as currentLogger() above -- PiwigoInfosSender is
     * constructed manually below, outside `Bootstrap/`, so this is called
     * from here rather than resolving `Kernel::container()` directly.
     */
    private static function imageStdParams(): ImageStdParams
    {
        $imageStdParams = Kernel::container()->get(ImageStdParams::class);
        if (! $imageStdParams instanceof ImageStdParams) {
            throw new LogicException('Container returned an unexpected type for ' . ImageStdParams::class);
        }

        return $imageStdParams;
    }

    /**
     * Same reasoning as currentLogger()/imageStdParams() above --
     * CoreUpdateService is constructed manually below, outside
     * `Bootstrap/`'s own manual-construction call sites.
     */
    private static function pageState(): PageState
    {
        $pageState = Kernel::container()->get(PageState::class);
        if (! $pageState instanceof PageState) {
            throw new LogicException('Container returned an unexpected type for ' . PageState::class);
        }

        return $pageState;
    }

    /**
     * Same reasoning as pageState() above -- PageTailRenderer is
     * constructed manually below, outside `Bootstrap/`'s own
     * manual-construction call sites, and reads requestStart/
     * countQueries/queriesTime/debugOutput (P41, docs/PLAN.md's
     * PageState split).
     */
    private static function requestMetrics(): RequestMetrics
    {
        $requestMetrics = Kernel::container()->get(RequestMetrics::class);
        if (! $requestMetrics instanceof RequestMetrics) {
            throw new LogicException('Container returned an unexpected type for ' . RequestMetrics::class);
        }

        return $requestMetrics;
    }

    /**
     * Same reasoning as pageState()/requestMetrics() above -- RedirectService
     * is constructed manually below, outside `Bootstrap/`'s own
     * manual-construction call sites, and needs a LayoutState (P41,
     * docs/PLAN.md's PageState split) for its own header-context build.
     */
    private static function layoutState(): LayoutState
    {
        $layoutState = Kernel::container()->get(LayoutState::class);
        if (! $layoutState instanceof LayoutState) {
            throw new LogicException('Container returned an unexpected type for ' . LayoutState::class);
        }

        return $layoutState;
    }

    /**
     * Same reasoning as currentLogger()/imageStdParams()/pageState() above
     * -- CoreUpdateService is constructed manually below, outside
     * `Bootstrap/`'s own manual-construction call sites.
     */
    private static function currentTemplate(): CurrentTemplate
    {
        $currentTemplate = Kernel::container()->get(CurrentTemplate::class);
        if (! $currentTemplate instanceof CurrentTemplate) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentTemplate::class);
        }

        return $currentTemplate;
    }

    /**
     * Same reasoning as currentTemplate() above -- RedirectService is
     * constructed manually below, outside `Bootstrap/`'s own
     * manual-construction call sites, and needs a real `Renderer` (P41,
     * docs/PLAN.md) for its own `redirectHtml()` cutover.
     */
    private static function templateRenderer(): Renderer
    {
        $renderer = Kernel::container()->get(Renderer::class);
        if (! $renderer instanceof Renderer) {
            throw new LogicException('Container returned an unexpected type for ' . Renderer::class);
        }

        return $renderer;
    }

    private static function currentConfigService(): CurrentConfigService
    {
        $currentConfigService = Kernel::container()->get(CurrentConfigService::class);
        if (! $currentConfigService instanceof CurrentConfigService) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfigService::class);
        }

        return $currentConfigService;
    }

    private static function urlService(): UrlServiceInterface
    {
        $urlService = Kernel::container()->get(UrlServiceInterface::class);
        if (! $urlService instanceof UrlServiceInterface) {
            throw new LogicException('Container returned an unexpected type for ' . UrlServiceInterface::class);
        }

        return $urlService;
    }

    /**
     * Same reasoning as currentLogger()/imageStdParams()/pageState()/
     * currentTemplate() above -- CoreUpdateService/PiwigoInfosSender are
     * constructed manually below, outside `Bootstrap/`'s own manual-
     * construction call sites.
     */
    private static function activityService(): ActivityService
    {
        $activityService = Kernel::container()->get(ActivityService::class);
        if (! $activityService instanceof ActivityService) {
            throw new LogicException('Container returned an unexpected type for ' . ActivityService::class);
        }

        return $activityService;
    }

    private static function installationStats(): InstallationStats
    {
        $installationStats = Kernel::container()->get(InstallationStats::class);
        if (! $installationStats instanceof InstallationStats) {
            throw new LogicException('Container returned an unexpected type for ' . InstallationStats::class);
        }

        return $installationStats;
    }

    private static function userService(): UserService
    {
        $userService = Kernel::container()->get(UserService::class);
        if (! $userService instanceof UserService) {
            throw new LogicException('Container returned an unexpected type for ' . UserService::class);
        }

        return $userService;
    }

    private static function imageService(): ImageService
    {
        $imageService = Kernel::container()->get(ImageService::class);
        if (! $imageService instanceof ImageService) {
            throw new LogicException('Container returned an unexpected type for ' . ImageService::class);
        }

        return $imageService;
    }

    private static function mailService(): MailService
    {
        $mailService = Kernel::container()->get(MailService::class);
        if (! $mailService instanceof MailService) {
            throw new LogicException('Container returned an unexpected type for ' . MailService::class);
        }

        return $mailService;
    }

    private static function extensionUpdateCachePool(): ExtensionUpdateCachePool
    {
        $extensionUpdateCachePool = Kernel::container()->get(ExtensionUpdateCachePool::class);
        if (! $extensionUpdateCachePool instanceof ExtensionUpdateCachePool) {
            throw new LogicException('Container returned an unexpected type for ' . ExtensionUpdateCachePool::class);
        }

        return $extensionUpdateCachePool;
    }

    private static function eventDispatcher(): EventDispatcher
    {
        $eventDispatcher = Kernel::container()->get(EventDispatcher::class);
        if (! $eventDispatcher instanceof EventDispatcher) {
            throw new LogicException('Container returned an unexpected type for ' . EventDispatcher::class);
        }

        return $eventDispatcher;
    }

    /**
     * Cheap, no-Doctrine-dependency counterpart to the other resolvers
     * above -- PageTailRenderer only ever needs isAGuest(), never
     * checkStatus()/accessDenied(), so this builds AccessLevelChecker
     * directly rather than resolving the full AccessControl through the
     * container.
     */
    private static function accessLevelChecker(): AccessLevelChecker
    {
        return new AccessLevelChecker(RequestBootstrap::currentUser(), RequestBootstrap::currentConfig());
    }

    private static function checkForUpdates(): void
    {
        $update_notify_check_period = RequestBootstrap::currentConfig()->updateNotifyCheckPeriod;
        if ($update_notify_check_period > 0) {
            $check_for_updates = false;

            $update_notify_last_check = RequestBootstrap::currentConfig()->updateNotifyLastCheck;
            $update_notify_last_check = is_string($update_notify_last_check) ? $update_notify_last_check : null;

            if ($update_notify_last_check !== null) {
                if (strtotime($update_notify_last_check) < strtotime($update_notify_check_period . ' seconds ago')) {
                    $check_for_updates = true;
                }
            } else {
                $check_for_updates = true;
            }

            if ($check_for_updates) {
                $exec_id = UniqueExecLock::begins(self::currentLogger()->get(), 'check_for_updates');
                if ($exec_id !== false) {
                    new CoreUpdateService(RequestBootstrap::lang(), new ZipExtractor(), new RedirectService(RequestBootstrap::lang(), self::userService(), self::eventDispatcher(), self::layoutState(), self::templateRenderer()), self::urlService(), self::currentConfigService()->get(), self::paths(), self::pageState(), self::currentTemplate(), self::activityService(), self::userService(), self::mailService(), RequestBootstrap::currentConfig(), self::extensionUpdateCachePool())
                        ->notifyPiwigoNewVersions();

                    UniqueExecLock::ends(self::currentLogger()->get(), 'check_for_updates');
                }
            }
        }
    }
}
