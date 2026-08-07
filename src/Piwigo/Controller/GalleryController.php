<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Override;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Bootstrap\PageTail;
use Piwigo\Caddie\CaddieService;
use Piwigo\Category\CategoryCatsRenderer;
use Piwigo\Category\CategoryDefaultRenderer;
use Piwigo\Category\CategoryService;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Controller\Request\GalleryDisplayRequest;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\FilterState;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\PaginationService;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Event\Location\LocBeginIndex;
use Piwigo\Event\Location\LocEndIndex;
use Piwigo\Event\Tag\RenderTagName;
use Piwigo\History\HistoryService;
use Piwigo\Html\HtmlService;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Image\DerivativeParams;
use Piwigo\Image\ImageStdParams;
use Piwigo\Lang\Translator;
use Piwigo\Menu\MenubarRenderer;
use Piwigo\Page\PageHeaderRenderer;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Search\SearchFilterRenderer;
use Piwigo\Section\SectionContext;
use Piwigo\Section\SectionContextRegistry;
use Piwigo\Section\SectionPopulator;
use Piwigo\Session\SessionService;
use Piwigo\Tag\TagService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Users\CurrentUser;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

/**
 * The main gallery browsing page (categories, thumbnails, search
 * results, calendar, tags, etc.).
 *
 * SectionPopulator::populate() must run before check_status():
 * check_restrictions() (called right after) depends on the built
 * SectionContext's `category` already being populated.
 *
 * check_status()/check_restrictions()/page_not_found() all happen before
 * any rendering starts.
 */
