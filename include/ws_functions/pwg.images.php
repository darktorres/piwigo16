<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Admin\Upload\UploadService;
use Piwigo\Auth\CookieService;
use Piwigo\Auth\EphemeralKeyService;
use Piwigo\Cache\PersistentFileCache;
use Piwigo\Category\CategoryService;
use Piwigo\Comment\CommentRepository;
use Piwigo\Comment\CommentService;
use Piwigo\Config\Config;
use Piwigo\Core\Logger;
use Piwigo\Core\ValidationPattern;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Group\GroupRepository;
use Piwigo\Html\HtmlService;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageStdParams;
use Piwigo\Mail\MailService;
use Piwigo\Metadata\MetadataRepository;
use Piwigo\Metadata\MetadataService;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\Rate\RateRepository;
use Piwigo\Rate\RateService;
use Piwigo\Search\SearchRepository;
use Piwigo\Search\SearchService;
use Piwigo\Storage\StorageRegistry;
use Piwigo\Tag\TagRepository;
use Piwigo\Tag\TagService;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgNamedArray;
use Piwigo\Ws\PwgNamedStruct;
use Piwigo\Ws\PwgServer;

// +-----------------------------------------------------------------------+
// | UTILITIES                                                             |
// +-----------------------------------------------------------------------+

/**
 * Sets associations of an image
 * @param int $image_id
 * @param string $categories_string - "cat_id[,rank];cat_id[,rank]"
 * @param bool $replace_mode - removes old associations
 */
function ws_add_image_category_relations($image_id, $categories_string, $replace_mode = false): true|PwgError
{
    // let's add links between the image and the categories
    //
    // $params['categories'] should look like 123,12;456,auto;789 which means:
    //
    // 1. associate with category 123 on rank 12
    // 2. associate with category 456 on automatic rank
    // 3. associate with category 789 on automatic rank
    $cat_ids = [];
    $rank_on_category = [];
    $search_current_ranks = false;

    if (empty($categories_string)) {
        if ($replace_mode) {
            $query = '
DELETE
  FROM ' . Tables::imageCategory() . '
  WHERE image_id = ' . $image_id . '
;';
            pwg_query($query);
            update_category([]);
        }
        return true;
    }
    $tokens = explode(';', $categories_string);
    foreach ($tokens as $token) {
        $token_parts = explode(',', $token);
        $cat_id = $token_parts[0];
        $rank = $token_parts[1] ?? 'auto';

        if (! (bool) preg_match('/^\d+$/', $cat_id)) {
            continue;
        }

        $cat_ids[] = $cat_id;
        $rank_on_category[$cat_id] = $rank;

        if ($rank == 'auto') {
            $search_current_ranks = true;
        }
    }

    $cat_ids = array_unique($cat_ids);

    if (count($cat_ids) == 0) {
        if ($replace_mode) {
            $query = '
DELETE
  FROM ' . Tables::imageCategory() . '
  WHERE image_id = ' . $image_id . '
;';
            pwg_query($query);
            update_category([]);
        }
        return true;
    }

    $query = '
SELECT id
  FROM ' . Tables::categories() . '
  WHERE id IN (' . implode(',', $cat_ids) . ')
;';
    // $value_name is given, so every element is a single 'id' column value
    // (string|null), never the nested-row array branch.
    $db_cat_ids = array_map(static fn (?string $v): string => is_string($v) ? $v : '', query2array($query, null, 'id'));

    $unknown_cat_ids = array_diff($cat_ids, $db_cat_ids);
    if (count($unknown_cat_ids) != 0) {
        return new PwgError(
            500,
            '[ws_add_image_category_relations] the following categories are unknown: ' . implode(', ', $unknown_cat_ids)
        );
    }

    $to_update_cat_ids = [];

    // in case of replace mode, we first check the existing associations
    $query = '
SELECT category_id
  FROM ' . Tables::imageCategory() . '
  WHERE image_id = ' . $image_id . '
;';
    // $value_name is given, so every element is a single 'category_id'
    // column value (string|null), never the nested-row array branch.
    $existing_cat_ids = array_map(static fn (?string $v): string => is_string($v) ? $v : '', query2array($query, null, 'category_id'));

    if ($replace_mode) {
        $to_remove_cat_ids = array_diff($existing_cat_ids, $cat_ids);
        if (count($to_remove_cat_ids) > 0) {
            $query = '
DELETE
  FROM ' . Tables::imageCategory() . '
  WHERE image_id = ' . $image_id . '
    AND category_id IN (' . implode(', ', $to_remove_cat_ids) . ')
;';
            pwg_query($query);
            update_category($to_remove_cat_ids);
        }
    }

    $new_cat_ids = array_diff($cat_ids, $existing_cat_ids);
    if (count($new_cat_ids) == 0) {
        return true;
    }

    if ($search_current_ranks) {
        $query = '
SELECT category_id, MAX(`rank`) AS max_rank
  FROM ' . Tables::imageCategory() . '
  WHERE `rank` IS NOT NULL
    AND category_id IN (' . implode(',', $new_cat_ids) . ')
  GROUP BY category_id
;';
        $current_rank_of = query2array(
            $query,
            'category_id',
            'max_rank'
        );

        foreach ($new_cat_ids as $cat_id) {
            if (! isset($current_rank_of[$cat_id])) {
                $current_rank_of[$cat_id] = 0;
            }

            if ($rank_on_category[$cat_id] == 'auto') {
                $max_rank = is_numeric($current_rank_of[$cat_id]) ? (int) $current_rank_of[$cat_id] : 0;
                $rank_on_category[$cat_id] = $max_rank + 1;
            }
        }
    }

    $inserts = [];

    foreach ($new_cat_ids as $cat_id) {
        $inserts[] = [
            'image_id' => $image_id,
            'category_id' => $cat_id,
            'rank' => $rank_on_category[$cat_id],
        ];
    }

    mass_inserts(
        Tables::imageCategory(),
        array_keys($inserts[0]),
        $inserts
    );

    include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
    update_category($new_cat_ids);
    return true;
}

/**
 * Merge chunks added by pwg.images.addChunk
 */
function merge_chunks(string $output_filepath, string $original_sum, string $type): ?PwgError
{
    /**
     * @var array<string, mixed> $conf
     * @var Logger $logger
     */
    global $conf, $logger;

    $logger->debug('[merge_chunks] input parameter $output_filepath : ' . $output_filepath, 'WS');

    if (is_file($output_filepath)) {
        unlink($output_filepath);

        if (is_file($output_filepath)) {
            return new PwgError(500, '[merge_chunks] error while trying to remove existing ' . $output_filepath);
        }
    }

    $upload_dir_conf = $conf['upload_dir'];
    $upload_dir_conf = is_string($upload_dir_conf) ? $upload_dir_conf : '';
    $upload_dir = $upload_dir_conf . '/buffer';
    $pattern = '/' . $original_sum . '-' . $type . '/';
    $chunks = [];

    if ((bool) ($handle = opendir($upload_dir))) {
        while (false !== ($file = readdir($handle))) {
            if ((bool) preg_match($pattern, $file)) {
                $logger->debug($file, 'WS');
                $chunks[] = $upload_dir . '/' . $file;
            }
        }
        closedir($handle);
    }

    sort($chunks);

    if (function_exists('memory_get_usage')) {
        $logger->debug('[merge_chunks] memory_get_usage before loading chunks: ' . memory_get_usage(), 'WS');
    }

    $i = 0;

    foreach ($chunks as $chunk) {
        $string = file_get_contents($chunk);

        if (function_exists('memory_get_usage')) {
            $logger->debug('[merge_chunks] memory_get_usage on chunk ' . ++$i . ': ' . memory_get_usage(), 'WS');
        }

        if (! (bool) file_put_contents($output_filepath, $string, FILE_APPEND)) {
            return new PwgError(500, '[merge_chunks] error while writting chunks for ' . $output_filepath);
        }

        unlink($chunk);
    }

    if (function_exists('memory_get_usage')) {
        $logger->debug('[merge_chunks] memory_get_usage after loading chunks: ' . memory_get_usage(), 'WS');
    }

    return null;
}

/**
 * Deletes chunks added with pwg.images.addChunk
 * @param string $original_sum
 * @param string $type
 *
 * Function introduced for Piwigo 2.4 and the new "multiple size"
 * (derivatives) feature. As we only need the biggest sent photo as
 * "original", we remove chunks for smaller sizes. We can't make it earlier
 * in ws_images_add_chunk because at this moment we don't know which $type
 * will be the biggest (we could remove the thumb, but let's use the same
 * algorithm)
 */
function remove_chunks($original_sum, $type): void
{
    /** @var array<string, mixed> $conf */
    global $conf;

    $upload_dir_conf = $conf['upload_dir'];
    $upload_dir_conf = is_string($upload_dir_conf) ? $upload_dir_conf : '';
    $upload_dir = $upload_dir_conf . '/buffer';
    $pattern = '/' . $original_sum . '-' . $type . '/';
    $chunks = [];

    if ((bool) ($handle = opendir($upload_dir))) {
        while (false !== ($file = readdir($handle))) {
            if ((bool) preg_match($pattern, $file)) {
                $chunks[] = $upload_dir . '/' . $file;
            }
        }
        closedir($handle);
    }

    foreach ($chunks as $chunk) {
        unlink($chunk);
    }
}

// +-----------------------------------------------------------------------+
// | METHODS                                                               |
// +-----------------------------------------------------------------------+

/**
 * API method
 * Adds a comment to an image
 * @param array{image_id: int, author: string, content: string, key: string, ...} $params
 *    image_id: WS_TYPE_ID, mandatory -- always a plain int. author/content/
 *    key have no WS_TYPE flag, but PwgServer::invoke() rejects an array
 *    value for any registered param without WS_PARAM_ACCEPT_ARRAY, so
 *    they're always plain strings too (author has a string default,
 *    content/key are mandatory)
 * @return PwgError|array{comment: PwgNamedStruct}
 */
function ws_images_addComment(array $params, PwgServer $service): PwgError|array
{
    /** @var array<string, mixed> $conf */
    global $conf;

    if (! (bool) $conf['activate_comments']) {
        return new PwgError(403, 'Comments are disabled');
    }

    $query = '
SELECT DISTINCT image_id
  FROM ' . Tables::imageCategory() . '
      INNER JOIN ' . Tables::categories() . ' ON category_id=id
  WHERE commentable="true"
    AND image_id=' . $params['image_id'] .
      (new \Piwigo\Permission\PermissionService(new \Piwigo\Permission\PermissionRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build())))->getSqlConditionFandF([
          'forbidden_categories' => 'id',
          'visible_categories' => 'id',
          'visible_images' => 'image_id',
      ], ' AND') . '
;';

    if (! (bool) pwg_db_num_rows(pwg_query($query))) {
        return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid image_id');
    }

    $comm = [
        'author' => trim($params['author']),
        'content' => trim($params['content']),
        'image_id' => $params['image_id'],
    ];

    $infos = [];
    $comment_action = new CommentService(new CommentRepository(DbConnection::build()), new EphemeralKeyService(), new MailService())
        ->insertComment($comm, $params['key'], $infos);

    switch ($comment_action) {
        case 'reject':
            $infos[] = l10n('Your comment has NOT been registered because it did not pass the validation rules');
            return new PwgError(403, implode('; ', $infos));

        case 'validate':
        case 'moderate':
            $ret = [
                'id' => $comm['id'],
                'validation' => $comment_action == 'validate',
            ];
            return [
                'comment' => new PwgNamedStruct($ret),
            ];

        default:
            return new PwgError(500, 'Unknown comment action ' . $comment_action);
    }
}

/**
 * API method
 * Returns detailed information for an element
 * @param array{image_id: int, comments_page: int, comments_per_page: int, ...} $params
 *    all three are WS_TYPE_INT|WS_TYPE_POSITIVE (image_id: WS_TYPE_ID) --
 *    always plain ints by the time this runs (comments_page/
 *    comments_per_page have defaults, so always present too)
 * @return PwgError|array<string, mixed>
 */
function ws_images_getInfo(array $params, PwgServer $service): PwgError|array
{
    /**
     * @var array<string, mixed> $user
     * @var array<string, mixed> $conf
     */
    global $user, $conf;

    $query = '
SELECT *
  FROM ' . Tables::images() . '
  WHERE id=' . $params['image_id'] .
      (new \Piwigo\Permission\PermissionService(new \Piwigo\Permission\PermissionRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build())))->getSqlConditionFandF([
          'visible_images' => 'id',
      ], ' AND') . '
