<?php

declare(strict_types=1);

namespace Piwigo\Section;

use Doctrine\DBAL\Connection;
use Piwigo\Calendar\CalendarService;
use Piwigo\Category\CategoryService;
use Piwigo\Config\Config;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Lang;
use Piwigo\Core\LoggerRegistry;
use Piwigo\Core\PageState;
use Piwigo\Core\StringUtil;
use Piwigo\Db\Tables;
use Piwigo\Event\Location\LocEndSectionInit;
use Piwigo\Event\Template\RenderCategoryDescription;
use Piwigo\Filter\FilterContextRegistry;
use Piwigo\Html\HtmlService;
use Piwigo\Http\RedirectResponder;
use Piwigo\Search\SearchService;
use Piwigo\Session\SessionService;
use Piwigo\Tag\TagService;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;
use Psr\Cache\CacheItemPoolInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Resolves the gallery section from the request URL and builds the
 * SectionContext value object with items, title, section, meta_robots,
 * body_classes, etc.
 *
 * The $scriptContext parameter ('index' or 'picture') distinguishes between
 * the gallery and single-image pages.
 *
 * SearchService, CalendarService and UrlService own their own per-request
 * state; this orchestrator passes inputs in and reads results back through
 * each service's typed getters rather than via a shared mutable array.
 */
