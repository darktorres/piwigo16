<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Cache\PersistentFileCache;
use Piwigo\Core\Logger;
use Piwigo\Db\DbConnection;
use Piwigo\Group\GroupRepository;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\Search\QExpression;
use Piwigo\Search\QMultiToken;
use Piwigo\Search\QResults;
use Piwigo\Search\QSingleToken;
use Piwigo\Search\SearchRepository;
use Piwigo\Search\SearchService;
use Piwigo\Template\Template;

function get_search_id_pattern(int|string $candidate): ?string
{
    return SearchService::getSearchIdPattern($candidate);
}

/**
 * @return array<string, mixed>|null
 */
function get_search_info(int|string $candidate): ?array
{
    /** @var array<string, mixed> $page */
    global $page;

    $clause_pattern = get_search_id_pattern($candidate);
    if ($clause_pattern === null) {
        die('Invalid search identifier');
    }

    $conn = DbConnection::build();
    $search = new SearchService(
        new SearchRepository($conn),
        new PermissionService(new PermissionRepository($conn), new GroupRepository($conn)),
        new PersistentFileCache()
    )->getSearchInfo($candidate);

    if ($search !== null) {
        // we don't want spies to be able to see the search rules of any prior search (performed
        // by any user). We don't want them to be try index.php?/search/123 then index.php?/search/124
        // and so on. That's why we have implemented search_uuid with random characters.
        //
        // We also don't want to break old search urls with only the numeric id, so we only break if
        // there is no uuid.
        //
        // We also don't want to die if we're in the API.
        if (script_basename() != 'ws' and $clause_pattern == 'id = ?' and isset($search['search_uuid'])) {
            fatal_error('this search is not reachable with its id, need the search_uuid instead');
        }

        if (isset($page['section']) and $page['section'] == 'search') {
            // to be used later in pwg_log
            $page['search_id'] = $search['id'];
        }
    }

    return $search;
}

/**
 * Returns search rules stored into a serialized array in "search"
 * table. Each search rules set is numericaly identified.
 *
 * @return array<string, mixed>|false
 */
function get_search_array(int|string $search_id): mixed
{
    $search = get_search_info($search_id);
    if (empty($search)) {
        bad_request('this search identifier does not exist');
    }

    $rules = $search['rules'] ?? null;
    if (! is_string($rules)) {
        return false;
    }

    $result = unserialize($rules);
    if (! is_array($result)) {
        return false;
    }

    /** @var array<string, mixed> $result */
    return $result;
}

/**
 * Returns the list of items corresponding to the advanced search array.
 *
 * @param array<string, mixed> $search
 * @param string $images_where optional additional restriction on images table
 * @return array{items: list<int>, search_details: array{matching_cat_ids: ?list<int>, matching_tag_ids: ?list<int>, has_filters_filled: bool, image_ids_for_filter: array<string, list<int>>}}
 */
function get_regular_search_results(array $search, string $images_where = ''): array
{
    /** @var Logger $logger */
    global $logger;

    $logger->debug(__FUNCTION__, 'search', $search);

    $conn = DbConnection::build();

    return new SearchService(
        new SearchRepository($conn),
        new PermissionService(new PermissionRepository($conn), new GroupRepository($conn)),
        new PersistentFileCache()
    )->getRegularSearchResults($search, $images_where);
}

/**
 * Returns the SQL WHERE clause to be used to build filter values
 *
 * @since 15
 */
function get_clause_for_filter(string $filter_name): string
{
    /** @var array<string, mixed> $page */
    global $page;

    $other_filters_items = get_items_for_filter($filter_name);
    if ($other_filters_items === false) {
        // $page['search_details'] is set (as get_regular_search_results()'s
        // return['search_details']) in section_init.inc.php; 'forbidden' is
        // itself set as a string a few lines above in search_filters.inc.php.
        $search_details = is_array($page['search_details'] ?? null) ? $page['search_details'] : [];
        $forbidden = $search_details['forbidden'] ?? null;
        return '1=1' . (is_string($forbidden) ? $forbidden : '');
    }

    // get_items_for_filter() ultimately pulls its values from
    // $page['search_details']['image_ids_for_filter'], which is declared
    // array<string, mixed> (get_regular_search_results()'s return shape) — in
    // practice always image ids, but narrow to scalars here for implode().
    $other_filters_item_strings = array_map(
        static fn (mixed $v): string => is_scalar($v) ? (string) $v : '0',
        $other_filters_items
    );

    return 'image_id IN (' . implode(',', $other_filters_item_strings) . ')';
}