LIMIT 1
;';
    $result = pwg_query($query);

    if (pwg_db_num_rows($result) == 0) {
        return new PwgError(404, 'image_id not found');
    }

    $image_row = pwg_db_fetch_assoc($result);
    if ($image_row === false || $image_row === null) {
        throw new Exception('ws_images_getInfo(): fetch failed after a non-zero pwg_db_num_rows()');
    }
    // id is the Tables::images() primary key, guaranteed numeric; captured
    // before array_merge() below widens every value of $image_row to mixed.
    assert(is_numeric($image_row['id']));
    $image_id = (int) $image_row['id'];

    // array_merge() with ws_std_get_urls()'s mixed-valued return widens
    // PHPStan's tracked shape for every other key of the original
    // pwg_db_fetch_assoc() row -- restate the columns this function reads
    // below (id/file: Tables::images() NOT NULL; name/comment/rating_score:
    // nullable) plus an open tail for the rest of the row and the
    // page_url/element_url/download_url/derivatives keys ws_std_get_urls()
    // injects.
    /** @var array{id: string, file: string, name: string|null, comment: string|null, rating_score: string|null, ...} $image_row */
    $image_row = array_merge($image_row, ws_std_get_urls($image_row));

    $image_row['name_raw'] = $image_row['name'];
    $rendered_name = trigger_change(
        'render_element_name',
        $image_row['name'],
        __FUNCTION__
    );
    $image_row['name'] = strip_tags(is_string($rendered_name) ? $rendered_name : '');

    $image_row['comment_raw'] = $image_row['comment'];
    $image_row['comment'] = trigger_change(
        'render_element_description',
        $image_row['comment'],
        __FUNCTION__
    );

    // -------------------------------------------------------- related categories
    $query = '
SELECT id, name, permalink, uppercats, global_rank, commentable
  FROM ' . Tables::imageCategory() . '
    INNER JOIN ' . Tables::categories() . ' ON category_id = id
  WHERE image_id = ' . $image_id .
      (new \Piwigo\Permission\PermissionService(new \Piwigo\Permission\PermissionRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build())))->getSqlConditionFandF([
          'forbidden_categories' => 'category_id',
      ], ' AND') . '
;';
    $result = pwg_query($query);

    $is_commentable = false;
    $related_categories = [];
    while ((bool) ($row = pwg_db_fetch_assoc($result))) {
        if ($row['commentable'] == 'true') {
            $is_commentable = true;
        }
        unset($row['commentable']);

        $row['url'] = make_index_url(
            [
                'category' => $row,
            ]
        );

        $row['page_url'] = make_picture_url(
            [
                'image_id' => $image_row['id'],
                'image_file' => $image_row['file'],
                'category' => $row,
            ]
        );

        $row['id'] = (int) $row['id'];

        $rendered_category_name = trigger_change(
            'render_category_name',
            $row['name'],
            __FUNCTION__
        );
        $row['name'] = strip_tags(is_string($rendered_category_name) ? $rendered_category_name : '');

        $related_categories[] = $row;
    }
    usort($related_categories, CategoryService::compareByGlobalRank(...));

    if (empty($related_categories) and ! \Piwigo\Auth\AccessControl::isAdmin()) {
        // photo might be in the lounge? or simply orphan. A standard user should not get
        // info. An admin should still be able to get info.
        return new PwgError(401, 'Access denied');
    }

    // -------------------------------------------------------------- related tags
    $tagConn = DbConnection::build();
    $related_tags = new TagService(new TagRepository($tagConn), new PermissionService(new PermissionRepository($tagConn), new GroupRepository($tagConn)))
        ->getCommonTags([$image_id], -1);
    foreach ($related_tags as $i => $tag) {
        $tag['url'] = make_index_url(
            [
                'tags' => [$tag],
            ]
        );
        $tag['page_url'] = make_picture_url(
            [
                'image_id' => $image_row['id'],
                'image_file' => $image_row['file'],
                'tags' => [$tag],
            ]
        );

        unset($tag['counter']);
        assert(is_numeric($tag['id']));
        $tag['id'] = (int) $tag['id'];
        $related_tags[$i] = $tag;
    }

    // ------------------------------------------------------------- related rates
    $rating_score_raw = $image_row['rating_score'];
    $rating = [
        'score' => $rating_score_raw,
        'count' => 0,
        'average' => null,
    ];
    if (isset($rating['score'])) {
        $query = '
SELECT COUNT(rate) AS count, ROUND(AVG(rate),2) AS average
  FROM ' . Tables::rate() . '
  WHERE element_id = ' . $image_id . '
;';
        $row = pwg_db_fetch_assoc(pwg_query($query));
        if ($row === false || $row === null) {
            throw new Exception('ws_images_getInfo(): rate aggregate query returned no row');
        }

        assert(is_numeric($rating_score_raw));
        $rating['score'] = (float) $rating_score_raw;
        $rating['average'] = (float) $row['average'];
        $rating['count'] = (int) $row['count'];
    }

    // ---------------------------------------------------------- related comments
    $related_comments = [];

    $where_comments = 'image_id = ' . $image_id;
    if (! \Piwigo\Auth\AccessControl::isAdmin()) {
        $where_comments .= ' AND validated="true"';
    }

    $query = '
SELECT COUNT(id) AS nb_comments
  FROM ' . Tables::comments() . '
  WHERE ' . $where_comments . '
;';
    [$nb_comments] = query2array($query, null, 'nb_comments');
    $nb_comments = (int) $nb_comments;

    if ($nb_comments > 0 and $params['comments_per_page'] > 0) {
        $query = '
SELECT id, date, author, content
  FROM ' . Tables::comments() . '
  WHERE ' . $where_comments . '
  ORDER BY date
  LIMIT ' . $params['comments_per_page'] . '
  OFFSET ' . ($params['comments_per_page'] * $params['comments_page']) . '
;';
        $result = pwg_query($query);

        while ((bool) ($row = pwg_db_fetch_assoc($result))) {
            $row['id'] = (int) $row['id'];
            $related_comments[] = $row;
        }
    }

    $comment_post_data = null;
    if ((bool) $conf['activate_comments'] and
        $is_commentable and
        (! \Piwigo\Auth\AccessControl::isAGuest()
          or (bool) $conf['comments_forall']
        )
    ) {
        $username = $user['username'];
        $comment_post_data['author'] = stripslashes(is_string($username) ? $username : '');
        $comment_post_data['key'] = get_ephemeral_key(2, (string) $params['image_id']);
    }

    $ret = $image_row;
    foreach (['id', 'width', 'height', 'hit', 'filesize'] as $k) {
        if (isset($ret[$k])) {
            assert(is_numeric($ret[$k]));
            $ret[$k] = (int) $ret[$k];
        }
    }
    foreach (['path', 'storage_category_id'] as $k) {
        unset($ret[$k]);
    }

    $ret['rates'] = [
        WS_XML_ATTRIBUTES => $rating,
    ];
    $ret['categories'] = new PwgNamedArray(
        $related_categories,
        'category',
        ['id', 'url', 'page_url']
    );
    $ret['tags'] = new PwgNamedArray(
        $related_tags,
        'tag',
        ws_std_get_tag_xml_attributes()
    );
    if (isset($comment_post_data)) {
        $ret['comment_post'] = [
            WS_XML_ATTRIBUTES => $comment_post_data,
        ];
    }
    $ret['comments_paging'] = new PwgNamedStruct(
        [
            'page' => $params['comments_page'],
            'per_page' => $params['comments_per_page'],
            'count' => count($related_comments),
            'total_count' => $nb_comments,
        ]
    );
    $ret['comments'] = new PwgNamedArray(
        $related_comments,
        'comment',
        ['id', 'date']
    );

    if ($service->_responseFormat != 'rest') {
        return $ret; // for backward compatibility only
    } else {
        return [
            'image' => new PwgNamedStruct($ret, null, ['name', 'comment']),
        ];
    }
}

/**
 * API method
 * Rates an image
 * @param array{image_id: int, rate: float, ...} $params both mandatory
 *    (WS_TYPE_ID / WS_TYPE_FLOAT, no 'default') -- always plain scalars by
 *    the time this runs
 */
function ws_images_rate(array $params, PwgServer $service): mixed
{
    $query = '
SELECT DISTINCT id
  FROM ' . Tables::images() . '
    INNER JOIN ' . Tables::imageCategory() . ' ON id=image_id
  WHERE id=' . $params['image_id']
      . (new \Piwigo\Permission\PermissionService(new \Piwigo\Permission\PermissionRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build())))->getSqlConditionFandF([
          'forbidden_categories' => 'category_id',
          'forbidden_images' => 'id',
      ], '    AND') . '
  LIMIT 1
;';
    if (pwg_db_num_rows(pwg_query($query)) == 0) {
        return new PwgError(404, 'Invalid image_id or access denied');
    }

    $res = new RateService(new RateRepository(DbConnection::build()), new CookieService())
        ->rate($params['image_id'], (int) $params['rate']);

    if ($res == false) {
        /** @var array<string, mixed> $conf */
        global $conf;
        $rate_items = $conf['rate_items'];
        $rate_items = is_array($rate_items) ? array_filter($rate_items, is_scalar(...)) : [];
        return new PwgError(403, 'Forbidden or rate not in ' . implode(',', $rate_items));
    }
    return $res;
}

/**
 * API method
 * Returns a list of elements corresponding to a query search
 * @param array{query: string, per_page: int, page: int, order: string|null, f_min_rate: float|null, f_max_rate: float|null, f_min_hit: int|null, f_max_hit: int|null, f_min_ratio: float|null, f_max_ratio: float|null, f_max_level: int|null, f_min_date_available: string|null, f_max_date_available: string|null, f_min_date_created: string|null, f_max_date_created: string|null, ...} $params
 *    query: no WS_TYPE flag, mandatory -- always a plain string (see
 *    ws_std_image_sql_filter()'s docblock for the shared f_* filter set,
 *    merged in via ws.php's $f_params)
 * @return array{paging: PwgNamedStruct, images: PwgNamedArray}
 */
function ws_images_search(array $params, PwgServer $service): array
{
    $images = [];
    $where_clauses = ws_std_image_sql_filter($params, 'i.');
    $order_by = ws_std_image_sql_order($params, 'i.');

    $super_order_by = false;
    if (! empty($order_by)) {
        /** @var array<string, mixed> $conf */
        global $conf;
        $conf['order_by'] = 'ORDER BY ' . $order_by;
        $super_order_by = true; // quick_search_result might be faster
    }

    $searchConn = DbConnection::build();
    $search_result = new SearchService(
        new SearchRepository($searchConn),
        new PermissionService(new PermissionRepository($searchConn), new GroupRepository($searchConn)),
        new PersistentFileCache(),
        new MailService(),
    )->getQuickSearchResults(
        $params['query'],
        [
            'super_order_by' => $super_order_by,
            'images_where' => implode(' AND ', $where_clauses),
        ]
    );

    // get_quick_search_results()'s return type is a generic array<string,
    // mixed> (cross-file root cause: include/functions_search.inc.php could
    // give 'items' a precise int[] shape, but that's shared by many other
    // callers -- narrow locally here instead).
    $search_items = $search_result['items'];
    if (! is_array($search_items)) {
        $search_items = [];
    }

    $image_ids = array_map(
        static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
        array_slice(
            $search_items,
            $params['page'] * $params['per_page'],
            $params['per_page']
        )
    );

    if ((bool) count($image_ids)) {
        $query = '
SELECT *
  FROM ' . Tables::images() . '
  WHERE id IN (' . implode(',', $image_ids) . ')
;';
        $result = pwg_query($query);
        $image_ids = array_flip($image_ids);
        $favorite_ids = get_user_favorites();

        while ((bool) ($row = pwg_db_fetch_assoc($result))) {
            $image = [];
            assert($row['id'] !== null);
            $image['is_favorite'] = isset($favorite_ids[$row['id']]);
            foreach (['id', 'width', 'height', 'hit'] as $k) {
                if (isset($row[$k])) {
                    $image[$k] = (int) $row[$k];
                }
            }
            foreach (['file', 'name', 'comment', 'date_creation', 'date_available'] as $k) {
                $image[$k] = $row[$k];
            }

            $rendered_image_name = trigger_change('render_element_name', $image['name'], __FUNCTION__);
            $image['name'] = strip_tags(is_string($rendered_image_name) ? $rendered_image_name : '');
            $image['comment'] = trigger_change('render_element_description', $image['comment'], __FUNCTION__);

            $image = array_merge($image, ws_std_get_urls($row));
            assert(is_int($image['id']));
            $images[$image_ids[$image['id']]] = $image;
        }
        ksort($images, SORT_NUMERIC);
        $images = array_values($images);
    }

    return [
        'paging' => new PwgNamedStruct(
            [
                'page' => $params['page'],
                'per_page' => $params['per_page'],
                'count' => count($images),
                'total_count' => count($search_items),
            ]
        ),
        'images' => new PwgNamedArray(
            $images,
            'image',
            ws_std_get_image_xml_attributes()
        ),
    ];
}

