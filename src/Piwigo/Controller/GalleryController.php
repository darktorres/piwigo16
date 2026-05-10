<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Latte\Runtime\Html;
use Piwigo\Category\CategoryCatsRenderer;
use Piwigo\Category\CategoryDefaultRenderer;
use Piwigo\Category\CategoryService;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigService;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\ServiceLocator;
use Piwigo\Core\StringUtil;
use Piwigo\Core\Util;
use Piwigo\Html\HtmlService;
use Piwigo\Http\ResponseFactory;
use Piwigo\Image\DerivativeSize;
use Piwigo\Image\ImageStdParams;
use Piwigo\Menu\MenubarRenderer;
use Piwigo\Page\PageHeaderRenderer;
use Piwigo\Page\PageTailRenderer;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Search\SearchFilterRenderer;
use Piwigo\Section\SectionInitializer;
use Piwigo\Session\SessionService;
use Piwigo\Tag\SelectedTagsRenderer;
use Piwigo\Tag\TagService;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlGenerator;
use Piwigo\Url\UrlService;
use Piwigo\Users\PermissionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Handles the gallery index page: categories, thumbnails, tags, search,
 * favorites, recent, best-rated, most-visited, and calendar views.
 *
 * Corresponds to the former index.php entry-point (lines 26-405).
 * Section routing is delegated to SectionInitializer which populates $GLOBALS['page'].
 */
final class GalleryController implements ControllerInterface
{
    #[\Override]
    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        ServiceLocator::get(SectionInitializer::class)->initialize($request, 'index');