/**
 * Returns the list of items (image_ids) to be used to build filter values
 * for a given filter. Depends on the other filters. Use a cache to avoid
 * computing the same large array_intersect several times.
 *
 * @since 15
 *
 * @return array<int, mixed>|false array of image_ids, or false
 */
function get_items_for_filter(string $filter_name): false|array
{
    /**
     * @var array<string, mixed> $page
     * @var Logger $logger
     */
    global $page, $logger;

    // $page['search_details'] is set (as get_regular_search_results()'s
    // return['search_details']) in section_init.inc.php.
    $search_details = is_array($page['search_details'] ?? null) ? $page['search_details'] : [];
    $image_ids_for_filter = is_array($search_details['image_ids_for_filter'] ?? null) ? $search_details['image_ids_for_filter'] : [];

    $other_filters = array_diff(array_keys($image_ids_for_filter), [$filter_name]);

    if (empty($other_filters)) {
        return false;
    }

    $cache_key = md5(implode(',', $other_filters));

    $filter_cache = is_array($search_details[__FUNCTION__] ?? null) ? $search_details[__FUNCTION__] : [];

    if (! isset($filter_cache[$cache_key])) {
        $function_start = get_moment();

        // every entry of $image_ids_for_filter is either a query2array() id
        // list (list<string|null>) or, for 'expert', the already-narrowed
        // result of get_quick_search_results() — normalize each to a plain
        // string-id list here so array_intersect() below has an unambiguous
        // element type (same normalization as get_regular_search_results()
        // above).
        $first_filter_raw = $image_ids_for_filter[array_shift($other_filters)] ?? null;
        $other_filters_items = [];
        if (is_array($first_filter_raw)) {
            foreach ($first_filter_raw as $id) {
                if (is_scalar($id)) {
                    $other_filters_items[] = (string) $id;
                }
            }
        }

        foreach ($other_filters as $other_filter) {
            $next_filter_raw = $image_ids_for_filter[$other_filter] ?? null;
            $next_filter_items = [];
            if (is_array($next_filter_raw)) {
                foreach ($next_filter_raw as $id) {
                    if (is_scalar($id)) {
                        $next_filter_items[] = (string) $id;
                    }
                }
            }
            $other_filters_items = array_intersect($other_filters_items, $next_filter_items);
        }

        $other_filters_items = array_unique($other_filters_items);

        $debug_msg = '[' . __FUNCTION__ . '] cache computed for ' . (count($other_filters) + 1) . ' other filters';
        $debug_msg .= ' (' . count($other_filters_items) . ' items)';
        $debug_msg .= ', time = ' . get_elapsed_time($function_start, get_moment());
        $logger->debug($debug_msg);

        if (empty($other_filters_items)) {
            $other_filters_items = [-1];
        }

        // write the whole 'search_details' structure back at once (rather
        // than chaining offset-writes through $page directly) so every
        // intermediate container is a value we've already proven is an array.
        $filter_cache[$cache_key] = $other_filters_items;
        $search_details[__FUNCTION__] = $filter_cache;
        $page['search_details'] = $search_details;

        return $other_filters_items;
    }

    $cached_items = $filter_cache[$cache_key];
    if (! is_array($cached_items)) {
        return [];
    }

    // only ever populated a few lines above (in this same function) with an
    // array<int, mixed> $other_filters_items.
    /** @var array<int, mixed> $cached_items */
    return $cached_items;
}

define('QST_QUOTED', 0x01);
define('QST_NOT', 0x02);
define('QST_OR', 0x04);
define('QST_WILDCARD_BEGIN', 0x08);
define('QST_WILDCARD_END', 0x10);
define('QST_WILDCARD', QST_WILDCARD_BEGIN | QST_WILDCARD_END);
define('QST_BREAK', 0x20);

/**
 * @param string[] $fields
 * @return non-falsy-string[]
 */
function qsearch_get_text_token_search_sql(QSingleToken $token, array $fields): array
{
    $conn = DbConnection::build();

    return new SearchService(
        new SearchRepository($conn),
        new PermissionService(new PermissionRepository($conn), new GroupRepository($conn)),
        new PersistentFileCache()
    )->qsearchGetTextTokenSearchSql($token, $fields);
}