/**
 * API method
 * Registers a new search
 *
 * Every param here is WS_PARAM_OPTIONAL with no 'default' key, so
 * PwgServer::invoke() leaves any not provided by the caller entirely
 * absent from $params (not filled with null) -- hence the optional (?:)
 * shape keys throughout. FORCE_ARRAY params, when present, are always
 * arrays (never a bare scalar).
 *
 * @param array{search_id?: string, allwords?: string, allwords_mode?: string, allwords_fields?: array<int, string>, tags?: array<int, int>, tags_mode?: string, categories?: array<int, int>, categories_withsubs?: bool, authors?: array<int, string>, added_by?: array<int, int>, filetypes?: array<int, string>, date_posted_preset?: string, date_posted_custom?: array<int, string>, date_created_preset?: string, date_created_custom?: array<int, string>, ratios?: array<int, string>, ratings?: array<int, string>, filesize_min?: int, filesize_max?: int, height_min?: int, height_max?: int, width_min?: int, width_max?: int, ...} $params
 * @return PwgError|array{search_id: string, search_url: string}
 */
function ws_images_filteredSearch_create(array $params, PwgServer $service): PwgError|array
{
    /**
     * @var array<string, mixed> $user
     * @var array<string, mixed> $conf
     */
    global $user, $conf;

    $searchConn = DbConnection::build();
    $searchService = new SearchService(
        new SearchRepository($searchConn),
        new PermissionService(new PermissionRepository($searchConn), new GroupRepository($searchConn)),
        new PersistentFileCache(),
        new MailService(),
    );

    // * check the search exists
    $search_info = null;
    if (isset($params['search_id'])) {
        if (empty(SearchService::getSearchIdPattern($params['search_id']))) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid search_id input parameter.');
        }

        $search_info = $searchService->getValidatedSearchInfo($params['search_id']);
        if (empty($search_info)) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'This search does not exist.');
        }
    }

    // 'fields' (and its 'date_posted'/'date_created' sub-arrays) are
    // predeclared so PHPStan can track $search's shape as it's built up
    // below -- this changes nothing at runtime (PHP would auto-vivify the
    // same nested arrays via the assignments further down anyway).
    $search = [
        'mode' => 'AND',
        'fields' => [
            'date_posted' => [],
            'date_created' => [],
        ],
    ];

    // * check all parameters
    if (isset($params['allwords'])) {
        $search['fields']['allwords'] = [];

        if (! isset($params['allwords_mode'])) {
            $params['allwords_mode'] = 'AND';
        }
        if (! (bool) preg_match('/^(OR|AND)$/', $params['allwords_mode'])) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid parameter allwords_mode');
        }
        $search['fields']['allwords']['mode'] = $params['allwords_mode'];

        $allwords_fields_available = ['name', 'comment', 'file', 'author', 'tags', 'cat-title', 'cat-desc'];
        if (! isset($params['allwords_fields'])) {
            $params['allwords_fields'] = $allwords_fields_available;
        }
        foreach ($params['allwords_fields'] as $field) {
            if (! in_array($field, $allwords_fields_available)) {
                return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid parameter allwords_fields');
            }
        }
        $search['fields']['allwords']['fields'] = $params['allwords_fields'];

        $search['fields']['allwords']['words'] = SearchService::splitAllwords($params['allwords']);
    }

    if (isset($params['tags'])) {
        foreach ($params['tags'] as $tag_id) {
            if (! (bool) preg_match('/^\d+$/', (string) $tag_id)) {
                return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid parameter tags');
            }
        }

        if (! isset($params['tags_mode'])) {
            $params['tags_mode'] = 'AND';
        }
        if (! (bool) preg_match('/^(OR|AND)$/', $params['tags_mode'])) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid parameter tags_mode');
        }

        $search['fields']['tags'] = [
            'words' => $params['tags'],
            'mode' => $params['tags_mode'],
        ];
    }

    if (isset($params['categories'])) {
        foreach ($params['categories'] as $cat_id) {
            if (! (bool) preg_match('/^\d+$/', (string) $cat_id)) {
                return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid parameter categories');
            }
        }

        $search['fields']['cat'] = [
            'words' => $params['categories'],
            'sub_inc' => $params['categories_withsubs'] ?? false,
        ];
    }

    if (isset($params['authors'])) {
        $authors = [];

        foreach ($params['authors'] as $author) {
            $authors[] = strip_tags($author);
        }

        $search['fields']['author'] = [
            'words' => $authors,
            'mode' => 'OR',
        ];
    }

    if (isset($params['filetypes'])) {
        foreach ($params['filetypes'] as $ext) {
            if (! (bool) preg_match('/^[a-z0-9]+$/i', $ext)) {
                return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid parameter filetypes');
            }
        }

        $search['fields']['filetypes'] = $params['filetypes'];
    }

    if (isset($params['added_by'])) {
        foreach ($params['added_by'] as $user_id) {
            if (! (bool) preg_match('/^\d+$/', (string) $user_id)) {
                return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid parameter added_by');
            }
        }

        $search['fields']['added_by'] = $params['added_by'];
    }

    if (isset($params['date_posted_preset'])) {
        if (! (bool) preg_match('/^(24h|7d|30d|3m|6m|custom|)$/', $params['date_posted_preset'])) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid parameter date_posted_preset');
        }

        @$search['fields']['date_posted']['preset'] = $params['date_posted_preset'];

        if ($search['fields']['date_posted']['preset'] == 'custom' and empty($params['date_posted_custom'])) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'date_posted_custom is missing');
        }
    }

    if (isset($params['date_posted_custom'])) {
        if (! isset($search['fields']['date_posted']['preset']) or $search['fields']['date_posted']['preset'] != 'custom') {
            return new PwgError(WS_ERR_INVALID_PARAM, 'date_posted_custom provided date_posted_preset is not custom');
        }

        foreach ($params['date_posted_custom'] as $date) {
            $correct_format = false;

            $ymd = substr($date, 0, 1);
            if ($ymd == 'y') {
                if ((bool) preg_match('/^y(\d{4})$/', $date, $matches)) {
                    $correct_format = true;
                }
            } elseif ($ymd == 'm') {
                if ((bool) preg_match('/^m(\d{4}-\d{2})$/', $date, $matches)) {
                    [$year, $month] = explode('-', $matches[1]);
                    if ($month >= 1 and $month <= 12) {
                        $correct_format = true;
                    }
                }
            } elseif ($ymd == 'd') {
                if ((bool) preg_match('/^d(\d{4}-\d{2}-\d{2})$/', $date, $matches)) {
                    [$year, $month, $day] = explode('-', $matches[1]);
                    if ($month >= 1 and $month <= 12 and $day >= 1 and $day <= cal_days_in_month(CAL_GREGORIAN, (int) $month, (int) $year)) {
                        $correct_format = true;
                    }
                }
            }

            if (! $correct_format) {
                return new PwgError(WS_ERR_INVALID_PARAM, 'date_posted_custom, invalid option ' . $date);
            }

            @$search['fields']['date_posted']['custom'][] = $date;
        }
    }

    if (isset($params['date_created_preset'])) {
        if (! (bool) preg_match('/^(7d|30d|3m|6m|12m|custom|)$/', $params['date_created_preset'])) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid parameter date_created_preset');
        }

        @$search['fields']['date_created']['preset'] = $params['date_created_preset'];

        if ($search['fields']['date_created']['preset'] == 'custom' and empty($params['date_created_custom'])) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'date_created_custom is missing');
        }
    }

    if (isset($params['date_created_custom'])) {
        if (! isset($search['fields']['date_created']['preset']) or $search['fields']['date_created']['preset'] != 'custom') {
            return new PwgError(WS_ERR_INVALID_PARAM, 'date_created_custom provided date_created_preset is not custom');
        }

        foreach ($params['date_created_custom'] as $date) {
            $correct_format = false;

            $ymd = substr($date, 0, 1);
            if ($ymd == 'y') {
                if ((bool) preg_match('/^y(\d{4})$/', $date, $matches)) {
                    $correct_format = true;
                }
            } elseif ($ymd == 'm') {
                if ((bool) preg_match('/^m(\d{4}-\d{2})$/', $date, $matches)) {
                    [$year, $month] = explode('-', $matches[1]);
                    if ($month >= 1 and $month <= 12) {
                        $correct_format = true;
                    }
                }
            } elseif ($ymd == 'd') {
                if ((bool) preg_match('/^d(\d{4}-\d{2}-\d{2})$/', $date, $matches)) {
                    [$year, $month, $day] = explode('-', $matches[1]);
                    if ($month >= 1 and $month <= 12 and $day >= 1 and $day <= cal_days_in_month(CAL_GREGORIAN, (int) $month, (int) $year)) {
                        $correct_format = true;
                    }
                }
            }

            if (! $correct_format) {
                return new PwgError(WS_ERR_INVALID_PARAM, 'date_created_custom, invalid option ' . $date);
            }

            @$search['fields']['date_created']['custom'][] = $date;
        }
    }

    if (isset($params['ratios'])) {
        foreach ($params['ratios'] as $ext) {
            if (! (bool) preg_match('/^[a-z0-9]+$/i', $ext)) {
                return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid parameter ratios');
            }
        }

        $search['fields']['ratios'] = $params['ratios'];
    }

    if (isset($params['expert'])) {
        $search['fields']['expert'] = [
            'string' => $params['expert'],
        ];
    }

    if ((bool) $conf['rate'] and isset($params['ratings'])) {
        $search['fields']['ratings'] = $params['ratings'];
    }

    if (isset($params['filesize_min'])) {
        $search['fields']['filesize_min'] = $params['filesize_min'];
    }

    if (isset($params['filesize_max'])) {
        $search['fields']['filesize_max'] = $params['filesize_max'];
    }

    if (isset($params['width_min'])) {
        $search['fields']['width_min'] = $params['width_min'];
    }

    if (isset($params['width_max'])) {
        $search['fields']['width_max'] = $params['width_max'];
    }

    if (isset($params['height_min'])) {
        $search['fields']['height_min'] = $params['height_min'];
    }

    if (isset($params['height_max'])) {
        $search['fields']['height_max'] = $params['height_max'];
    }

    $search_info_id = $search_info['id'] ?? null;
    $forked_from = is_numeric($search_info_id) ? (int) $search_info_id : null;
    [$search_uuid, $search_url] = $searchService->saveSearch($search, $forked_from);

    return [
        'search_id' => $search_uuid,
        'search_url' => $search_url,
    ];
}

/**
 * API method
 * Sets the level of an image
 * @param array{image_id: array<int, int>, level: int, ...} $params
 *    image_id: WS_PARAM_FORCE_ARRAY|WS_TYPE_ID -- always coerced to a list
 *      of positive ints by PwgServer::invoke() before this runs
 *    level: WS_TYPE_INT|WS_TYPE_POSITIVE, mandatory (no 'default') -- always
 *      a plain int by the time this runs
 */
function ws_images_setPrivacyLevel(array $params, PwgServer $service): PwgError|int|string
{
    /** @var array<string, mixed> $conf */
    global $conf;

    $available_permission_levels = $conf['available_permission_levels'];
    $available_permission_levels = is_array($available_permission_levels) ? $available_permission_levels : [];
    if (! in_array($params['level'], $available_permission_levels)) {
        return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid level');
    }

    $query = '
UPDATE ' . Tables::images() . '
  SET level=' . $params['level'] . '
  WHERE id IN (' . implode(',', $params['image_id']) . ')
;';
    $result = pwg_query($query);

    pwg_activity('photo', $params['image_id'], 'edit');

    $affected_rows = pwg_db_changes();
    if ((bool) $affected_rows) {
        include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
        invalidate_user_cache();
    }
    return $affected_rows;
}

/**
 * API method
 * Sets the rank of an image in a category
 * @param array{image_id: array<int, int>, category_id: int, rank: int|null, ...} $params
 *    image_id: WS_PARAM_FORCE_ARRAY|WS_TYPE_ID -- always a list of positive
 *    ints. category_id: WS_TYPE_ID, mandatory. rank: WS_TYPE_INT|POSITIVE|
 *    NOTNULL with a null default -- int when the caller provides it, null
 *    otherwise
 * @return array<string, mixed>|PwgError
 */