        PermissionService::get()->checkStatus(AccessLevel::Guest);

        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];

        // Extract commonly-used typed locals to avoid repeated mixed-type access
        $items       = is_array($page['items'] ?? null) ? $page['items'] : [];
        $start       = is_scalar($page['start'] ?? null) ? (int) $page['start'] : 0;
        $nbImagePage = is_scalar($page['nb_image_page'] ?? null) ? (int) $page['nb_image_page'] : 0;
        $section     = is_string($page['section'] ?? null) ? $page['section'] : 'categories';
        $category    = is_array($page['category'] ?? null) ? $page['category'] : null;
        $catId       = $category !== null && is_scalar($category['id'] ?? null) ? (int) $category['id'] : 0;
        $countCats   = $category !== null && is_scalar($category['count_categories'] ?? null)
            ? (int) $category['count_categories'] : null;

        if ($category !== null) {
            ServiceLocator::get(CategoryService::class)->checkRestrictions($catId);
        }
        if ($start > 0 && $start >= count($items)) {
            ServiceLocator::get(HtmlService::class)->pageNotFound('', UrlService::get()->duplicateIndexUrl(['start' => 0]));
        }

        EventDispatcher::notify('loc_begin_index');

        // Image display-order change
        $imageOrder = StringUtil::get()->inputInt('image_order', null, $_GET);
        if ($imageOrder !== null) {
            if ($imageOrder > 0) {
                ServiceLocator::get(SessionService::class)->setSessionVar('image_order', $imageOrder);
            } else {
                ServiceLocator::get(SessionService::class)->unsetSessionVar('image_order');
            }
            Util::get()->redirect(UrlService::get()->duplicateIndexUrl([], ['start']));
        }

        $display = StringUtil::get()->inputString('display', null, $_GET);
        if ($display !== null) {
            $metaRobots             = is_array($page['meta_robots'] ?? null) ? $page['meta_robots'] : [];
            $metaRobots['noindex']  = 1;
            $page['meta_robots']    = $metaRobots;
            if (array_key_exists($display, ImageStdParams::getDefinedTypeMap())) {
                ServiceLocator::get(SessionService::class)->setSessionVar('index_deriv', $display);
            }
        }

        $tpl = TemplateRegistry::current();

        // Navigation bar
        $page['navigation_bar'] = [];
        if (count($items) > $nbImagePage) {
            $page['navigation_bar'] = ServiceLocator::get(Util::class)->createNavigationBar(
                UrlService::get()->duplicateIndexUrl([], ['start']),
                count($items),
                $start,
                $nbImagePage,
                true,
                'start'
            );
        }
        $tpl->assign('thumb_navbar', $page['navigation_bar']);

        // Caddie filling
        if (StringUtil::get()->inputString('caddie', null, $_GET) !== null) {
            ServiceLocator::get(Util::class)->fillCaddie(array_map(static fn (mixed $i): int => is_scalar($i) ? (int) $i : 0, $items));
            Util::get()->redirect(UrlService::get()->duplicateIndexUrl());
        }

        // Canonical URL
        if (isset($page['is_homepage']) && $page['is_homepage']) {
            $canonicalUrl = UrlService::get()->getGalleryHomeUrl();
        } else {
            $safeStart = $nbImagePage > 0 ? (int) ((float) $nbImagePage * round((float) $start / (float) $nbImagePage)) : 0;
            if ($safeStart > 0 && $safeStart >= count($items)) {
                $safeStart -= $nbImagePage;
            }
            $canonicalUrl = UrlService::get()->duplicateIndexUrl(['start' => $safeStart]);
        }
        $tpl->assign('U_CANONICAL', $canonicalUrl);
        $useStandardPagesRaw = ServiceLocator::get(ConfigService::class)->confGetParam('use_standard_pages', false);
        $tpl->assign('use_standard_pages', is_array($useStandardPagesRaw) || is_scalar($useStandardPagesRaw) || $useStandardPagesRaw === null ? $useStandardPagesRaw : null);

        // Page title
        $tpl->assign('TITLE', new Html(is_string($page['section_title'] ?? null) ? $page['section_title'] : ''));
        $tpl->assign('NB_ITEMS', count($items));

        // Menubar
        ServiceLocator::get(MenubarRenderer::class)->render();

        $tpl->setFilename('index', 'index.latte');

        if (empty($page['is_external'])) {
            $page['body_id'] = 'theCategoryPage';

            if (isset($page['flat']) || isset($page['chronology_field'])) {
                $tpl->assign('U_MODE_NORMAL', UrlService::get()->duplicateIndexUrl([], ['chronology_field', 'start', 'flat']));
            }

            if (Config::indexFlatIcon() && !isset($page['flat']) && $section === 'categories') {
                $tpl->assign('U_MODE_FLAT', UrlService::get()->duplicateIndexUrl(['flat' => ''], ['start', 'chronology_field']));
            }

            if (!isset($page['chronology_field'])) {
                $chronoParams = [
                    'chronology_field' => 'created',
                    'chronology_style' => 'monthly',
                    'chronology_view'  => 'list',
                ];
                if (Config::indexCreatedDateIcon()) {
                    $tpl->assign('U_MODE_CREATED', UrlService::get()->duplicateIndexUrl($chronoParams, ['start', 'flat']));
                }
                if (Config::indexPostedDateIcon()) {
                    $chronoParams['chronology_field'] = 'posted';
                    $tpl->assign('U_MODE_POSTED', UrlService::get()->duplicateIndexUrl($chronoParams, ['start', 'flat']));
                }
            } else {
                $chronoField = is_string($page['chronology_field']) && $page['chronology_field'] === 'created'
                    ? 'posted' : 'created';
                if (Config::raw('index_' . $chronoField . '_date_icon')) {
                    $url = UrlService::get()->duplicateIndexUrl(
                        ['chronology_field' => $chronoField],
                        ['chronology_date', 'start', 'flat']
                    );
                    $tpl->assign('U_MODE_' . strtoupper($chronoField), $url);
                }
            }

            ServiceLocator::get(SearchFilterRenderer::class)->render();

            if ($section === 'categories' && $category !== null && !isset($page['combined_categories'])) {
                $tpl->assign([
                    'SEARCH_IN_SET_BUTTON' => Config::indexSearchInSetButton(),
                    'SEARCH_IN_SET_ACTION' => Config::indexSearchInSetAction(),
                    'SEARCH_IN_SET_URL'    => UrlService::get()->addUrlParams(ServiceLocator::get(UrlGenerator::class)->searchPage(), ['cat_id' => $catId]),
                ]);
            }

            // Tag-related context (tags page only)
            $bodyDataArr = is_array($page['body_data'] ?? null) ? $page['body_data'] : [];
            if (is_array($bodyDataArr['tag_ids'] ?? null)) {
                $pageTagIds = is_array($page['tag_ids'] ?? null)
                    ? array_map(static fn (mixed $i): int => is_scalar($i) ? (int) $i : 0, $page['tag_ids'])
                    : [];
                $pageTags = is_array($page['tags'] ?? null) ? $page['tags'] : [];

                $tags        = ServiceLocator::get(TagService::class)->getCommonTags($items, Config::menubarTagCloudItemsNumber(), $pageTagIds);
                $tags        = ServiceLocator::get(TagService::class)->addLevelToTags($tags);
                $relatedTags = [];
                foreach ($tags as $tag) {
                    $tagArr = is_array($tag) ? $tag : [];
                    $relatedTags[] = array_merge($tagArr, [
                        'U_ADD' => UrlService::get()->makeIndexUrl(['tags' => array_merge($pageTags, [$tag])]),
                        'URL'   => UrlService::get()->makeIndexUrl(['tags' => [$tag]]),
                    ]);
                }
                usort(
                    $relatedTags,
                    static fn (array $a, array $b): int =>
                    (is_numeric($b['counter'] ?? null) ? (int) $b['counter'] : 0)
                    <=> (is_numeric($a['counter'] ?? null) ? (int) $a['counter'] : 0)
                );

                ServiceLocator::get(SelectedTagsRenderer::class)->render();

                $tagIds = $bodyDataArr['tag_ids'];
                $tpl->assign([
                    'SEARCH_IN_SET_BUTTON' => Config::indexSearchInSetButton(),
                    'SEARCH_IN_SET_ACTION' => Config::indexSearchInSetAction(),
                    'SEARCH_IN_SET_URL'    => UrlService::get()->addUrlParams(ServiceLocator::get(UrlGenerator::class)->searchPage(), ['tag_id' => implode(',', array_map(static fn (mixed $id): int => is_scalar($id) ? (int) $id : 0, $tagIds))]),
                    'COMBINABLE_TAGS' => $relatedTags,
                ]);
            }

            if ($category !== null && PermissionService::get()->isAdmin() && Config::indexEditIcon()) {
                $tpl->assign('U_EDIT', ServiceLocator::get(UrlGenerator::class)->admin('album-' . $catId));
            }

            if (PermissionService::get()->isAdmin() && !empty($items) && Config::indexCaddieIcon()) {
                $tpl->assign('U_CADDIE', UrlService::get()->addUrlParams(UrlService::get()->duplicateIndexUrl(), ['caddie' => 1]));
            }

            // Search results hints
            if ($section === 'search' && $start === 0 && !isset($page['chronology_field']) && isset($page['qsearch_details'])) {
                $qd   = is_array($page['qsearch_details']) ? $page['qsearch_details'] : [];
                $cats = array_merge(
                    is_array($qd['matching_cats_no_images'] ?? null) ? $qd['matching_cats_no_images'] : [],
                    is_array($qd['matching_cats'] ?? null) ? $qd['matching_cats'] : []
                );
                if (count($cats) > 0) {
                    usort($cats, static fn (mixed $a, mixed $b): int => ServiceLocator::get(HtmlService::class)->nameCompare(
                        is_array($a) ? $a : [],
                        is_array($b) ? $b : []
                    ));
                    $hints = [];
                    foreach ($cats as $cat) {
                        $hints[] = new Html(ServiceLocator::get(HtmlService::class)->getCatDisplayName([$cat], ''));
                    }
                    $tpl->assign('category_search_results', $hints);
                }
                $searchTags = is_array($qd['matching_tags'] ?? null) ? $qd['matching_tags'] : [];
                foreach ($searchTags as $tag) {
                    if (!is_array($tag)) {
                        continue;
                    }
                    $tag['URL'] = UrlService::get()->makeIndexUrl(['tags' => [$tag]]);
                    $tpl->append('tag_search_results', $tag);
                }
                if (empty($items)) {
                    $tpl->append('no_search_results', is_string($qd['q'] ?? null) ? $qd['q'] : '');
                } elseif (!empty($qd['unmatched_terms'])) {
                    $unmatched = is_array($qd['unmatched_terms']) ? $qd['unmatched_terms'] : [];
                    $tpl->assign('no_search_results', array_map(
                        static fn (mixed $t): string => is_scalar($t) ? (string) $t : '',
                        $unmatched
                    ));
                }
            }

            // Image-order selector
            if (Config::indexSortOrderInput() && count($items) > 0 && $section !== 'most_visited' && $section !== 'best_rated') {
                $preferredOrders = ServiceLocator::get(CategoryService::class)->getCategoryPreferredImageOrders();
                $rawOrder        = ServiceLocator::get(SessionService::class)->getSessionVar('image_order', 0);
                $orderIdx        = is_numeric($rawOrder) ? (int) $rawOrder : 0;
                $firstOrder      = trim(substr(Config::orderBy(), 9));
                if (($pos = strpos($firstOrder, ',')) !== false) {
                    $firstOrder = substr($firstOrder, 0, $pos);
                }
                $firstOrder    = trim($firstOrder);
                $url           = UrlService::get()->addUrlParams(UrlService::get()->duplicateIndexUrl(), ['image_order' => '']);
                $tplOrders     = [];
                $orderSelected = false;
                foreach ($preferredOrders as $orderId => $order) {
                    if (!is_array($order) || !$order[2]) {
                        continue;
                    }
                    if (!$orderSelected && $order[1] === $firstOrder) {
                        $orderIdx      = (int) $orderId;
                        $orderSelected = true;
                    }
                    $tplOrders[(int) $orderId] = [
                        'DISPLAY'  => $order[0],
                        'URL'      => $url . $orderId,
                        'SELECTED' => $orderIdx === (int) $orderId,
                    ];
                }
                $tplOrders[0]['SELECTED'] = !$orderSelected;
                $tpl->assign('image_orders', $tplOrders);
            }

            // Category description
            if (($start === 0 || Config::albumDescriptionOnAllPages())
                && !isset($page['chronology_field'])
                && !empty($page['comment'])
            ) {
                $commentVal = $page['comment'];
                $commentText = is_scalar($commentVal) ? (string) $commentVal : '';
                $tpl->assign('CONTENT_DESCRIPTION', $commentText !== '' ? new Html($commentText) : null);
            }

            if ($countCats === 0) {
                $tpl->clearAssign('U_MODE_FLAT');
            }

            // Sub-category grid
            if ($start === 0
                && !isset($page['flat'])
                && !isset($page['chronology_field'])
                && ($section === 'recent_cats' || $section === 'categories')
                && ($countCats === null || $countCats > 0)
            ) {
                ServiceLocator::get(CategoryCatsRenderer::class)->render();
            }

            if (!empty($items)) {
                ServiceLocator::get(CategoryDefaultRenderer::class)->render();

                if (Config::indexSizesIcon()) {
                    $url        = UrlService::get()->addUrlParams(UrlService::get()->duplicateIndexUrl(), ['display' => '']);
                    $derivObj   = $tpl->getTemplateVars('derivative_params');
                    $selType    = is_object($derivObj) ? (is_scalar($derivObj->type ?? null) ? (string) $derivObj->type : '') : '';
                    $tpl->clearAssign('derivative_params');
                    $typeMap = ImageStdParams::getDefinedTypeMap();
                    unset($typeMap[DerivativeSize::TwoXLarge->value], $typeMap[DerivativeSize::XLarge->value]);
                    foreach ($typeMap as $params) {
                        $tpl->append('image_derivatives', [
                            'DISPLAY'  => Lang::t($params->type),
                            'URL'      => $url . $params->type,
                            'SELECTED' => $params->type === $selType,
                        ]);
                    }
                }
            }

            // Slideshow
            if (!empty($page['cat_slideshow_url'])) {
                $slideshowUrlRaw = $page['cat_slideshow_url'];
                $slideshowUrl = is_string($slideshowUrlRaw) ? $slideshowUrlRaw : '';
                if (StringUtil::get()->inputString('slideshow', null, $_GET) !== null) {
                    Util::get()->redirect($slideshowUrl);
                } elseif (Config::indexSlideShowIcon()) {
                    $tpl->assign('U_SLIDESHOW', $slideshowUrl);
                }
            }

            // Related tags (for non-tags sections)
            if (!empty($items) && ($bodyDataArr['section'] ?? '') !== 'tags') {
                $selection  = array_slice($items, $start, $nbImagePage);
                $commonTags = ServiceLocator::get(TagService::class)->addLevelToTags(ServiceLocator::get(TagService::class)->getCommonTags($selection, Config::contentTagCloudItemsNumber()));
                $relTags    = [];
                foreach ($commonTags as $tag) {
                    $relTags[] = array_merge(is_array($tag) ? $tag : [], [
                        'URL' => UrlService::get()->makeIndexUrl(['tags' => [$tag]]),
                    ]);
                }
                $tpl->assign([
                    'RELATED_TAGS_ACTION' => !empty($relTags),
                    'RELATED_TAGS'        => $relTags,
                ]);
            }
        }

        // Render page (outputs directly — legacy Smarty model)
        PageHeaderRenderer::render();
        EventDispatcher::notify('loc_end_index');
        ServiceLocator::get(HtmlService::class)->flushPageMessages();
        $tpl->parseIndexButtons();
        $tpl->pparse('index');

        ServiceLocator::get(Util::class)->pwgLog();
        PageTailRenderer::render();

        return ResponseFactory::create(200);
    }
}
