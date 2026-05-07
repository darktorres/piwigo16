<?php

declare(strict_types=1);

namespace Piwigo\Section;

use Piwigo\Cache\PersistentCacheRegistry;
use Piwigo\Calendar\CalendarService;
use Piwigo\Config\Config;
use Piwigo\Core\LoggerRegistry;
use Piwigo\Core\ServiceLocator;
use Piwigo\Search\SearchService;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlService;
use Piwigo\Users\UserRepository;
use Psr\Http\Message\ServerRequestInterface;
use Piwigo\Users\PermissionService;
use Piwigo\Users\UserService;

/**
 * Resolves the gallery section from the request URL and populates $GLOBALS['page']
 * with items, title, section, meta_robots, body_classes, etc.
 *
 * Replaces the former include/section_init.inc.php procedural script.
 * The $scriptContext parameter ('index' or 'picture') replaces the old
 * script_basename() calls that distinguished between the gallery and single-
 * image pages.
 *
 * All $GLOBALS['page'] mutations are preserved so that existing Smarty
 * templates continue to work unchanged.
 */
final class SectionInitializer
{
    public function initialize(ServerRequestInterface $request, string $scriptContext = 'index'): void
    {
        /** @var array<string,mixed> $page */
        $page = &$GLOBALS['page'];
        /** @var array<string,mixed> $user */
        $user = &$GLOBALS['user'];
        /** @var array<string,mixed> $filter */
        $filter = &$GLOBALS['filter'];

        $page['items']    = [];
        $page['start']    = 0;
        $page['startcat'] = 0;

        // ── URL path → $rewritten + $page['root_path'] ───────────────────────

        $routePath = is_string($request->getAttribute('_route_path')) ? $request->getAttribute('_route_path') : '/';

        $rewritten         = $routePath;
        $page['root_path'] = PHPWG_ROOT_PATH;

        if (str_starts_with((string) $page['root_path'], './')) {
            $page['root_path'] = substr((string) $page['root_path'], 2);
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
                    bad_request('invalid picture identifier');
                }
            } else {
                preg_match('/^(\d+-)?(.*)?$/', $token, $matches);
                if (isset($matches[1]) && is_numeric($matches[1] = rtrim($matches[1], '-'))) {
                    $page['image_id'] = $matches[1];
                    if (!empty($matches[2])) {
                        $page['image_file'] = $matches[2];
                    }
                } else {
                    $page['image_id'] = 0;
                    if (!empty($matches[2] ?? '')) {
                        $page['image_file'] = $matches[2];
                    } else {
                        bad_request('picture identifier is missing');
                    }
                }
            }
        }

        // ── Parse section + well-known params from URL tokens ─────────────────

        $urls = ServiceLocator::get(UrlService::class);
        $page = array_merge($page, $urls->parseSectionUrl($tokens, $nextToken));

        if (!isset($page['section'])) {
            $page['section'] = 'categories';

            if ($scriptContext === 'index') {
                if (!empty(Config::randomIndexRedirect()) && empty($tokens[$nextToken])) {
                    $randomOptions = [];
                    foreach (Config::randomIndexRedirect() as $randomUrl => $randomCondition) {
                        if (empty($randomCondition) || eval($randomCondition)) {
                            $randomOptions[] = $randomUrl;
                        }
                    }
                    if (!empty($randomOptions)) {
                        redirect($randomOptions[mt_rand(0, count($randomOptions) - 1)]);
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

        $section   = is_string($page['section'] ?? null) ? $page['section'] : 'categories';
        $category  = is_array($page['category'] ?? null) ? $page['category'] : null;
        $pageTags  = is_array($page['tags'] ?? null) ? $page['tags'] : [];
        $pageList  = is_array($page['list'] ?? null) ? $page['list'] : [];
        $startcat  = is_numeric($page['startcat'] ?? null) ? (int) $page['startcat'] : 0;

        $page['nb_image_page'] = is_numeric($user['nb_image_page'] ?? null)
            ? (int) $user['nb_image_page']
            : 0;

        if ($section === 'categories' && !isset($page['flat'])) {
            Config::override('order_by', Config::orderByInsideCategory());
        }

        if (pwg_get_session_var('image_order', 0) > 0) {
            $imageOrderId    = pwg_get_session_var('image_order', 0);
            $orders          = get_category_preferred_image_orders();
            $imageOrderIdInt = (int) $imageOrderId;
            $orderEntry      = is_array($orders[$imageOrderIdInt] ?? null) ? $orders[$imageOrderIdInt] : [];
            if ($orderEntry[2] ?? false) {
                $orderCol = is_scalar($orderEntry[1] ?? null) ? (string) $orderEntry[1] : '';
                Config::override('order_by', str_replace(
                    'ORDER BY ',
                    'ORDER BY ' . $orderCol . ',',
                    Config::orderBy()
                ));
                $page['super_order_by'] = true;
            } else {
                pwg_unset_session_var('image_order');
                $page['super_order_by'] = false;
            }
        }

        $forbidden = PermissionService::get()->getSqlConditionFandF(
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
                $page['title'] = get_combined_categories_content_title();
            } elseif ($category !== null) {
                $catComment   = is_string($category['comment'] ?? null) ? $category['comment'] : '';
                $upperNames   = is_array($category['upper_names'] ?? null) ? $category['upper_names'] : [];
                $page = array_merge($page, [
                    'comment' => trigger_change('render_category_description', $catComment, 'main_page_category_description'),
                    'title'   => get_cat_display_name($upperNames, ''),
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
                $page['items'] = get_image_ids_for_categories($catIds);
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
  FROM ' . CATEGORIES_TABLE . '
  WHERE
    uppercats LIKE \'' . $catUppercats . ',%\' '
                            . PermissionService::get()->getSqlConditionFandF(
                                ['forbidden_categories' => 'id', 'visible_categories' => 'id'],
                                "\n  AND"
                            );
                        $subcatIds   = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'id');
                        $subcatIds[] = $catId;
                        $subcatIdsStr = array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $subcatIds);
                        $whereSql    = 'category_id IN (' . implode(',', $subcatIdsStr) . ')';
                        $forbidden   = PermissionService::get()->getSqlConditionFandF(['visible_images' => 'id'], 'AND');
                    } else {
                        $userId    = is_scalar($user['id'] ?? null) ? (string) $user['id'] : '0';
                        $cacheTime = is_scalar($user['cache_update_time'] ?? null) ? (string) $user['cache_update_time'] : '';
                        $cacheKey  = PersistentCacheRegistry::current()->make_key(
                            'all_iids' . $userId . $cacheTime . Config::orderBy()
                        );
                        unset($page['is_homepage']);
                        $whereSql = '1=1';
                    }
                } else {
                    $catId    = $category !== null && is_scalar($category['id'] ?? null) ? (string) $category['id'] : '0';
                    $whereSql = 'category_id = ' . $catId;
                }

                $cacheHit = isset($cacheKey) && PersistentCacheRegistry::current()->get($cacheKey, $page['items']);
                if (!$cacheHit) {
                    $query = '
SELECT DISTINCT(image_id)
  FROM ' . IMAGE_CATEGORY_TABLE . '
    INNER JOIN ' . IMAGES_TABLE . ' ON id = image_id
  WHERE
    ' . $whereSql . '
' . $forbidden . '
  ' . Config::orderBy() . '
;';
                    $page['items'] = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'image_id');
                    if (isset($cacheKey)) {
                        PersistentCacheRegistry::current()->set($cacheKey, $page['items']);
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

            $items = get_image_ids_for_tags($tagIds);
            if (count($items) === 0) {
                LoggerRegistry::current()->info(
                    'attempt to see the name of the tag #' . implode(', #', $tagIds)
                    . ' from the address : '
                    . (is_scalar($_SERVER['REMOTE_ADDR'] ?? null) ? (string) $_SERVER['REMOTE_ADDR'] : '')
                );
                access_denied();
            }
            $page = array_merge($page, [
                'title' => get_tags_content_title(),
                'items' => $items,
            ]);
        } elseif ($section === 'search') {
            $superOrderBy = isset($page['super_order_by']) && (bool) $page['super_order_by'];
            $pageSearch   = is_scalar($page['search'] ?? null) ? (string) $page['search'] : '';
            $searchResult = ServiceLocator::get(SearchService::class)->getSearchResults($pageSearch, $superOrderBy);
            if (isset($searchResult['qs'])) {
                $page['qsearch_details'] = $searchResult['qs'];
            } elseif (isset($searchResult['search_details'])) {
                $page['search_details'] = $searchResult['search_details'];
            }
            $page = array_merge($page, [
                'items' => $searchResult['items'],
                'title' => '<a href="' . duplicate_index_url(['start' => 0]) . '">' . l10n('Search results') . '</a>',
            ]);
        } elseif ($section === 'favorites') {
            UserService::get()->checkUserFavorites();
            $page = array_merge($page, [
                'title' => '<a href="' . duplicate_index_url(['start' => 0]) . '">' . l10n('Favorites') . '</a>',
            ]);
            $action = is_scalar($_GET['action'] ?? null) ? (string) $_GET['action'] : '';
            if ($action === 'remove_all_from_favorites') {
                $userId = is_numeric($user['id'] ?? null) ? (int) $user['id'] : 0;
                ServiceLocator::get(UserRepository::class)->deleteAllFavoritesByUserId($userId);
                redirect(make_index_url(['section' => 'favorites']));
            } else {
                $userId = is_scalar($user['id'] ?? null) ? (string) $user['id'] : '0';
                $query  = '
SELECT image_id
  FROM ' . FAVORITES_TABLE . '
    INNER JOIN ' . IMAGES_TABLE . ' ON image_id = id
  WHERE user_id = ' . $userId . '
' . PermissionService::get()->getSqlConditionFandF(['visible_images' => 'id'], 'AND') . '
  ' . Config::orderBy() . '
;';
                $page = array_merge($page, [
                    'items' => array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'image_id'),
                ]);
                if (count($page['items']) > 0) {
                    TemplateRegistry::current()->assign('favorite', [
                        'U_FAVORITE' => add_url_params(
                            make_index_url(['section' => 'favorites']),
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
  FROM ' . IMAGES_TABLE . '
    INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' AS ic ON id = ic.image_id
  WHERE ' . PermissionService::get()->getRecentPhotosSql('date_available') . '
  ' . $forbidden . Config::orderBy() . '
;';
            $page = array_merge($page, [
                'title' => '<a href="' . duplicate_index_url(['start' => 0]) . '">' . l10n('Recent photos') . '</a>',
                'items' => array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'id'),
            ]);
        } elseif ($section === 'recent_cats') {
            $page = array_merge($page, [
                'title' => '<a href="' . duplicate_index_url(['start' => 0]) . '">' . l10n('Recent albums') . '</a>',
            ]);
        } elseif ($section === 'most_visited') {
            $page['super_order_by'] = true;
            Config::override('order_by', ' ORDER BY hit DESC, id DESC');
            $query = '
SELECT DISTINCT(id)
  FROM ' . IMAGES_TABLE . '
    INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' AS ic ON id = ic.image_id
  WHERE hit > 0
    ' . $forbidden . '
    ' . Config::orderBy() . '
  LIMIT ' . Config::topNumber() . '
;';
            $page = array_merge($page, [
                'title' => '<a href="' . duplicate_index_url(['start' => 0]) . '">'
                           . Config::topNumber() . ' ' . l10n('Most visited') . '</a>',
                'items' => array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'id'),
            ]);
        } elseif ($section === 'best_rated') {
            $page['super_order_by'] = true;
            Config::override('order_by', ' ORDER BY rating_score DESC, id DESC');
            $query = '
SELECT DISTINCT(id)
  FROM ' . IMAGES_TABLE . '
    INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' AS ic ON id = ic.image_id
  WHERE rating_score IS NOT NULL
    ' . $forbidden . '
    ' . Config::orderBy() . '
  LIMIT ' . Config::topNumber() . '
;';
            $page = array_merge($page, [
                'title' => '<a href="' . duplicate_index_url(['start' => 0]) . '">'
                           . Config::topNumber() . ' ' . l10n('Best rated') . '</a>',
                'items' => array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'id'),
            ]);
        } elseif ($section === 'list') {
            $listIds = array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $pageList);
            $query   = '
SELECT DISTINCT(id)
  FROM ' . IMAGES_TABLE . '
    INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' AS ic ON id = ic.image_id
  WHERE image_id IN (' . implode(',', $listIds) . ')
    ' . $forbidden . '
  ' . Config::orderBy() . '
;';
            $page = array_merge($page, [
                'title' => '<a href="' . duplicate_index_url(['start' => 0]) . '">' . l10n('Random photos') . '</a>',
                'items' => array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'id'),
            ]);
        }

        // ── Chronology ────────────────────────────────────────────────────────

        if (isset($page['chronology_field'])) {
            unset($page['is_homepage']);
            ServiceLocator::get(CalendarService::class)->initializeCalendar();
        }

        // ── Title ─────────────────────────────────────────────────────────────

        if (isset($page['title'])) {
            $page['section_title'] = '<a href="' . get_gallery_home_url() . '">' . l10n('Home') . '</a>';
            $pageTitle = is_string($page['title']) ? $page['title'] : '';
            if ($pageTitle !== '') {
                $page['section_title'] .= Config::levelSeparator() . $pageTitle;
            } else {
                $page['title'] = $page['section_title'];
            }
        }

        // ── Meta robots ───────────────────────────────────────────────────────

        $page['meta_robots'] = [];
        if (isset($page['chronology_field'])
            || (isset($page['flat']) && $category !== null)
            || $section === 'list'
            || $section === 'recent_pics') {
            $page['meta_robots'] = ['noindex' => 1, 'nofollow' => 1];
        } elseif ($section === 'tags') {
            $tagIds = is_array($page['tag_ids'] ?? null) ? $page['tag_ids'] : [];
            if (count($tagIds) > 1) {
                $page['meta_robots'] = ['noindex' => 1, 'nofollow' => 1];
            }
        } elseif ($section === 'recent_cats') {
            $page['meta_robots']['noindex'] = 1;
        } elseif ($section === 'search') {
            $page['meta_robots'] = ['noindex' => 1, 'nofollow' => 1];
        } elseif ($section === 'categories' && isset($page['combined_categories'])) {
            $page['meta_robots'] = ['noindex' => 1, 'nofollow' => 1];
        }

        $filterEnabled = is_bool($filter['enabled'] ?? null) ? $filter['enabled'] : (bool) ($filter['enabled'] ?? false);
        if ($filterEnabled) {
            $page['meta_robots']['noindex'] = 1;
        }

        // ── Permalink redirect ────────────────────────────────────────────────

        if ($section === 'categories' && $category !== null && !isset($page['combined_categories'])) {
            $catPermalink   = is_scalar($category['permalink'] ?? null) ? (string) $category['permalink'] : '';
            $catName        = is_scalar($category['name'] ?? null) ? (string) $category['name'] : '';
            $hitBy          = is_array($page['hit_by'] ?? null) ? $page['hit_by'] : [];
            $hitUrlName     = is_scalar($hitBy['cat_url_name'] ?? null) ? (string) $hitBy['cat_url_name'] : '';
            $hitPermalink   = is_scalar($hitBy['cat_permalink'] ?? null) ? (string) $hitBy['cat_permalink'] : null;
            $needRedirect   = false;

            if ($catPermalink === '') {
                if (Config::categoryUrlStyle() === 'id-name' && $hitUrlName !== str2url($catName)) {
                    $needRedirect = true;
                }
            } elseif ($catPermalink !== $hitPermalink) {
                $needRedirect = true;
            }

            if ($needRedirect) {
                $catId = is_scalar($category['id'] ?? null) ? (int) $category['id'] : 0;
                check_restrictions($catId);
                $redirectUrl = $scriptContext === 'picture' ? duplicate_picture_url() : duplicate_index_url();
                if (!headers_sent()) {
                    set_status_header(301);
                    redirect_http($redirectUrl);
                }
                redirect($redirectUrl);
            }
            unset($page['hit_by']);
        }

        // ── Body classes and data ─────────────────────────────────────────────

        $bodyClasses = is_array($page['body_classes'] ?? null) ? $page['body_classes'] : [];
        $bodyData    = is_array($page['body_data'] ?? null) ? $page['body_data'] : [];

        $bodyClasses[] = 'section-' . $section;
        $bodyData['section'] = $section;

        if ($section === 'categories' && $category !== null) {
            $catId = is_scalar($category['id'] ?? null) ? (string) $category['id'] : '0';
            $bodyClasses[] = 'category-' . $catId;
            $bodyData['category_id'] = $catId;

            $combinedCategories = is_array($page['combined_categories'] ?? null)
                ? $page['combined_categories']
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
                $tagId = is_scalar($tag['id'] ?? null) ? (string) $tag['id'] : '0';
                $bodyClasses[] = 'tag-' . $tagId;
                $bodyData['tag_ids'][] = $tagId;
            }
        } elseif (isset($page['search'])) {
            $searchId = is_scalar($page['search']) ? (string) $page['search'] : '';
            $bodyClasses[] = 'search-' . $searchId;
            $bodyData['search_id'] = $searchId;
        }

        if (isset($page['image_id'])) {
            $imageId = is_scalar($page['image_id']) ? (string) $page['image_id'] : '0';
            $bodyClasses[] = 'image-' . $imageId;
            $bodyData['image_id'] = $imageId;
        }

        $page['body_classes'] = $bodyClasses;
        $page['body_data']    = $bodyData;

        trigger_notify('loc_end_section_init');
    }
}