function ws_images_setRank(array $params, PwgServer $service): array|PwgError
{
    if (count($params['image_id']) > 1) {
        include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

        save_images_order(
            $params['category_id'],
            $params['image_id']
        );

        $query = '
SELECT
    image_id
  FROM ' . Tables::imageCategory() . '
  WHERE category_id = ' . $params['category_id'] . '
  ORDER BY `rank` ASC
;';
        $image_ids = query2array($query, null, 'image_id');

        // return data for client
        return [
            'image_id' => $image_ids,
            'category_id' => $params['category_id'],
        ];
    }

    // turns image_id into a simple int instead of array
    $params['image_id'] = array_shift($params['image_id']);

    if (empty($params['rank'])) {
        return new PwgError(WS_ERR_MISSING_PARAM, 'rank is missing');
    }

    // does the image really exist?
    $query = '
SELECT COUNT(*)
  FROM ' . Tables::images() . '
  WHERE id = ' . $params['image_id'] . '
;';
    $row = pwg_db_fetch_row(pwg_query($query));
    assert($row !== null);
    [$count] = $row;
    if ($count == 0) {
        return new PwgError(404, 'image_id not found');
    }

    // is the image associated to this category?
    $query = '
SELECT COUNT(*)
  FROM ' . Tables::imageCategory() . '
  WHERE image_id = ' . $params['image_id'] . '
    AND category_id = ' . $params['category_id'] . '
;';
    $row = pwg_db_fetch_row(pwg_query($query));
    assert($row !== null);
    [$count] = $row;
    if ($count == 0) {
        return new PwgError(404, 'This image is not associated to this category');
    }

    // what is the current higher rank for this category?
    $query = '
SELECT MAX(`rank`) AS max_rank
  FROM ' . Tables::imageCategory() . '
  WHERE category_id = ' . $params['category_id'] . '
;';
    $row = pwg_db_fetch_assoc(pwg_query($query));
    if ($row === false || $row === null) {
        throw new Exception('ws_images_setRank(): max-rank aggregate query returned no row');
    }

    if (is_numeric($row['max_rank'])) {
        if ($params['rank'] > $row['max_rank']) {
            $params['rank'] = $row['max_rank'] + 1;
        }
    } else {
        $params['rank'] = 1;
    }

    // update rank for all other photos in the same category
    $query = '
UPDATE ' . Tables::imageCategory() . '
  SET `rank` = `rank` + 1
  WHERE category_id = ' . $params['category_id'] . '
    AND `rank` IS NOT NULL
    AND `rank` >= ' . $params['rank'] . '
;';
    pwg_query($query);

    // set the new rank for the photo
    $query = '
UPDATE ' . Tables::imageCategory() . '
  SET `rank` = ' . $params['rank'] . '
  WHERE image_id = ' . $params['image_id'] . '
    AND category_id = ' . $params['category_id'] . '
;';
    pwg_query($query);

    // return data for client
    return [
        'image_id' => $params['image_id'],
        'category_id' => $params['category_id'],
        'rank' => $params['rank'],
    ];
}

/**
 * API method
 * Adds a file chunk
 * @param array{data: string, original_sum: string, type: string, position: string, ...} $params
 *    none of these have a WS_TYPE flag; data/original_sum/position are
 *    mandatory (no 'default'), type defaults to 'file' -- all always plain
 *    strings (see PwgServer::invoke()'s array-rejection check)
 */
function ws_images_add_chunk(array $params, PwgServer $service): ?PwgError
{
    /**
     * @var array<string, mixed> $conf
     * @var Logger $logger
     */
    global $conf, $logger;

    foreach ($params as $param_key => $param_value) {
        if ($param_key == 'data') {
            continue;
        }

        $logger->debug(sprintf(
            '[ws_images_add_chunk] input param "%s" : "%s"',
            $param_key,
            is_scalar($param_value) ? $param_value : 'NULL'
        ), 'WS');
    }

    $upload_dir_conf = $conf['upload_dir'];
    $upload_dir_conf = is_string($upload_dir_conf) ? $upload_dir_conf : '';
    $upload_dir = $upload_dir_conf . '/buffer';

    // create the upload directory tree if not exists
    if (! mkgetdir($upload_dir, MKGETDIR_DEFAULT & ~MKGETDIR_DIE_ON_ERROR)) {
        return new PwgError(500, 'error during buffer directory creation');
    }

    $filename = sprintf(
        '%s-%s-%05u.block',
        $params['original_sum'],
        $params['type'],
        $params['position']
    );

    $logger->debug('[ws_images_add_chunk] data length : ' . strlen($params['data']), 'WS');

    $bytes_written = file_put_contents(
        $upload_dir . '/' . $filename,
        base64_decode($params['data'])
    );

    if ($bytes_written === false) {
        return new PwgError(
            500,
            'an error has occured while writting chunk ' . $params['position'] . ' for ' . $params['type']
        );
    }

    return null;
}

/**
 * API method
 * Adds a file
 * @param array{image_id: int, type: string, sum: string, ...} $params
 *    image_id: WS_TYPE_ID, mandatory. type: no WS_TYPE flag, defaults to
 *    'file'. sum: no WS_TYPE flag, mandatory -- both always plain strings
 */
function ws_images_addFile(array $params, PwgServer $service): PwgError|bool|null
{
    /**
     * @var array<string, mixed> $conf
     * @var Logger $logger
     */
    global $conf, $logger;

    $logger->debug(__FUNCTION__, 'WS', $params);

    // what is the path and other infos about the photo?
    $query = '
SELECT
    path, file, md5sum,
    width, height, filesize
  FROM ' . Tables::images() . '
  WHERE id = ' . $params['image_id'] . '
;';
    $result = pwg_query($query);

    if (pwg_db_num_rows($result) == 0) {
        return new PwgError(404, 'image_id not found');
    }

    $image = pwg_db_fetch_assoc($result);
    if ($image === false || $image === null) {
        throw new Exception('ws_images_addFile(): fetch failed after a non-zero pwg_db_num_rows()');
    }

    // this legacy chunked-upload flow locates buffered chunks by md5sum, so
    // it cannot proceed for a photo that has none (e.g. added before the
    // md5sum feature was enabled, see pwg.images.setMd5sum).
    if (! is_string($image['md5sum'])) {
        return new PwgError(500, '[ws_images_addFile] image_id ' . $params['image_id'] . ' has no md5sum');
    }

    // since Piwigo 2.4 and derivatives, we do not take the imported "thumb" into account
    if ($params['type'] == 'thumb') {
        remove_chunks($image['md5sum'], $params['type']);
        return true;
    }

    // since Piwigo 2.4 and derivatives, we only care about the "original"
    $original_type = 'file';
    if ($params['type'] == 'high') {
        $original_type = 'high';
    }

    $upload_dir_conf = $conf['upload_dir'];
    $upload_dir_conf = is_string($upload_dir_conf) ? $upload_dir_conf : '';
    $file_path = $upload_dir_conf . '/buffer/' . $image['md5sum'] . '-original';

    merge_chunks($file_path, $image['md5sum'], $original_type);
    chmod($file_path, 0644);

    // fill_lounge(), called indirectly via UploadService::addUploadedFile()
    // when $conf['lounge_active'] is set.
    include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

    // if we receive the "file", we only update the original if the "file" is
    // bigger than current original
    if ($params['type'] == 'file') {
        $do_update = false;

        $infos = new UploadService()
            ->pwgImageInfos($file_path);

        foreach (['width', 'height', 'filesize'] as $image_info) {
            if ($infos[$image_info] > $image[$image_info]) {
                $do_update = true;
            }
        }

        if (! $do_update) {
            unlink($file_path);
            return true;
        }
    }

    $image_id = new UploadService()
        ->addUploadedFile(
            $file_path,
            $image['file'],
            null,
            null,
            $params['image_id'],
            $image['md5sum'] // we force the md5sum to remain the same
        );

    return null;
}

/**
 * API method
 * Adds an image
 * @param array{thumbnail_sum: string|null, high_sum: string|null, original_sum: string, original_filename: string|null, name: string|null, author: string|null, date_creation: string|null, comment: string|null, categories: string|null, tag_ids: string|null, level: int, check_uniqueness: bool, image_id: int|null, ...} $params
 *    All except level/check_uniqueness/image_id have no WS_TYPE flag and a
 *    null default (or none, for the mandatory original_sum) -- always
 *    plain strings when present (see PwgServer::invoke()'s array-rejection
 *    check). level: WS_TYPE_INT|POSITIVE, default 0 (non-null) -- always
 *    int. check_uniqueness: WS_TYPE_BOOL, default true -- always bool.
 *    image_id: WS_TYPE_ID, null default -- int|null.
 * @return PwgError|array{image_id: int|string, url: string}
 */
function ws_images_add(array $params, PwgServer $service): PwgError|array
{
    /**
     * @var array<string, mixed> $conf
     * @var array<string, mixed> $user
     * @var Logger $logger
     */
    global $conf, $user, $logger;

    foreach ($params as $param_key => $param_value) {
        $logger->debug(sprintf(
            '[pwg.images.add] input param "%s" : "%s"',
            $param_key,
            is_scalar($param_value) ? $param_value : 'NULL'
        ), 'WS');
    }

    if ($params['image_id'] > 0) {
        $query = '
SELECT COUNT(*)
  FROM ' . Tables::images() . '
  WHERE id = ' . $params['image_id'] . '
;';
        $row = pwg_db_fetch_row(pwg_query($query));
        assert($row !== null);
        [$count] = $row;
        if ($count == 0) {
            return new PwgError(404, 'image_id not found');
        }
    }

    // does the image already exists ?
    if ($params['check_uniqueness']) {
        $where_clause = '0'; // no known uniqueness_mode: skip the uniqueness check
        if ($conf['uniqueness_mode'] == 'md5sum') {
            $where_clause = "md5sum = '" . $params['original_sum'] . "'";
        }
        if ($conf['uniqueness_mode'] == 'filename') {
            $where_clause = "file = '" . $params['original_filename'] . "'";
        }

        $query = '
SELECT COUNT(*)
  FROM ' . Tables::images() . '
  WHERE ' . $where_clause . '
;';
        $row = pwg_db_fetch_row(pwg_query($query));
        assert($row !== null);
        [$counter] = $row;
        if ($counter != 0) {
            return new PwgError(500, 'file already exists');
        }
    }

    // due to the new feature "derivatives" (multiple sizes) introduced for
    // Piwigo 2.4, we only take the biggest photos sent on
    // pwg.images.addChunk. If "high" is available we use it as "original"
    // else we use "file".
    remove_chunks($params['original_sum'], 'thumb');

    if (isset($params['high_sum'])) {
        $original_type = 'high';
        remove_chunks($params['original_sum'], 'file');
    } else {
        $original_type = 'file';
    }

    $upload_dir_conf = $conf['upload_dir'];
    $upload_dir_conf = is_string($upload_dir_conf) ? $upload_dir_conf : '';
    $file_path = $upload_dir_conf . '/buffer/' . $params['original_sum'] . '-original';

    merge_chunks($file_path, $params['original_sum'], $original_type);
    chmod($file_path, 0644);

    // fill_lounge(), called indirectly via UploadService::addUploadedFile()
    // when $conf['lounge_active'] is set.
    include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

    $image_id = new UploadService()
        ->addUploadedFile(
            $file_path,
            $params['original_filename'],
            null, // categories
            $params['level'],
            $params['image_id'] > 0 ? $params['image_id'] : null,
            $params['original_sum']
        );

    $info_columns = [
        'name',
        'author',
        'comment',
        'date_creation',
    ];

    $update = [];
    foreach ($info_columns as $key) {
        if (isset($params[$key])) {
            $update[$key] = $params[$key];
        }
    }

    if (count(array_keys($update)) > 0) {
        single_update(
            Tables::images(),
            $update,
            [
                'id' => $image_id,
            ]
        );
    }

    $url_params = [
        'image_id' => $image_id,
    ];

    // let's add links between the image and the categories
    if (isset($params['categories'])) {
        ws_add_image_category_relations((int) $image_id, $params['categories']);

        if ((bool) preg_match('/^\d+/', $params['categories'], $matches)) {
            $category_id = $matches[0];

            $query = '
SELECT id, name, permalink
  FROM ' . Tables::categories() . '
  WHERE id = ' . $category_id . '
;';
            $result = pwg_query($query);
            $category = pwg_db_fetch_assoc($result);

            $url_params['section'] = 'categories';
            $url_params['category'] = $category;
        }
    }

    // and now, let's create tag associations
    if (isset($params['tag_ids']) and ! empty($params['tag_ids'])) {
        set_tags(
            explode(',', $params['tag_ids']),
            (int) $image_id
        );
    }

    invalidate_user_cache();

    return [
        'image_id' => $image_id,
        'url' => make_picture_url($url_params),
    ];
}

