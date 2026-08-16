<?php

declare(strict_types=1);

namespace Piwigo\Section;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Cache\CalendarNavCachePool;
use Piwigo\Cache\SectionImageIdsCachePool;
use Piwigo\Calendar\CalendarRenderer;
use Piwigo\Category\CategoryService;
use Piwigo\Category\Event\RenderCategoryDescription;
use Piwigo\Common\Enum\Section;
use Piwigo\Common\ValueObject\IpAddress;
use Piwigo\Common\ValueObject\TagId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\FilterState;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\PageFilterHelper;
use Piwigo\Core\PageState;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\RequestMountDepth;
use Piwigo\Core\StringHelper;
use Piwigo\Core\TemplateInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Image\ImageStdParams;
use Piwigo\Lang\Translator;
use Piwigo\Permission\PermissionService;
use Piwigo\Permission\SqlCondition;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Search\SearchService;
use Piwigo\Section\Event\SectionInitialized;
use Piwigo\Section\Projection\SectionFavoritePageContext;
use Piwigo\Section\Request\FavoritesActionRequest;
use Piwigo\Session\SessionService;
use Piwigo\Tag\TagService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserService;
use Psr\Cache\CacheItemInterface;

/**
 * Builds $page['items']/$page['title'] and everything else
 * GalleryController/PictureController read off $page for the rest of the
 * request -- per-section (categories/tags/search/favorites/recent_pics/
 * recent_cats/most_visited/best_rated/list) item-id queries, chronology
 * dispatch, meta_robots rules, the permalink-mismatch redirect check, and
 * body_classes/body_data.
 *
 * A sibling to SectionInitializer rather than a second method on it --
 * genuinely different responsibility (URL parsing vs. $page/$template
 * population + real DB queries).
 */
