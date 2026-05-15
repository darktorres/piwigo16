<?php

declare(strict_types=1);

namespace Piwigo\Page;

use Latte\Runtime\Html;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigService;
use Piwigo\Core\Kernel;
use Piwigo\Core\PageState;
use Piwigo\Core\StringUtil;
use Piwigo\Http\RequestContext;
use Piwigo\Http\RequestContextRegistry;
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

        EventDispatcher::notify('loc_begin_page_header');

        $show_mobile_app_banner = Kernel::service(ConfigService::class)->confGetParam('show_mobile_app_banner_in_gallery', false);
        if (RequestContextRegistry::current() === RequestContext::Admin) {
            $show_mobile_app_banner = Kernel::service(ConfigService::class)->confGetParam('show_mobile_app_banner_in_admin', true);
        }

        $pageBannerRaw = $pageState->pageBanner ?? Config::pageBanner();
        $template->assign([
            'GALLERY_TITLE'          => $pageState->galleryTitle ?? Config::galleryTitle(),
            'PAGE_BANNER'            => new Html((string) EventDispatcher::dispatch(
                'render_page_banner',
                str_replace('%gallery_title%', Config::galleryTitle(), $pageBannerRaw)
            )),
            'BODY_ID'                => $pageState->bodyId,
            'CONTENT_ENCODING'       => Kernel::service(StringUtil::class)->getPwgCharset(),
            'PAGE_TITLE'             => strip_tags($title),
            'U_HOME'                 => Kernel::service(UrlService::class)->getGalleryHomeUrl(),
            'LEVEL_SEPARATOR'        => Config::levelSeparator(),
            'SHOW_MOBILE_APP_BANNER' => $show_mobile_app_banner,
            'BODY_CLASSES'           => $pageState->bodyClasses,
            'BODY_DATA'              => json_encode($pageState->bodyData),
            'header_msgs'            => $pageState->headerMessages,
            'header_notes'           => $pageState->headerNotes,
        ]);

        $metaRobots = $pageState->metaRobots;
        if (!Config::metaRef()) {
            $metaRobots['noindex']  = 1;
            $metaRobots['nofollow'] = 1;
        }
        if (!empty($metaRobots)) {
            $template->append('head_elements', new Html('<meta name="robots" content="' . implode(',', array_keys($metaRobots)) . '">'));
        }
        if (!isset($metaRobots['noindex'])) {
            $template->assign('meta_ref', 1);
        }

        if ($refresh !== null && $refresh >= 0 && $urlLink !== null) {
            $template->assign(['page_refresh' => ['TIME' => $refresh, 'U_REFRESH' => $urlLink]]);
        }

        EventDispatcher::notify('loc_end_page_header');

        header('Content-Type: text/html; charset=' . Kernel::service(StringUtil::class)->getPwgCharset());
        $template->parse('header.latte');

        EventDispatcher::notify('loc_after_page_header');
    }
}