final readonly class SectionInitializer
{
    public function __construct(
        private Connection $conn,
        private CalendarService $calendarService,
        private CategoryService $categoryService,
        private HtmlService $htmlService,
        private PermissionService $permissionService,
        private SearchService $searchService,
        private SessionService $sessionService,
        private TagService $tagService,
        private UrlService $urlService,
        private UserRepository $userRepository,
        private UserService $userService,
        private RedirectResponder $redirectResponder,
        private CacheItemPoolInterface $pool,
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    public function initialize(ServerRequestInterface $request, string $scriptContext = 'index'): void
    {
        $page = [];
        /** @var array<string,mixed> $user */
        $user = CurrentUser::get()->rawAttributes;

        $page['items']    = [];
        $page['start']    = 0;
        $page['startcat'] = 0;

        // ── URL path → $rewritten + $page['root_path'] ───────────────────────

        $routePath = is_string($request->getAttribute('_route_path')) ? $request->getAttribute('_route_path') : '/';

        $rewritten         = $routePath;
        $page['root_path'] = PHPWG_ROOT_PATH;

        /** @psalm-var string $rootPathStr */
        $rootPathStr = $page['root_path'];
        if (str_starts_with($rootPathStr, './')) {
            $page['root_path'] = substr($rootPathStr, 2);
        }

        $page['section_url'] = $rewritten;
        $tokens    = explode('/', ltrim((string) $rewritten, '/'));
        $nextToken = 0;

        // ── Picture page: first token is the image identifier ─────────────────

        if ($scriptContext === 'picture') {
            $token = $tokens[$nextToken];
            $nextToken++;
            if (is_numeric($token)) {
                $page['image_id'] = $token;
                if ((int) $token === 0) {
                    $this->htmlService->badRequest('invalid picture identifier');
                }
            } else {
                preg_match('/^(\d+-)?(.*)?$/', $token, $matches);
                if (isset($matches[1]) && is_numeric($matches[1] = rtrim($matches[1], '-'))) {
                    $page['image_id'] = $matches[1];
                    if (isset($matches[2]) && $matches[2] !== '') {
                        $page['image_file'] = $matches[2];
                    }
                } else {
                    $page['image_id'] = 0;
                    if (isset($matches[2]) && $matches[2] !== '') {
                        $page['image_file'] = $matches[2];
                    } else {
                        $this->htmlService->badRequest('picture identifier is missing');
                    }
                }
            }
        }

        // ── Parse section + well-known params from URL tokens ─────────────────

        $urls = $this->urlService;
        $page = array_merge($page, $urls->parseSectionUrl($tokens, $nextToken));

        if (!isset($page['section'])) {
            $page['section'] = 'categories';

            if ($scriptContext === 'index') {
                if (!empty(Config::randomIndexRedirect()) && (!isset($tokens[$nextToken]) || $tokens[$nextToken] === '')) {
                    $randomOptions = [];
                    foreach (Config::randomIndexRedirect() as $randomUrl => $randomCondition) {
                        if ($randomCondition === '' || eval($randomCondition)) {
                            $randomOptions[] = $randomUrl;
                        }
                    }
                    if (!empty($randomOptions)) {
                        $this->redirectResponder->redirect($randomOptions[mt_rand(0, count($randomOptions) - 1)]);
                    }
                }
                $page['is_homepage'] = true;
            }
        }

        $page = array_merge($page, $urls->parseWellKnownParamsUrl($tokens, $nextToken));

        if ($scriptContext === 'picture'
            && 'categories' === ($page['section'] ?? '')
            && !isset($page['category'])
            && !isset($page['chronology_field'])) {
            $page['flat'] = true;
        }

        // ── Extract typed locals now that $page is fully populated ───────────

        $pageSectionRaw = $page['section'] ?? null;
        $section   = is_string($pageSectionRaw) ? $pageSectionRaw : 'categories';
        $pageCategoryRaw = $page['category'] ?? null;
        $category  = is_array($pageCategoryRaw) ? $pageCategoryRaw : null;
        $pageTagsRaw = $page['tags'] ?? null;
        $pageTags  = is_array($pageTagsRaw) ? $pageTagsRaw : [];
        $pageListRaw = $page['list'] ?? null;
        $pageList  = is_array($pageListRaw) ? $pageListRaw : [];
        $startcatRaw = $page['startcat'] ?? null;
        $startcat  = is_numeric($startcatRaw) ? (int) $startcatRaw : 0;

        $page['nb_image_page'] = is_numeric($user['nb_image_page'] ?? null)
            ? (int) $user['nb_image_page']
            : 0;

        if ($section === 'categories' && !isset($page['flat'])) {
            Config::override('order_by', Config::orderByInsideCategory());
        }

        $imageOrderRaw = $this->sessionService->getSessionVar('image_order', 0);
        if (is_numeric($imageOrderRaw) && (int) $imageOrderRaw > 0) {
            $orders          = $this->categoryService->getCategoryPreferredImageOrders();
            $imageOrderIdInt = (int) $imageOrderRaw;
            $ordersEntry = $orders[$imageOrderIdInt] ?? null;
            $orderEntry  = is_array($ordersEntry) ? $ordersEntry : [];
            $orderEntry2 = $orderEntry[2] ?? null;
            if ($orderEntry2 !== null && $orderEntry2 !== false && $orderEntry2 !== '' && $orderEntry2 !== 0) {
                $orderCol = is_scalar($orderEntry[1] ?? null) ? (string) $orderEntry[1] : '';
                Config::override('order_by', str_replace(
                    'ORDER BY ',
                    'ORDER BY ' . $orderCol . ',',
                    Config::orderBy()
                ));
                $page['super_order_by'] = true;
            } else {
                $this->sessionService->unsetSessionVar('image_order');
                $page['super_order_by'] = false;
            }
        }

        $forbidden = $this->permissionService->getSqlConditionFandF(
            [
                'forbidden_categories' => 'category_id',
                'visible_categories'   => 'category_id',
                'visible_images'       => 'id',
            ],
            'AND'
        );

        // ── Categories ────────────────────────────────────────────────────────

        if ($section === 'categories') {
            if (isset($page['combined_categories'])) {
                $page['title'] = $this->htmlService->getCombinedCategoriesContentTitle();
            } elseif ($category !== null) {
                $catComment   = is_string($category['comment'] ?? null) ? $category['comment'] : '';
                $upperNames   = is_array($category['upper_names'] ?? null) ? $category['upper_names'] : [];
                $catDescEvent = new RenderCategoryDescription($catComment, 'main_page_category_description');
                $this->dispatcher->dispatch($catDescEvent);
                $page = array_merge($page, [
                    'comment' => $catDescEvent->categoryDescription,
                    'title'   => $this->htmlService->getCatDisplayName($upperNames, ''),
                ]);
            } else {
                $page['title'] = '';
            }

            $combinedCategories = is_array($page['combined_categories'] ?? null) ? $page['combined_categories'] : null;

            if ($combinedCategories !== null) {
                $catIds = [];
                if ($category !== null && is_numeric($category['id'] ?? null)) {
                    $catIds[] = (int) $category['id'];
                }
                foreach ($combinedCategories as $combinedCat) {
                    if (is_array($combinedCat) && is_numeric($combinedCat['id'] ?? null)) {
                        $catIds[] = (int) $combinedCat['id'];
                    }
                }
                $page['items'] = $this->categoryService->getImageIdsForCategories($catIds);
            } elseif (
                $startcat === 0
                && !isset($page['chronology_field'])
                && ($category !== null || isset($page['flat']))
            ) {
                $catImageOrder = $category !== null && is_scalar($category['image_order'] ?? null)
                    ? (string) $category['image_order']
                    : '';
                if ($catImageOrder !== '' && !isset($page['super_order_by'])) {
                    Config::override('order_by', ' ORDER BY ' . $catImageOrder);
                }

                if (isset($page['flat'])) {
                    if ($category !== null) {
                        $catUppercats = is_scalar($category['uppercats'] ?? null) ? (string) $category['uppercats'] : '';
                        $catId        = is_scalar($category['id'] ?? null) ? (string) $category['id'] : '0';
                        $query = '
SELECT id
  FROM ' . Tables::categories() . '
  WHERE
    uppercats LIKE \'' . $catUppercats . ',%\' '
                            . $this->permissionService->getSqlConditionFandF(
                                ['forbidden_categories' => 'id', 'visible_categories' => 'id'],
                                "\n  AND"
                            );
                        $subcatIds   = array_column($this->conn->executeQuery($query)->fetchAllAssociative(), 'id');
                        $subcatIds[] = $catId;
                        $subcatIdsStr = array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $subcatIds);
                        $whereSql    = 'category_id IN (' . implode(',', $subcatIdsStr) . ')';
                        $forbidden   = $this->permissionService->getSqlConditionFandF(['visible_images' => 'id'], 'AND');
                    } else {
                        $userId    = is_scalar($user['id'] ?? null) ? (string) $user['id'] : '0';
                        $cacheTime = is_scalar($user['cache_update_time'] ?? null) ? (string) $user['cache_update_time'] : '';
                        $cacheKey  = md5('all_iids' . $userId . $cacheTime . Config::orderBy() . AppInfo::VERSION);
                        unset($page['is_homepage']);
                        $whereSql = '1=1';
                    }
                } else {
                    $catId    = $category !== null && is_scalar($category['id'] ?? null) ? (string) $category['id'] : '0';
                    $whereSql = 'category_id = ' . $catId;
                }

                $cacheItem = isset($cacheKey) ? $this->pool->getItem($cacheKey) : null;
                if ($cacheItem !== null && $cacheItem->isHit()) {
                    $cachedItems   = $cacheItem->get();
                    $page['items'] = is_array($cachedItems) ? $cachedItems : [];
                } else {
                    $query = '
SELECT DISTINCT(image_id)
  FROM ' . Tables::imageCategory() . '
    INNER JOIN ' . Tables::images() . ' ON id = image_id
  WHERE
    ' . $whereSql . '
' . $forbidden . '
  ' . Config::orderBy() . '
;';
                    $page['items'] = array_column($this->conn->executeQuery($query)->fetchAllAssociative(), 'image_id');
                    if ($cacheItem !== null) {
                        $cacheItem->set($page['items']);
                        $cacheItem->expiresAfter(86400);
                        $this->pool->save($cacheItem);
                    }
                }
            }
        }

        // ── Special sections ──────────────────────────────────────────────────

        elseif ($section === 'tags') {
            $tagIds = [];
            foreach ($pageTags as $tag) {
                if (!is_array($tag)) {
                    continue;
                }
                $tagId    = $tag['id'] ?? null;
                $tagIds[] = is_numeric($tagId) ? (int) $tagId : 0;
            }
            $page['tag_ids'] = $tagIds;

            $items = $this->tagService->getImageIdsForTags($tagIds);
            if (count($items) === 0) {
                /** @var mixed $remoteAddrInfo */
                $remoteAddrInfo = $_SERVER['REMOTE_ADDR'] ?? '';
                LoggerRegistry::current()->info(
                    'attempt to see the name of the tag #' . implode(', #', $tagIds)
                    . ' from the address : '
                    . (is_string($remoteAddrInfo) ? $remoteAddrInfo : '')
                );
                $this->htmlService->accessDenied();
            }
            $page = array_merge($page, [
                'title' => $this->htmlService->getTagsContentTitle(),
                'items' => $items,
            ]);
        } elseif ($section === 'search') {
            $superOrderBy = isset($page['super_order_by']) && (bool) $page['super_order_by'];
            $pageSearchRaw = $page['search'] ?? null;
            $pageSearch   = is_scalar($pageSearchRaw) ? (string) $pageSearchRaw : '';
            $searchResult = $this->searchService->getSearchResults($pageSearch, $superOrderBy);
            if (isset($searchResult['qs'])) {
                $page['qsearch_details'] = $searchResult['qs'];
            } elseif (isset($searchResult['search_details'])) {
                $page['search_details'] = $searchResult['search_details'];
            }
            $page = array_merge($page, [
                'items' => $searchResult['items'],
                'title' => '<a href="' . $this->urlService->duplicateIndexUrl(['start' => 0]) . '">' . Lang::t('Search results') . '</a>',
            ]);
        } elseif ($section === 'favorites') {
            $this->userService->checkUserFavorites();
            $page = array_merge($page, [
                'title' => '<a href="' . $this->urlService->duplicateIndexUrl(['start' => 0]) . '">' . Lang::t('Favorites') . '</a>',
            ]);
            $action = $_GET['action'] ?? '';
            if ($action === 'remove_all_from_favorites') {
                $userId = is_numeric($user['id'] ?? null) ? (int) $user['id'] : 0;
                $this->userRepository->deleteAllFavoritesByUserId($userId);
                $this->redirectResponder->redirect($this->urlService->makeIndexUrl(['section' => 'favorites']));
            } else {
                $userId = is_scalar($user['id'] ?? null) ? (string) $user['id'] : '0';
                $query  = '
SELECT image_id
  FROM ' . Tables::favorites() . '
    INNER JOIN ' . Tables::images() . ' ON image_id = id
  WHERE user_id = ' . $userId . '
' . $this->permissionService->getSqlConditionFandF(['visible_images' => 'id'], 'AND') . '
  ' . Config::orderBy() . '
;';
                $page = array_merge($page, [
                    'items' => array_column($this->conn->executeQuery($query)->fetchAllAssociative(), 'image_id'),
                ]);
                if (count($page['items']) > 0) {
                    TemplateRegistry::current()->assign('favorite', [
                        'U_FAVORITE' => $this->urlService->addUrlParams(
                            $this->urlService->makeIndexUrl(['section' => 'favorites']),
                            ['action' => 'remove_all_from_favorites']
                        ),
                    ]);
                }
            }
        } elseif ($section === 'recent_pics') {
            if (!isset($page['super_order_by'])) {
                Config::override('order_by', str_replace(
                    'ORDER BY ',
                    'ORDER BY date_available DESC,',
                    Config::orderBy()
                ));
            }
            $query = '
SELECT DISTINCT(id)
  FROM ' . Tables::images() . '
    INNER JOIN ' . Tables::imageCategory() . ' AS ic ON id = ic.image_id
  WHERE ' . $this->permissionService->getRecentPhotosSql('date_available') . '
  ' . $forbidden . Config::orderBy() . '
;';
            $page = array_merge($page, [
                'title' => '<a href="' . $this->urlService->duplicateIndexUrl(['start' => 0]) . '">' . Lang::t('Recent photos') . '</a>',
                'items' => array_column($this->conn->executeQuery($query)->fetchAllAssociative(), 'id'),
            ]);
        } elseif ($section === 'recent_cats') {
            $page = array_merge($page, [
                'title' => '<a href="' . $this->urlService->duplicateIndexUrl(['start' => 0]) . '">' . Lang::t('Recent albums') . '</a>',
            ]);
        } elseif ($section === 'most_visited') {
            $page['super_order_by'] = true;
            Config::override('order_by', ' ORDER BY hit DESC, id DESC');
            $query = '
SELECT DISTINCT(id)
  FROM ' . Tables::images() . '
    INNER JOIN ' . Tables::imageCategory() . ' AS ic ON id = ic.image_id
  WHERE hit > 0
    ' . $forbidden . '
    ' . Config::orderBy() . '
  LIMIT ' . Config::topNumber() . '
;';
            $page = array_merge($page, [
                'title' => '<a href="' . $this->urlService->duplicateIndexUrl(['start' => 0]) . '">'
                           . Config::topNumber() . ' ' . Lang::t('Most visited') . '</a>',
                'items' => array_column($this->conn->executeQuery($query)->fetchAllAssociative(), 'id'),
            ]);
        } elseif ($section === 'best_rated') {
            $page['super_order_by'] = true;
            Config::override('order_by', ' ORDER BY rating_score DESC, id DESC');
            $query = '
SELECT DISTINCT(id)
  FROM ' . Tables::images() . '
    INNER JOIN ' . Tables::imageCategory() . ' AS ic ON id = ic.image_id
  WHERE rating_score IS NOT NULL
    ' . $forbidden . '
    ' . Config::orderBy() . '
  LIMIT ' . Config::topNumber() . '
;';
            $page = array_merge($page, [
                'title' => '<a href="' . $this->urlService->duplicateIndexUrl(['start' => 0]) . '">'
                           . Config::topNumber() . ' ' . Lang::t('Best rated') . '</a>',
                'items' => array_column($this->conn->executeQuery($query)->fetchAllAssociative(), 'id'),
            ]);
        } elseif ($section === 'list') {
            $listIds = array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $pageList);
            $query   = '
SELECT DISTINCT(id)
  FROM ' . Tables::images() . '
    INNER JOIN ' . Tables::imageCategory() . ' AS ic ON id = ic.image_id
  WHERE image_id IN (' . implode(',', $listIds) . ')
    ' . $forbidden . '
  ' . Config::orderBy() . '
;';
            $page = array_merge($page, [
                'title' => '<a href="' . $this->urlService->duplicateIndexUrl(['start' => 0]) . '">' . Lang::t('Random photos') . '</a>',
                'items' => array_column($this->conn->executeQuery($query)->fetchAllAssociative(), 'id'),
            ]);
        }

        // ── Chronology ────────────────────────────────────────────────────────

        if (isset($page['chronology_field'])) {
            unset($page['is_homepage']);
            $superOrderBy   = isset($page['super_order_by']) && (bool) $page['super_order_by'];
            $chronoDateRaw  = $page['chronology_date'] ?? null;
            $rawCD          = is_array($chronoDateRaw) ? $chronoDateRaw : [];
            /** @var list<int|string> $cdList */
            $cdList         = array_values(array_map(
                static fn (mixed $v): int|string => is_int($v) ? $v : (is_scalar($v) ? (string) $v : ''),
                $rawCD
            ));
            $itemsRaw       = $page['items'] ?? null;
            $rawItems       = is_array($itemsRaw) ? $itemsRaw : [];
            /** @var list<string> $itemList */
            $itemList       = array_values(array_map(
                static fn (mixed $v): string => is_scalar($v) ? (string) $v : '0',
                $rawItems
            ));
            $chronoField    = $page['chronology_field'];
            $chronoStyleRaw = $page['chronology_style'] ?? null;
            $chronoViewRaw  = $page['chronology_view']  ?? null;
            $this->calendarService->initializeCalendar(
                section:          $section,
                category:         $category,
                superOrderBy:     $superOrderBy,
                chronologyField:  is_string($chronoField) ? $chronoField : '',
                chronologyStyle:  is_string($chronoStyleRaw) ? $chronoStyleRaw : null,
                chronologyView:   is_string($chronoViewRaw) ? $chronoViewRaw : null,
                chronologyDate:   $cdList,
                sectionItems:     $itemList,
            );
            $page['chronology_style'] = $this->calendarService->getChronologyStyle();
            $page['chronology_view']  = $this->calendarService->getChronologyView();
            $page['chronology_date']  = $this->calendarService->getChronologyDate();
            $page['items']            = $this->calendarService->getItems();
            $page['comment']          = $this->calendarService->getComment();
        }

        // ── Title ─────────────────────────────────────────────────────────────

        if (isset($page['title'])) {
            $page['section_title'] = '<a href="' . $this->urlService->getGalleryHomeUrl() . '">' . Lang::t('Home') . '</a>';
            /** @var mixed $pageTitle */
            $pageTitle = $page['title'];
            if ($pageTitle !== '') {
                $page['section_title'] .= Config::levelSeparator() . (is_scalar($pageTitle) ? (string) $pageTitle : '');
            } else {
                $page['title'] = $page['section_title'];
            }
        }

        // ── Meta robots ───────────────────────────────────────────────────────

        $ps = PageState::current();
        $ps->metaRobots = [];
        if (isset($page['chronology_field'])
            || (isset($page['flat']) && $category !== null)
            || $section === 'list'
            || $section === 'recent_pics') {
            $ps->metaRobots = ['noindex' => 1, 'nofollow' => 1];
        } elseif ($section === 'tags') {
            $pageTagIdsRaw = $page['tag_ids'] ?? null;
            $tagIds = is_array($pageTagIdsRaw) ? $pageTagIdsRaw : [];
            if (count($tagIds) > 1) {
                $ps->metaRobots = ['noindex' => 1, 'nofollow' => 1];
            }
        } elseif ($section === 'recent_cats') {
            $ps->metaRobots = ['noindex' => 1];
        } elseif ($section === 'search') {
            $ps->metaRobots = ['noindex' => 1, 'nofollow' => 1];
        } elseif ($section === 'categories' && isset($page['combined_categories'])) {
            $ps->metaRobots = ['noindex' => 1, 'nofollow' => 1];
        }

        if (FilterContextRegistry::current()->enabled) {
            $ps->metaRobots = array_merge($ps->metaRobots, ['noindex' => 1]);
        }

        // ── Permalink redirect ────────────────────────────────────────────────

        if ($section === 'categories' && $category !== null && !isset($page['combined_categories'])) {
            $catPermalink   = is_scalar($category['permalink'] ?? null) ? (string) $category['permalink'] : '';
            $catName        = is_scalar($category['name'] ?? null) ? (string) $category['name'] : '';
            $pageHitByRaw = $page['hit_by'] ?? null;
            $hitBy          = is_array($pageHitByRaw) ? $pageHitByRaw : [];
            $hitUrlName     = is_scalar($hitBy['cat_url_name'] ?? null) ? (string) $hitBy['cat_url_name'] : '';
            $hitPermalink   = is_scalar($hitBy['cat_permalink'] ?? null) ? (string) $hitBy['cat_permalink'] : null;
            $needRedirect   = false;

            if ($catPermalink === '') {
                if (Config::categoryUrlStyle() === 'id-name' && $hitUrlName !== StringUtil::str2url($catName)) {
                    $needRedirect = true;
                }
            } elseif ($catPermalink !== $hitPermalink) {
                $needRedirect = true;
            }

            if ($needRedirect) {
                $catId = is_scalar($category['id'] ?? null) ? (int) $category['id'] : 0;
                $this->categoryService->checkRestrictions($catId);
                $redirectUrl = $scriptContext === 'picture' ? $this->urlService->duplicatePictureUrl() : $this->urlService->duplicateIndexUrl();
                if (!headers_sent()) {
                    $this->htmlService->setStatusHeader(301);
                    $this->redirectResponder->redirectHttp($redirectUrl);
                }
                $this->redirectResponder->redirect($redirectUrl);
            }
            unset($page['hit_by']);
        }

        // ── Body classes and data ─────────────────────────────────────────────

        $bodyClasses = PageState::current()->bodyClasses;
        $bodyData    = PageState::current()->bodyData;

        $bodyClasses[] = 'section-' . $section;
        $bodyData['section'] = $section;

        if ($section === 'categories' && $category !== null) {
            $catId = is_scalar($category['id'] ?? null) ? (string) $category['id'] : '0';
            $bodyClasses[] = 'category-' . $catId;
            $bodyData['category_id'] = $catId;

            $combinedCategoriesRaw = $page['combined_categories'] ?? null;
            $combinedCategories = is_array($combinedCategoriesRaw)
                ? $combinedCategoriesRaw
                : null;
            if ($combinedCategories !== null) {
                $bodyData['combined_category_ids'] = [];
                foreach ($combinedCategories as $combinedCat) {
                    if (!is_array($combinedCat)) {
                        continue;
                    }
                    $combinedId = is_scalar($combinedCat['id'] ?? null) ? (string) $combinedCat['id'] : '0';
                    $bodyClasses[] = 'category-' . $combinedId;
                    $bodyData['combined_category_ids'][] = $combinedId;
                }
            }
        } elseif ($pageTags !== []) {
            $bodyData['tag_ids'] = [];
            foreach ($pageTags as $tag) {
                if (!is_array($tag)) {
                    continue;
                }
                $tagIdRaw = $tag['id'] ?? null;
                $tagId = is_string($tagIdRaw) ? $tagIdRaw : '0';
                $bodyClasses[] = 'tag-' . $tagId;
                $bodyData['tag_ids'][] = $tagId;
            }
        } elseif (isset($page['search'])) {
            $pageSearchRaw = $page['search'];
            $searchId = is_string($pageSearchRaw) ? $pageSearchRaw : '';
            $bodyClasses[] = 'search-' . $searchId;
            $bodyData['search_id'] = $searchId;
        }

        if (isset($page['image_id'])) {
            $pageImageIdRaw = $page['image_id'];
            $imageId = is_string($pageImageIdRaw) ? $pageImageIdRaw : '0';
            $bodyClasses[] = 'image-' . $imageId;
            $bodyData['image_id'] = $imageId;
        }

        PageState::current()->bodyClasses = $bodyClasses;
        PageState::current()->bodyData    = $bodyData;

        // ── Build typed SectionContext ─────────────────────────────────────────

        // Extract each $page key to a local once so Psalm can narrow types
        // through is_X() checks; in-line repeated access trips PossiblyUndefinedArrayOffset.
        $itemsRaw         = $page['items']            ?? null;
        $tagIdsRaw        = $page['tag_ids']          ?? null;
        $listRaw          = $page['list']             ?? null;
        $whereRaw         = $page['where_clauses']    ?? null;
        $chronoDateRaw    = $page['chronology_date']  ?? null;
        $sectionUrlRaw    = $page['section_url']      ?? null;
        $rootPathRaw      = $page['root_path']        ?? null;
        $startRaw         = $page['start']            ?? null;
        $startcatRaw      = $page['startcat']         ?? null;
        $imageIdRaw       = $page['image_id']         ?? null;
        $imageFileRaw     = $page['image_file']       ?? null;
        $categoryRaw      = $page['category']         ?? null;
        $combinedRaw      = $page['combined_categories'] ?? null;
        $tagsRaw          = $page['tags']             ?? null;
        $searchRaw        = $page['search']           ?? null;
        $searchDetailsRaw = $page['search_details']   ?? null;
        $qsearchRaw       = $page['qsearch_details']  ?? null;
        $chronoFieldRaw   = $page['chronology_field'] ?? null;
        $chronoViewRaw    = $page['chronology_view']  ?? null;
        $chronoStyleRaw   = $page['chronology_style'] ?? null;
        $titleRaw         = $page['title']            ?? null;
        $commentRaw       = $page['comment']          ?? null;
        $sectionTitleRaw  = $page['section_title']    ?? null;
        $feedRaw          = $page['feed']             ?? null;

        $rawItems       = is_array($itemsRaw) ? $itemsRaw : [];
        $rawTagIds      = is_array($tagIdsRaw) ? $tagIdsRaw : [];
        $rawList        = is_array($listRaw) ? $listRaw : [];
        $rawWhere       = is_array($whereRaw) ? $whereRaw : [];
        $rawChronoDate  = is_array($chronoDateRaw) ? $chronoDateRaw : [];

        $ctx = new SectionContext(
            section:            $section,
            sectionUrl:         is_string($sectionUrlRaw) ? $sectionUrlRaw : '',
            rootPath:           is_string($rootPathRaw) ? $rootPathRaw : '',
            items:              array_values(array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $rawItems)),
            start:              is_numeric($startRaw) ? (int) $startRaw : 0,
            startcat:           is_numeric($startcatRaw) ? (int) $startcatRaw : 0,
            nbImagePage:        $page['nb_image_page'],
            flat:               isset($page['flat']),
            isHomepage:         isset($page['is_homepage']),
            superOrderBy:       isset($page['super_order_by']) && (bool) $page['super_order_by'],
            imageId:            is_scalar($imageIdRaw) ? (string) $imageIdRaw : null,
            imageFile:          is_string($imageFileRaw) ? $imageFileRaw : '',
            category:           is_array($categoryRaw) ? $categoryRaw : null,
            combinedCategories: is_array($combinedRaw) ? array_values(array_filter($combinedRaw, is_array(...))) : null,
            tags:               is_array($tagsRaw) ? array_values(array_filter($tagsRaw, is_array(...))) : [],
            tagIds:             array_values(array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rawTagIds)),
            list:               array_values(array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $rawList)),
            search:             is_scalar($searchRaw) ? (string) $searchRaw : null,
            searchId:           $this->searchService->getSearchId(),
            searchDetails:      is_array($searchDetailsRaw) ? $searchDetailsRaw : [],
            qsearchDetails:     is_array($qsearchRaw) ? $qsearchRaw : [],
            whereClauses:       array_values(array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $rawWhere)),
            useRegexpICU:       isset($page['use_regexp_icu']) && (bool) $page['use_regexp_icu'],
            chronologyDate:     array_values(array_map(static fn (mixed $v): int|string => is_int($v) ? $v : (is_scalar($v) ? (string) $v : ''), $rawChronoDate)),
            chronologyField:    is_string($chronoFieldRaw) ? $chronoFieldRaw : '',
            chronologyView:     is_string($chronoViewRaw) ? $chronoViewRaw : '',
            chronologyStyle:    is_string($chronoStyleRaw) ? $chronoStyleRaw : '',
            title:              is_scalar($titleRaw) ? (string) $titleRaw : '',
            comment:            is_string($commentRaw) ? $commentRaw : '',
            sectionTitle:       is_string($sectionTitleRaw) ? $sectionTitleRaw : '',
            feed:               is_string($feedRaw) ? $feedRaw : '',
            isExternal:         isset($page['is_external']),
        );
        SectionContextRegistry::set($ctx);

        $this->dispatcher->dispatch(new LocEndSectionInit());
    }
}
