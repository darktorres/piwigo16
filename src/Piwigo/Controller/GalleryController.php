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
use Piwigo\Core\PageState;
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
use Piwigo\Section\SectionContextRegistry;
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
final readonly class GalleryController implements ControllerInterface
{
    public function __construct(
        private CategoryCatsRenderer $categoryCatsRenderer,
        private CategoryDefaultRenderer $categoryDefaultRenderer,
        private CategoryService $categoryService,
        private ConfigService $configService,
        private HtmlService $htmlService,
        private MenubarRenderer $menubarRenderer,
        private PermissionService $permissionService,
        private SearchFilterRenderer $searchFilterRenderer,
        private SectionInitializer $sectionInitializer,
        private SelectedTagsRenderer $selectedTagsRenderer,
        private SessionService $sessionService,
        private StringUtil $stringUtil,
        private TagService $tagService,
        private UrlGenerator $urlGenerator,
        private UrlService $urlService,
        private Util $util,
    ) {
    }

    #[\Override]
    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $this->sectionInitializer->initialize($request, 'index');

        $this->permissionService->checkStatus(AccessLevel::Guest);

        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];
        $ctx  = SectionContextRegistry::current();

        // Extract commonly-used typed locals from SectionContext
        $items       = $ctx->items;
        $start       = $ctx->start;
        $nbImagePage = $ctx->nbImagePage;
        $section     = $ctx->section;
        $category    = $ctx->category;
        $catId       = $category !== null && is_scalar($category['id'] ?? null) ? (int) $category['id'] : 0;
        $countCats   = $category !== null && is_scalar($category['count_categories'] ?? null)
            ? (int) $category['count_categories'] : null;

        if ($category !== null) {
            $this->categoryService->checkRestrictions($catId);
        }
        if ($start > 0 && $start >= count($items)) {
            $this->htmlService->pageNotFound('', $this->urlService->duplicateIndexUrl(['start' => 0]));
        }

        EventDispatcher::notify('loc_begin_index');

        // Image display-order change
        $imageOrder = $this->stringUtil->inputInt('image_order', null, $_GET);
        if ($imageOrder !== null) {
            if ($imageOrder > 0) {
                $this->sessionService->setSessionVar('image_order', $imageOrder);
            } else {
                $this->sessionService->unsetSessionVar('image_order');
            }
            $this->util->redirect($this->urlService->duplicateIndexUrl([], ['start']));
        }

        $display = $this->stringUtil->inputString('display', null, $_GET);
        if ($display !== null) {
            $ps = PageState::current();
            $ps->metaRobots = array_merge($ps->metaRobots, ['noindex' => 1]);
            if (array_key_exists($display, ImageStdParams::getDefinedTypeMap())) {
                $this->sessionService->setSessionVar('index_deriv', $display);
            }
        }

        $tpl = TemplateRegistry::current();

        // Navigation bar
        $page['navigation_bar'] = [];
        if (count($items) > $nbImagePage) {
            $page['navigation_bar'] = $this->util->createNavigationBar(
                $this->urlService->duplicateIndexUrl([], ['start']),
                count($items),
                $start,
                $nbImagePage,
                true,
                'start'
            );
        }
        $tpl->assign('thumb_navbar', $page['navigation_bar']);

        // Caddie filling
        if ($this->stringUtil->inputString('caddie', null, $_GET) !== null) {
            $this->util->fillCaddie(array_map(static fn (string $i): int => (int) $i, $items));
            $this->util->redirect($this->urlService->duplicateIndexUrl());
        }

        // Canonical URL
        if ($ctx->isHomepage) {
            $canonicalUrl = $this->urlService->getGalleryHomeUrl();
        } else {
            $safeStart = $nbImagePage > 0 ? (int) ((float) $nbImagePage * round((float) $start / (float) $nbImagePage)) : 0;
            if ($safeStart > 0 && $safeStart >= count($items)) {
                $safeStart -= $nbImagePage;
            }
            $canonicalUrl = $this->urlService->duplicateIndexUrl(['start' => $safeStart]);
        }
        $tpl->assign('U_CANONICAL', $canonicalUrl);
        $useStandardPagesRaw = $this->configService->confGetParam('use_standard_pages', false);
        $tpl->assign('use_standard_pages', is_array($useStandardPagesRaw) || is_scalar($useStandardPagesRaw) || $useStandardPagesRaw === null ? $useStandardPagesRaw : null);

        // Page title
        $tpl->assign('TITLE', new Html($ctx->sectionTitle));
        $tpl->assign('NB_ITEMS', count($items));

        // Menubar
        $this->menubarRenderer->render();

        if (!$ctx->isExternal) {
            PageState::current()->bodyId = 'theCategoryPage';

            if ($ctx->flat || $ctx->chronologyField !== '') {
                $tpl->assign('U_MODE_NORMAL', $this->urlService->duplicateIndexUrl([], ['chronology_field', 'start', 'flat']));
            }

            if (Config::indexFlatIcon() && !$ctx->flat && $section === 'categories') {
                $tpl->assign('U_MODE_FLAT', $this->urlService->duplicateIndexUrl(['flat' => ''], ['start', 'chronology_field']));
            }

            if ($ctx->chronologyField === '') {
                $chronoParams = [
                    'chronology_field' => 'created',
                    'chronology_style' => 'monthly',
                    'chronology_view'  => 'list',
                ];
                if (Config::indexCreatedDateIcon()) {
                    $tpl->assign('U_MODE_CREATED', $this->urlService->duplicateIndexUrl($chronoParams, ['start', 'flat']));
                }
                if (Config::indexPostedDateIcon()) {
                    $chronoParams['chronology_field'] = 'posted';
                    $tpl->assign('U_MODE_POSTED', $this->urlService->duplicateIndexUrl($chronoParams, ['start', 'flat']));
                }
            } else {
                $chronoField = $ctx->chronologyField === 'created' ? 'posted' : 'created';
                if (Config::raw('index_' . $chronoField . '_date_icon')) {
                    $url = $this->urlService->duplicateIndexUrl(
                        ['chronology_field' => $chronoField],
                        ['chronology_date', 'start', 'flat']
                    );
                    $tpl->assign('U_MODE_' . strtoupper($chronoField), $url);
                }
            }

            $this->searchFilterRenderer->render();

            if ($section === 'categories' && $category !== null && $ctx->combinedCategories === null) {
                $tpl->assign([
                    'SEARCH_IN_SET_BUTTON' => Config::indexSearchInSetButton(),
                    'SEARCH_IN_SET_ACTION' => Config::indexSearchInSetAction(),
                    'SEARCH_IN_SET_URL'    => $this->urlService->addUrlParams($this->urlGenerator->searchPage(), ['cat_id' => $catId]),
                ]);
            }

            // Tag-related context (tags page only)
            $bodyDataArr = PageState::current()->bodyData;
            if (is_array($bodyDataArr['tag_ids'] ?? null)) {
                $pageTagIds = $ctx->tagIds;
                $pageTags   = $ctx->tags;

                $tags        = $this->tagService->getCommonTags($items, Config::menubarTagCloudItemsNumber(), $pageTagIds);
                $tags        = $this->tagService->addLevelToTags($tags);
                $relatedTags = [];
                foreach ($tags as $tag) {
                    $tagArr = is_array($tag) ? $tag : [];
                    $relatedTags[] = array_merge($tagArr, [
                        'U_ADD' => $this->urlService->makeIndexUrl(['tags' => array_merge($pageTags, [$tag])]),
                        'URL'   => $this->urlService->makeIndexUrl(['tags' => [$tag]]),
                    ]);
                }
                usort(
                    $relatedTags,
                    static fn (array $a, array $b): int =>
                    (is_numeric($b['counter'] ?? null) ? (int) $b['counter'] : 0)
                    <=> (is_numeric($a['counter'] ?? null) ? (int) $a['counter'] : 0)
                );

                $this->selectedTagsRenderer->render();

                $tagIds = $bodyDataArr['tag_ids'];
                $tpl->assign([
                    'SEARCH_IN_SET_BUTTON' => Config::indexSearchInSetButton(),
                    'SEARCH_IN_SET_ACTION' => Config::indexSearchInSetAction(),
                    'SEARCH_IN_SET_URL'    => $this->urlService->addUrlParams($this->urlGenerator->searchPage(), ['tag_id' => implode(',', array_map(static fn (mixed $id): int => is_scalar($id) ? (int) $id : 0, $tagIds))]),
                    'COMBINABLE_TAGS' => $relatedTags,
                ]);
            }

            if ($category !== null && $this->permissionService->isAdmin() && Config::indexEditIcon()) {
                $tpl->assign('U_EDIT', $this->urlGenerator->admin('album-' . $catId));
            }

            if ($this->permissionService->isAdmin() && !empty($items) && Config::indexCaddieIcon()) {
                $tpl->assign('U_CADDIE', $this->urlService->addUrlParams($this->urlService->duplicateIndexUrl(), ['caddie' => 1]));
            }

            // Search results hints
            if ($section === 'search' && $start === 0 && $ctx->chronologyField === '' && $ctx->qsearchDetails !== []) {
                $qd = $ctx->qsearchDetails;
                $cats = array_merge(
                    is_array($qd['matching_cats_no_images'] ?? null) ? $qd['matching_cats_no_images'] : [],
                    is_array($qd['matching_cats'] ?? null) ? $qd['matching_cats'] : []
                );
                if (count($cats) > 0) {
                    usort($cats, fn (mixed $a, mixed $b): int => $this->htmlService->nameCompare(
                        is_array($a) ? $a : [],
                        is_array($b) ? $b : []
                    ));
                    $hints = [];
                    foreach ($cats as $cat) {
                        $hints[] = new Html($this->htmlService->getCatDisplayName([$cat], ''));
                    }
                    $tpl->assign('category_search_results', $hints);
                }
                $searchTags = is_array($qd['matching_tags'] ?? null) ? $qd['matching_tags'] : [];
                foreach ($searchTags as $tag) {
                    if (!is_array($tag)) {
                        continue;
                    }
                    $tag['URL'] = $this->urlService->makeIndexUrl(['tags' => [$tag]]);
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
                $preferredOrders = $this->categoryService->getCategoryPreferredImageOrders();
                $rawOrder        = $this->sessionService->getSessionVar('image_order', 0);
                $orderIdx        = is_numeric($rawOrder) ? (int) $rawOrder : 0;
                $firstOrder      = trim(substr(Config::orderBy(), 9));
                if (($pos = strpos($firstOrder, ',')) !== false) {
                    $firstOrder = substr($firstOrder, 0, $pos);
                }
                $firstOrder    = trim($firstOrder);
                $url           = $this->urlService->addUrlParams($this->urlService->duplicateIndexUrl(), ['image_order' => '']);
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
                && $ctx->chronologyField === ''
                && $ctx->comment !== ''
            ) {
                $commentText = $ctx->comment;
                $tpl->assign('CONTENT_DESCRIPTION', new Html($commentText));
            }

            if ($countCats === 0) {
                $tpl->clearAssign('U_MODE_FLAT');
            }

            // Sub-category grid
            if ($start === 0
                && !$ctx->flat
                && $ctx->chronologyField === ''
                && ($section === 'recent_cats' || $section === 'categories')
                && ($countCats === null || $countCats > 0)
            ) {
                $this->categoryCatsRenderer->render();
            }

            if (!empty($items)) {
                $this->categoryDefaultRenderer->render();

                if (Config::indexSizesIcon()) {
                    $url        = $this->urlService->addUrlParams($this->urlService->duplicateIndexUrl(), ['display' => '']);
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
                if ($this->stringUtil->inputString('slideshow', null, $_GET) !== null) {
                    $this->util->redirect($slideshowUrl);
                } elseif (Config::indexSlideShowIcon()) {
                    $tpl->assign('U_SLIDESHOW', $slideshowUrl);
                }
            }

            // Related tags (for non-tags sections)
            if (!empty($items) && ($bodyDataArr['section'] ?? '') !== 'tags') {
                $selection  = array_slice($items, $start, $nbImagePage);
                $commonTags = $this->tagService->addLevelToTags($this->tagService->getCommonTags($selection, Config::contentTagCloudItemsNumber()));
                $relTags    = [];
                foreach ($commonTags as $tag) {
                    $relTags[] = array_merge(is_array($tag) ? $tag : [], [
                        'URL' => $this->urlService->makeIndexUrl(['tags' => [$tag]]),
                    ]);
                }
                $tpl->assign([
                    'RELATED_TAGS_ACTION' => !empty($relTags),
                    'RELATED_TAGS'        => $relTags,
                ]);
            }
        }

        PageHeaderRenderer::render();
        EventDispatcher::notify('loc_end_index');
        $this->htmlService->flushPageMessages();
        $tpl->parseIndexButtons();
        $tpl->pparse('index.latte');

        $this->util->pwgLog();
        PageTailRenderer::render();

        return ResponseFactory::create(200);
    }
}
