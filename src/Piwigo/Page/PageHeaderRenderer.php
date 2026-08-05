<?php

declare(strict_types=1);

namespace Piwigo\Page;

use LogicException;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\AdminContext;
use Piwigo\Core\CharsetHelper;
use Piwigo\Core\Kernel;
use Piwigo\Core\PageState;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Event\Lifecycle\LocAfterPageHeader;
use Piwigo\Event\Location\LocBeginPageHeader;
use Piwigo\Event\Location\LocEndPageHeader;
use Piwigo\Event\Template\RenderPageBanner;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;

/**
 * Renders the page `<head>`/opening chrome into $template. Injects
 * nothing -- same "no constructor deps" shape as Html\HtmlService/
 * Menu\MenubarRenderer: cross-domain calls (get_pwg_charset(),
 * trigger_notify()) stay as plain global-function calls to modules
 * migrated in earlier phases; the one real Url-family call
 * (getGalleryHomeUrl()) goes through the container-resolved
 * UrlServiceInterface below (singleton/service-locator elimination
 * campaign, Phase 6 -- see RootPathOverride's own docblock for why a
 * throwaway construction is no longer safe) -- this class has no
 * constructor at all today, so no dependency is added.
 * Piwigo\Bootstrap\RedirectService's own redirectHtml() early-crash
 * fallback constructs this class directly (`new PageHeaderRenderer()`),
 * same PHP-DI-autowiring-only-inspects-constructors reasoning as
 * Html\HtmlService::urlService().
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
     * Same reasoning as urlService() above -- this class has no
     * constructor at all, and ~14 real render() call sites (singleton/
     * service-locator elimination campaign, Phase 11 sub-phase 11G).
     * Falls back to `false` when Kernel::boot() hasn't run, matching
     * AdminContext::isActiveStatic()'s own former identical pre-boot
     * fallback.
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

        $template->set_filenames([
            'header' => 'header.tpl',
        ]);

        $eventDispatcher->dispatchNotify(new LocBeginPageHeader());

        $show_mobile_app_banner = $currentConfig->showMobileAppBannerInGallery();
        if (self::isAdminContextActive()) {
            $show_mobile_app_banner = $currentConfig->showMobileAppBannerInAdmin();
        }

        /** @var string $conf_gallery_title */
        $conf_gallery_title = $currentConfig->galleryTitle();
        $page_banner = $pageState->pageBanner ?? $currentConfig->pageBanner();

        $template->assign(
            [
                'GALLERY_TITLE' => $conf_gallery_title,

                'PAGE_BANNER' => $eventDispatcher->dispatchChange(new RenderPageBanner(
                    str_replace(
                        '%gallery_title%',
                        $conf_gallery_title,
                        $page_banner
                    )
                ))->banner,

                'BODY_ID' => $pageState->bodyId,

                'CONTENT_ENCODING' => CharsetHelper::getPwgCharset(),
                'PAGE_TITLE' => strip_tags($title),

                'U_HOME' => self::urlService()->getGalleryHomeUrl(),

                'LEVEL_SEPARATOR' => $currentConfig->levelSeparator(),

                'SHOW_MOBILE_APP_BANNER' => $show_mobile_app_banner,

                'BODY_CLASSES' => $pageState->bodyClasses,

                'BODY_DATA' => json_encode($pageState->bodyData),
            ]
        );

        // Header notes
        $header_notes = $pageState->headerNotes;
        if (! self::emptyValue($header_notes)) {
            $template->assign('header_notes', $header_notes);
        }

        if (! $currentConfig->metaRef()) {
            $pageState->setMetaRobotsFlag('noindex');
            $pageState->setMetaRobotsFlag('nofollow');
        }

        if (! self::emptyValue($pageState->metaRobots)) {
            $template->append(
                'head_elements',
                '<meta name="robots" content="'
                  . implode(',', array_keys($pageState->metaRobots))
                  . '">'
            );
        }
        if (! isset($pageState->metaRobots['noindex'])) {
            $template->assign('meta_ref', 1);
        }

        // refresh
        $refresh_numeric = $refresh !== null && is_numeric($refresh) ? $refresh : null;
        if ($refresh_numeric !== null and intval($refresh_numeric) >= 0
            and $urlLink !== null) {
            $template->assign(
                [
                    'page_refresh' => [
                        'TIME' => $refresh_numeric,
                        'U_REFRESH' => $urlLink,
                    ],
                ]
            );
        }

        $eventDispatcher->dispatchNotify(new LocEndPageHeader());

        header('Content-Type: text/html; charset=' . CharsetHelper::getPwgCharset());
        $template->parse('header');

        $eventDispatcher->dispatchNotify(new LocAfterPageHeader());
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