/**
 * API method
 * Adds a image (simple way)
 * @param array{category: array<int, int>, name: string|null, author: string|null, comment: string|null, level: int, tags: string|array<array-key, string>|null, image_id: int|null, ...} $params
 *    category: WS_PARAM_FORCE_ARRAY|WS_TYPE_ID with a null default --
 *    makeArrayParam() converts the null default to [], so never null,
 *    always a list of positive ints. name/author/comment: no WS_TYPE
 *    flag, null default -- string|null. level: WS_TYPE_INT|POSITIVE,
 *    default 0 (non-null) -- always int. tags: WS_PARAM_ACCEPT_ARRAY (not
 *    FORCE), no WS_TYPE flag, null default -- string, array (if the
 *    caller uses bracket syntax), or null. image_id: WS_TYPE_ID, null
 *    default -- int|null.
 * @return PwgError|array{image_id: int|string, url: string}
 */
function ws_images_addSimple(array $params, PwgServer $service): PwgError|array
{
    /**
     * @var array<string, mixed> $conf
     * @var Logger $logger
     */
    global $conf, $logger;

    if (! isset($_FILES['image']) || ! is_array($_FILES['image'])) {
        return new PwgError(405, 'The image (file) is missing');
    }
    $uploaded_image = $_FILES['image'];

    if (isset($uploaded_image['error']) && $uploaded_image['error'] != 0) {
        $upload_error = $uploaded_image['error'];
        $message = match ($upload_error) {
            UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive in php.ini.',
            UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.',
            UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload. ' .
            'PHP does not provide a way to ascertain which extension caused the file ' .
            'upload to stop; examining the list of loaded extensions with phpinfo() may help.',
            default => 'Error number ' . (is_scalar($upload_error) ? $upload_error : 'unknown') . ' occurred while uploading a file.',
        };

        $logger->error(__FUNCTION__ . ' ' . $message);
        return new PwgError(500, $message);
    }

    if ($params['image_id'] > 0) {
        $query = '
SELECT COUNT(*)
  FROM ' . Tables::images() . '
  WHERE id = ' . $params['image_id'] . '
;';
        $row = pwg_db_fetch_row(pwg_query($query));
        assert($row !== null);
        [$count] = $row;
        if ($count == 0) {
            return new PwgError(404, 'image_id not found');
        }
    }

    $uploaded_tmp_name = $uploaded_image['tmp_name'] ?? null;
    if (! is_string($uploaded_tmp_name)) {
        return new PwgError(500, '[ws_images_addSimple] missing uploaded file temp name');
    }
    $uploaded_name = $uploaded_image['name'] ?? null;

    // fill_lounge(), called indirectly via UploadService::addUploadedFile()
    // when $conf['lounge_active'] is set.
    include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

    $image_id = new UploadService()
        ->addUploadedFile(
            $uploaded_tmp_name,
            is_string($uploaded_name) ? $uploaded_name : null,
            $params['category'],
            8,
            $params['image_id'] > 0 ? $params['image_id'] : null
        );

    $info_columns = [
        'name',
        'author',
        'comment',
        'level',
        'date_creation',
    ];

    $update = [];
    foreach ($info_columns as $key) {
        if (isset($params[$key])) {
            $update[$key] = $params[$key];
        }
    }

    single_update(
        Tables::images(),
        $update,
        [
            'id' => $image_id,
        ]
    );

    if (isset($params['tags']) and ! empty($params['tags'])) {
        include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

        $tag_ids = [];
        if (is_array($params['tags'])) {
            foreach ($params['tags'] as $tag_name) {
                $tag_ids[] = tag_id_from_tag_name($tag_name);
            }
        } else {
            $tag_names = preg_split('~(?<!\\\),~', $params['tags']);
            if ($tag_names === false) {
                throw new Exception('ws_images_addSimple(): preg_split() failed');
            }
            foreach ($tag_names as $tag_name) {
                $unescaped_tag_name = preg_replace('#\\\\*,#', ',', $tag_name);
                assert($unescaped_tag_name !== null);
                $tag_ids[] = tag_id_from_tag_name($unescaped_tag_name);
            }
        }

        add_tags($tag_ids, [(int) $image_id]);
    }

    $url_params = [
        'image_id' => $image_id,
    ];

    if (! empty($params['category'])) {
        $query = '
SELECT id, name, permalink
  FROM ' . Tables::categories() . '
  WHERE id = ' . $params['category'][0] . '
;';
        $result = pwg_query($query);
        $category = pwg_db_fetch_assoc($result);

        $url_params['section'] = 'categories';
        $url_params['category'] = $category;
    }

    // update metadata from the uploaded file (exif/iptc), even if the sync
    // was already performed by add_uploaded_file().
    new MetadataService(new MetadataRepository(DbConnection::build()))->syncMetadata([(int) $image_id]);

    return [
        'image_id' => $image_id,
        'url' => make_picture_url($url_params),
    ];
}

/**
 * API method
 * Uploads a file, chunked or whole
 *
 * @param array{name: string|null, category: array<int, int>, level: int, format_of: int|null, update_mode: bool, pwg_token: string, ...} $params
 *    name: no WS_TYPE flag, null default -- string|null. category:
 *    WS_PARAM_FORCE_ARRAY|WS_TYPE_ID, null default -- makeArrayParam()
 *    converts the null default to [], so never null. level:
 *    WS_TYPE_INT|POSITIVE, default 0 (non-null) -- always int. format_of:
 *    WS_TYPE_ID, null default -- int|null. update_mode: WS_TYPE_BOOL,
 *    default false (non-null) -- always bool. pwg_token: no WS_TYPE flag,
 *    mandatory -- always a plain string.
 * @return PwgError|array<string, mixed>|null
 */
