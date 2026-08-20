<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Latte\Runtime\Html;
use Override;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Bootstrap\PageTail;
use Piwigo\Caddie\CaddieService;
use Piwigo\Category\CategoryCatsRenderer;
use Piwigo\Category\CategoryDefaultRenderer;
use Piwigo\Category\CategoryService;
use Piwigo\Common\Enum\Section;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Controller\Event\IndexRendered;
use Piwigo\Controller\Event\IndexRendering;
use Piwigo\Controller\Projection\CanonicalUrlPageContext;
use Piwigo\Controller\Projection\CategoryCatsHtmlPageContext;
use Piwigo\Controller\Projection\CategoryCatsView;
use Piwigo\Controller\Projection\IndexView;
use Piwigo\Controller\Projection\SearchFiltersHtmlPageContext;
use Piwigo\Controller\Projection\SearchFiltersView;
use Piwigo\Controller\Projection\SelectedTagsView;
use Piwigo\Controller\Projection\ThumbnailsHtmlPageContext;
use Piwigo\Controller\Projection\ThumbnailsView;
use Piwigo\Controller\Request\GalleryDisplayRequest;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\FilterState;
use Piwigo\Core\Lang;
use Piwigo\Core\LayoutState;
use Piwigo\Core\PageState;
use Piwigo\Core\PaginationService;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\SortRenderer;
use Piwigo\History\HistoryService;
use Piwigo\Html\HtmlService;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Image\ImageStdParams;
use Piwigo\Lang\Translator;
use Piwigo\Menu\MenubarRenderer;
use Piwigo\Page\PageHeaderRenderer;
use Piwigo\Permission\PermissionService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Search\SearchFilterRenderer;
use Piwigo\Section\SectionContext;
use Piwigo\Section\SectionContextRegistry;
use Piwigo\Section\SectionPopulator;
use Piwigo\Session\SessionService;
use Piwigo\Tag\Event\RenderTagName;
use Piwigo\Tag\TagService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Renderer;
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
final readonly class GalleryController implements ControllerInterface
{
    public function __construct(
        private Lang $lang,
        private AccessControl $accessControl,
        private RedirectServiceInterface $redirectService,
        private UrlServiceInterface $urlService,
        private ConfigService $configService,
        private FilterState $filterState,
        private SectionContextRegistry $sectionContextRegistry,
        private SessionService $sessionService,
        private EventDispatcher $eventDispatcher,
        private DeploymentPolicy $deploymentPolicy,
        private ImageStdParams $imageStdParams,
        private PageState $pageState,
        private LayoutState $layoutState,
        private CurrentUser $currentUser,
        private CurrentTemplate $currentTemplate,
        private SectionPopulator $sectionPopulator,
        private SearchFilterRenderer $searchFilterRenderer,
        private HistoryService $historyService,
        private CategoryService $categoryService,
        private TagService $tagService,
        private CategoryCatsRenderer $categoryCatsRenderer,
        private CategoryDefaultRenderer $categoryDefaultRenderer,
        private HtmlService $htmlService,
        private CurrentConfig $currentConfig,
        private Translator $translator,
        private CurrentLogger $currentLogger,
        private PermissionService $permissionService,
        private EntityManagerInterface $entityManager,
        private Renderer $renderer,
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

        $this->eventDispatcher->dispatch(new IndexRendering());

        $galleryDisplay = GalleryDisplayRequest::fromGlobals();

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
            $this->layoutState->setMetaRobotsFlag('noindex');
            if ($galleryDisplay->display !== null && array_key_exists($galleryDisplay->display, $this->imageStdParams->getDefinedTypeMap())) {
                $this->sessionService->setSessionVar('index_deriv', $galleryDisplay->display);
            }
        }

        // navigation bar
        $navigationBar = [];
        if (count($page_items) > $page_nb_image_page) {
            $navigationBar = new PaginationService($this->currentConfig)
                ->createNavigationBar($urlService->duplicateIndexUrl([], ['start']), count($page_items), $page_start, $page_nb_image_page, true, 'start');
        }

        // caddie filling :-)
        if ($galleryDisplay->hasCaddie) {
            CaddieService::fillCurrentUserCaddie($page_items, $this->currentUser, $this->entityManager);
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

        // header.latte renders this before IndexView is ever
        // constructed -- see CanonicalUrlPageContext's own docblock.
        $template->assignContext(new CanonicalUrlPageContext($canonical_url));

        $title = $section_context->title;
        $template_title = $section_context->sectionTitle;
        $nb_items = count($page_items);

        $categoryCountCategories = new MenubarRenderer()
            ->render($this->lang, new AccessLevelChecker($this->currentUser, $this->currentConfig), $urlService, $this->filterState, $this->sectionContextRegistry, $this->sessionService, $this->deploymentPolicy, $this->currentUser, $this->currentTemplate, $this->currentConfig, $this->eventDispatcher, $this->translator, $this->currentLogger, $this->permissionService, $this->entityManager, $this->renderer);

        $this->layoutState->setBodyId('theCategoryPage');

        $u_mode_normal = null;
        if ($section_context->flat or $section_context->chronologyField !== null) {
            $u_mode_normal = $urlService->duplicateIndexUrl([], ['chronology_field', 'start', 'flat']);
        }

        $u_mode_flat = null;
        if ($this->currentConfig->indexFlatIcon and ! $section_context->flat and $section_context->section === Section::Categories) {
            $u_mode_flat = $urlService->duplicateIndexUrl([
                'flat' => '',
            ], ['start', 'chronology_field']);
        }

        $u_mode_created = null;
        $u_mode_posted = null;

        if ($section_context->chronologyField === null) {
            $chronology_params = [
                'chronology_field' => 'created',
                'chronology_style' => 'monthly',
                'chronology_view' => 'list',
            ];
            if ($this->currentConfig->indexCreatedDateIcon) {
                $u_mode_created = $urlService->duplicateIndexUrl($chronology_params, ['start', 'flat']);
            }
            if ($this->currentConfig->indexPostedDateIcon) {
                $chronology_params['chronology_field'] = 'posted';
                $u_mode_posted = $urlService->duplicateIndexUrl($chronology_params, ['start', 'flat']);
            }
        } else {
            if ($section_context->chronologyField === 'created') {
                $chronology_field = 'posted';
            } else {
                $chronology_field = 'created';
            }
            $chronology_date_icon = match ($chronology_field) {
                'created' => $this->currentConfig->indexCreatedDateIcon,
                'posted' => $this->currentConfig->indexPostedDateIcon,
            };
            if ($chronology_date_icon) {
                $url = $urlService->duplicateIndexUrl(
                    [
                        'chronology_field' => $chronology_field,
                    ],
                    ['chronology_date', 'start', 'flat']
                );
                if ($chronology_field === 'created') {
                    $u_mode_created = $url;
                } else {
                    $u_mode_posted = $url;
                }
            }
        }

        $searchFilterResult = $this->searchFilterRenderer->render($section_context);
        $resolved_search_id = $searchFilterResult->resolvedSearchId;
        if ($searchFilterResult->data !== null) {
            $searchFiltersHtml = $this->renderer->render(new SearchFiltersView(
                displayFilter: $searchFilterResult->data->displayFilter,
                showFilterRatings: $searchFilterResult->data->showFilterRatings,
                gp: $searchFilterResult->data->gp,
                searchId: $searchFilterResult->data->searchId,
                tags: $searchFilterResult->data->tags,
                authors: $searchFilterResult->data->authors,
                addedBy: $searchFilterResult->data->addedBy,
                fullnameOf: $searchFilterResult->data->fullnameOf,
                filetypes: $searchFilterResult->data->filetypes,
                rating: $searchFilterResult->data->rating,
                filesize: $searchFilterResult->data->filesize,
                ratios: $searchFilterResult->data->ratios,
                height: $searchFilterResult->data->height,
                width: $searchFilterResult->data->width,
                albumsFound: $searchFilterResult->data->albumsFound,
                tagsFound: $searchFilterResult->data->tagsFound,
                listDatePosted: $searchFilterResult->data->listDatePosted,
                datePosted: $searchFilterResult->data->datePosted,
                listDateCreated: $searchFilterResult->data->listDateCreated,
                dateCreated: $searchFilterResult->data->dateCreated,
            ));
            $template->assignContext(new SearchFiltersHtmlPageContext($searchFiltersHtml));
        }

        $search_in_set_button = null;
        $search_in_set_action = null;
        $search_in_set_url = null;

        if ($section_context->section === Section::Categories and $section_context->category !== null and $section_context->combinedCategories === null) {
            $search_in_set_button = $this->currentConfig->indexSearchInSetButton;
            $search_in_set_action = $this->currentConfig->indexSearchInSetAction;
            $search_in_set_url = $urlService->getRootUrl() . 'search.php?cat_id=' . (is_numeric($section_context->category['id'] ?? null) ? (int) $section_context->category['id'] : 0);
        }

        $bodyData = $this->pageState->bodyData;
        $related_tags = [];
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
                $this->currentConfig->menubarTagCloudItemsNumber,
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

                $selectedTagNameEvent = $this->eventDispatcher->dispatch(new RenderTagName(is_string($selectedTag['name']) ? $selectedTag['name'] : '', $selectedTag));
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

            $select_related_tags = $selected_related_tags_info;
        }

        $search_in_set_button_tags = null;
        $search_in_set_action_tags = null;
        $search_in_set_url_tags = null;
        $combinable_tags = null;
        $selected_tags_template = new Html('');

        if (isset($bodyData['tag_ids']) and is_array($bodyData['tag_ids'])) {
            $selected_tags_template = $this->renderer->render(new SelectedTagsView($select_related_tags ?? null));

            $body_data_tag_ids = array_values(array_filter($bodyData['tag_ids'], is_scalar(...)));

            $search_in_set_button_tags = $this->currentConfig->indexSearchInSetButton;
            $search_in_set_action_tags = $this->currentConfig->indexSearchInSetAction;
            $search_in_set_url_tags = $urlService->getRootUrl() . 'search.php?tag_id=' . implode(',', $body_data_tag_ids);
            $combinable_tags = $related_tags;
        }

        $u_edit = null;
        if ($section_context->category !== null and $this->accessControl->isAdmin() and $this->currentConfig->indexEditIcon) {
            $u_edit = $urlService->getRootUrl() . 'admin.php?page=album-' . (is_numeric($section_context->category['id'] ?? null) ? (int) $section_context->category['id'] : 0);
        }

        $u_caddie = null;
        if ($this->accessControl->isAdmin() and $page_items !== [] and $this->currentConfig->indexCaddieIcon) {
            $u_caddie = $urlService->addUrlParams($urlService->duplicateIndexUrl(), [
                'caddie' => 1,
            ]);
        }

        $category_search_results = null;
        $no_search_results = null;
        $tag_search_results = [];

        if ($section_context->section === Section::Search and $page_start === 0 and
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
                $category_search_results = $hints;
            }

            $matching_tags = is_array($qsearchDetails['matching_tags'] ?? null) ? array_values(array_filter($qsearchDetails['matching_tags'], is_array(...))) : [];
            /** @var array<int, array<string, mixed>> $matching_tags */
            foreach ($matching_tags as $tag) {
                $tag['URL'] = $urlService->makeIndexUrl([
                    'tags' => [$tag],
                ]);
                $tag_search_results[] = $tag;
            }

            if ($page_items === []) {
                $search_query = $qsearchDetails['q'] ?? null;
                $no_search_results = [htmlspecialchars(is_string($search_query) ? $search_query : '')];
            } else {
                $unmatched_terms = $qsearchDetails['unmatched_terms'] ?? null;
                if (is_array($unmatched_terms) && $unmatched_terms !== []) {
                    /** @var list<string> $unmatched_terms */
                    $unmatched_terms = array_values(array_filter($unmatched_terms, is_string(...)));
                    $no_search_results = array_map(htmlspecialchars(...), $unmatched_terms);
                }
            }
        }

        // image order
        $image_orders = null;
        if ($this->currentConfig->indexSortOrderInput
            and count($page_items) > 0
            and $section_context->section !== Section::MostVisited
            and $section_context->section !== Section::BestRated) {
            $preferred_image_orders = $categoryService->getPreferredImageOrders();
            $order_idx = $this->sessionService->getImageOrder() ?? 0;

            // get first order field and direction -- toSqlBody(), not
            // toSql(): a nullable first field (date_available, ...) renders
            // as a leading "CASE WHEN ... END <dir>" null-ordering
            // discriminant fragment before its own real "<column> <dir>"
            // fragment (see SortRenderer::isNullable()'s own field list),
            // so the first ", "-delimited fragment isn't necessarily the
            // real column -- skip any discriminant fragment(s) to find it.
            $order_by = new SortRenderer($this->entityManager->getConnection())
                ->toSqlBody($this->currentConfig->orderBy);
            $first_order = '';
            foreach (explode(', ', $order_by) as $fragment) {
                if (! str_starts_with($fragment, 'CASE WHEN ')) {
                    $first_order = $fragment;
                    break;
                }
            }

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
                        'SELECTED' => (string) $order_idx === (string) $order_id,
                    ];
                }
            }

            $tpl_orders[0]['SELECTED'] = ! $order_selected; // unselect "Default" if another one is selected
            $image_orders = $tpl_orders;
        }

        // category comment
        $page_comment = $section_context->comment;
        $page_comment_present = $page_comment !== '' && $page_comment !== '0';
        $content_description = null;
        if (($page_start === 0 or $this->currentConfig->albumDescriptionOnAllPages) and $section_context->chronologyField === null and $page_comment_present) {
            $content_description = $section_context->comment;
        }

        if ($section_context->category !== null and $categoryCountCategories === 0) {// count_categories might be computed by menubar - if the case unassign flat link if no sub albums
            $u_mode_flat = null;
        }

        if ($page_start === 0
          and ! $section_context->flat
          and $section_context->chronologyField === null
          and ($section_context->section === Section::RecentCats or $section_context->section === Section::Categories)
          and ($section_context->category === null or $categoryCountCategories === null or $categoryCountCategories > 0)
        ) {
            $categoryCatsResult = $this->categoryCatsRenderer->render($section_context->section, $section_context->category, $section_context->startcat);
            if ($categoryCatsResult !== null) {
                $categoriesHtml = $this->renderer->render(new CategoryCatsView(
                    maxRequests: $categoryCatsResult->maxRequests,
                    categoryThumbnails: $categoryCatsResult->categoryThumbnails,
                    derivativeParams: $categoryCatsResult->derivativeParams,
                    rootUrl: $urlService->getRootUrl(),
                    iconDir: $template->themeConf('icon_dir'),
                ));
                $template->assignContext(new CategoryCatsHtmlPageContext($categoriesHtml));
            }
        }

        $image_derivatives = [];
        $slideshow_url = null;
        if ($page_items !== []) {
            $categoryDefaultResult = $this->categoryDefaultRenderer->render($page_items, $page_start, $page_nb_image_page, $section_context->section);
            $slideshow_url = $categoryDefaultResult->slideshowUrl;

            $thumbnailsHtml = $this->renderer->render(new ThumbnailsView(
                derivativeParams: $categoryDefaultResult->derivativeParams,
                maxRequests: $categoryDefaultResult->maxRequests,
                showThumbnailCaption: $categoryDefaultResult->showThumbnailCaption,
                thumbnails: $categoryDefaultResult->thumbnails,
            ));
            $template->assignContext(new ThumbnailsHtmlPageContext($thumbnailsHtml));

            if ($this->currentConfig->indexSizesIcon) {
                $url = $urlService->addUrlParams(
                    $urlService->duplicateIndexUrl(),
                    [
                        'display' => '',
                    ]
                );

                $selected_type = $categoryDefaultResult->derivativeParams->type;
                $type_map = $this->imageStdParams->getDefinedTypeMap();
                unset($type_map[ImageStdParams::XXLARGE], $type_map[ImageStdParams::XLARGE]);

                foreach ($type_map as $params) {
                    $image_derivatives[] = [
                        'DISPLAY' => $this->lang->t($params->type),
                        'URL' => $url . $params->type,
                        'SELECTED' => $params->type === $selected_type,
                    ];
                }
            }
        }

        // slideshow
        // execute after init thumbs in order to have all picture
        // informations
        $slideshow_url_present = is_string($slideshow_url) && $slideshow_url !== '' && $slideshow_url !== '0';
        $u_slideshow = null;
        if ($slideshow_url_present) {
            if ($galleryDisplay->hasSlideshow) {
                $redirectService->redirect($slideshow_url);
            } elseif ($this->currentConfig->indexSlideshowIcon) {
                $u_slideshow = $slideshow_url;
            }
        }

        // We want all pages that display thumbnails, except on the
        // tags page
        // Fill related tags action
        $body_data_section = $this->pageState->bodyData['section'] ?? null;
        $related_tags_action = null;
        $related_tags_list = null;
        if ($page_items !== [] and $body_data_section !== 'tags') {
            $selection = array_slice($page_items, $page_start, $page_nb_image_page);
            $tags = $tagService->addLevelToTags($tagService->getCommonTags($selection, $this->currentConfig->contentTagCloudItemsNumber, $this->htmlService));
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

            $related_tags_action = $related_tags !== [];
            $related_tags_list = $related_tags;
        }

        new PageHeaderRenderer()
            ->prepareContext($title, $this->eventDispatcher, $this->layoutState, $this->currentTemplate, $this->currentConfig);
        $single_category = $section_context->section === Section::Categories ? $section_context->category : null;
        $single_category_id = is_numeric($single_category['id'] ?? null) ? (int) $single_category['id'] : null;
        $single_category_name = is_string($single_category['name'] ?? null) ? $single_category['name'] : null;
        $single_category_comment = is_string($single_category['comment'] ?? null) ? $single_category['comment'] : null;
        $this->eventDispatcher->dispatch(new IndexRendered(
            categoryId: $single_category_id,
            categoryName: $single_category_name,
            categoryComment: $single_category_comment,
        ));
        $this->htmlService
            ->flushPageMessages();

        $indexView = new IndexView(
            thumbNavbar: $navigationBar,
            title: $template_title,
            nbItems: $nb_items,
            uModeNormal: $u_mode_normal,
            uModeFlat: $u_mode_flat,
            uModeCreated: $u_mode_created,
            uModePosted: $u_mode_posted,
            searchInSetButton: $search_in_set_button_tags ?? $search_in_set_button,
            searchInSetAction: $search_in_set_action_tags ?? $search_in_set_action,
            searchInSetUrl: $search_in_set_url_tags ?? $search_in_set_url,
            combinableTags: $combinable_tags,
            uEdit: $u_edit,
            uCaddie: $u_caddie,
            categorySearchResults: $category_search_results,
            noSearchResults: $no_search_results,
            imageOrders: $image_orders,
            contentDescription: $content_description,
            uSlideshow: $u_slideshow,
            relatedTagsAction: $related_tags_action,
            relatedTags: $related_tags_list,
            tagSearchResults: $tag_search_results,
            imageDerivatives: $image_derivatives,
            selectedTagsTemplate: $selected_tags_template,
            pluginIndexButtons: $template->indexButtons(),
            searchId: $searchFilterResult->data?->searchId,
        );

        $this->historyService
            ->logVisit(
                section: $section_context->section->value,
                category: $section_context->category,
                tagIds: $section_context->tagIds,
                searchId: $resolved_search_id,
            );

        PageTail::prepareContext();

        $html = $this->renderer->render($indexView);
        $body = $template->finalizeHtml((string) $html);

        return ResponseFactory::html($body);
    }
}
