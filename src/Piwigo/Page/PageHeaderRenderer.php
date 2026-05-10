<?php

declare(strict_types=1);

namespace Piwigo\Page;

use Latte\Runtime\Html;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigService;
use Piwigo\Core\PageState;
use Piwigo\Core\ServiceLocator;
use Piwigo\Core\StringUtil;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlService;

final class PageHeaderRenderer
{
    public static function render(
        string $title = '',
        ?int $refresh = null,
        ?string $urlLink = null
    ): void {
        $template  = TemplateRegistry::current();
        $pageState = PageState::current();
        $page      = is_array($GLOBALS['page'] ?? null) ? $GLOBALS['page'] : [];

        EventDispatcher::notify('loc_begin_page_header');

        $show_mobile_app_banner = ServiceLocator::get(ConfigService::class)->confGetParam('show_mobile_app_banner_in_gallery', false);
        if (defined('IN_ADMIN')) {
            $show_mobile_app_banner = ServiceLocator::get(ConfigService::class)->confGetParam('show_mobile_app_banner_in_admin', true);
        }

        $pageBanner = $page['page_banner'] ?? Config::pageBanner();
        $template->assign([
            'GALLERY_TITLE'          => $page['gallery_title'] ?? Config::galleryTitle(),
            'PAGE_BANNER'            => new Html((string) EventDispatcher::dispatch(
                'render_page_banner',
                str_replace('%gallery_title%', Config::galleryTitle(), is_string($pageBanner) ? $pageBanner : '')
            )),
            'BODY_ID'                => $page['body_id'] ?? '',
            'CONTENT_ENCODING'       => ServiceLocator::get(StringUtil::class)->getPwgCharset(),
            'PAGE_TITLE'             => strip_tags($title),
            'U_HOME'                 => UrlService::get()->getGalleryHomeUrl(),
            'LEVEL_SEPARATOR'        => Config::levelSeparator(),
            'SHOW_MOBILE_APP_BANNER' => $show_mobile_app_banner,
            'BODY_CLASSES'           => $pageState->bodyClasses,
            'BODY_DATA'              => json_encode($pageState->bodyData),
        ]);

        if (!Config::metaRef()) {
            if (!isset($page['meta_robots']) || !is_array($page['meta_robots'])) {
                $page['meta_robots'] = [];
            }
            $page['meta_robots']['noindex']  = 1;
            $page['meta_robots']['nofollow'] = 1;
            $GLOBALS['page'] = $page;
        }

        $metaRobots = is_array($page['meta_robots'] ?? null) ? $page['meta_robots'] : null;
        if ($metaRobots !== null && !empty($metaRobots)) {
            $template->append('head_elements', new Html('<meta name="robots" content="' . implode(',', array_keys($metaRobots)) . '">'));
        }
        if (!is_array($metaRobots) || !isset($metaRobots['noindex'])) {
            $template->assign('meta_ref', 1);
        }

        if ($refresh !== null && $refresh >= 0 && $urlLink !== null) {
            $template->assign(['page_refresh' => ['TIME' => $refresh, 'U_REFRESH' => $urlLink]]);
        }

        EventDispatcher::notify('loc_end_page_header');

        header('Content-Type: text/html; charset=' . ServiceLocator::get(StringUtil::class)->getPwgCharset());
        $template->parse('header.latte');

        EventDispatcher::notify('loc_after_page_header');
    }
}
