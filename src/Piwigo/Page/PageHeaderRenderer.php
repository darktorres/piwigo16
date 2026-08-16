<?php

declare(strict_types=1);

namespace Piwigo\Page;

use LogicException;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\AdminContext;
use Piwigo\Core\Kernel;
use Piwigo\Core\PageState;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Page\Event\PageHeaderContextFinalized;
use Piwigo\Page\Event\PageHeaderRendered;
use Piwigo\Page\Event\PageHeaderRendering;
use Piwigo\Page\Event\RenderPageBanner;
use Piwigo\Page\Projection\PageHeaderPageContext;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;

/**
 * Renders the page `<head>`/opening chrome into $template. Has no
 * constructor: `Piwigo\Bootstrap\RedirectService`'s own redirectHtml()
 * early-crash fallback constructs this class directly (`new
 * PageHeaderRenderer()`), so PHP-DI autowiring never sees a constructor
 * to inject through. The one real Url-family call (getGalleryHomeUrl())
 * instead goes through the container-resolved UrlServiceInterface below.
 */
final class PageHeaderRenderer
{
    private static function urlService(): UrlServiceInterface
    {
        $urlService = Kernel::container()->get(UrlServiceInterface::class);
        if (! $urlService instanceof UrlServiceInterface) {
            throw new LogicException('Container returned an unexpected type for ' . UrlServiceInterface::class);
        }

        return $urlService;
    }

    /**
     * Same reasoning as urlService() above: no constructor to inject
     * AdminContext through. Falls back to `false` when Kernel::boot()
     * hasn't run yet.
     */
    private static function isAdminContextActive(): bool
    {
        if (! Kernel::isBooted()) {
            return false;
        }

        $adminContext = Kernel::container()->get(AdminContext::class);
        if (! $adminContext instanceof AdminContext) {
            throw new LogicException('Container returned an unexpected type for ' . AdminContext::class);
        }

        return $adminContext->isActive();
    }

    /**
     * @param string $title set by the including page script, right before
     *   the original page_header.php include.
     * @param string|null $refresh optional meta-refresh delay in seconds;
     *   only applied together with $urlLink, matching the original
     *   $refresh/$url_link top-level-scope contract -- Bootstrap\RedirectService::redirectHtml()
     *   is the one real caller that sets both today.
     */
    public function render(string $title, EventDispatcher $eventDispatcher, PageState $pageState, CurrentTemplate $currentTemplate, CurrentConfig $currentConfig, ?string $refresh = null, ?string $urlLink = null): void
    {
        $template = $currentTemplate->get();

        $eventDispatcher->dispatch(new PageHeaderRendering());

        $show_mobile_app_banner = $currentConfig->showMobileAppBannerInGallery;
        if (self::isAdminContextActive()) {
            $show_mobile_app_banner = $currentConfig->showMobileAppBannerInAdmin;
        }

        /** @var string $conf_gallery_title */
        $conf_gallery_title = $currentConfig->galleryTitle;
        $page_banner = $pageState->pageBanner ?? $currentConfig->pageBanner;

        $bodyDataJson = json_encode($pageState->bodyData);
        $bodyDataJson = is_string($bodyDataJson) ? $bodyDataJson : '{}';

        // Header notes
        $header_notes = $pageState->headerNotes;
        $headerNotesValue = self::emptyValue($header_notes) ? null : $header_notes;

        if (! $currentConfig->metaRef) {
            $pageState->setMetaRobotsFlag('noindex');
            $pageState->setMetaRobotsFlag('nofollow');
        }

        $head_elements = [];
        if (! self::emptyValue($pageState->metaRobots)) {
            $head_elements[] = '<meta name="robots" content="'
                  . implode(',', array_keys($pageState->metaRobots))
                  . '">';
        }
        $metaRef = isset($pageState->metaRobots['noindex']) ? null : 1;

        // refresh
        $refresh_numeric = $refresh !== null && is_numeric($refresh) ? $refresh : null;
        $pageRefresh = null;
        if ($refresh_numeric !== null and intval($refresh_numeric) >= 0
            and $urlLink !== null) {
            $pageRefresh = [
                'TIME' => $refresh_numeric,
                'U_REFRESH' => $urlLink,
            ];
        }

        $template->assignContext(new PageHeaderPageContext(
            galleryTitle: $conf_gallery_title,
            pageBanner: $eventDispatcher->dispatch(new RenderPageBanner(
                str_replace(
                    '%gallery_title%',
                    $conf_gallery_title,
                    $page_banner
                )
            ))->banner,
            bodyId: $pageState->bodyId,
            contentEncoding: 'utf-8',
            pageTitle: strip_tags($title),
            homeUrl: self::urlService()->getGalleryHomeUrl(),
            levelSeparator: $currentConfig->levelSeparator,
            showMobileAppBanner: $show_mobile_app_banner,
            bodyClasses: $pageState->bodyClasses,
            bodyData: $bodyDataJson,
            headerNotes: $headerNotesValue,
            metaRef: $metaRef,
            pageRefresh: $pageRefresh,
            headElements: $head_elements,
        ));

        $eventDispatcher->dispatch(new PageHeaderContextFinalized());

        header('Content-Type: text/html; charset=utf-8');
        $template->parse('header.latte');

        $eventDispatcher->dispatch(new PageHeaderRendered());
    }

    /**
     * Matches empty()'s exact truthiness semantics -- required since
     * empty() itself is disallowed by this project's strict PHPStan rules.
     */
    private static function emptyValue(mixed $value): bool
    {
        return $value === null || $value === '' || $value === 0 || $value === 0.0 || $value === '0' || $value === false || $value === [];
    }
}