function qsearch_get_images(QExpression $expr, QResults $qsr): void
{
    $conn = DbConnection::build();
    new SearchService(
        new SearchRepository($conn),
        new PermissionService(new PermissionRepository($conn), new GroupRepository($conn)),
        new PersistentFileCache()
    )->qsearchGetImages($expr, $qsr);
}

function qsearch_get_tags(QExpression $expr, QResults $qsr): void
{
    $conn = DbConnection::build();
    new SearchService(
        new SearchRepository($conn),
        new PermissionService(new PermissionRepository($conn), new GroupRepository($conn)),
        new PersistentFileCache()
    )->qsearchGetTags($expr, $qsr);
}

function qsearch_get_categories(QExpression $expr, QResults $qsr): void
{
    $conn = DbConnection::build();
    new SearchService(
        new SearchRepository($conn),
        new PermissionService(new PermissionRepository($conn), new GroupRepository($conn)),
        new PersistentFileCache()
    )->qsearchGetCategories($expr, $qsr);
}

/**
 * Built exclusively from QResults::$images_iids/$cat_iids/$tag_iids (or, on
 * recursion, from this same function), all of which hold list<int>.
 *
 * @param string[] $ignored_terms
 * @return array<int, int>
 */
function qsearch_eval(QMultiToken $expr, QResults $qsr, bool &$qualifies, array &$ignored_terms): array
{
    $conn = DbConnection::build();

    return new SearchService(
        new SearchRepository($conn),
        new PermissionService(new PermissionRepository($conn), new GroupRepository($conn)),
        new PersistentFileCache()
    )->qsearchEval($expr, $qsr, $qualifies, $ignored_terms);
}

/**
 * Returns the search results corresponding to a quick/query search.
 * A quick/query search returns many items (search is not strict), but results
 * are sorted by relevance unless $super_order_by is true. Returns:
 *  array (
 *    'items' => array of matching images
 *    'qs'    => array(
 *      'unmatched_terms' => array of terms from the input string that were not matched
 *      'matching_tags' => array of matching tags
 *      'matching_cats' => array of matching categories
 *      'matching_cats_no_images' =>array(99) - matching categories without images
 *      )
 *    )
 *
 * @param array<string, mixed> $options
 * @return array<string, mixed>
 */
function get_quick_search_results(string $q, array $options): array
{
    $conn = DbConnection::build();

    return new SearchService(
        new SearchRepository($conn),
        new PermissionService(new PermissionRepository($conn), new GroupRepository($conn)),
        new PersistentFileCache()
    )->getQuickSearchResults($q, $options);
}

/**
 * @see get_quick_search_results but without result caching
 *
 * @param array<string, mixed> $options
 * @return array<string, mixed>
 */
function get_quick_search_results_no_cache(string $q, array $options): array
{
    /** @var Template $template */
    global $template;

    $conn = DbConnection::build();
    $result = new SearchService(
        new SearchRepository($conn),
        new PermissionService(new PermissionRepository($conn), new GroupRepository($conn)),
        new PersistentFileCache()
    )->getQuickSearchResultsNoCache($q, $options);
    $debug = $result['debug'];
    unset($result['debug']);

    $template->append('footer_elements', implode("\n", $debug));

    return $result;
}

/**
 * Returns an array of 'items' corresponding to the search id.
 * It can be either a quick search or a regular search.
 *
 * @return array<string, mixed>
 */
function get_search_results(int|string $search_id, ?bool $super_order_by, string $images_where = ''): array
{
    $conn = DbConnection::build();

    return new SearchService(
        new SearchRepository($conn),
        new PermissionService(new PermissionRepository($conn), new GroupRepository($conn)),
        new PersistentFileCache()
    )->getSearchResults($search_id, $super_order_by, $images_where);
}

/**
 * @return string[]|null
 */
function split_allwords(string $raw_allwords): ?array
{
    return SearchService::splitAllwords($raw_allwords);
}

function get_available_search_uuid(): string
{
    $conn = DbConnection::build();

    return new SearchService(
        new SearchRepository($conn),
        new PermissionService(new PermissionRepository($conn), new GroupRepository($conn)),
        new PersistentFileCache()
    )->getAvailableSearchUuid();
}

/**
 * @param array<string, mixed> $rules
 * @return array{0: string, 1: string}
 */
function save_search(array $rules, ?int $forked_from = null): array
{
    $conn = DbConnection::build();

    return new SearchService(
        new SearchRepository($conn),
        new PermissionService(new PermissionRepository($conn), new GroupRepository($conn)),
        new PersistentFileCache()
    )->saveSearch($rules, $forked_from);
}