function ws_images_upload(array $params, PwgServer $service): PwgError|array|null
{
    /** @var array<string, mixed> $conf */
    global $conf;

    // fill_lounge(), called indirectly via UploadService::addUploadedFile()
    // when $conf['lounge_active'] is set. Loaded up front rather than
    // right before use, since include_once is opaque to PHPStan's flow
    // analysis and would otherwise invalidate the $format_ext narrowing
    // below.
    include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

    $format_ext = null;

    if (get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    if (isset($params['format_of'])) {
        // are formats enabled?
        if (! (bool) $conf['enable_formats']) {
            return new PwgError(401, 'formats are disabled');
        }

        $format_ext_list = $conf['format_ext'];
        $format_ext_list = is_array($format_ext_list) ? array_filter($format_ext_list, is_string(...)) : [];

        // We must check if the extension is in the authorized list.
        if ((bool) preg_match('/\.(' . implode('|', $format_ext_list) . ')$/', (string) $params['name'], $matches)) {
            $format_ext = $matches[1];
        }

        if (empty($format_ext)) {
            return new PwgError(401, 'unexpected format extension of file "' . $params['name'] . '" (authorized extensions: ' . implode(', ', $format_ext_list) . ')');
        }
    }

    // usleep(100000);

    // if (!isset($_FILES['image']))
    // {
    //   return new PwgError(405, 'The image (file) is missing');
    // }

    // file_put_contents('/tmp/plupload.log', "[".date('c')."] ".__FUNCTION__."\n\n", FILE_APPEND);
    // file_put_contents('/tmp/plupload.log', '$_FILES = '.var_export($_FILES, true)."\n", FILE_APPEND);
    // file_put_contents('/tmp/plupload.log', '$_POST = '.var_export($_POST, true)."\n", FILE_APPEND);

    $upload_dir_conf = $conf['upload_dir'];
    $upload_dir_conf = is_string($upload_dir_conf) ? $upload_dir_conf : '';
    $upload_dir = $upload_dir_conf . '/buffer';

    // create the upload directory tree if not exists
    if (! mkgetdir($upload_dir, MKGETDIR_DEFAULT & ~MKGETDIR_DIE_ON_ERROR)) {
        return new PwgError(500, 'error during buffer directory creation');
    }

    // Get a file name
    if (isset($_REQUEST['name'])) {
        $fileName = $_REQUEST['name'];
    } elseif (! empty($_FILES) && isset($_FILES['file']) && is_array($_FILES['file'])) {
        $fileName = $_FILES['file']['name'];
    } else {
        $fileName = uniqid('file_');
    }

    // change the name of the file in the buffer to avoid any unexpected
    // extension. Function add_uploaded_file will eventually clean the mess.
    $fileName = md5(is_scalar($fileName) ? (string) $fileName : '');

    $filePath = $upload_dir . DIRECTORY_SEPARATOR . $fileName;

    // Chunking might be enabled
    $chunk = isset($_REQUEST['chunk']) && is_scalar($_REQUEST['chunk']) ? intval($_REQUEST['chunk']) : 0;
    $chunks = isset($_REQUEST['chunks']) && is_scalar($_REQUEST['chunks']) ? intval($_REQUEST['chunks']) : 0;

    // file_put_contents('/tmp/plupload.log', "[".date('c')."] ".__FUNCTION__.', '.$fileName.' '.($chunk+1).'/'.$chunks."\n", FILE_APPEND);

    // Open temp file
    if (! (bool) ($out = @fopen("{$filePath}.part", ((bool) $chunks) ? 'ab' : 'wb'))) {
        die('{"jsonrpc" : "2.0", "error" : {"code": 102, "message": "Failed to open output stream."}, "id" : "id"}');
    }

    if (! empty($_FILES)) {
        if (! isset($_FILES['file']) || ! is_array($_FILES['file'])) {
            die('{"jsonrpc" : "2.0", "error" : {"code": 103, "message": "Failed to move uploaded file."}, "id" : "id"}');
        }
        $uploaded_file = $_FILES['file'];
        $uploaded_file_tmp_name = $uploaded_file['tmp_name'] ?? null;

        if (! empty($uploaded_file['error']) || ! is_string($uploaded_file_tmp_name) || ! is_uploaded_file($uploaded_file_tmp_name)) {
            die('{"jsonrpc" : "2.0", "error" : {"code": 103, "message": "Failed to move uploaded file."}, "id" : "id"}');
        }

        // Read binary input stream and append it to temp file
        if (! (bool) ($in = @fopen($uploaded_file_tmp_name, 'rb'))) {
            die('{"jsonrpc" : "2.0", "error" : {"code": 101, "message": "Failed to open input stream."}, "id" : "id"}');
        }
    } else {
        if (! (bool) ($in = @fopen('php://input', 'rb'))) {
            die('{"jsonrpc" : "2.0", "error" : {"code": 101, "message": "Failed to open input stream."}, "id" : "id"}');
        }
    }

    while ((bool) ($buff = fread($in, 4096))) {
        fwrite($out, $buff);
    }

    @fclose($out);
    @fclose($in);

    $add_status = 'add';
    // Check if file has been uploaded
    if (! (bool) $chunks || $chunk == $chunks - 1) {
        // Strip the temp .part suffix off
        rename("{$filePath}.part", $filePath);

        if (isset($params['format_of'])) {
            $query = '
SELECT *
  FROM ' . Tables::images() . '
  WHERE id = ' . $params['format_of'] . '
;';
            $images = query2array($query);
            if (count($images) == 0) {
                return new PwgError(404, __FUNCTION__ . ' : image_id not found');
            }

            $image = $images[0];

            assert($image['id'] !== null);
            $add_status = new UploadService()
                ->addFormat($filePath, $format_ext, $image['id']);

            return [
                'image_id' => $image['id'],
                'src' => DerivativeImage::thumb_url($image),
                'square_src' => DerivativeImage::url(ImageStdParams::get_by_type(IMG_SQUARE), $image),
                'name' => $image['name'],
                'add_status' => $add_status,
            ];
        }

        $name = pwg_db_real_escape_string(stripslashes((string) $params['name']));
        $id_image = null; // null by default

        if ($params['update_mode']) {
            $query = '
SELECT
  id
  FROM ' . Tables::images() . ' AS i
    INNER JOIN ' . Tables::imageCategory() . ' as ic ON ic.image_id = i.id
  WHERE i.file = \'' . $name . '\'
  AND ic.category_id = ' . $params['category'][0] . '
;';
            $images = query2array($query);
            if ($images != null) {
                $existing_id = $images[0]['id']; // take the id of the already existing image to replace it
                $id_image = is_numeric($existing_id) ? (int) $existing_id : null;
                $add_status = 'update';
            }
        }

        $image_id = new UploadService()
            ->addUploadedFile(
                $filePath,
                $name, // function add_uploaded_file will secure before insert
                $params['category'],
                $params['level'],
                $id_image
            );

        $query = '
SELECT
    id,
    name,
    representative_ext,
    path
  FROM ' . Tables::images() . '
  WHERE id = ' . $image_id . '
;';
        $image_infos = pwg_db_fetch_assoc(pwg_query($query));
        if ($image_infos === false || $image_infos === null) {
            throw new Exception('ws_images_upload(): image fetch failed right after inserting it');
        }

        $query = '
SELECT
    COUNT(*) AS nb_photos
  FROM ' . Tables::imageCategory() . '
  WHERE category_id = ' . $params['category'][0] . '
;';
        $category_infos = pwg_db_fetch_assoc(pwg_query($query));
        if ($category_infos === false || $category_infos === null) {
            throw new Exception('ws_images_upload(): category-count aggregate query returned no row');
        }

        $query = '
SELECT
    COUNT(*)
  FROM ' . Tables::lounge() . '
  WHERE category_id = ' . $params['category'][0] . '
  AND image_id NOT IN (Select image_id from ' . Tables::imageCategory() . ')
;';
        $row = pwg_db_fetch_row(pwg_query($query));
        assert($row !== null);
        [$nb_photos_lounge] = $row;

        $category_name = new HtmlService()
            ->getCatDisplayNameFromId($params['category'][0], null);

        $nb_photos_in_category = is_numeric($category_infos['nb_photos']) ? (int) $category_infos['nb_photos'] : 0;
        $nb_photos_lounge = is_numeric($nb_photos_lounge) ? (int) $nb_photos_lounge : 0;

        return [
            'image_id' => $image_id,
            'src' => DerivativeImage::thumb_url($image_infos),
            'square_src' => DerivativeImage::url(ImageStdParams::get_by_type(IMG_SQUARE), $image_infos),
            'name' => $image_infos['name'],
            'category' => [
                'id' => $params['category'][0],
                'nb_photos' => $nb_photos_in_category + $nb_photos_lounge,
                'label' => $category_name,
            ],
            'add_status' => $add_status,
        ];
    }

    return null;
}

/**
 * API method
 * Adds a chunk of an image. Chunks don't have to be uploaded in the right sort order. When the last chunk is added, they get merged.
 * @since 11
 * @param array{username?: string, password: string|null, chunk: int, chunk_sum: string, chunks: int, original_sum: string, category: array<int, int>, filename: string, name: string|null, author: string|null, comment: string|null, date_creation: string|null, level: int, tag_ids: string|null, image_id: int|null, ...} $params
 *    username: WS_PARAM_OPTIONAL, no 'default' -- may be entirely absent
 *    from $params. password: WS_PARAM_OPTIONAL, null default. chunk/
 *    chunks: WS_TYPE_INT|POSITIVE, mandatory -- always int. chunk_sum/
 *    original_sum/filename: no WS_TYPE flag, mandatory -- always string.
 *    category: WS_PARAM_FORCE_ARRAY|WS_TYPE_ID, null default -- never
 *    null (makeArrayParam() converts to []). name/author/comment/
 *    date_creation/tag_ids: no WS_TYPE flag, null default -- string|null.
 *    level: WS_TYPE_INT|POSITIVE, default 0 (non-null) -- always int.
 *    image_id: WS_TYPE_ID, null default -- int|null.
 */
function ws_images_uploadAsync(array $params, PwgServer &$service): mixed
{
    /**
     * @var array<string, mixed> $conf
     * @var array<string, mixed> $user
     * @var Logger $logger
     */
    global $conf, $user, $logger;

    // the username/password parameters have been used in include/user.inc.php
    // to authenticate the request (a much better time/place than here)

    // additional check for some parameters
    if (! (bool) preg_match('/^[a-fA-F0-9]{32}$/', $params['original_sum'])) {
        return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid original_sum');
    }

    if ($params['image_id'] > 0) {
        $query = '
SELECT COUNT(*)
  FROM ' . Tables::images() . '
  WHERE id = ' . $params['image_id'] . '
;';
        $row = pwg_db_fetch_row(pwg_query($query));
        assert($row !== null);
        [$count] = $row;
        if ($count == 0) {
            return new PwgError(404, __FUNCTION__ . ' : image_id not found');
        }
    }

    // handle upload error as in ws_images_addSimple
    // if (isset($_FILES['image']['error']) && $_FILES['image']['error'] != 0)

    $upload_dir_conf = $conf['upload_dir'];
    $upload_dir_conf = is_string($upload_dir_conf) ? $upload_dir_conf : '';
    $user_id_raw = $user['id'] ?? null;
    $user_id_for_path = is_numeric($user_id_raw) ? (int) $user_id_raw : 0;
    $output_filepath_prefix = $upload_dir_conf . '/buffer/' . $params['original_sum'] . '-u' . $user_id_for_path;
    $chunkfile_path_pattern = $output_filepath_prefix . '-%03uof%03u.chunk';

    $chunkfile_path = sprintf($chunkfile_path_pattern, $params['chunk'] + 1, $params['chunks']);

    // create the upload directory tree if not exists
    if (! mkgetdir(dirname($chunkfile_path), MKGETDIR_DEFAULT & ~MKGETDIR_DIE_ON_ERROR)) {
        return new PwgError(500, 'error during buffer directory creation');
    }
    secure_directory(dirname($chunkfile_path));

    // move uploaded file
    $uploaded_chunk = $_FILES['file'] ?? null;
    $uploaded_chunk_tmp_name = is_array($uploaded_chunk) ? ($uploaded_chunk['tmp_name'] ?? null) : null;
    if (! is_string($uploaded_chunk_tmp_name)) {
        return new PwgError(500, 'missing uploaded chunk file');
    }
    // $chunkfile_path is relative (built from $conf['upload_dir'] without a
    // PHPWG_ROOT_PATH prefix) -- normalize to absolute before stripRoot()
    // can compute the 'uploads' disk-relative path; everything downstream
    // keeps using the original relative $chunkfile_path unchanged, since
    // the 'uploads' disk is rooted at the same real filesystem location.
    $chunk_root = PHPWG_ROOT_PATH . Config::uploadDir();
    $chunk_abs_path = PHPWG_ROOT_PATH . ltrim(str_replace(['\\', '/./'], ['/', '/'], $chunkfile_path), '/');
    $chunk_rel_path = StorageRegistry::stripRoot($chunk_root, $chunk_abs_path);
    $chunk_stream = fopen($uploaded_chunk_tmp_name, 'rb');
    if ($chunk_stream !== false) {
        StorageRegistry::disk('uploads')->writeStream($chunk_rel_path, $chunk_stream);
        fclose($chunk_stream);
    }
    $logger->debug(__FUNCTION__ . ' uploaded ' . $chunkfile_path);

    // MD5 checksum
    $chunk_md5 = md5_file($chunkfile_path);
    if ($chunk_md5 != $params['chunk_sum']) {
        unlink($chunkfile_path);
        $logger->error(__FUNCTION__ . ' ' . $chunkfile_path . ' MD5 checksum mismatched');
        return new PwgError(500, 'MD5 checksum chunk file mismatched');
    }

    // are all chunks uploaded?
    $chunk_ids_uploaded = [];
    for ($i = 1; $i <= $params['chunks']; $i++) {
        $chunkfile = sprintf($chunkfile_path_pattern, $i, $params['chunks']);
        if (file_exists($chunkfile) && ($fp = fopen($chunkfile, 'rb')) !== false) {
            $chunk_ids_uploaded[] = $i;
            fclose($fp);
        }
    }

    if ($params['chunks'] != count($chunk_ids_uploaded)) {
        // all chunks are not yet available
        $logger->debug(__FUNCTION__ . ' all chunks are not uploaded yet, maybe on next chunk, exit for now');
        return [
            'message' => 'chunks uploaded = ' . implode(',', $chunk_ids_uploaded),
        ];
    }

    // all chunks available
    $logger->debug(__FUNCTION__ . ' ' . $params['original_sum'] . ' ' . $params['chunks'] . ' chunks available, try now to get lock for merging');
    $output_filepath = $output_filepath_prefix . '.merged';

    // chunks already being merged?
    if (file_exists($output_filepath) && ($fp = fopen($output_filepath, 'rb')) !== false) {
        // merge file already exists
        fclose($fp);
        $logger->error(__FUNCTION__ . ' ' . $output_filepath . ' already exists, another merge is under process');
        return [
            'message' => 'chunks uploaded = ' . implode(',', $chunk_ids_uploaded),
        ];
    }

    // create merged and open it for writing only
    $fp = fopen($output_filepath, 'wb');
    if (! (bool) $fp) {
        // unable to create file and open it for writing only
        $logger->error(__FUNCTION__ . ' ' . $chunkfile_path . ' unable to create merge file');
        return new PwgError(500, 'error while creating merged ' . $chunkfile_path);
    }

    // acquire an exclusive lock and keep it until merge completes
    // this postpones another uploadAsync task running in another thread
    if (! flock($fp, LOCK_EX)) {
        // unable to obtain lock
        fclose($fp);
        $logger->error(__FUNCTION__ . ' ' . $chunkfile_path . ' unable to obtain lock');
        return new PwgError(500, 'error while locking merged ' . $chunkfile_path);
    }

    $logger->debug(__FUNCTION__ . ' lock obtained to merge chunks');

    // loop over all chunks
    foreach ($chunk_ids_uploaded as $chunk_id) {
        $chunkfile_path = sprintf($chunkfile_path_pattern, $chunk_id, $params['chunks']);

        // chunk deleted by preceding merge?
        if (! file_exists($chunkfile_path)) {
            // cancel merge
            $logger->error(__FUNCTION__ . ' ' . $chunkfile_path . ' already merged');
            flock($fp, LOCK_UN);
            fclose($fp);
            return [
                'message' => 'chunks uploaded = ' . implode(',', $chunk_ids_uploaded),
            ];
        }

        $chunk_contents = file_get_contents($chunkfile_path);
        if ($chunk_contents === false || ! (bool) fwrite($fp, $chunk_contents)) {
            // could not append chunk
            $logger->error(__FUNCTION__ . ' error merging chunk ' . $chunkfile_path);
            flock($fp, LOCK_UN);
            fclose($fp);

            // delete merge file without returning an error
            @unlink($output_filepath);
            return new PwgError(500, 'error while merging chunk ' . $chunk_id);
        }

        $logger->debug(__FUNCTION__ . ' original_sum=' . $params['original_sum'] . ', chunk ' . $chunk_id . '/' . $params['chunks'] . ' merged');

        // delete chunk and clear cache
        unlink($chunkfile_path);
    }

    // flush output before releasing lock
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    $logger->debug(__FUNCTION__ . ' merged file ' . $output_filepath . ' saved');

    // MD5 checksum
    $merged_md5 = md5_file($output_filepath);

    if ($merged_md5 != $params['original_sum']) {
        unlink($output_filepath);
        $logger->error(__FUNCTION__ . ' ' . $output_filepath . ' MD5 checksum mismatched!');
        return new PwgError(500, 'MD5 checksum merged file mismatched');
    }

    $logger->debug(__FUNCTION__ . ' ' . $output_filepath . ' MD5 checksum OK');

    // fill_lounge(), called indirectly via UploadService::addUploadedFile()
    // when $conf['lounge_active'] is set.
    include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

    $image_id = new UploadService()
        ->addUploadedFile(
            $output_filepath,
            $params['filename'],
            $params['category'],
            $params['level'],
            $params['image_id'],
            $params['original_sum']
        );

    $logger->debug(__FUNCTION__ . ' image_id after add_uploaded_file = ' . $image_id);

    // and now, let's create tag associations
    if (isset($params['tag_ids']) and ! empty($params['tag_ids'])) {
        set_tags(
            explode(',', $params['tag_ids']),
            (int) $image_id
        );
    }

    // time to set other infos
    $info_columns = [
        'name',
        'author',
        'comment',
        'date_creation',
    ];

    $update = [];
    foreach ($info_columns as $key) {
        if (isset($params[$key])) {
            $update[$key] = $params[$key];
        }
    }

    if (count(array_keys($update)) > 0) {
        single_update(
            Tables::images(),
            $update,
            [
                'id' => $image_id,
            ]
        );
    }

    // final step, reset user cache
    invalidate_user_cache();

    // trick to bypass get_sql_condition_FandF
    if (! empty($params['level']) and $params['level'] > $user['level']) {
        // this will not persist
        $user['level'] = $params['level'];
    }

    // delete chunks older than a week
    $now = time();
    $chunk_files = glob($upload_dir_conf . '/buffer/*.chunk');
    foreach (($chunk_files !== false ? $chunk_files : []) as $file) {
        if (is_file($file)) {
            $file_mtime = filemtime($file);
            // filemtime() can race with a concurrent cleanup pass removing
            // $file between the is_file() check above and here; skip it
            // this round rather than treat a failed stat as "old".
            if ($file_mtime !== false && $now - $file_mtime >= 60 * 60 * 24 * 7) { // 7 days
                $logger->info(__FUNCTION__ . ' delete ' . $file);
                unlink($file);
            } else {
                $logger->debug(__FUNCTION__ . ' keep ' . $file);
            }
        }
    }

    // delete merged older than a week
    $merged_files = glob($upload_dir_conf . '/buffer/*.merged');
    foreach (($merged_files !== false ? $merged_files : []) as $file) {
        if (is_file($file)) {
            $file_mtime = filemtime($file);
            // filemtime() can race with a concurrent cleanup pass removing
            // $file between the is_file() check above and here; skip it
            // this round rather than treat a failed stat as "old".
            if ($file_mtime !== false && $now - $file_mtime >= 60 * 60 * 24 * 7) { // 7 days
                $logger->info(__FUNCTION__ . ' delete ' . $file);
                unlink($file);
            } else {
                $logger->debug(__FUNCTION__ . ' keep ' . $file);
            }
        }
    }

    return $service->invoke('pwg.images.getInfo', [
        'image_id' => $image_id,
    ]);
}

/**
 * API method
 * Check if an image exists by it's name or md5 sum
 * @param array{md5sum_list: string|null, filename_list: string|null, ...} $params
 *    both: no WS_TYPE flag, null default -- string|null.
 * @return mixed[]
 */
function ws_images_exist(array $params, PwgServer $service): array
{
    /**
     * @var array<string, mixed> $conf
     * @var Logger $logger
     */
    global $conf, $logger;

    $logger->debug(__FUNCTION__, 'WS', $params);

    $split_pattern = '/[\s,;\|]/';
    $result = [];

    if ($conf['uniqueness_mode'] == 'md5sum') {
        // search among photos the list of photos already added, based on md5sum list
        $md5sums = preg_split(
            $split_pattern,
            (string) $params['md5sum_list'],
            -1,
            PREG_SPLIT_NO_EMPTY
        );
        if ($md5sums === false) {
            throw new Exception('ws_images_exist(): preg_split() failed');
        }

        $query = '
SELECT id, md5sum
  FROM ' . Tables::images() . '
  WHERE md5sum IN (\'' . implode("','", $md5sums) . '\')
;';
        $id_of_md5 = query2array($query, 'md5sum', 'id');

        foreach ($md5sums as $md5sum) {
            $result[$md5sum] = null;
            if (isset($id_of_md5[$md5sum])) {
                $result[$md5sum] = $id_of_md5[$md5sum];
            }
        }
    } elseif ($conf['uniqueness_mode'] == 'filename') {
        // search among photos the list of photos already added, based on
        // filename list
        $filenames = preg_split(
            $split_pattern,
            (string) $params['filename_list'],
            -1,
            PREG_SPLIT_NO_EMPTY
        );
        if ($filenames === false) {
            throw new Exception('ws_images_exist(): preg_split() failed');
        }

        $query = '
SELECT id, file
  FROM ' . Tables::images() . '
  WHERE file IN (\'' . implode("','", $filenames) . '\')
;';
        $id_of_filename = query2array($query, 'file', 'id');

        foreach ($filenames as $filename) {
            $result[$filename] = null;
            if (isset($id_of_filename[$filename])) {
                $result[$filename] = $id_of_filename[$filename];
            }
        }
    }

    return $result;
}

/**
 * API method
 * Check if an image exists by it's name or md5 sum
 *
 * @since 13
 * @param array{filename_list: string, ...} $params filename_list: no
 *    WS_TYPE flag, mandatory -- always a plain string.
 * @return array<int|string, array<string, mixed>>
 */
function ws_images_formats_searchImage(array $params, PwgServer $service): array
{
    /**
     * @var array<string, mixed> $conf
     * @var Logger $logger
     */
    global $conf, $logger;

    $logger->debug(__FUNCTION__, 'WS', $params);

    $candidates = json_decode(stripslashes($params['filename_list']), true);
    if (! is_array($candidates)) {
        $candidates = [];
    }
    /** @var array<int|string, mixed> $candidates */
    $unique_filenames_db = [];

    $query = '
SELECT
    id,
    file
  FROM ' . Tables::images() . '
;';
    $result = pwg_query($query);
    while ((bool) ($row = pwg_db_fetch_assoc($result))) {
        assert($row['file'] !== null);
        $filename_wo_ext = get_filename_wo_extension($row['file']);
        @$unique_filenames_db[$filename_wo_ext][] = $row['id'];
    }

    // we want "long" format extensions first to match "cmyk.jpg" before "jpg" for example
    // (kept as a local variable, not written back to $conf: the original
    // in-place usort() by reference on $conf['format_ext'] only ever
    // mutated the request-local config copy anyway, since $conf is reloaded
    // from scratch on every request)
    $format_ext_list = $conf['format_ext'];
    $format_ext_list = is_array($format_ext_list) ? array_values(array_filter($format_ext_list, is_string(...))) : [];
    usort($format_ext_list, static fn (string $a, string $b): int => strlen($b) - strlen($a));

    $query = '
SELECT
    image_id,
    ext
  FROM ' . Tables::imageFormat() . '
;';
    $result = pwg_query($query);
    $format_db = [];
    while ((bool) ($row = pwg_db_fetch_assoc($result))) {
        assert($row['image_id'] !== null);
        $format_image_id = $row['image_id'];
        @$format_db[$format_image_id][] = $row['ext'];
    }

    $result = [];

    foreach ($candidates as $format_external_id => $format_filename) {
        $candidate_filename_wo_ext = null;

        if (! is_string($format_filename)) {
            $result[$format_external_id] = [
                'status' => 'not found',
            ];
            continue;
        }

        if ((bool) preg_match('/^(.*?)\.(' . implode('|', $format_ext_list) . ')$/', $format_filename, $matches)) {
            $candidate_filename_wo_ext = $matches[1];
        }

        if (empty($candidate_filename_wo_ext)) {
            $result[$format_external_id] = [
                'status' => 'not found',
            ];
            continue;
        }

        if (isset($unique_filenames_db[$candidate_filename_wo_ext])) {
            if (count($unique_filenames_db[$candidate_filename_wo_ext]) > 1) {
                $result[$format_external_id] = [
                    'status' => 'multiple',
                ];
                continue;
            }
            $img_id = $unique_filenames_db[$candidate_filename_wo_ext][0];
            assert($img_id !== null);
            $mult_form = false;
            if (isset($format_db[$img_id])) {
                $format_ext = pathinfo($format_filename, PATHINFO_EXTENSION);
                if (array_search($format_ext, $format_db[$img_id]) !== false) {
                    $mult_form = true;
                }
            }
            $result[$format_external_id] = [
                'status' => 'found',
                'image_id' => $img_id,
                'format_exist' => $mult_form,
            ];
            continue;
        }

        $result[$format_external_id] = [
            'status' => 'not found',
        ];
    }

    return $result;
}

/**
 * API method
 * Remove a formats from the database and the file system
 *
 * @since 13
 * @param array{format_id: int|array<int, int>|null, pwg_token: string, ...} $params
 *    format_id: WS_TYPE_ID + WS_PARAM_ACCEPT_ARRAY, null default -- a
 *    plain int, a list of ints, or null. pwg_token: no WS_TYPE flag,
 *    mandatory -- always a plain string.
 */
function ws_images_formats_delete(array $params, PwgServer $service): PwgError|bool
{
    if (get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    if (! is_array($params['format_id'])) {
        $format_id_list = preg_split(
            '/[\s,;\|]/',
            (string) $params['format_id'],
            -1,
            PREG_SPLIT_NO_EMPTY
        );
        if ($format_id_list === false) {
            throw new Exception('ws_images_formats_delete(): preg_split() failed');
        }
        $params['format_id'] = $format_id_list;
    }
    $params['format_id'] = array_map(intval(...), $params['format_id']);

    $format_ids = [];
    foreach ($params['format_id'] as $format_id) {
        if ($format_id >= 0) {
            $format_ids[] = $format_id;
        }
    }

    include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

    $image_ids = [];
    $formats_of = [];

    // Delete physical file
    $ok = true;

    $query = '
SELECT
    image_id,
    ext
  FROM ' . Tables::imageFormat() . '
  WHERE format_id IN (' . implode(',', $format_ids) . ')
;';
    $result = pwg_query($query);
    while ((bool) ($row = pwg_db_fetch_assoc($result))) {
        assert($row['image_id'] !== null);
        assert($row['ext'] !== null);

        if (! isset($formats_of[$row['image_id']])) {
            $image_ids[] = $row['image_id'];
            $formats_of[$row['image_id']] = [];
        }

        $formats_of[$row['image_id']][] = $row['ext'];
    }

    if (count($image_ids) == 0) {
        return new PwgError(404, 'No format found for the id(s) given');
    }

    $query = '
SELECT
    id,
    path,
    representative_ext
  FROM ' . Tables::images() . '
  WHERE id IN (' . implode(',', $image_ids) . ')
;';
    $result = pwg_query($query);
    while ((bool) ($row = pwg_db_fetch_assoc($result))) {
        assert($row['path'] !== null);
        if (url_is_remote($row['path'])) {
            continue;
        }

        $files = [];
        $image_path = get_element_path($row);

        assert($row['id'] !== null);
        if (isset($formats_of[$row['id']])) {
            foreach ($formats_of[$row['id']] as $format_ext) {
                $files[] = original_to_format($image_path, $format_ext);
            }
        }

        foreach ($files as $path) {
            if (is_file($path) and ! unlink($path)) {
                $ok = false;
                trigger_error('"' . $path . '" cannot be removed', E_USER_WARNING);
                break;
            }
        }
    }

    // Delete format in the database
    $query = '
DELETE FROM ' . Tables::imageFormat() . '
  WHERE format_id IN (' . implode(',', $format_ids) . ')
;';
    pwg_query($query);

    invalidate_user_cache();

    return $ok;
}

/**
 * API method
 * Check is file has been update
 * @param array{image_id: int, file_sum: string|null, thumbnail_sum: string|null, high_sum: string|null, ...} $params
 *    image_id: WS_TYPE_ID, mandatory -- always int. file_sum/
 *    thumbnail_sum/high_sum: no WS_TYPE flag, null default -- string|null.
 * @return PwgError|array<string, string>
 */
function ws_images_checkFiles(array $params, PwgServer $service): PwgError|array
{
    /** @var Logger $logger */
    global $logger;

    $logger->debug(__FUNCTION__, 'WS', $params);

    $query = '
SELECT path
  FROM ' . Tables::images() . '
  WHERE id = ' . $params['image_id'] . '
;';
    $result = pwg_query($query);

    if (pwg_db_num_rows($result) == 0) {
        return new PwgError(404, 'image_id not found');
    }

    $row = pwg_db_fetch_row($result);
    assert($row !== null);
    [$path] = $row;
    assert($path !== null);

    $ret = [];

    if (isset($params['thumbnail_sum'])) {
        // We always say the thumbnail is equal to create no reaction on the
        // other side. Since Piwigo 2.4 and derivatives, the thumbnails and web
        // sizes are always generated by Piwigo
        $ret['thumbnail'] = 'equals';
    }

    if (isset($params['high_sum'])) {
        $ret['file'] = 'equals';
        $compare_type = 'high';
    } elseif (isset($params['file_sum'])) {
        $compare_type = 'file';
    }

    if (isset($compare_type)) {
        $logger->debug(__FUNCTION__ . ', md5_file($path) = ' . md5_file($path), 'WS');
        if (md5_file($path) != $params[$compare_type . '_sum']) {
            $ret[$compare_type] = 'differs';
        } else {
            $ret[$compare_type] = 'equals';
        }
    }

    $logger->debug(__FUNCTION__, 'WS', $ret);

    return $ret;
}

/**
 * API method
 * Sets details of an image
 * @param array{image_id: int, file: string|null, name: string|null, author: string|null, date_creation: string|null, comment: string|null, categories: string|null, tag_ids: string|null, level: int|null, single_value_mode: string, multiple_value_mode: string, pwg_token?: string, ...} $params
 *    image_id: WS_TYPE_ID, mandatory -- always int. file/name/author/
 *    date_creation/comment/categories/tag_ids: no WS_TYPE flag, null
 *    default -- string|null. level: WS_TYPE_INT|POSITIVE, null default --
 *    int|null. single_value_mode/multiple_value_mode: no WS_TYPE flag,
 *    non-null string defaults -- always string. pwg_token:
 *    WS_PARAM_OPTIONAL with no 'default' key -- may be entirely absent.
 */
function ws_images_setInfo(array $params, PwgServer $service): ?PwgError
{
    /** @var array<string, mixed> $conf */
    global $conf;

    if (isset($params['pwg_token']) and get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

    $query = '
SELECT *
  FROM ' . Tables::images() . '
  WHERE id = ' . $params['image_id'] . '
;';
    $result = pwg_query($query);

    if (pwg_db_num_rows($result) == 0) {
        return new PwgError(404, 'image_id not found');
    }

    $image_row = pwg_db_fetch_assoc($result);

    // database registration
    $update = [];

    $info_columns = [
        'name',
        'author',
        'comment',
        'level',
        'date_creation',
    ];

    foreach ($info_columns as $key) {
        if (isset($params[$key])) {
            if (! (bool) $conf['allow_html_descriptions'] or ! isset($params['pwg_token'])) {
                $params[$key] = strip_tags((string) $params[$key], '<b><strong><em><i>');
            }

            if ($params['single_value_mode'] == 'fill_if_empty') {
                if (empty($image_row[$key])) {
                    $update[$key] = $params[$key];
                }
            } elseif ($params['single_value_mode'] == 'replace') {
                $update[$key] = $params[$key];
            } else {
                return new PwgError(
                    500,
                    '[ws_images_setInfo]'
          . ' invalid parameter single_value_mode "' . $params['single_value_mode'] . '"'
          . ', possible values are {fill_if_empty, replace}.'
                );
            }
        }
    }

    if (isset($params['file'])) {
        if (! empty($image_row['storage_category_id'])) {
            return new PwgError(
                500,
                '[ws_images_setInfo] updating "file" is forbidden on photos added by synchronization'
            );
        }

        // prevent XSS, remove HTML tags
        $update['file'] = strip_tags($params['file']);
        if (empty($update['file'])) {
            unset($update['file']);
        }
    }

    if (count(array_keys($update)) > 0) {
        $update['id'] = $params['image_id'];

        single_update(
            Tables::images(),
            $update,
            [
                'id' => $update['id'],
            ]
        );

        pwg_activity('photo', $update['id'], 'edit');
    }

    if (isset($params['categories'])) {
        ws_add_image_category_relations(
            $params['image_id'],
            $params['categories'],
            ($params['multiple_value_mode'] == 'replace' ? true : false)
        );
    }

    // and now, let's create tag associations
    if (isset($params['tag_ids'])) {
        $tag_ids = [];

        foreach (explode(',', $params['tag_ids']) as $candidate) {
            $candidate = trim($candidate);

            if ((bool) preg_match(ValidationPattern::ID, $candidate)) {
                $tag_ids[] = $candidate;
            }
        }

        if ($params['multiple_value_mode'] == 'replace') {
            set_tags(
                $tag_ids,
                $params['image_id']
            );
        } elseif ($params['multiple_value_mode'] == 'append') {
            add_tags(
                $tag_ids,
                [$params['image_id']]
            );
        } else {
            return new PwgError(
                500,
                '[ws_images_setInfo]'
        . ' invalid parameter multiple_value_mode "' . $params['multiple_value_mode'] . '"'
        . ', possible values are {replace, append}.'
            );
        }
    }

    // Temporary use of the batch manager's unit mode,
    // not to be used by an external application,
    // as this code bellow will be deleted when a tag selector is added.
    if (isset($_REQUEST['tag_list']) && is_array($_REQUEST['tag_list'])) {
        if (isset($params['tag_ids'])) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'Do not use tag_list and tag_ids at the same time.');
        }

        // clean user input
        $cleaned_tag_list = [];
        foreach ($_REQUEST['tag_list'] as $tag_candidate) {
            $cleaned_tag_list[] = pwg_db_real_escape_string(strip_tags(stripslashes(is_scalar($tag_candidate) ? (string) $tag_candidate : '')));
        }

        // pwg_db_real_escape_string() only returns null for a null input,
        // and every element pushed above is already a string.
        $tag_list = get_tag_ids(array_filter($cleaned_tag_list, is_string(...)));
        set_tags($tag_list, $params['image_id']);
    }

    invalidate_user_cache();

    return null;
}

/**
 * API method
 * Deletes an image
 * @param array{image_id: string|array<array-key, string>, pwg_token: string, ...} $params
 *    image_id: WS_PARAM_ACCEPT_ARRAY (not FORCE), no WS_TYPE flag,
 *    mandatory -- a plain string or an array, never null. pwg_token: no
 *    WS_TYPE flag, mandatory -- always a plain string.
 */
function ws_images_delete(array $params, PwgServer $service): PwgError|int
{
    if (get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    if (! is_array($params['image_id'])) {
        $image_id_list = preg_split(
            '/[\s,;\|]/',
            $params['image_id'],
            -1,
            PREG_SPLIT_NO_EMPTY
        );
        if ($image_id_list === false) {
            throw new Exception(__FUNCTION__ . '(): preg_split() failed');
        }
        $params['image_id'] = $image_id_list;
    }
    $params['image_id'] = array_map(intval(...), $params['image_id']);

    $image_ids = [];
    foreach ($params['image_id'] as $image_id) {
        if ($image_id > 0) {
            $image_ids[] = $image_id;
        }
    }

    include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
    $ret = delete_elements($image_ids, true);
    invalidate_user_cache();

    return $ret;
}

/**
 * API method
 * Checks if Piwigo is ready for upload
 * @param mixed[] $params
 * @return array{message: ?string, ready_for_upload: bool}
 */
function ws_images_checkUpload(array $params, PwgServer $service): array
{
    $ret = [];
    $ret['message'] = new UploadService()->readyForUploadMessage();
    $ret['ready_for_upload'] = true;
    if (! empty($ret['message'])) {
        $ret['ready_for_upload'] = false;
    }

    return $ret;
}

/**
 * API method
 * Empties the lounge, where photos may wait before taking off.
 * @since 12
 * @param mixed[] $params
 * @return array{rows: mixed}
 */
function ws_images_emptyLounge(array $params, PwgServer $service): array
{
    include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

    $ret = [
        'rows' => empty_lounge(),
    ];

    return $ret;
}

/**
 * API method
 * Notify Piwigo you have finished uploading a set of photos.
 * @since 12
 * @param array{image_id: string|array<array-key, string>|null, pwg_token: string, category_id: int, ...} $params
 *    image_id: WS_PARAM_ACCEPT_ARRAY (not FORCE), no WS_TYPE flag, null
 *    default -- string, array, or null. pwg_token: no WS_TYPE flag,
 *    mandatory -- always string. category_id: WS_TYPE_ID, mandatory --
 *    always int.
 * @return PwgError|array<string, mixed>
 */
function ws_images_uploadCompleted(array $params, PwgServer $service): PwgError|array
{
    include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

    if (get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    if ($params['image_id'] === null) {
        // documented null default (no image_id filter provided) -- treat
        // the same as an empty list rather than reaching preg_split()
        // with a null subject.
        $params['image_id'] = [];
    } elseif (! is_array($params['image_id'])) {
        $image_id_list = preg_split(
            '/[\s,;\|]/',
            $params['image_id'],
            -1,
            PREG_SPLIT_NO_EMPTY
        );
        if ($image_id_list === false) {
            throw new Exception(__FUNCTION__ . '(): preg_split() failed');
        }
        $params['image_id'] = $image_id_list;
    }
    $params['image_id'] = array_map(intval(...), $params['image_id']);

    $image_ids = [];
    foreach ($params['image_id'] as $image_id) {
        if ($image_id > 0) {
            $image_ids[] = $image_id;
        }
    }

    // the list of images moved from the lounge might not be the same than
    // $image_ids (canbe a subset or more image_ids from another upload too)
    $moved_from_lounge = empty_lounge();

    $query = '
SELECT
    COUNT(*) AS nb_photos
  FROM ' . Tables::imageCategory() . '
  WHERE category_id = ' . $params['category_id'] . '
;';
    $category_infos = pwg_db_fetch_assoc(pwg_query($query));
    if ($category_infos === false || $category_infos === null) {
        throw new Exception(__FUNCTION__ . '(): category-count aggregate query returned no row');
    }
    $category_name = new HtmlService()
        ->getCatDisplayNameFromId($params['category_id'], null);

    trigger_notify(
        'ws_images_uploadCompleted',
        [
            'image_ids' => $image_ids,
            'category_id' => $params['category_id'],
            'moved_from_lounge' => $moved_from_lounge,
        ]
    );

    return [
        'moved_from_lounge' => $moved_from_lounge,
        'category' => [
            'id' => $params['category_id'],
            'nb_photos' => $category_infos['nb_photos'],
            'label' => $category_name,
        ],
    ];
}

/**
 * API method
 * add md5sum at photos, by block. Returns how md5sum were added and how many are remaining.
 * @param array{block_size: int, pwg_token: string, ...} $params
 *    block_size: WS_TYPE_INT|POSITIVE, default is a non-null $conf value
 *    -- always int. pwg_token: no WS_TYPE flag, mandatory -- always string.
 * @return PwgError|array{nb_added: int, nb_no_md5sum: int}
 */
function ws_images_setMd5sum(array $params, PwgServer $service): PwgError|array
{
    if (get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

    $no_md5sum_ids = get_photos_no_md5sum();
    $added_count = 0;

    if (count($no_md5sum_ids) > 0) {
        $md5sum_ids_to_add = array_slice($no_md5sum_ids, 0, $params['block_size']);
        $added_count = add_md5sum($md5sum_ids_to_add);
    }

    return [
        'nb_added' => $added_count,
        'nb_no_md5sum' => count(get_photos_no_md5sum()),
    ];
}

/**
 * API method
 * Synchronize metadatas photos. Returns how many metadatas were sync.
 * @param array{image_id: string|array<array-key, string>, pwg_token: string, ...} $params
 *    image_id: WS_PARAM_ACCEPT_ARRAY, no WS_TYPE flag, mandatory -- a
 *    plain string or an array, never null. pwg_token: mandatory string.
 * @return PwgError|array{nb_synchronized: int}
 */
function ws_images_syncMetadata(array $params, PwgServer $service): PwgError|array
{
    if (get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    if (! is_array($params['image_id'])) {
        $image_id_list = preg_split(
            '/[\s,;\|]/',
            $params['image_id'],
            -1,
            PREG_SPLIT_NO_EMPTY
        );
        if ($image_id_list === false) {
            throw new Exception(__FUNCTION__ . '(): preg_split() failed');
        }
        $params['image_id'] = $image_id_list;
    }

    $image_ids = [];
    foreach ($params['image_id'] as $image_id) {
        $image_id = trim($image_id);

        if (! (bool) preg_match(ValidationPattern::ID, $image_id)) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid image_id "' . $image_id . '"');
        }

        $image_ids[] = $image_id;
    }

    if (empty($image_ids)) {
        return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid image_id (no value after filters)');
    }

    $query = '
SELECT id
  FROM ' . Tables::images() . '
  WHERE id IN (' . implode(', ', $image_ids) . ')
;';
    $image_ids = query2array($query, null, 'id');

    if (empty($image_ids)) {
        return new PwgError(403, 'No image found');
    }

    $image_ids = array_map(intval(...), $image_ids);

    include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
    new MetadataService(new MetadataRepository(DbConnection::build()))->syncMetadata($image_ids);

    return [
        'nb_synchronized' => count($image_ids),
    ];
}

/**
 * API method
 * Deletes orphan photos, by block. Returns how many orphans were deleted and how many are remaining.
 * @param array{block_size: int, pwg_token: string, ...} $params
 *    block_size: WS_TYPE_INT|POSITIVE, default 1000 (non-null) -- always
 *    int. pwg_token: no WS_TYPE flag, mandatory -- always string.
 * @return PwgError|array{nb_deleted: int, nb_orphans: int}
 */
function ws_images_deleteOrphans(array $params, PwgServer $service): PwgError|array
{
    if (get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

    $orphan_ids_to_delete = array_slice(get_orphans(), 0, $params['block_size']);
    $deleted_count = delete_elements($orphan_ids_to_delete, true);
    invalidate_user_cache();

    return [
        'nb_deleted' => $deleted_count,
        'nb_orphans' => count(get_orphans()),
    ];
}

/**
 * API method
 * Associate/Dissociate/Move photos with an album.
 *
 * @since 14
 * @param array{image_id: array<int, int>, category_id: int, action: string, pwg_token: string, ...} $params
 *    image_id: WS_PARAM_FORCE_ARRAY|WS_TYPE_ID -- always a list of positive
 *    ints. category_id: WS_TYPE_ID, mandatory. action/pwg_token: no
 *    WS_TYPE flag, but always plain strings (action has a string default,
 *    pwg_token is mandatory)
 */
function ws_images_setCategory(array $params, PwgServer $service): ?PwgError
{
    if (get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    // does the category really exist?
    $query = '
SELECT
    id
  FROM ' . Tables::categories() . '
  WHERE id = ' . $params['category_id'] . '
;';
    $categories = query2array($query);

    if (count($categories) == 0) {
        return new PwgError(404, 'category_id not found');
    }

    include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

    if ($params['action'] == 'associate') {
        associate_images_to_categories($params['image_id'], [$params['category_id']]);
    } elseif ($params['action'] == 'dissociate') {
        dissociate_images_from_category($params['image_id'], $params['category_id']);
    } elseif ($params['action'] == 'move') {
        move_images_to_categories($params['image_id'], [$params['category_id']]);
    }

    invalidate_user_cache();

    return null;
}
