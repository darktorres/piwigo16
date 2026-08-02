<?php

declare(strict_types=1);

namespace Piwigo\Page;

use Piwigo\Core\UrlServiceInterface;
use Piwigo\Event\Lifecycle\LocAfterPageHeader;
use Piwigo\Event\Location\LocBeginPageHeader;
use Piwigo\Event\Location\LocEndPageHeader;
use Piwigo\Event\Template\RenderPageBanner;
use Piwigo\Html\HtmlService;
use Piwigo\Url\UrlService;

/**
 * Renders the page `<head>`/opening chrome into $template. Injects
 * nothing -- same "no constructor deps" shape as Html\HtmlService/
 * Menu\MenubarRenderer: cross-domain calls (get_pwg_charset(),
 * trigger_notify()) stay as plain global-function calls to modules
 * migrated in earlier phases; the one real Url-family call
 * (getGalleryHomeUrl()) goes through a throwaway UrlService construction
 * instead (Legacy Coupling Retirement Phase 4c) -- this class has no
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
        return new UrlService(new HtmlService());
    }

    /**
     * @param string $title set by the including page script, right before
     *   the original page_header.php include.
     * @param string|null $refresh optional meta-refresh delay in seconds;
     *   only applied together with $urlLink, matching the original
     *   $refresh/$url_link top-level-scope contract -- Bootstrap\RedirectService::redirectHtml()
     *   is the one real caller that sets both today.
     */
    public function render(string $title, ?string $refresh = null, ?string $urlLink = null): void
    {
        $template = \Piwigo\Template\CurrentTemplate::get();

        $template->set_filenames([
            'header' => 'header.tpl',
        ]);

        \Piwigo\PluginConfig\EventDispatcher::get()->dispatchNotify(new LocBeginPageHeader());

        $show_mobile_app_banner = \Piwigo\Config\CurrentConfig::showMobileAppBannerInGallery();
        if (\Piwigo\Core\AdminContext::isActiveStatic()) {
            $show_mobile_app_banner = \Piwigo\Config\CurrentConfig::showMobileAppBannerInAdmin();
        }

        /** @var string $conf_gallery_title */
        $conf_gallery_title = \Piwigo\Config\CurrentConfig::galleryTitle();
        $page_banner = \Piwigo\Core\PageState::current()->pageBanner ?? \Piwigo\Config\CurrentConfig::pageBanner();

        $template->assign(
            [
                'GALLERY_TITLE' => $conf_gallery_title,

                'PAGE_BANNER' => \Piwigo\PluginConfig\EventDispatcher::get()->dispatchChange(new RenderPageBanner(
                    str_replace(
                        '%gallery_title%',
                        $conf_gallery_title,
                        $page_banner
                    )
                ))->banner,

                'BODY_ID' => \Piwigo\Core\PageState::current()->bodyId,

                'CONTENT_ENCODING' => \Piwigo\Core\CharsetHelper::getPwgCharset(),
                'PAGE_TITLE' => strip_tags($title),

                'U_HOME' => self::urlService()->getGalleryHomeUrl(),

                'LEVEL_SEPARATOR' => \Piwigo\Config\CurrentConfig::levelSeparator(),

                'SHOW_MOBILE_APP_BANNER' => $show_mobile_app_banner,

                'BODY_CLASSES' => \Piwigo\Core\PageState::current()->bodyClasses,

                'BODY_DATA' => json_encode(\Piwigo\Core\PageState::current()->bodyData),
            ]
        );

        // Header notes
        $header_notes = \Piwigo\Core\PageState::current()->headerNotes;
        if (! self::emptyValue($header_notes)) {
            $template->assign('header_notes', $header_notes);
        }

        $pageState = \Piwigo\Core\PageState::current();
        if (! \Piwigo\Config\CurrentConfig::metaRef()) {
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

        \Piwigo\PluginConfig\EventDispatcher::get()->dispatchNotify(new LocEndPageHeader());

        header('Content-Type: text/html; charset=' . \Piwigo\Core\CharsetHelper::getPwgCharset());
        $template->parse('header');

        \Piwigo\PluginConfig\EventDispatcher::get()->dispatchNotify(new LocAfterPageHeader());
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