final class GalleryController implements ControllerInterface
{
    public function __construct(
        private readonly Lang $lang,
        private readonly AccessControl $accessControl,
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
        private readonly ConfigService $configService,
        private readonly FilterState $filterState,
        private readonly SectionContextRegistry $sectionContextRegistry,
        private readonly SessionService $sessionService,
        private readonly EventDispatcher $eventDispatcher,
        private readonly DeploymentPolicy $deploymentPolicy,
        private readonly ImageStdParams $imageStdParams,
        private readonly PageState $pageState,
        private readonly CurrentUser $currentUser,
        private readonly CurrentTemplate $currentTemplate,
        private readonly SectionPopulator $sectionPopulator,
        private readonly SearchFilterRenderer $searchFilterRenderer,
        private readonly HistoryService $historyService,
        private readonly CategoryService $categoryService,
        private readonly TagService $tagService,
        private readonly CategoryCatsRenderer $categoryCatsRenderer,
        private readonly CategoryDefaultRenderer $categoryDefaultRenderer,
        private readonly HtmlService $htmlService,
        private readonly CurrentConfig $currentConfig,
        private readonly Translator $translator,
        private readonly CurrentLogger $currentLogger,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $template = $this->currentTemplate->get();

        $this->sectionPopulator
            ->populate();

        $this->accessControl->checkStatus(AccessLevel::Guest);

        // populate() always calls SectionContextRegistry::set() as the very
        // last thing it does, so this is guaranteed non-null by the time
        // this controller (its one real caller besides PictureController)
        // runs -- a real guard, not dead code, since the type itself is
        // nullable.
        $section_context = $this->sectionContextRegistry->current();
        if (! $section_context instanceof SectionContext) {
            throw new RuntimeException('SectionContextRegistry::current() is null after SectionPopulator::populate()');
        }

        $page_items = array_values(array_filter(array_map(
            static fn (int|string $id): ?int => is_numeric($id) ? (int) $id : null,
            $section_context->items
        ), static fn (?int $id): bool => $id !== null));

        $page_start = $section_context->start;
        $page_nb_image_page = $section_context->nbImagePage;

        // access authorization check
        if ($section_context->category !== null && is_numeric($section_context->category['id'] ?? null)) {
            $this->categoryService->checkRestrictions((int) $section_context->category['id'], $this->htmlService, $this->redirectService, $this->currentUser);
        }
        if ($page_start > 0 && $page_start >= count($page_items)) {
            $this->htmlService
                ->pageNotFound($this->redirectService, '', $this->urlService->duplicateIndexUrl([
                    'start' => 0,
                ]));
        }

        $redirectService = $this->redirectService;
        $urlService = $this->urlService;
        $configService = $this->configService;

        // $title is set and read entirely within this method (passed
        // straight into PageHeaderRenderer::render() below) -- no other
        // file reads $GLOBALS['title']. Plain local, not global.

        $tagService = $this->tagService;
        $categoryService = $this->categoryService;

        $this->eventDispatcher->dispatchNotify(new LocBeginIndex());

        $galleryDisplay = GalleryDisplayRequest::fromGlobals();

        // ---------------------------------------- change of image display order
        if ($galleryDisplay->hasImageOrder) {
            if ($galleryDisplay->validImageOrder !== null) {
                $this->sessionService->setSessionVar('image_order', $galleryDisplay->validImageOrder);
            } else {
                $this->sessionService->unsetSessionVar('image_order');
            }
            $redirectService->redirect(
                $urlService->duplicateIndexUrl(
                    [],        // nothing to redefine
                    ['start']  // changing display order goes back to section first page
                )
            );
        }
        if ($galleryDisplay->hasDisplayParam) {
            $this->pageState->setMetaRobotsFlag('noindex');
            if ($galleryDisplay->display !== null && array_key_exists($galleryDisplay->display, $this->imageStdParams->get_defined_type_map())) {
                $this->sessionService->setSessionVar('index_deriv', $galleryDisplay->display);
            }
        }

        // -------------------------------------------------- initialization
        // navigation bar
        $navigationBar = [];
        if (count($page_items) > $page_nb_image_page) {
            $navigationBar = new PaginationService($this->currentConfig)
                ->createNavigationBar($urlService->duplicateIndexUrl([], ['start']), count($page_items), $page_start, $page_nb_image_page, true, 'start');
        }

        $template->assign('thumb_navbar', $navigationBar);

        // caddie filling :-)
        if ($galleryDisplay->hasCaddie) {
            CaddieService::fillCurrentUserCaddie($page_items, $this->currentUser);
            $redirectService->redirect($urlService->duplicateIndexUrl());
        }

        if ($section_context->isHomepage) {
            $canonical_url = $urlService->getGalleryHomeUrl();
        } else {
            $start = (float) $page_nb_image_page * round($page_start / $page_nb_image_page);
            if ($start > 0 && $start >= count($page_items)) {
                $start -= (float) $page_nb_image_page;
            }
            $canonical_url = $urlService->duplicateIndexUrl([
                'start' => $start,
            ]);
        }
        $template->assign('U_CANONICAL', $canonical_url);

        // Standard Pages
        // Some themes will want to use standard pages so this will let
        // them know
        $template->assign('use_standard_pages', $this->currentConfig->useStandardPages());

        // -------------------------------------------------- page title
        $title = $section_context->title;
        $template_title = $section_context->sectionTitle;
        $nb_items = count($page_items);
        $template->assign('TITLE', $template_title);
        $template->assign('NB_ITEMS', $nb_items);

        // -------------------------------------------------- menubar
        $categoryCountCategories = new MenubarRenderer()
            ->render($this->lang, new AccessLevelChecker($this->currentUser, $this->currentConfig), $urlService, $this->filterState, $this->sectionContextRegistry, $this->sessionService, $this->deploymentPolicy, $this->currentUser, $this->currentTemplate, $this->currentConfig, $this->eventDispatcher, $this->translator, $this->currentLogger);

        $template->set_filename('index', 'index.tpl');

        // +-----------------------------------------------------------------+
        // |  index page (categories, thumbnails, search, calendar, etc.)    |
        // +-----------------------------------------------------------------+
        // ------------------------------------------------- template init
        $this->pageState->setBodyId('theCategoryPage');

        if ($section_context->flat or $section_context->chronologyField !== null) {
            $template->assign(
                'U_MODE_NORMAL',
                $urlService->duplicateIndexUrl([], ['chronology_field', 'start', 'flat'])
            );
        }

        if ($this->currentConfig->indexFlatIcon() and ! $section_context->flat and $section_context->section === 'categories') {
            $template->assign(
                'U_MODE_FLAT',
                $urlService->duplicateIndexUrl([
                    'flat' => '',
                ], ['start', 'chronology_field'])
            );
        }

        if ($section_context->chronologyField === null) {
            $chronology_params = [
                'chronology_field' => 'created',
                'chronology_style' => 'monthly',
                'chronology_view' => 'list',
            ];
            if ($this->currentConfig->indexCreatedDateIcon()) {
                $template->assign(
                    'U_MODE_CREATED',
                    $urlService->duplicateIndexUrl($chronology_params, ['start', 'flat'])
                );
            }
            if ($this->currentConfig->indexPostedDateIcon()) {
                $chronology_params['chronology_field'] = 'posted';
                $template->assign(
                    'U_MODE_POSTED',
                    $urlService->duplicateIndexUrl($chronology_params, ['start', 'flat'])
                );
            }
        } else {
            if ($section_context->chronologyField === 'created') {
                $chronology_field = 'posted';
            } else {
                $chronology_field = 'created';
            }
            $chronology_date_icon = match ($chronology_field) {
                'created' => $this->currentConfig->indexCreatedDateIcon(),
                'posted' => $this->currentConfig->indexPostedDateIcon(),
            };
            if ($chronology_date_icon) {
                $url = $urlService->duplicateIndexUrl(
                    [
                        'chronology_field' => $chronology_field,
                    ],
                    ['chronology_date', 'start', 'flat']
                );
                $template->assign(
                    'U_MODE_' . strtoupper($chronology_field),
                    $url
                );
            }
        }

        $resolved_search_id = $this->searchFilterRenderer->render($section_context);

        if ($section_context->section === 'categories' and $section_context->category !== null and $section_context->combinedCategories === null) {
            $template->assign(
                [
                    'SEARCH_IN_SET_BUTTON' => $this->currentConfig->indexSearchInSetButton(),
                    'SEARCH_IN_SET_ACTION' => $this->currentConfig->indexSearchInSetAction(),
                    'SEARCH_IN_SET_URL' => $urlService->getRootUrl() . 'search.php?cat_id=' . (is_numeric($section_context->category['id'] ?? null) ? (int) $section_context->category['id'] : 0),
                ]
            );
        }

        $bodyData = $this->pageState->bodyData;
        if (isset($bodyData['tag_ids']) and is_array($bodyData['tag_ids'])) {
            // get tags for related tags "button", with the
            // possibility to combine them
            //
            // NB: the excluded tag ids intentionally come from
            // $section_context->tagIds (the tags currently being
            // viewed, only set on the "tags" section), not from
            // PageState::bodyData['tag_ids'] (a broader,
            // always-present mirror of the same data used for
            // JS/body attributes).
            $excluded_tag_ids = $section_context->tagIds;

            $tags = $tagService->getCommonTags(
                $page_items,
                $this->currentConfig->menubarTagCloudItemsNumber(),
                $this->htmlService,
                $excluded_tag_ids
            );

            $tags = $tagService->addLevelToTags($tags);

            $page_tags = $section_context->tags;

            $related_tags = [];

            foreach ($tags as $tag) {
                $related_tags[] = array_merge(
                    $tag,
                    [
                        'U_ADD' => $urlService->makeIndexUrl(
                            [
                                'tags' => array_merge(
                                    $page_tags,
                                    [$tag]
                                ),
                            ]
                        ),
                        'URL' => $urlService->makeIndexUrl(
                            [
                                'tags' => [$tag],
                            ]
                        ),
                    ]
                );
            }

            // We sort the array here because we want them sorted by
            // counter and not alphabetically like before.
            usort(
                $related_tags,
                fn (array $a, array $b): int => (is_numeric($b['counter'] ?? null) ? (int) $b['counter'] : 0)
                    <=> (is_numeric($a['counter'] ?? null) ? (int) $a['counter'] : 0)
            );

            $selected_related_tags_info = [];

            $selectedTags = $section_context->tags;

            foreach ($selectedTags as $selectedTagKey => $selectedTag) {
                $otherSelectedTags = $selectedTags;
                unset($otherSelectedTags[$selectedTagKey]);

                $selectedTagNameEvent = $this->eventDispatcher->dispatchChange(new RenderTagName(is_string($selectedTag['name']) ? $selectedTag['name'] : '', $selectedTag));
                $selected_related_tags_info[$selectedTagKey] =
                [
                    'tag_name' => $selectedTagNameEvent->tagName,
                    'item_count' => '',
                    'index_url' => $urlService->makeIndexUrl(
                        [
                            'tags' => [$selectedTag],
                        ]
                    ),
                    'remove_url' => $urlService->makeIndexUrl(
                        [
                            'tags' => $otherSelectedTags,
                        ]
                    ),
                ];
            }

            $template->assign(
                [
                    'SELECT_RELATED_TAGS' => $selected_related_tags_info,
                ]
            );

            $template->set_filename('selected_tags', 'include/selected_tags.inc.tpl');
            $template->assign_var_from_handle('SELECTED_TAGS_TEMPLATE', 'selected_tags');

            $body_data_tag_ids = array_values(array_filter($bodyData['tag_ids'], is_scalar(...)));

            $template->assign(
                [
                    'SEARCH_IN_SET_BUTTON' => $this->currentConfig->indexSearchInSetButton(),
                    'SEARCH_IN_SET_ACTION' => $this->currentConfig->indexSearchInSetAction(),
                    'SEARCH_IN_SET_URL' => $urlService->getRootUrl() . 'search.php?tag_id=' . implode(',', $body_data_tag_ids),
                    'COMBINABLE_TAGS' => $related_tags,
                ]
            );
        }

        if ($section_context->category !== null and $this->accessControl->isAdmin() and $this->currentConfig->indexEditIcon()) {
            $template->assign(
                'U_EDIT',
                $urlService->getRootUrl() . 'admin.php?page=album-' . (is_numeric($section_context->category['id'] ?? null) ? (int) $section_context->category['id'] : 0)
            );
        }

        if ($this->accessControl->isAdmin() and $page_items !== [] and $this->currentConfig->indexCaddieIcon()) {
            $template->assign(
                'U_CADDIE',
                $urlService->addUrlParams($urlService->duplicateIndexUrl(), [
                    'caddie' => 1,
                ])
            );
        }

        if ($section_context->section === 'search' and $page_start === 0 and
            $section_context->chronologyField === null and $section_context->qsearchDetails !== []) {
            $qsearchDetails = $section_context->qsearchDetails;

            $matching_cats_no_images = is_array($qsearchDetails['matching_cats_no_images'] ?? null) ? array_values(array_filter($qsearchDetails['matching_cats_no_images'], is_array(...))) : [];
            $matching_cats = is_array($qsearchDetails['matching_cats'] ?? null) ? array_values(array_filter($qsearchDetails['matching_cats'], is_array(...))) : [];
            /**
             * @var array<int, array<string, mixed>> $matching_cats_no_images
             * @var array<int, array<string, mixed>> $matching_cats
             */
            $cats = array_merge($matching_cats_no_images, $matching_cats);
            if ($cats !== []) {
                usort($cats, $this->htmlService->nameCompare(...));
                $hints = [];
                foreach ($cats as $cat) {
                    $hints[] = $this->htmlService->getCatDisplayName([$cat], '');
                }
                $template->assign('category_search_results', $hints);
            }

            $matching_tags = is_array($qsearchDetails['matching_tags'] ?? null) ? array_values(array_filter($qsearchDetails['matching_tags'], is_array(...))) : [];
            /** @var array<int, array<string, mixed>> $matching_tags */
            foreach ($matching_tags as $tag) {
                $tag['URL'] = $urlService->makeIndexUrl([
                    'tags' => [$tag],
                ]);
                $template->append('tag_search_results', $tag);
            }

            if ($page_items === []) {
                $search_query = $qsearchDetails['q'] ?? null;
                $template->append('no_search_results', htmlspecialchars(is_string($search_query) ? $search_query : ''));
            } else {
                $unmatched_terms = $qsearchDetails['unmatched_terms'] ?? null;
                if (is_array($unmatched_terms) && $unmatched_terms !== []) {
                    /** @var list<string> $unmatched_terms */
                    $unmatched_terms = array_values(array_filter($unmatched_terms, is_string(...)));
                    $template->assign('no_search_results', array_map(htmlspecialchars(...), $unmatched_terms));
                }
            }
        }

        // image order
        if ($this->currentConfig->indexSortOrderInput()
            and count($page_items) > 0
            and $section_context->section !== 'most_visited'
            and $section_context->section !== 'best_rated') {
            $preferred_image_orders = $categoryService->getPreferredImageOrders();
            $order_idx = $this->sessionService->getSessionVar('image_order', 0);

            // get first order field and direction
            $order_by = $this->currentConfig->orderBy();
            $first_order = substr($order_by, 9);
            if (($pos = strpos($first_order, ',')) !== false) {
                $first_order = substr($first_order, 0, $pos);
            }
            $first_order = trim($first_order);

            $url = $urlService->addUrlParams(
                $urlService->duplicateIndexUrl(),
                [
                    'image_order' => '',
                ]
            );
            $tpl_orders = [];
            $order_selected = false;

            foreach ($preferred_image_orders as $order_id => $order) {
                if ($order[2]) {
                    // force select if the field is the first field
                    // of order_by
                    if (! $order_selected && $order[1] === $first_order) {
                        $order_idx = $order_id;
                        $order_selected = true;
                    }

                    $tpl_orders[$order_id] = [
                        'DISPLAY' => $order[0],
                        'URL' => $url . $order_id,
                        'SELECTED' => (is_scalar($order_idx) ? (string) $order_idx : '') === (string) $order_id,
                    ];
                }
            }

            $tpl_orders[0]['SELECTED'] = ! $order_selected; // unselect "Default" if another one is selected
            $template->assign('image_orders', $tpl_orders);
        }

        // category comment
        $page_comment = $section_context->comment;
        $page_comment_present = $page_comment !== '' && $page_comment !== '0';
        if (($page_start === 0 or $this->currentConfig->albumDescriptionOnAllPages()) and $section_context->chronologyField === null and $page_comment_present) {
            $template->assign('CONTENT_DESCRIPTION', $section_context->comment);
        }

        if ($section_context->category !== null and $categoryCountCategories === 0) {// count_categories might be computed by menubar - if the case unassign flat link if no sub albums
            $template->clear_assign('U_MODE_FLAT');
        }

        // -------------------------------------------- main part : thumbnails
        if ($page_start === 0
          and ! $section_context->flat
          and $section_context->chronologyField === null
          and ($section_context->section === 'recent_cats' or $section_context->section === 'categories')
          and ($section_context->category === null or $categoryCountCategories === null or $categoryCountCategories > 0)
        ) {
            $this->categoryCatsRenderer->render($section_context->section, $section_context->category, $section_context->startcat);
        }

        $slideshow_url = null;
        if ($page_items !== []) {
            $slideshow_url = $this->categoryDefaultRenderer->render($page_items, $page_start, $page_nb_image_page, $section_context->section);

            if ($this->currentConfig->indexSizesIcon()) {
                $url = $urlService->addUrlParams(
                    $urlService->duplicateIndexUrl(),
                    [
                        'display' => '',
                    ]
                );

                $derivative_params_var = $template->get_template_vars('derivative_params');
                $selected_type = ($derivative_params_var instanceof DerivativeParams) ? $derivative_params_var->type : null;
                $template->clear_assign('derivative_params');
                $type_map = $this->imageStdParams->get_defined_type_map();
                unset($type_map[ImageStdParams::XXLARGE], $type_map[ImageStdParams::XLARGE]);

                foreach ($type_map as $params) {
                    $template->append(
                        'image_derivatives',
                        [
                            'DISPLAY' => $this->lang->t($params->type),
                            'URL' => $url . $params->type,
                            'SELECTED' => $params->type === $selected_type,
                        ]
                    );
                }
            }
        }

        // slideshow
        // execute after init thumbs in order to have all picture
        // informations
        $slideshow_url_present = is_string($slideshow_url) && $slideshow_url !== '' && $slideshow_url !== '0';
        if ($slideshow_url_present) {
            if ($galleryDisplay->hasSlideshow) {
                $redirectService->redirect($slideshow_url);
            } elseif ($this->currentConfig->indexSlideShowIcon()) {
                $template->assign('U_SLIDESHOW', $slideshow_url);
            }
        }

        // We want all pages that display thumbnails, except on the
        // tags page
        // Fill related tags action
        $body_data_section = $this->pageState->bodyData['section'] ?? null;
        if ($page_items !== [] and $body_data_section !== 'tags') {
            $selection = array_slice($page_items, $page_start, $page_nb_image_page);
            $tags = $tagService->addLevelToTags($tagService->getCommonTags($selection, $this->currentConfig->contentTagCloudItemsNumber(), $this->htmlService));
            $related_tags = [];
            foreach ($tags as $tag) {
                $related_tags[] =
                array_merge(
                    $tag,
                    [
                        'URL' => $urlService->makeIndexUrl([
                            'tags' => [$tag],
                        ]),
                    ]
                );
            }

            $template->assign(
                [
                    'RELATED_TAGS_ACTION' => $related_tags !== [],
                    'RELATED_TAGS' => $related_tags,
                ]
            );
        }

        // ---------------------------------------------------------- end
        new PageHeaderRenderer()
            ->render($title, $this->eventDispatcher, $this->pageState, $this->currentTemplate, $this->currentConfig);
        $this->eventDispatcher->dispatchNotify(new LocEndIndex());
        $this->htmlService
            ->flushPageMessages();
        $template->parse_index_buttons();
        $template->parse('index', false);

        // ------------------------------------------------ log informations
        $this->historyService
            ->logVisit(
                section: $section_context->section,
                category: $section_context->category,
                tagIds: $section_context->tagIds,
                searchId: $resolved_search_id,
            );
        $body = PageTail::renderToString();

        return ResponseFactory::html($body);
    }
}