final readonly class SectionPopulator
{
    public function __construct(
        private Lang $lang,
        private AccessLevelChecker $accessLevelChecker,
        private HtmlRenderingInterface $htmlRenderer,
        private TemplateInterface $template,
        private SectionRepository $repo,
        private CategoryService $categoryService,
        private PermissionService $permissionService,
        private TagService $tagService,
        private SearchService $searchService,
        private UserService $userService,
        private RedirectServiceInterface $redirectService,
        private UrlServiceInterface $urlService,
        private FilterState $filterState,
        private CurrentLogger $currentLogger,
        private SectionContextRegistry $sectionContextRegistry,
        private RequestMountDepth $requestMountDepth,
        private SessionService $sessionService,
        private EventDispatcher $eventDispatcher,
        private PageState $pageState,
        private CurrentUser $currentUser,
        private CurrentConfig $currentConfig,
        private Translator $translator,
        private ImageStdParams $imageStdParams,
        private EntityManagerInterface $entityManager,
        private SectionImageIdsCachePool $sectionImageIdsCachePool,
        private CalendarNavCachePool $calendarNavCachePool,
    ) {}

    public function populate(): void
    {
        $logger = $this->currentLogger->get();
        $template = $this->template;

        // $page is a local scratch array for this method's own body only.
        // A SectionContext is built from its final contents and
        // registered at the end.
        /** @var array<string, mixed> */
        $page = [];

        // 'order_by' is progressively narrowed/overridden by several of
        // this method's section-specific branches below and read back by
        // several others -- a single local variable threaded through the
        // whole method, seeded from CurrentConfig::orderBy().
        $order_by = $this->currentConfig->orderBy->toSql();

        $page['items'] = [];
        $page['start'] = $page['startcat'] = 0;

        // The real SectionContext is built and registered at the very end
        // of this method, once $page holds its final values -- no
        // downstream code reads SectionContextRegistry::current()
        // mid-request, so deferring the set() call to the end is safe.
        $url_parse = new SectionInitializer($this->htmlRenderer, $this->repo, $this->redirectService, $this->urlService, $this->requestMountDepth, $this->currentConfig)
            ->parse();

        $page['root_path'] = $url_parse->rootPath;
        $page['section_url'] = $url_parse->sectionUrl;
        if ($url_parse->imageId !== null) {
            $page['image_id'] = $url_parse->imageId;
        }
        if ($url_parse->imageFile !== null) {
            $page['image_file'] = $url_parse->imageFile;
        }
        $tokens = $url_parse->tokens;
        $next_token = $url_parse->nextToken;
        $page = array_merge($page, $url_parse->parsed);

        if (! isset($page['section'])) {
            $page['section'] = Section::Categories;

            switch (PageFilterHelper::scriptBasename($this->currentConfig)) {
                case 'picture':
                    break;
                case 'index':
                    // No section defined, go to random url
                    // CurrentConfig::randomIndexRedirect() is a map of URL => named
                    // condition string (see include/config_default.inc.php); a
                    // malformed local override shouldn't crash this page.
                    // [SEC-15] RandomIndexRedirectResolver avoids
                    // eval()'ing the condition string directly -- a config
                    // value with DB write access could otherwise gain
                    // arbitrary PHP execution.
                    $redirect_candidates = $this->currentConfig->randomIndexRedirect;
                    $next_token_value = $tokens[$next_token] ?? '';
                    $next_token_is_empty = $next_token_value === '' || $next_token_value === '0';
                    if ($redirect_candidates !== [] and $next_token_is_empty) {
                        $random_index_redirect = new RandomIndexRedirectResolver()
                            ->resolveCandidates($this->accessLevelChecker, $redirect_candidates);
                        if ($random_index_redirect !== []) {
                            $this->redirectService->redirect($random_index_redirect[random_int(0, count($random_index_redirect) - 1)]);
                        }
                    }
                    $page['is_homepage'] = true;
                    break;

                default:
                    trigger_error(
                        'script_basename "' . PageFilterHelper::scriptBasename($this->currentConfig) . '" unknown',
                        E_USER_WARNING
                    );
            }
        }

        $page = array_merge($page, $this->urlService->parseWellKnownParamsUrl($tokens, $next_token));

        // $page['section'] is set exactly once, above (or already set by
        // UrlService::parseSectionUrl()/SectionInitializer before this method
        // ran) -- never reassigned again below, so a single snapshot is safe
        // and matches every $page['section'] read for the rest of this
        // method. PHPStan proves the offset always exists at this point now
        // that parseWellKnownParamsUrl()'s return shape is precise (it never
        // sets 'section' -- see that method's own docblock).
        //
        // $page['section'] is always a real Section enum case by this
        // point -- every assignment site (UrlService::parseSectionUrl()'s
        // own 9 literal branches, and this method's own default a few
        // lines above) sets the enum case directly, never a raw string.
        $section = $page['section'] instanceof Section ? $page['section'] : Section::Categories;

        // access a picture only by id, file or id-file without given section
        if (PageFilterHelper::scriptBasename($this->currentConfig) === 'picture' and $section === Section::Categories and
              ! isset($page['category']) and ! isset($page['chronology_field'])) {
            $page['flat'] = true;
        }

        // $page['nb_image_page'] is the number of picture to display on this page
        // By default, it is the same as CurrentUser::get()->rawAttributes['nb_image_page']
        $page['nb_image_page'] = $this->currentUser->get()->rawAttributes['nb_image_page'] ?? null;

        // if flat mode is active, we must consider the image set as a standard set
        // and not as a category set because we can't use the #image_category.rank :
        // displayed images are not directly linked to the displayed category
        if ($section === Section::Categories and ! isset($page['flat'])) {
            $order_by = $this->currentConfig->orderByInsideCategory->toSql();
        }

        $image_order_id = $this->sessionService->getImageOrder() ?? 0;
        if ($image_order_id > 0) {
            $orders = $this->categoryService->getPreferredImageOrders();

            // the current session stored image_order might be not compatible with
            // current image set, for example if the current image_order is the rank
            // and that we are displaying images related to a tag.
            //
            // In case of incompatibility, the session stored image_order is removed.
            if ($orders[$image_order_id][2]) {
                $order_by = str_replace(
                    'ORDER BY ',
                    'ORDER BY ' . $orders[$image_order_id][1] . ',',
                    $order_by
                );
                $page['super_order_by'] = true;
            } else {
                $this->sessionService->unsetSessionVar('image_order');
                $page['super_order_by'] = false;
            }
        }

        $permissionCriteria = $this->permissionService->getPermissionCriteria();
        // visible_images's own old fallthrough into forbidden_images
        // (fieldName 'id' -> the images-table's own level check) -- see
        // PermissionCriteria's own docblock.
        $forbiddenCondition = SqlCondition::combine(
            'AND',
            $permissionCriteria->forbiddenCategoriesCondition('category_id'),
            $permissionCriteria->visibleCategoriesCondition('category_id'),
            $permissionCriteria->visibleImagesCondition('id'),
            $permissionCriteria->maxLevelCondition('level'),
        );

        // most_visited/best_rated's own findTopByHitsImageIds()/
        // findTopRatedImageIds() run as real DQL (their own $order_by is a
        // hardcoded literal below, not CurrentConfig::orderBy()'s genuinely
        // open-ended admin-typed text like every other section here) --
        // this needs the same condition expressed with DQL property paths
        // instead of raw column names. Computed unconditionally alongside
        // $forbidden, same convention this file's own $forbidden already
        // follows.
        $forbiddenConditionDql = SqlCondition::combine(
            'AND',
            $permissionCriteria->forbiddenCategoriesCondition('ic.categoryId'),
            $permissionCriteria->visibleCategoriesCondition('ic.categoryId'),
            $permissionCriteria->visibleImagesCondition('i.id'),
            $permissionCriteria->maxLevelCondition('i.level'),
        );

        // parse_section_url()'s own return type is the generic array<string, mixed>
        // (functions_url.inc.php), so $page['category'] loses the more precise
        // shape that CategoryService::getCategoryInfo() actually returns;
        // re-narrow it once here, unconditionally, so it stays defined (and
        // PHPStan-visible) for every later use in this method, not just inside
        // the 'categories' section branch below.
        $page_category = null;
        if (isset($page['category']) and is_array($page['category'])) {
            // same cross-file $page[...] false-narrowing as the tag_ids block below
            // -- PHPStan infers list<string> for $page['category'] from an
            // unrelated write elsewhere in the codebase, but
            // CategoryService::getCategoryInfo() (this key's real, original
            // source) returns the shape below, which is what every downstream
            // $page_category['...'] read in this method relies on (see the
            // class-level comment above).
            /** @var array{id: int, name: string, id_uppercat: ?int, comment: ?string, dir: ?string, rank: ?int, status: string, site_id: ?int, visible: bool, representative_picture_id: ?int, uppercats: string, commentable: bool, global_rank: ?string, image_order: ?string, permalink: ?string, lastmodified: string, upper_names: list<array{id: int, name: string, permalink: ?string}>} $page_category */
            $page_category = $page['category'];
        }

        if ($section === Section::Categories) {
            if (isset($page['combined_categories'])) {
                /** @var list<array<string, mixed>> $combined_categories_for_title */
                $combined_categories_for_title = is_array($page['combined_categories']) ? array_values(array_filter($page['combined_categories'], is_array(...))) : [];
                $page['title'] = $this->htmlRenderer->getCombinedCategoriesContentTitle($page_category, $combined_categories_for_title);
            } elseif ($page_category !== null) {
                $upper_names = $page_category['upper_names'];

                $descriptionEvent = $this->eventDispatcher->dispatch(new RenderCategoryDescription($page_category['comment'], 'main_page_category_description'));

                $page = array_merge(
                    $page,
                    [
                        'comment' => $descriptionEvent->categoryDescription,
                        'title' => $this->htmlRenderer->getCatDisplayName($upper_names, ''),
                    ]
                );
            } else {
                $page['title'] = ''; // will be set later
            }

            // GET IMAGES LIST
            if (isset($page['combined_categories'])) {
                // combined_categories is only ever set (by parse_section_url() in
                // functions_url.inc.php) after category has already been set
                assert($page_category !== null);
                $cat_ids = [$page_category['id']];
                $combined_categories_raw = is_array($page['combined_categories']) ? $page['combined_categories'] : [];
                foreach ($combined_categories_raw as $category) {
                    $combined_id = is_array($category) ? ($category['id'] ?? null) : null;
                    if (is_numeric($combined_id)) {
                        $cat_ids[] = (int) $combined_id;
                    }
                }

                $page['items'] = $this->categoryService->getImageIdsForCategories($cat_ids);
            } elseif (
                // startcat defaults to 0 above, and may be overwritten by a
                // real 'startcat-N' URL token during parse_well_known_params_url()
                isset($page['startcat']) and
                (is_numeric($page['startcat']) ? (int) $page['startcat'] : 0) === 0 and
                (! isset($page['chronology_field'])) and // otherwise the calendar will requery all subitems
                (
                    ($page_category !== null) or
                    (isset($page['flat']))
                )
            ) {
                if ($page_category !== null) {
                    $image_order_raw = $page_category['image_order'];
                    $image_order_is_set = is_string($image_order_raw) && $image_order_raw !== '' && $image_order_raw !== '0';
                    if ($image_order_is_set and ! isset($page['super_order_by'])) {
                        $order_by = ' ORDER BY ' . $image_order_raw;
                    }
                }

                $where_params = [];
                $where_types = [];
                // flat categories mode
                if (isset($page['flat'])) {
                    // get all allowed sub-categories
                    if ($page_category !== null) {
                        $uppercats = $page_category['uppercats'];
                        $subcatsCriteria = $this->permissionService->getPermissionCriteria();
                        $subcatsCondition = SqlCondition::combine(
                            'AND',
                            $subcatsCriteria->forbiddenCategoriesCondition('c.id'),
                            $subcatsCriteria->visibleCategoriesCondition('c.id'),
                        );
                        $subcat_ids_raw = $this->repo->findVisibleSubcategoryIds($uppercats, $subcatsCondition);
                        $subcat_ids = array_values(array_filter($subcat_ids_raw, is_string(...)));
                        $subcat_ids[] = (string) $page_category['id'];
                        $where_sql = 'category_id IN (:subcatIds)';
                        $where_params['subcatIds'] = $subcat_ids;
                        $where_types['subcatIds'] = ArrayParameterType::STRING;
                        // remove categories from forbidden because just checked above
                        //
                        // visible_images's own old fallthrough into
                        // forbidden_images (fieldName 'id' -> the
                        // images-table's own level check) -- see
                        // PermissionCriteria's own docblock.
                        $flatCriteria = $this->permissionService->getPermissionCriteria();
                        $forbiddenCondition = SqlCondition::combine(
                            'AND',
                            $flatCriteria->visibleImagesCondition('id'),
                            $flatCriteria->maxLevelCondition('level'),
                        );
                    } else {
                        $user = $this->currentUser->get();
                        $user_id_for_cache = $user->id->value;
                        $cache_item = $this->sectionImageIdsCachePool
                            ->getItem('all_iids_' . $user_id_for_cache . '_' . md5($order_by));
                        unset($page['is_homepage']);
                        // Whole-gallery flat mode: no category restriction at
                        // all, so the scope fragment stays empty and the
                        // repository omits it from the WHERE.
                        $where_sql = '';
                    }
                }
                // normal mode
                else {
                    // the enclosing elseif requires isset($page['category']) or
                    // isset($page['flat']); $page['flat'] isn't set in this branch
                    // (see the `if (isset($page['flat']))` above), so category
                    // must be the one that's set
                    assert($page_category !== null);
                    $where_sql = 'category_id = :categoryId';
                    $where_params['categoryId'] = $page_category['id'];
                }

                // $cache_item is only ever assigned in the flat-mode/no-
                // page_category branch above -- may be legitimately unset
                // in every other branch.
                $cache_item ??= null;
                $cached_items = $cache_item?->isHit() === true ? $cache_item->get() : null;

                if (is_array($cached_items)) {
                    /** @var list<string|null> $cached_items */
                    $page['items'] = $cached_items;
                } else {
                    // main query
                    // `SELECT DISTINCT(image_id) ... ORDER BY <col not in
                    // select>` is invalid under ONLY_FULL_GROUP_BY --
                    // Piwigo\Db\DbConnection deliberately doesn't strip that
                    // sql_mode the way the legacy dblayer does (see its own
                    // docblock), so `GROUP BY id` (images' own primary key,
                    // functionally dependent) replaces DISTINCT(image_id)
                    // here, same fix as CalendarRepository::findImageIds()/
                    // SearchService::getQuickSearchResultsNoCache() -- `id`
                    // and `image_id` are equal per the JOIN condition.
                    $page['items'] = $this->repo->findSectionImageIds(new SqlCondition($where_sql, $where_params, $where_types), $forbiddenCondition, $order_by);

                    if ($cache_item instanceof CacheItemInterface) {
                        $cache_item->set($page['items']);
                        $this->sectionImageIdsCachePool->save($cache_item);
                    }
                }
            }
        }
        // special sections
        else {
            if ($section === Section::Tags) {
                // parse_section_url() (functions_url.inc.php) always sets 'tags'
                // alongside 'section' => 'tags'
                assert(isset($page['tags']));
                $tags_raw = is_array($page['tags']) ? $page['tags'] : [];
                $tag_ids = [];
                foreach ($tags_raw as $tag) {
                    // PHPStan proves $tag is always string here, apparently cross-
                    // referencing some unrelated $page['tags'] write elsewhere in
                    // the codebase -- but the real, original (pre-L10) behavior of
                    // *this* code path is find_tags()'s row shape (array with an
                    // 'id' key, per `git log -p` on this file's prior revision),
                    // so this is a real defensive check, not dead code.
                    $tag_id = is_array($tag) ? ($tag['id'] ?? null) : null;
                    if (is_numeric($tag_id)) {
                        // same cross-file $page[...] false-narrowing as above --
                        // PHPStan believes $tag_id is already int here, but the
                        // real, original row shape (see comment above) can yield a
                        // numeric string, so this cast is load-bearing.
                        $tag_ids[] = (int) $tag_id;
                    }
                }
                $page['tag_ids'] = $tag_ids;

                $items = $this->tagService->getImageIdsForTags(array_map(TagId::from(...), $tag_ids));

                if (count($items) === 0) {
                    $remote_addr = IpAddress::fromRemoteAddr()->value ?? '';
                    $logger->info(
                        'attempt to see the name of the tag #' . implode(', #', array_map(strval(...), $tag_ids))
                . ' from the address : ' . $remote_addr
                    );
                    $this->htmlRenderer->accessDenied($this->redirectService);
                }

                /** @var list<array<string, mixed>> $tags_raw */
                $tags_raw = array_values(array_filter($tags_raw, is_array(...)));
                $page = array_merge(
                    $page,
                    [
                        'title' => $this->htmlRenderer->getTagsContentTitle($tags_raw),
                        'items' => $items,
                    ]
                );
            } elseif ($section === Section::Search) {
                // parse_section_url() (functions_url.inc.php) always sets 'search'
                // alongside 'section' => 'search'; 'super_order_by' is genuinely
                // optional (SearchService::getSearchResults()'s 2nd param is ?bool)
                assert(isset($page['search']));
                $search_id = is_string($page['search']) ? $page['search'] : '';
                $search_super_order_by = $page['super_order_by'] ?? null;
                $search_super_order_by = is_bool($search_super_order_by) ? $search_super_order_by : null;
                $search_result = $this->searchService->getSearchResults($search_id, $search_super_order_by);

                // save the details of the query search
                if (isset($search_result['qs'])) {
                    $page['qsearch_details'] = $search_result['qs'];
                } elseif (isset($search_result['search_details'])) {
                    $page['search_details'] = $search_result['search_details'];
                }

                $page = array_merge(
                    $page,
                    [
                        'items' => $search_result['items'],
                        'title' => '<a href="' . $this->urlService->duplicateIndexUrl([
                            'start' => 0,
                        ]) . '">'
                                    . $this->lang->t('Search results') . '</a>',
                    ]
                );
            } elseif ($section === Section::Favorites) {
                $this->userService->checkUserFavorites();

                $page = array_merge(
                    $page,
                    [
                        'title' => '<a href="' . $this->urlService->duplicateIndexUrl([
                            'start' => 0,
                        ]) . '">'
                                    . $this->lang->t('Favorites') . '</a>',
                    ]
                );

                $current_user_id = $this->currentUser->get()
                    ->id;
                if (FavoritesActionRequest::fromGlobals()->removeAllFromFavorites) {
                    $this->userService->removeAllFavorites($current_user_id);
                    $this->redirectService->redirect($this->urlService->makeIndexUrl([
                        'section' => 'favorites',
                    ]));
                } else {
                    $page = array_merge(
                        $page,
                        [
                            'items' => $this->userService->getVisibleFavoriteImageIds(
                                $current_user_id,
                                $this->permissionService->getPermissionCriteria(),
                                $order_by
                            ),
                        ]
                    );

                    if (count($page['items']) > 0) {
                        $template->assignContext(new SectionFavoritePageContext(
                            removeAllUrl: $this->urlService->addUrlParams(
                                $this->urlService->makeIndexUrl([
                                    'section' => 'favorites',
                                ]),
                                [
                                    'action' => 'remove_all_from_favorites',
                                ]
                            ),
                        ));
                    }
                }
            } elseif ($section === Section::RecentPics) {
                if (! isset($page['super_order_by'])) {
                    $order_by = str_replace(
                        'ORDER BY ',
                        'ORDER BY date_available DESC,',
                        $order_by
                    );
                }

                // GROUP BY id (images' own primary key), not DISTINCT --
                // same ONLY_FULL_GROUP_BY fix as the categories-section
                // query above; $order_by orders by date_available, images'
                // own column, so `id` is a full functional-dependency key
                // for it.
                $recentCondition = $this->userService->getRecentPhotosCondition('date_available');
                $page = array_merge(
                    $page,
                    [
                        'title' => '<a href="' . $this->urlService->duplicateIndexUrl([
                            'start' => 0,
                        ]) . '">'
                                    . $this->lang->t('Recent photos') . '</a>',
                        'items' => $this->repo->findRecentImageIds($recentCondition, $forbiddenCondition, $order_by),
                    ]
                );
            } elseif ($section === Section::RecentCats) {
                $page = array_merge(
                    $page,
                    [
                        'title' => '<a href="' . $this->urlService->duplicateIndexUrl([
                            'start' => 0,
                        ]) . '">'
                                    . $this->lang->t('Recent albums') . '</a>',
                    ]
                );
            } elseif ($section === Section::MostVisited) {
                $page['super_order_by'] = true;

                $top_number = $this->currentConfig->topNumber;

                $page = array_merge(
                    $page,
                    [
                        'title' => '<a href="' . $this->urlService->duplicateIndexUrl([
                            'start' => 0,
                        ]) . '">'
                                    . $top_number . ' ' . $this->lang->t('Most visited') . '</a>',
                        'items' => $this->repo->findTopByHitsImageIds($forbiddenConditionDql, $top_number),
                    ]
                );
            } elseif ($section === Section::BestRated) {
                $page['super_order_by'] = true;

                $top_number = $this->currentConfig->topNumber;

                $page = array_merge(
                    $page,
                    [
                        'title' => '<a href="' . $this->urlService->duplicateIndexUrl([
                            'start' => 0,
                        ]) . '">'
                                    . $top_number . ' ' . $this->lang->t('Best rated') . '</a>',
                        'items' => $this->repo->findTopRatedImageIds($forbiddenConditionDql, $top_number),
                    ]
                );
            } elseif ($section === Section::ListView) {
                // parse_section_url() (functions_url.inc.php) always sets 'list'
                // (a dummy [Db\NoMatchSentinel::ID] or a real id list)
                // alongside 'section' => 'list'
                assert(isset($page['list']));
                $list_ids_raw = is_array($page['list']) ? array_filter($page['list'], is_scalar(...)) : [];
                $list_ids = array_values(array_map(strval(...), $list_ids_raw));
                // GROUP BY id, same ONLY_FULL_GROUP_BY fix as above --
                // $order_by orders by images' own columns.
                $page = array_merge(
                    $page,
                    [
                        'title' => '<a href="' . $this->urlService->duplicateIndexUrl([
                            'start' => 0,
                        ]) . '">'
                                    . $this->lang->t('Random photos') . '</a>',
                        'items' => $this->repo->findImageIdsAmongList($list_ids, $forbiddenCondition, $order_by),
                    ]
                );
            }
        }

        if (isset($page['chronology_field'])) {
            unset($page['is_homepage']);

            // parseWellKnownParamsUrl()'s return shape guarantees
            // chronology_field is a real string whenever set (the
            // enclosing isset() above already proved it's set).
            $calendar_field = $page['chronology_field'];
            $calendar_style = is_string($page['chronology_style'] ?? null) ? $page['chronology_style'] : null;
            $calendar_view = is_string($page['chronology_view'] ?? null) ? $page['chronology_view'] : null;
            // parseWellKnownParamsUrl()'s return shape guarantees
            // chronology_date is already a real list<string> whenever set --
            // unlike $page['items'] below, no further element filtering is
            // needed.
            $calendar_date = is_array($page['chronology_date'] ?? null) ? $page['chronology_date'] : [];
            $calendar_items_raw = is_array($page['items']) ? $page['items'] : [];
            $calendar_items = array_values(array_filter($calendar_items_raw, static fn (mixed $v): bool => is_int($v) || is_string($v)));

            $calendar_result = new CalendarRenderer($this->lang, $this->htmlRenderer, $this->template, $this->urlService, $this->currentUser, $this->currentConfig, $this->eventDispatcher, $this->translator, $this->imageStdParams, $this->pageState, $this->permissionService, $this->entityManager, $this->calendarNavCachePool)
                ->render(
                    $section,
                    $page_category,
                    $calendar_items,
                    $calendar_field,
                    $calendar_style,
                    $calendar_view,
                    $calendar_date,
                    isset($page['super_order_by']),
                );

            $page['items'] = $calendar_result->items;
            $page['comment'] = $calendar_result->comment;
            $page['chronology_date'] = $calendar_result->chronologyDate;
            $page['chronology_style'] = $calendar_result->chronologyStyle;
            $page['chronology_view'] = $calendar_result->chronologyView;
        }

        // title update
        //
        // Every real $section branch above unconditionally sets
        // $page['title'] to a real string, so PHPStan proves it's always
        // set by this point.
        $gallery_home_url = $this->urlService->getGalleryHomeUrl();
        $page['section_title'] = '<a href="' . $gallery_home_url . '">' . $this->lang->t('Home') . '</a>';
        $title_value = $page['title'];
        if ($title_value !== '' && $title_value !== '0') {
            $level_separator = $this->currentConfig->levelSeparator;
            $page['section_title'] .= $level_separator . $title_value;
        } else {
            $page['title'] = $page['section_title'];
        }

        // add meta robots noindex, nofollow to avoid unnecesary robot crawls.
        // A not-yet-initialized FilterState is treated as disabled.
        $filter_enabled = $this->filterState->isInitialized() && $this->filterState->isEnabled();
        $this->pageState->setMetaRobots(self::computeMetaRobots($page, $filter_enabled));

        // see if we need a redirect because of a permalink
        if ($section === Section::Categories and $page_category !== null and ! isset($page['combined_categories'])) {
            $hit_by = is_array($page['hit_by'] ?? null) ? $page['hit_by'] : [];
            $hit_by_cat_url_name = $hit_by['cat_url_name'] ?? null;
            $hit_by_cat_url_name = is_string($hit_by_cat_url_name) ? $hit_by_cat_url_name : null;
            $hit_by_cat_permalink = $hit_by['cat_permalink'] ?? null;
            $hit_by_cat_permalink = is_string($hit_by_cat_permalink) ? $hit_by_cat_permalink : null;
            $category_url_style = $this->currentConfig->categoryUrlStyle;
            $category_permalink = is_string($page_category['permalink']) ? $page_category['permalink'] : null;
            $category_name = $page_category['name'];
            $expected_cat_url_name = StringHelper::str2url($category_name);

            if (self::needsPermalinkRedirect($category_permalink, $category_url_style, $hit_by_cat_url_name, $hit_by_cat_permalink, $expected_cat_url_name)) {
                $this->categoryService->checkRestrictions($page_category['id'], $this->htmlRenderer, $this->redirectService, $this->currentUser);
                // duplicateIndexUrl()/duplicatePictureUrl() read the
                // current section's params from SectionContextRegistry,
                // which this method otherwise only populates at its very
                // end (buildSectionContext($page), below) -- too late for
                // this early-exit redirect. $page['category'] is already
                // set by this point (see $page_category's own assignment
                // above), and buildSectionContext() is fully defensive for
                // every field it doesn't have yet, so registering here is
                // safe; the later, fully-populated call for the normal
                // (non-redirect) path simply overwrites it afterward.
                $this->sectionContextRegistry->set(self::buildSectionContext($page));
                $redirect_url = PageFilterHelper::scriptBasename($this->currentConfig) === 'picture' ? $this->urlService->duplicatePictureUrl() : $this->urlService->duplicateIndexUrl();

                if (! headers_sent()) { // this is a permanent redirection
                    $this->htmlRenderer->setStatusHeader(301);
                    $this->redirectService->redirectHttp($redirect_url, 301);
                }
                $this->redirectService->redirect($redirect_url);
            }
            unset($page['hit_by']);
        }

        $body_classes = [];
        $body_data = [];

        array_push($body_classes, 'section-' . $section->value);
        $body_data['section'] = $section->value;

        if ($section === Section::Categories && $page_category !== null) {
            $body_category_id = (string) $page_category['id'];
            array_push($body_classes, 'category-' . $body_category_id);
            $body_data['category_id'] = $body_category_id;

            if (isset($page['combined_categories'])) {
                $combined_category_ids = [];
                $combined_categories_for_body = is_array($page['combined_categories']) ? $page['combined_categories'] : [];
                foreach ($combined_categories_for_body as $combined_category) {
                    $combined_body_id = is_array($combined_category) ? ($combined_category['id'] ?? null) : null;
                    $combined_body_id = is_scalar($combined_body_id) ? (string) $combined_body_id : '';
                    array_push($body_classes, 'category-' . $combined_body_id);
                    $combined_category_ids[] = $combined_body_id;
                }
                $body_data['combined_category_ids'] = $combined_category_ids;
            }
        } elseif (isset($page['tags'])) {
            $body_tag_ids = [];
            $tags_for_body = is_array($page['tags']) ? $page['tags'] : [];
            foreach ($tags_for_body as $tag) {
                $body_tag_id = is_array($tag) ? ($tag['id'] ?? null) : null;
                $body_tag_id = is_scalar($body_tag_id) ? (string) $body_tag_id : '';
                array_push($body_classes, 'tag-' . $body_tag_id);
                $body_tag_ids[] = $body_tag_id;
            }
            $body_data['tag_ids'] = $body_tag_ids;
        } elseif (isset($page['search'])) {
            $body_search_id = is_scalar($page['search']) ? (string) $page['search'] : '';
            array_push($body_classes, 'search-' . $body_search_id);
            $body_data['search_id'] = $body_search_id;
        }

        if (isset($page['image_id'])) {
            $body_image_id = is_scalar($page['image_id']) ? (string) $page['image_id'] : '';
            array_push($body_classes, 'image-' . $body_image_id);
            $body_data['image_id'] = $body_image_id;
        }

        $this->pageState->bodyClasses = $body_classes;
        $this->pageState->bodyData = $body_data;

        $this->sectionContextRegistry->set(self::buildSectionContext($page));

        $this->eventDispatcher->dispatch(new SectionInitialized());
    }

    /**
     * Converts this method's own finished $page scratch array into the
     * typed SectionContext value object distributed via
     * SectionContextRegistry -- extracted as its own pure static method
     * for the same reason as computeMetaRobots()/needsPermalinkRedirect()
     * below (directly Unit-testable, no free-function/global dependency).
     *
     * @param  array<string, mixed>  $page
     */
    private static function buildSectionContext(array $page): SectionContext
    {
        $section = $page['section'] ?? null;
        $section = $section instanceof Section ? $section : Section::Categories;

        $category = null;
        if (isset($page['category']) and is_array($page['category'])) {
            // Rows here always come from get_cat_info()'s own array<string, mixed>
            // shape (see the identical $page_category narrowing earlier in
            // populate()); array_filter(..., is_array(...)) below can only prove
            // "is an array", not the string-keyed row shape, hence the same
            // @var override this file already uses for $upper_names above.
            /** @var array<string, mixed> $category */
            $category = $page['category'];
        }

        $combinedCategories = null;
        if (is_array($page['combined_categories'] ?? null)) {
            /** @var list<array<string, mixed>> $combinedCategories */
            $combinedCategories = array_values(array_filter($page['combined_categories'], is_array(...)));
        }

        $items = is_array($page['items'] ?? null) ? array_values(array_filter($page['items'], static fn (mixed $v): bool => is_int($v) || is_string($v))) : [];
        /** @var list<array<string, mixed>> $tags */
        $tags = is_array($page['tags'] ?? null) ? array_values(array_filter($page['tags'], is_array(...))) : [];
        $tagIds = is_array($page['tag_ids'] ?? null) ? array_values(array_map(intval(...), array_filter($page['tag_ids'], is_numeric(...)))) : [];
        $list = is_array($page['list'] ?? null) ? array_values(array_filter($page['list'], static fn (mixed $v): bool => is_int($v) || is_string($v))) : [];
        $chronologyDate = is_array($page['chronology_date'] ?? null) ? array_values(array_filter($page['chronology_date'], static fn (mixed $v): bool => is_int($v) || is_string($v))) : [];

        $imageId = $page['image_id'] ?? null;
        $imageId = is_int($imageId) || is_string($imageId) ? $imageId : null;

        /** @var array<string, mixed> $searchDetails */
        $searchDetails = is_array($page['search_details'] ?? null) ? $page['search_details'] : [];
        /** @var array<string, mixed> $qsearchDetails */
        $qsearchDetails = is_array($page['qsearch_details'] ?? null) ? $page['qsearch_details'] : [];

        return new SectionContext(
            section: $section,
            sectionUrl: is_string($page['section_url'] ?? null) ? $page['section_url'] : '',
            rootPath: is_string($page['root_path'] ?? null) ? $page['root_path'] : '',
            imageId: $imageId,
            imageFile: is_string($page['image_file'] ?? null) ? $page['image_file'] : null,
            items: $items,
            start: is_numeric($page['start'] ?? null) ? (int) $page['start'] : 0,
            startcat: is_numeric($page['startcat'] ?? null) ? (int) $page['startcat'] : 0,
            nbImagePage: is_numeric($page['nb_image_page'] ?? null) ? (int) $page['nb_image_page'] : 0,
            flat: isset($page['flat']),
            isHomepage: isset($page['is_homepage']),
            superOrderBy: isset($page['super_order_by']),
            category: $category,
            combinedCategories: $combinedCategories,
            tags: $tags,
            tagIds: $tagIds,
            list: $list,
            search: is_string($page['search'] ?? null) ? $page['search'] : null,
            searchDetails: $searchDetails,
            qsearchDetails: $qsearchDetails,
            chronologyDate: $chronologyDate,
            chronologyField: is_string($page['chronology_field'] ?? null) ? $page['chronology_field'] : null,
            chronologyView: is_string($page['chronology_view'] ?? null) ? $page['chronology_view'] : null,
            chronologyStyle: is_string($page['chronology_style'] ?? null) ? $page['chronology_style'] : null,
            title: is_string($page['title'] ?? null) ? $page['title'] : '',
            comment: is_string($page['comment'] ?? null) ? $page['comment'] : '',
            sectionTitle: is_string($page['section_title'] ?? null) ? $page['section_title'] : '',
        );
    }

    /**
     * The noindex/nofollow decision, as a pure function.
     *
     * @param  array<string, mixed>  $page
     * @return array<string, int>
     */
    public static function computeMetaRobots(array $page, bool $filterEnabled): array
    {
        $meta_robots = [];
        $section = $page['section'] ?? null;
        $section = $section instanceof Section ? $section : null;

        if (
            isset($page['chronology_field'])
            or (isset($page['flat']) and isset($page['category']))
            or $section === Section::ListView or $section === Section::RecentPics
        ) {
            $meta_robots = [
                'noindex' => 1,
                'nofollow' => 1,
            ];
        } elseif ($section === Section::Tags) {
            $page_tag_ids = $page['tag_ids'] ?? null;
            if (is_countable($page_tag_ids) and count($page_tag_ids) > 1) {
                $meta_robots = [
                    'noindex' => 1,
                    'nofollow' => 1,
                ];
            }
        } elseif ($section === Section::RecentCats) {
            $meta_robots['noindex'] = 1;
        } elseif ($section === Section::Search) {
            $meta_robots = [
                'noindex' => 1,
                'nofollow' => 1,
            ];
        } elseif ($section === Section::Categories and isset($page['combined_categories'])) {
            $meta_robots = [
                'noindex' => 1,
                'nofollow' => 1,
            ];
        }

        if ($filterEnabled) {
            $meta_robots['noindex'] = 1;
        }

        return $meta_robots;
    }

    /**
     * The permalink-mismatch redirect decision, as a pure function. Does
     * not itself redirect; the caller owns the real
     * checkRestrictions()/redirect()/redirectHttp() calls.
     *
     * $expectedCatUrlName is str2url($category['name']), pre-computed by
     * the caller rather than by this method, since str2url() is a global
     * free function this method avoids calling directly.
     */
    public static function needsPermalinkRedirect(
        ?string $categoryPermalink,
        string $categoryUrlStyle,
        ?string $hitByCatUrlName,
        ?string $hitByCatPermalink,
        ?string $expectedCatUrlName,
    ): bool {
        if (self::emptyValue($categoryPermalink)) {
            return $categoryUrlStyle === 'id-name' and $hitByCatUrlName !== $expectedCatUrlName;
        }

        return $categoryPermalink !== $hitByCatPermalink;
    }

    /**
     * Matches empty()'s exact truthiness semantics -- required since
     * empty() itself is disallowed by this project's strict PHPStan rules.
     * Same helper as Piwigo\Section\SectionInitializer::emptyValue() (kept
     * as its own private copy rather than shared, matching this codebase's
     * per-class-small-helper convention).
     */
    private static function emptyValue(mixed $value): bool
    {
        return $value === null || $value === '' || $value === 0 || $value === 0.0 || $value === '0' || $value === false || $value === [];
    }
}
