<?php

declare(strict_types=1);

global $persistent_cache;

use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageStdParams;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgNamedArray;
use Piwigo\Ws\PwgNamedStruct;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

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
            \Piwigo\Core\ServiceLocator::get(\Piwigo\Category\CategoryRepository::class)
                ->deleteImageCategoryByImageIds([(int) $image_id]);
            update_category([]);
        }
        return true;
    }
    $tokens = explode(';', $categories_string);
    foreach ($tokens as $token) {
        $parts = explode(',', $token);
        $cat_id = $parts[0];
        $rank = $parts[1] ?? null;

        if (!preg_match('/^\d+$/', $cat_id)) {
            continue;
        }

        $cat_ids[] = $cat_id;

        if (empty($rank)) {
            $rank = 'auto';
        }
        $rank_on_category[$cat_id] = $rank;

        if ($rank == 'auto') {
            $search_current_ranks = true;
        }
    }

    $cat_ids = array_unique($cat_ids);

    if (count($cat_ids) == 0) {
        if ($replace_mode) {
            \Piwigo\Core\ServiceLocator::get(\Piwigo\Category\CategoryRepository::class)
                ->deleteImageCategoryByImageIds([(int) $image_id]);
            update_category([]);
        }
        return true;
    }

    $query = '
SELECT id
  FROM '.CATEGORIES_TABLE.'
  WHERE id IN ('.implode(',', $cat_ids).')
;';
    $db_cat_ids = query2array($query, null, 'id');

    $unknown_cat_ids = array_diff($cat_ids, $db_cat_ids);
    if (count($unknown_cat_ids) != 0) {
        return new PwgError(
            500,
            '[ws_add_image_category_relations] the following categories are unknown: '.implode(', ', $unknown_cat_ids)
        );
    }

    $to_update_cat_ids = [];

    // in case of replace mode, we first check the existing associations
    $query = '
SELECT category_id
  FROM '.IMAGE_CATEGORY_TABLE.'
  WHERE image_id = '.$image_id.'
;';
    $existing_cat_ids = query2array($query, null, 'category_id');

    if ($replace_mode) {
        $to_remove_cat_ids = array_diff($existing_cat_ids, $cat_ids);
        if (count($to_remove_cat_ids) > 0) {
            \Piwigo\Core\ServiceLocator::get(\Piwigo\Category\CategoryRepository::class)
                ->removeImageFromCategories((int) $image_id, array_map('intval', $to_remove_cat_ids));
            update_category(array_map(fn ($v) => (int) $v, $to_remove_cat_ids));
        }
    }

    $new_cat_ids = array_diff($cat_ids, $existing_cat_ids);
    if (count($new_cat_ids) == 0) {
        return true;
    }

    if ($search_current_ranks) {
        $query = '
SELECT category_id, MAX(`rank`) AS max_rank
  FROM '.IMAGE_CATEGORY_TABLE.'
  WHERE `rank` IS NOT NULL
    AND category_id IN ('.implode(',', $new_cat_ids).')
  GROUP BY category_id
;';
        $current_rank_of = query2array(
            $query,
            'category_id',
            'max_rank'
        );

        foreach ($new_cat_ids as $cat_id) {
            if (!isset($current_rank_of[$cat_id])) {
                $current_rank_of[$cat_id] = 0;
            }

            if ('auto' == $rank_on_category[$cat_id]) {
                $rank_on_category[$cat_id] = (int) $current_rank_of[$cat_id] + 1;
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
        IMAGE_CATEGORY_TABLE,
        array_keys($inserts[0]),
        $inserts
    );

    include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');
    update_category($new_cat_ids);
    return true;
}

/**
 * Merge chunks added by pwg.images.addChunk
 */
function merge_chunks(string $output_filepath, string $original_sum, string $type): mixed
{
    $logger = \Piwigo\Core\LoggerRegistry::current();

    $logger->debug('[merge_chunks] input parameter $output_filepath : '.$output_filepath);

    if (is_file($output_filepath)) {
        unlink($output_filepath);

        if (is_file($output_filepath)) {
            return new PwgError(500, '[merge_chunks] error while trying to remove existing '.$output_filepath);
        }
    }

    $upload_dir = \Piwigo\Config\Config::uploadDir().'/buffer';
    $pattern = '/'.$original_sum.'-'.$type.'/';
    $chunks = [];

    if ($handle = opendir($upload_dir)) {
        while (false !== ($file = readdir($handle))) {
            if (preg_match($pattern, $file)) {
                $logger->debug($file);
                $chunks[] = $upload_dir.'/'.$file;
            }
        }
        closedir($handle);
    }

    sort($chunks);

    if (function_exists('memory_get_usage')) {
        $logger->debug('[merge_chunks] memory_get_usage before loading chunks: '.memory_get_usage());
    }

    $i = 0;

    foreach ($chunks as $chunk) {
        $string = file_get_contents($chunk);

        if (function_exists('memory_get_usage')) {
            $logger->debug('[merge_chunks] memory_get_usage on chunk '.++$i.': '.memory_get_usage());
        }

        if (!file_put_contents($output_filepath, $string, FILE_APPEND)) {
            return new PwgError(500, '[merge_chunks] error while writting chunks for '.$output_filepath);
        }

        unlink($chunk);
    }

    if (function_exists('memory_get_usage')) {
        $logger->debug('[merge_chunks] memory_get_usage after loading chunks: '.memory_get_usage());
    }
    return null;
}

/**
 * Deletes chunks added with pwg.images.addChunk
 * @param string $type
 *
 * Function introduced for Piwigo 2.4 and the new "multiple size"
 * (derivatives) feature. As we only need the biggest sent photo as
 * "original", we remove chunks for smaller sizes. We can't make it earlier
 * in ws_images_add_chunk because at this moment we don't know which $type
 * will be the biggest (we could remove the thumb, but let's use the same
 * algorithm)
 */
function remove_chunks(string $original_sum, string $type): void
{
    $upload_dir = \Piwigo\Config\Config::uploadDir().'/buffer';
    $pattern = '/'.$original_sum.'-'.$type.'/';
    $chunks = [];

    if ($handle = opendir($upload_dir)) {
        while (false !== ($file = readdir($handle))) {
            if (preg_match($pattern, $file)) {
                $chunks[] = $upload_dir.'/'.$file;
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
 * @param mixed[] $params
 *    @option int image_id
 *    @option string author
 *    @option string content
 *    @option string key
 */
/**
 * @return array<mixed>|\Piwigo\Ws\PwgError
 * @param array<mixed> $params
 * @param array<mixed> $params
 */
function ws_images_addComment(array $params, \Piwigo\Ws\PwgServer $service): PwgError|array
{
    if (!\Piwigo\Config\Config::activateComments()) {
        return new PwgError(403, 'Comments are disabled');
    }

    $p_image_id = is_numeric($params['image_id']) ? (int) $params['image_id'] : 0;
    $query = '
SELECT DISTINCT image_id
  FROM '. IMAGE_CATEGORY_TABLE .'
      INNER JOIN '.CATEGORIES_TABLE.' ON category_id=id
  WHERE commentable="true"
    AND image_id='.$p_image_id.
      get_sql_condition_FandF(
          [
          'forbidden_categories' => 'id',
          'visible_categories' => 'id',
          'visible_images' => 'image_id',
          ],
          ' AND'
      ).'
;';

    if (!pwg_db_num_rows(pwg_query($query))) {
        return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid image_id');
    }

    $comm = [
      'author' => trim(is_scalar($params['author']) ? (string) $params['author'] : ''),
      'content' => trim(is_scalar($params['content']) ? (string) $params['content'] : ''),
      'image_id' => $p_image_id,
     ];

    include_once(PHPWG_ROOT_PATH.'include/functions_comment.inc.php');

    $infos = [];
    $comment_action = insert_user_comment($comm, is_scalar($params['key']) ? (string) $params['key'] : '', $infos);

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
            return ['comment' => new PwgNamedStruct($ret)];

        default:
            return new PwgError(500, 'Unknown comment action '.$comment_action);
    }
}

/**
 * API method
 * Returns detailed information for an element
 * @param mixed[] $params
 *    @option int image_id
 *    @option int comments_page
 *    @option int comments_per_page
 */
/**
 * @return array<mixed>|\Piwigo\Ws\PwgError
 * @param array<mixed> $params
 * @param array<mixed> $params
 */
function ws_images_getInfo(array $params, \Piwigo\Ws\PwgServer $service): PwgError|array
{
    $p_image_id = is_numeric($params['image_id']) ? (int) $params['image_id'] : 0;
    $query = '
SELECT *
  FROM '. IMAGES_TABLE .'
  WHERE id='. $p_image_id .
      get_sql_condition_FandF(
          ['visible_images' => 'id'],
          ' AND'
      ).'
LIMIT 1
;';
    $result = pwg_query($query);

    if (pwg_db_num_rows($result) == 0) {
        return new PwgError(404, 'image_id not found');
    }

    $image_row = pwg_db_fetch_assoc($result);
    if ($image_row === null) {
        return new PwgError(404, 'image_id not found');
    }
    /** @var array<string, mixed> $image_row */
    $image_row = array_merge($image_row, ws_std_get_urls($image_row));
    $image_row_id = is_numeric($image_row['id']) ? (int) $image_row['id'] : 0;
    $image_row_file = is_scalar($image_row['file']) ? (string) $image_row['file'] : '';

    $image_row['name_raw'] = $image_row['name'];
    $render_name = trigger_change('render_element_name', $image_row['name'], __FUNCTION__);
    $image_row['name'] = strip_tags(is_scalar($render_name) ? (string) $render_name : '');

    $image_row['comment_raw'] = $image_row['comment'];
    $image_row['comment'] = trigger_change(
        'render_element_description',
        $image_row['comment'],
        __FUNCTION__
    );

    //-------------------------------------------------------- related categories
    $query = '
SELECT id, name, permalink, uppercats, global_rank, commentable
  FROM '. IMAGE_CATEGORY_TABLE .'
    INNER JOIN '. CATEGORIES_TABLE .' ON category_id = id
  WHERE image_id = '. $image_row_id .
      get_sql_condition_FandF(
          ['forbidden_categories' => 'category_id'],
          ' AND'
      ).'
;';
    $result = pwg_query($query);

    $is_commentable = false;
    $related_categories = [];
    while ($row = pwg_db_fetch_assoc($result)) {
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
            'image_id' => $image_row_id,
            'image_file' => $image_row_file,
            'category' => $row,
            ]
        );

        $row['id'] = (int) $row['id'];

        $cat_name_raw = trigger_change('render_category_name', $row['name'], __FUNCTION__);
        $row['name'] = strip_tags(is_scalar($cat_name_raw) ? (string) $cat_name_raw : '');

        $related_categories[] = $row;
    }
    usort($related_categories, global_rank_compare(...));

    if (empty($related_categories) and !is_admin()) {
        // photo might be in the lounge? or simply orphan. A standard user should not get
        // info. An admin should still be able to get info.
        return new PwgError(401, 'Access denied');
    }

    //-------------------------------------------------------------- related tags
    /** @var list<array<string, mixed>> $related_tags */
    $related_tags = get_common_tags([$image_row_id], -1);
    foreach ($related_tags as $i => $tag) {
        $tag['url'] = make_index_url(
            [
            'tags' => [$tag],
            ]
        );
        $tag['page_url'] = make_picture_url(
            [
            'image_id' => $image_row_id,
            'image_file' => $image_row_file,
            'tags' => [$tag],
            ]
        );

        unset($tag['counter']);
        $tag['id'] = is_numeric($tag['id']) ? (int) $tag['id'] : 0;
        $related_tags[$i] = $tag;
    }

    //------------------------------------------------------------- related rates
    $rating = [
    'score' => $image_row['rating_score'],
    'count' => 0,
    'average' => null,
    ];
    if (isset($rating['score'])) {
        $query = '
SELECT COUNT(rate) AS count, ROUND(AVG(rate),2) AS average
  FROM '. RATE_TABLE .'
  WHERE element_id = '. $image_row_id .'
;';
        $row = pwg_db_fetch_assoc(pwg_query($query));

        $rating['score'] = is_numeric($rating['score']) ? (float) $rating['score'] : 0.0;
        if ($row !== null) {
            $rating['average'] = is_numeric($row['average']) ? (float) $row['average'] : null;
            $rating['count'] = is_numeric($row['count']) ? (int) $row['count'] : 0;
        }
    }

    //---------------------------------------------------------- related comments
    $related_comments = [];

    $where_comments = 'image_id = '.$image_row_id;
    if (!is_admin()) {
        $where_comments .= ' AND validated="true"';
    }

    $query = '
SELECT COUNT(id) AS nb_comments
  FROM '. COMMENTS_TABLE .'
  WHERE '. $where_comments .'
;';
    [$nb_comments] = query2array($query, null, 'nb_comments');
    $nb_comments = (int)$nb_comments;

    $p_comments_per_page = is_numeric($params['comments_per_page']) ? (int) $params['comments_per_page'] : 0;
    $p_comments_page = is_numeric($params['comments_page']) ? (int) $params['comments_page'] : 0;
    if ($nb_comments > 0 and $p_comments_per_page > 0) {
        $query = '
SELECT id, date, author, content
  FROM '. COMMENTS_TABLE .'
  WHERE '. $where_comments .'
  ORDER BY date
  LIMIT '. $p_comments_per_page .'
  OFFSET '. ($p_comments_per_page * $p_comments_page) .'
;';
        $result = pwg_query($query);

        while ($row = pwg_db_fetch_assoc($result)) {
            $row['id'] = (int) $row['id'];
            $related_comments[] = $row;
        }
    }

    $comment_post_data = null;
    if (\Piwigo\Config\Config::activateComments() and
        $is_commentable and
        (!is_a_guest()
          or \Piwigo\Config\Config::commentsForall()
        )
    ) {
        $comment_post_data['author'] = stripslashes(\Piwigo\Users\CurrentUser::get()->username);
        $comment_post_data['key'] = get_ephemeral_key(2, (string) $p_image_id);
    }

    $ret = $image_row;
    foreach (['id','width','height','hit','filesize'] as $k) {
        if (isset($ret[$k])) {
            $ret[$k] = is_numeric($ret[$k]) ? (int) $ret[$k] : 0;
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
        ['id','url', 'page_url']
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
        ['id','date']
    );

    if ($service->getResponseFormat() != 'rest') {
        return $ret; // for backward compatibility only
    } else {
        return [
          'image' => new PwgNamedStruct($ret, null, ['name','comment']),
          ];
    }
}

/**
 * API method
 * Rates an image
 * @param mixed[] $params
 *    @option int image_id
 *    @option float rate
 */
/**
 * @param array<mixed> $params
 * @param array<mixed> $params
 */
function ws_images_rate(array $params, \Piwigo\Ws\PwgServer $service): mixed
{
    $p_image_id = is_numeric($params['image_id']) ? (int) $params['image_id'] : 0;
    $p_rate = is_numeric($params['rate']) ? (int) $params['rate'] : 0;
    $query = '
SELECT DISTINCT id
  FROM '. IMAGES_TABLE .'
    INNER JOIN '. IMAGE_CATEGORY_TABLE .' ON id=image_id
  WHERE id='. $p_image_id
      .get_sql_condition_FandF(
          [
          'forbidden_categories' => 'category_id',
          'forbidden_images' => 'id',
          ],
          '    AND'
      ).'
  LIMIT 1
;';
    if (pwg_db_num_rows(pwg_query($query)) == 0) {
        return new PwgError(404, 'Invalid image_id or access denied');
    }

    include_once(PHPWG_ROOT_PATH.'include/functions_rate.inc.php');
    $res = rate_picture($p_image_id, $p_rate);

    if ($res == false) {
        return new PwgError(403, 'Forbidden or rate not in '. implode(',', \Piwigo\Config\Config::rateItems()));
    }
    return $res;
}

/**
 * API method
 * Returns a list of elements corresponding to a query search
 * @param mixed[] $params
 *    @option string query
 *    @option int per_page
 *    @option int page
 *    @option string order (optional)
 */
/**
 * @return array<mixed>
 * @param array<mixed> $params
 * @param array<mixed> $params
 */
function ws_images_search(array $params, \Piwigo\Ws\PwgServer $service): array
{
    include_once(PHPWG_ROOT_PATH .'include/functions_search.inc.php');

    $p_query = is_scalar($params['query']) ? (string) $params['query'] : '';
    $p_page = is_numeric($params['page']) ? (int) $params['page'] : 0;
    $p_per_page = is_numeric($params['per_page']) ? (int) $params['per_page'] : 0;
    $images = [];
    /** @var array<string> $where_clauses */
    $where_clauses = ws_std_image_sql_filter($params, 'i.');
    $order_by = ws_std_image_sql_order($params, 'i.');

    $super_order_by = false;
    if (!empty($order_by)) {
        \Piwigo\Config\Config::override('order_by', 'ORDER BY '.$order_by);
        $super_order_by = true; // quick_search_result might be faster
    }

    $search_result = get_quick_search_results(
        $p_query,
        [
        'super_order_by' => $super_order_by,
        'images_where' => implode(' AND ', $where_clauses),
    ]
    );

    $search_items = is_array($search_result['items'] ?? null) ? $search_result['items'] : [];
    $image_ids = array_slice(
        $search_items,
        $p_page * $p_per_page,
        $p_per_page
    );

    if (count($image_ids)) {
        $image_ids_int = array_map(fn ($v) => is_numeric($v) ? (int) $v : 0, $image_ids);
        $image_ids_flip = array_flip($image_ids_int);
        $favorite_ids = get_user_favorites();

        foreach (\Piwigo\Core\ServiceLocator::get(\Piwigo\Image\ImageRepository::class)
            ->findByIds($image_ids_int) as $row) {
            $image = [];
            $row_id = is_scalar($row['id']) ? (string) $row['id'] : '';
            $image['is_favorite'] = $row_id !== '' && isset($favorite_ids[$row_id]);
            foreach (['id', 'width', 'height', 'hit'] as $k) {
                if (isset($row[$k])) {
                    $image[$k] = is_numeric($row[$k]) ? (int) $row[$k] : 0;
                }
            }
            foreach (['file', 'name', 'comment', 'date_creation', 'date_available'] as $k) {
                $image[$k] = $row[$k];
            }

            $name_raw = trigger_change('render_element_name', $image['name'] ?? '', __FUNCTION__);
            $image['name'] = strip_tags(is_string($name_raw) ? $name_raw : (string) $name_raw);
            $image['comment'] = trigger_change('render_element_description', $image['comment'] ?? null, __FUNCTION__);

            $image = array_merge($image, ws_std_get_urls($row));
            $img_id_int = is_numeric($image['id']) ? (int) $image['id'] : 0;
            if (isset($image_ids_flip[$img_id_int])) {
                $images[$image_ids_flip[$img_id_int]] = $image;
            }
        }
        ksort($images, SORT_NUMERIC);
        $images = array_values($images);
    }

    return [
      'paging' => new PwgNamedStruct(
          [
          'page' => $p_page,
          'per_page' => $p_per_page,
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
 * @param mixed[] $params
 *    @option string query
 */
/**
 * @return array<mixed>|\Piwigo\Ws\PwgError
 * @param array<mixed> $params
 * @param array<mixed> $params
 */
function ws_images_filteredSearch_create(array $params, \Piwigo\Ws\PwgServer $service): PwgError|array
{
    include_once(PHPWG_ROOT_PATH.'include/functions_search.inc.php');

    // * check the search exists
    $search_info = null;
    if (isset($params['search_id'])) {
        $p_search_id = is_scalar($params['search_id']) ? (string) $params['search_id'] : '';
        if (empty(get_search_id_pattern($p_search_id))) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid search_id input parameter.');
        }

        $search_info = get_search_info($p_search_id);
        if (empty($search_info)) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'This search does not exist.');
        }
    }

    $search = ['mode' => 'AND'];

    // * check all parameters
    if (isset($params['allwords'])) {
        $search['fields']['allwords'] = [];

        if (!isset($params['allwords_mode'])) {
            $params['allwords_mode'] = 'AND';
        }
        $p_allwords_mode = is_scalar($params['allwords_mode']) ? (string) $params['allwords_mode'] : '';
        if (!preg_match('/^(OR|AND)$/', $p_allwords_mode)) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid parameter allwords_mode');
        }
        $search['fields']['allwords']['mode'] = $p_allwords_mode;

        $allwords_fields_available = ['name', 'comment', 'file', 'author', 'tags', 'cat-title', 'cat-desc'];
        if (!isset($params['allwords_fields'])) {
            $params['allwords_fields'] = $allwords_fields_available;
        }
        $p_allwords_fields = is_array($params['allwords_fields']) ? $params['allwords_fields'] : [];
        foreach ($p_allwords_fields as $field) {
            if (!in_array($field, $allwords_fields_available)) {
                return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid parameter allwords_fields');
            }
        }
        $search['fields']['allwords']['fields'] = $p_allwords_fields;

        $search['fields']['allwords']['words'] = split_allwords(is_scalar($params['allwords']) ? (string) $params['allwords'] : '');
    }

    if (isset($params['tags'])) {
        $p_tags = is_array($params['tags']) ? $params['tags'] : [];
        foreach ($p_tags as $tag_id) {
            if (!preg_match('/^\d+$/', is_scalar($tag_id) ? (string) $tag_id : '')) {
                return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid parameter tags');
            }
        }

        if (!isset($params['tags_mode'])) {
            $params['tags_mode'] = 'AND';
        }
        $p_tags_mode = is_scalar($params['tags_mode']) ? (string) $params['tags_mode'] : '';
        if (!preg_match('/^(OR|AND)$/', $p_tags_mode)) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid parameter tags_mode');
        }

        $search['fields']['tags'] = [
          'words' => $p_tags,
          'mode'  => $p_tags_mode,
        ];
    }

    if (isset($params['categories'])) {
        $p_categories = is_array($params['categories']) ? $params['categories'] : [];
        foreach ($p_categories as $cat_id) {
            if (!preg_match('/^\d+$/', is_scalar($cat_id) ? (string) $cat_id : '')) {
                return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid parameter categories');
            }
        }

        $search['fields']['cat'] = [
          'words'   => $p_categories,
          'sub_inc' => $params['categories_withsubs'] ?? false,
        ];
    }

    if (isset($params['authors'])) {
        $authors = [];

        $p_authors = is_array($params['authors']) ? $params['authors'] : [];
        foreach ($p_authors as $author) {
            $authors[] = strip_tags(is_scalar($author) ? (string) $author : '');
        }

        $search['fields']['author'] = [
          'words' => $authors,
          'mode' => 'OR',
        ];
    }

    if (isset($params['filetypes'])) {
        $p_filetypes = is_array($params['filetypes']) ? $params['filetypes'] : [];
        foreach ($p_filetypes as $ext) {
            if (!preg_match('/^[a-z0-9]+$/i', is_scalar($ext) ? (string) $ext : '')) {
                return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid parameter filetypes');
            }
        }

        $search['fields']['filetypes'] = $p_filetypes;
    }

    if (isset($params['added_by'])) {
        $p_added_by = is_array($params['added_by']) ? $params['added_by'] : [];
        foreach ($p_added_by as $user_id) {
            if (!preg_match('/^\d+$/', is_scalar($user_id) ? (string) $user_id : '')) {
                return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid parameter added_by');
            }
        }

        $search['fields']['added_by'] = $p_added_by;
    }

    if (isset($params['date_posted_preset'])) {
        $p_date_posted_preset = is_scalar($params['date_posted_preset']) ? (string) $params['date_posted_preset'] : '';
        if (!preg_match('/^(24h|7d|30d|3m|6m|custom|)$/', $p_date_posted_preset)) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid parameter date_posted_preset');
        }

        $search['fields']['date_posted'] = ['preset' => $p_date_posted_preset];

        if ('custom' == $p_date_posted_preset and empty($params['date_posted_custom'])) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'date_posted_custom is missing');
        }
    }

    if (isset($params['date_posted_custom'])) {
        if (!isset($search['fields']['date_posted']['preset']) or $search['fields']['date_posted']['preset'] != 'custom') {
            return new PwgError(WS_ERR_INVALID_PARAM, 'date_posted_custom provided date_posted_preset is not custom');
        }

        $p_date_posted_custom = is_array($params['date_posted_custom']) ? $params['date_posted_custom'] : [];
        foreach ($p_date_posted_custom as $date) {
            $correct_format = false;
            $date_str = is_scalar($date) ? (string) $date : '';

            $ymd = substr($date_str, 0, 1);
            if ('y' == $ymd) {
                if (preg_match('/^y(\d{4})$/', $date_str, $matches)) {
                    $correct_format = true;
                }
            } elseif ('m' == $ymd) {
                if (preg_match('/^m(\d{4}-\d{2})$/', $date_str, $matches)) {
                    [$year, $month] = explode('-', $matches[1]);
                    if ($month >= 1 and $month <= 12) {
                        $correct_format = true;
                    }
                }
            } elseif ('d' == $ymd) {
                if (preg_match('/^d(\d{4}-\d{2}-\d{2})$/', $date_str, $matches)) {
                    [$year, $month, $day] = explode('-', $matches[1]);
                    if ($month >= 1 and $month <= 12 and $day >= 1 and $day <= cal_days_in_month(CAL_GREGORIAN, (int)$month, (int)$year)) {
                        $correct_format = true;
                    }
                }
            }

            if (!$correct_format) {
                return new PwgError(WS_ERR_INVALID_PARAM, 'date_posted_custom, invalid option '.$date_str);
            }

            $search['fields']['date_posted']['custom'][] = $date_str;
        }
    }

    if (isset($params['date_created_preset'])) {
        $p_date_created_preset = is_scalar($params['date_created_preset']) ? (string) $params['date_created_preset'] : '';
        if (!preg_match('/^(7d|30d|3m|6m|12m|custom|)$/', $p_date_created_preset)) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid parameter date_created_preset');
        }

        $search['fields']['date_created']['preset'] = $p_date_created_preset;

        if ('custom' == $p_date_created_preset and empty($params['date_created_custom'])) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'date_created_custom is missing');
        }
    }

    if (isset($params['date_created_custom'])) {
        if (!isset($search['fields']['date_created']['preset']) or $search['fields']['date_created']['preset'] != 'custom') {
            return new PwgError(WS_ERR_INVALID_PARAM, 'date_created_custom provided date_created_preset is not custom');
        }

        $p_date_created_custom = is_array($params['date_created_custom']) ? $params['date_created_custom'] : [];
        foreach ($p_date_created_custom as $date) {
            $correct_format = false;
            $date_str = is_scalar($date) ? (string) $date : '';

            $ymd = substr($date_str, 0, 1);
            if ('y' == $ymd) {
                if (preg_match('/^y(\d{4})$/', $date_str, $matches)) {
                    $correct_format = true;
                }
            } elseif ('m' == $ymd) {
                if (preg_match('/^m(\d{4}-\d{2})$/', $date_str, $matches)) {
                    [$year, $month] = explode('-', $matches[1]);
                    if ($month >= 1 and $month <= 12) {
                        $correct_format = true;
                    }
                }
            } elseif ('d' == $ymd) {
                if (preg_match('/^d(\d{4}-\d{2}-\d{2})$/', $date_str, $matches)) {
                    [$year, $month, $day] = explode('-', $matches[1]);
                    if ($month >= 1 and $month <= 12 and $day >= 1 and $day <= cal_days_in_month(CAL_GREGORIAN, (int)$month, (int)$year)) {
                        $correct_format = true;
                    }
                }
            }

            if (!$correct_format) {
                return new PwgError(WS_ERR_INVALID_PARAM, 'date_created_custom, invalid option '.$date_str);
            }

            $search['fields']['date_created']['custom'][] = $date_str;
        }
    }

    if (isset($params['ratios'])) {
        $p_ratios = is_array($params['ratios']) ? $params['ratios'] : [];
        foreach ($p_ratios as $ext) {
            if (!preg_match('/^[a-z0-9]+$/i', is_scalar($ext) ? (string) $ext : '')) {
                return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid parameter ratios');
            }
        }

        $search['fields']['ratios'] = $p_ratios;
    }

    if (isset($params['expert'])) {
        $search['fields']['expert'] = ['string' => $params['expert']];
    }

    if (\Piwigo\Config\Config::rateEnabled() and isset($params['ratings'])) {
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

    $forked_from = isset($search_info['id']) && is_scalar($search_info['id']) ? (string) $search_info['id'] : null;
    [$search_uuid, $search_url] = save_search($search, $forked_from);

    return [
      'search_id' => $search_uuid,
      'search_url' => $search_url,
    ];
}

/**
 * API method
 * Sets the level of an image
 * @param mixed[] $params
 *    @option int image_id
 *    @option int level
 */
/**
 * @param array<mixed> $params
 * @param array<mixed> $params
 */
function ws_images_setPrivacyLevel(array $params, \Piwigo\Ws\PwgServer $service): mixed
{
    if (!in_array($params['level'], \Piwigo\Config\Config::availablePermissionLevels())) {
        return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid level');
    }

    $p_level = is_numeric($params['level']) ? (int) $params['level'] : 0;
    $p_image_ids = is_array($params['image_id']) ? array_map(fn ($v) => is_numeric($v) ? (int) $v : 0, $params['image_id']) : [];
    $affected_rows = \Piwigo\Core\ServiceLocator::get(\Piwigo\Image\ImageRepository::class)
        ->setLevelForIds($p_level, $p_image_ids);

    pwg_activity('photo', $p_image_ids, 'edit');
    if ($affected_rows) {
        include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');
        invalidate_user_cache();
    }
    return $affected_rows;
}

/**
 * API method
 * Sets the rank of an image in a category
 * @param mixed[] $params
 *    @option int image_id
 *    @option int category_id
 *    @option int rank
 */
/**
 * @param array<mixed> $params
 * @return array<mixed>
 * @param array<mixed> $params
 */function ws_images_setRank(array $params, \Piwigo\Ws\PwgServer $service): array|PwgError
{
    $p_image_id_arr = is_array($params['image_id']) ? array_map(fn ($v) => is_numeric($v) ? (int) $v : 0, $params['image_id']) : [];
    $p_category_id = is_numeric($params['category_id']) ? (int) $params['category_id'] : 0;
    if (count($p_image_id_arr) > 1) {
        include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

        save_images_order(
            $p_category_id,
            $p_image_id_arr
        );

        $query = '
SELECT
    image_id
  FROM '.IMAGE_CATEGORY_TABLE.'
  WHERE category_id = '.$p_category_id.'
  ORDER BY `rank` ASC
;';
        $image_ids = query2array($query, null, 'image_id');

        // return data for client
        return [
          'image_id' => $image_ids,
          'category_id' => $p_category_id,
          ];
    }

    // turns image_id into a simple int instead of array
    $p_image_id = $p_image_id_arr[0] ?? 0;

    if (empty($params['rank'])) {
        return new PwgError(WS_ERR_MISSING_PARAM, 'rank is missing');
    }

    $catRepo = \Piwigo\Core\ServiceLocator::get(\Piwigo\Category\CategoryRepository::class);

    // does the image really exist?
    if (!\Piwigo\Core\ServiceLocator::get(\Piwigo\Image\ImageRepository::class)->existsById($p_image_id)) {
        return new PwgError(404, 'image_id not found');
    }

    // is the image associated to this category?
    if (!$catRepo->hasImageInCategory($p_image_id, $p_category_id)) {
        return new PwgError(404, 'This image is not associated to this category');
    }

    $p_rank = is_numeric($params['rank']) ? (int) $params['rank'] : 1;

    // what is the current higher rank for this category?
    $max_rank = $catRepo->findMaxRankInCategory($p_category_id);

    if ($max_rank !== null) {
        if ($p_rank > $max_rank) {
            $p_rank = $max_rank + 1;
        }
    } else {
        $p_rank = 1;
    }

    // update rank for all other photos in the same category
    $catRepo->incrementRanksFrom($p_category_id, $p_rank);

    // set the new rank for the photo
    $catRepo->setImageRank($p_image_id, $p_category_id, $p_rank);

    // return data for client
    return [
      'image_id' => $p_image_id,
      'category_id' => $p_category_id,
      'rank' => $p_rank,
      ];
}

/**
 * API method
 * Adds a file chunk
 * @param mixed[] $params
 *    @option string data
 *    @option string original_sum
 *    @option string type = 'file'
 *    @option int position
 */
/**
 * @param array<mixed> $params
 * @param array<mixed> $params
 */
function ws_images_add_chunk(array $params, \Piwigo\Ws\PwgServer $service): mixed
{
    $logger = \Piwigo\Core\LoggerRegistry::current();

    foreach ($params as $param_key => $param_value) {
        if ('data' == $param_key) {
            continue;
        }

        $logger->debug(sprintf(
            '[ws_images_add_chunk] input param "%s" : "%s"',
            $param_key,
            is_scalar($param_value) ? (string) $param_value : 'NULL'
        ));
    }

    $upload_dir = \Piwigo\Config\Config::uploadDir().'/buffer';

    // create the upload directory tree if not exists
    if (!mkgetdir($upload_dir, MKGETDIR_DEFAULT & ~MKGETDIR_DIE_ON_ERROR)) {
        return new PwgError(500, 'error during buffer directory creation');
    }

    $p_original_sum = is_scalar($params['original_sum']) ? (string) $params['original_sum'] : '';
    $p_type = is_scalar($params['type']) ? (string) $params['type'] : '';
    $p_position = is_numeric($params['position']) ? (int) $params['position'] : 0;
    $p_data = is_scalar($params['data']) ? (string) $params['data'] : '';
    $filename = sprintf(
        '%s-%s-%05u.block',
        $p_original_sum,
        $p_type,
        $p_position
    );

    $logger->debug('[ws_images_add_chunk] data length : '.strlen($p_data));

    $bytes_written = file_put_contents(
        $upload_dir.'/'.$filename,
        base64_decode($p_data)
    );

    if (false === $bytes_written) {
        return new PwgError(
            500,
            'an error has occured while writting chunk '.$p_position.' for '.$p_type
        );
    }
    return null;
}

/**
 * API method
 * Adds a file
 * @param mixed[] $params
 *    @option int image_id
 *    @option string type = 'file'
 *    @option string sum
 */
/**
 * @param array<mixed> $params
 * @param array<mixed> $params
 */
function ws_images_addFile(array $params, \Piwigo\Ws\PwgServer $service): mixed
{
    $logger = \Piwigo\Core\LoggerRegistry::current();

    $logger->debug(__FUNCTION__, $params);

    $p_image_id = is_numeric($params['image_id']) ? (int) $params['image_id'] : 0;
    $p_type_af = is_scalar($params['type']) ? (string) $params['type'] : '';
    // what is the path and other infos about the photo?
    $image = \Piwigo\Core\ServiceLocator::get(\Piwigo\Image\ImageRepository::class)
        ->findById($p_image_id);
    if ($image === null) {
        return new PwgError(404, 'image_id not found');
    }

    $image_md5sum = is_scalar($image['md5sum']) ? (string) $image['md5sum'] : '';
    $image_file = is_scalar($image['file']) ? (string) $image['file'] : '';

    // since Piwigo 2.4 and derivatives, we do not take the imported "thumb" into account
    if ('thumb' == $p_type_af) {
        remove_chunks($image_md5sum, $p_type_af);
        return true;
    }

    // since Piwigo 2.4 and derivatives, we only care about the "original"
    $original_type = 'file';
    if ('high' == $p_type_af) {
        $original_type = 'high';
    }

    $file_path = \Piwigo\Config\Config::uploadDir().'/buffer/'.$image_md5sum.'-original';

    merge_chunks($file_path, $image_md5sum, $original_type);
    chmod($file_path, 0644);

    include_once(PHPWG_ROOT_PATH.'admin/include/functions_upload.inc.php');

    // if we receive the "file", we only update the original if the "file" is
    // bigger than current original
    if ('file' == $p_type_af) {
        $do_update = false;

        $infos = pwg_image_infos($file_path);

        foreach (['width', 'height', 'filesize'] as $image_info) {
            if ($infos[$image_info] > $image[$image_info]) {
                $do_update = true;
            }
        }

        if (!$do_update) {
            unlink($file_path);
            return true;
        }
    }

    $image_id = add_uploaded_file(
        $file_path,
        $image_file,
        null,
        null,
        $p_image_id,
        $image_md5sum // we force the md5sum to remain the same
    );
    return null;
}

/**
 * API method
 * Adds an image
 * @param mixed[] $params
 *    @option string original_sum
 *    @option string original_filename (optional)
 *    @option string name (optional)
 *    @option string author (optional)
 *    @option string date_creation (optional)
 *    @option string comment (optional)
 *    @option string categories (optional) - "cat_id[,rank];cat_id[,rank]"
 *    @option string tags_ids (optional) - "tag_id,tag_id"
 *    @option int level
 *    @option bool check_uniqueness
 *    @option int image_id (optional)
 */
/**
 * @return array<mixed>|\Piwigo\Ws\PwgError
 * @param array<mixed> $params
 * @param array<mixed> $params
 */
function ws_images_add(array $params, \Piwigo\Ws\PwgServer $service): PwgError|array
{
    $logger = \Piwigo\Core\LoggerRegistry::current();

    foreach ($params as $param_key => $param_value) {
        $logger->debug(sprintf(
            '[pwg.images.add] input param "%s" : "%s"',
            $param_key,
            is_scalar($param_value) ? (string) $param_value : 'NULL'
        ));
    }

    $p_image_id = is_numeric($params['image_id']) ? (int) $params['image_id'] : 0;
    $p_original_sum = is_scalar($params['original_sum']) ? (string) $params['original_sum'] : '';
    $p_original_filename = is_scalar($params['original_filename']) ? (string) $params['original_filename'] : null;
    $p_level = isset($params['level']) && is_numeric($params['level']) ? (int) $params['level'] : null;

    if ($p_image_id > 0 && !\Piwigo\Core\ServiceLocator::get(\Piwigo\Image\ImageRepository::class)->existsById($p_image_id)) {
        return new PwgError(404, 'image_id not found');
    }

    // does the image already exists ?
    if ($params['check_uniqueness']) {
        $where_clause = '1=1';
        if ('md5sum' == \Piwigo\Config\Config::uniquenessMode()) {
            $where_clause = "md5sum = '".$p_original_sum."'";
        }
        if ('filename' == \Piwigo\Config\Config::uniquenessMode()) {
            $where_clause = "file = '".(is_scalar($params['original_filename']) ? (string) $params['original_filename'] : '')."'";
        }

        $query = '
SELECT COUNT(*)
  FROM '. IMAGES_TABLE .'
  WHERE '. $where_clause .'
;';
        [$counter] = pwg_db_fetch_row(pwg_query($query)) ?? [null];
        if ($counter != 0) {
            return new PwgError(500, 'file already exists');
        }
    }

    // due to the new feature "derivatives" (multiple sizes) introduced for
    // Piwigo 2.4, we only take the biggest photos sent on
    // pwg.images.addChunk. If "high" is available we use it as "original"
    // else we use "file".
    remove_chunks($p_original_sum, 'thumb');

    if (isset($params['high_sum'])) {
        $original_type = 'high';
        remove_chunks($p_original_sum, 'file');
    } else {
        $original_type = 'file';
    }

    $file_path = \Piwigo\Config\Config::uploadDir().'/buffer/'.$p_original_sum.'-original';

    merge_chunks($file_path, $p_original_sum, $original_type);
    chmod($file_path, 0644);

    include_once(PHPWG_ROOT_PATH.'admin/include/functions_upload.inc.php');

    $image_id = add_uploaded_file(
        $file_path,
        $p_original_filename,
        null, // categories
        $p_level,
        $p_image_id > 0 ? $p_image_id : null,
        $p_original_sum
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
            IMAGES_TABLE,
            $update,
            ['id' => $image_id]
        );
    }

    $url_params = ['image_id' => $image_id];

    // let's add links between the image and the categories
    if (isset($params['categories'])) {
        $p_categories_str = is_scalar($params['categories']) ? (string) $params['categories'] : '';
        ws_add_image_category_relations($image_id, $p_categories_str);

        if (preg_match('/^\d+/', $p_categories_str, $matches)) {
            $category_id = $matches[0];

            $category = \Piwigo\Core\ServiceLocator::get(\Piwigo\Category\CategoryRepository::class)
                ->findCategoryById((int) $category_id);

            $url_params['section'] = 'categories';
            $url_params['category'] = $category;
        }
    }

    // and now, let's create tag associations
    if (isset($params['tag_ids']) and !empty($params['tag_ids'])) {
        set_tags(
            explode(',', is_scalar($params['tag_ids']) ? (string) $params['tag_ids'] : ''),
            $image_id
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
 * @param mixed[] $params
 *    @option int[] category
 *    @option string name (optional)
 *    @option string author (optional)
 *    @option string comment (optional)
 *    @option int level
 *    @option string|string[] tags
 *    @option int image_id (optional)
 */
/**
 * @return array<mixed>|\Piwigo\Ws\PwgError
 * @param array<mixed> $params
 * @param array<mixed> $params
 */
function ws_images_addSimple(array $params, \Piwigo\Ws\PwgServer $service): PwgError|array
{
    $logger = \Piwigo\Core\LoggerRegistry::current();

    if (!isset($_FILES['image'])) {
        return new PwgError(405, 'The image (file) is missing');
    }

    $files_image = is_array($_FILES['image']) ? $_FILES['image'] : [];
    $files_image_error = is_numeric($files_image['error']) ? (int) $files_image['error'] : 0;
    if (isset($files_image['error']) && $files_image_error != 0) {
        $message = match ($files_image_error) {
            UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive in php.ini.',
            UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.',
            UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload. ' .
            'PHP does not provide a way to ascertain which extension caused the file ' .
            'upload to stop; examining the list of loaded extensions with phpinfo() may help.',
            default => "Error number {$files_image_error} occurred while uploading a file.",
        };

        $logger->error(__FUNCTION__ . ' ' . $message);
        return new PwgError(500, $message);
    }

    $p_image_id_as = is_numeric($params['image_id']) ? (int) $params['image_id'] : 0;
    $p_category_as = array_map(fn ($v): int => is_numeric($v) ? (int) $v : 0, is_array($params['category']) ? $params['category'] : []);
    if ($p_image_id_as > 0 && !\Piwigo\Core\ServiceLocator::get(\Piwigo\Image\ImageRepository::class)->existsById($p_image_id_as)) {
        return new PwgError(404, 'image_id not found');
    }

    include_once(PHPWG_ROOT_PATH.'admin/include/functions_upload.inc.php');

    $files_tmp = is_scalar($files_image['tmp_name']) ? (string) $files_image['tmp_name'] : '';
    $files_name = is_scalar($files_image['name']) ? (string) $files_image['name'] : null;
    $image_id = add_uploaded_file(
        $files_tmp,
        $files_name,
        $p_category_as,
        8,
        $p_image_id_as > 0 ? $p_image_id_as : null
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
        IMAGES_TABLE,
        $update,
        ['id' => $image_id]
    );

    if (isset($params['tags']) and !empty($params['tags'])) {
        include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

        $tag_ids = [];
        if (is_array($params['tags'])) {
            foreach ($params['tags'] as $tag_name) {
                $tag_ids[] = tag_id_from_tag_name(is_scalar($tag_name) ? (string) $tag_name : '');
            }
        } else {
            $tag_names = preg_split('~(?<!\\\),~', is_scalar($params['tags']) ? (string) $params['tags'] : '') ?: [];
            foreach ($tag_names as $tag_name) {
                $tag_ids[] = tag_id_from_tag_name(preg_replace('#\\\\*,#', ',', $tag_name) ?? '');
            }
        }

        add_tags($tag_ids, [$image_id]);
    }

    $url_params = ['image_id' => $image_id];

    if (!empty($p_category_as)) {
        $first_cat = $p_category_as[0] ?? null;
        $first_cat_id = is_numeric($first_cat) ? (int) $first_cat : 0;
        $category = \Piwigo\Core\ServiceLocator::get(\Piwigo\Category\CategoryRepository::class)
            ->findCategoryById((int) $first_cat_id);

        $url_params['section'] = 'categories';
        $url_params['category'] = $category;
    }

    // update metadata from the uploaded file (exif/iptc), even if the sync
    // was already performed by add_uploaded_file().
    require_once(PHPWG_ROOT_PATH.'admin/include/functions_metadata.php');
    sync_metadata([$image_id]);

    return [
      'image_id' => $image_id,
      'url' => make_picture_url($url_params),
      ];
}

/**
 * API method
 * Adds a image (simple way)
 * @param mixed[] $params
 *    @option int[] category
 *    @option string name (optional)
 *    @option string author (optional)
 *    @option string comment (optional)
 *    @option int level
 *    @option string|string[] tags
 *    @option int image_id (optional)
 */
/**
 * @param array<mixed> $params
 * @param array<mixed> $params
 */
function ws_images_upload(array $params, \Piwigo\Ws\PwgServer $service): mixed
{
    $format_ext = null;
    if (get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    if (isset($params['format_of'])) {

        // are formats enabled?
        if (!\Piwigo\Config\Config::isFormatsEnabled()) {
            return new PwgError(401, 'formats are disabled');
        }

        // We must check if the extension is in the authorized list.
        $p_name = is_scalar($params['name']) ? (string) $params['name'] : '';
        if (preg_match('/\.('.implode('|', \Piwigo\Config\Config::formatExtensions()).')$/', $p_name, $matches)) {
            $format_ext = $matches[1];
        }

        if (empty($format_ext)) {
            return new PwgError(401, 'unexpected format extension of file "'.$p_name.'" (authorized extensions: '.implode(', ', \Piwigo\Config\Config::formatExtensions()).')');
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

    $upload_dir = \Piwigo\Config\Config::uploadDir().'/buffer';

    // create the upload directory tree if not exists
    if (!mkgetdir($upload_dir, MKGETDIR_DEFAULT & ~MKGETDIR_DIE_ON_ERROR)) {
        return new PwgError(500, 'error during buffer directory creation');
    }

    // Get a file name
    if (isset($_REQUEST['name'])) {
        $fileName = is_scalar($_REQUEST['name']) ? (string) $_REQUEST['name'] : uniqid('file_');
    } elseif (!empty($_FILES)) {
        $filesFile = is_array($_FILES['file']) ? $_FILES['file'] : [];
        $fileName = is_scalar($filesFile['name'] ?? null) ? (string) $filesFile['name'] : uniqid('file_');
    } else {
        $fileName = uniqid('file_');
    }

    // change the name of the file in the buffer to avoid any unexpected
    // extension. Function add_uploaded_file will eventually clean the mess.
    $fileName = md5($fileName);

    $filePath = $upload_dir.DIRECTORY_SEPARATOR.$fileName;

    // Chunking might be enabled
    $chunk = isset($_REQUEST['chunk']) ? (is_numeric($_REQUEST['chunk']) ? intval($_REQUEST['chunk']) : 0) : 0;
    $chunks = isset($_REQUEST['chunks']) ? (is_numeric($_REQUEST['chunks']) ? intval($_REQUEST['chunks']) : 0) : 0;

    // file_put_contents('/tmp/plupload.log', "[".date('c')."] ".__FUNCTION__.', '.$fileName.' '.($chunk+1).'/'.$chunks."\n", FILE_APPEND);

    // Open temp file
    if (!$out = \Piwigo\Core\Filesystem::tryFopen("{$filePath}.part", $chunks ? 'ab' : 'wb')) {
        return new PwgError(102, 'Failed to open output stream.');
    }

    if (!empty($_FILES)) {
        $filesFile = is_array($_FILES['file']) ? $_FILES['file'] : [];
        $filesFileError = is_scalar($filesFile['error'] ?? null) ? $filesFile['error'] : 0;
        $filesFileTmpName = is_scalar($filesFile['tmp_name'] ?? null) ? (string) $filesFile['tmp_name'] : '';
        if ($filesFileError || !is_uploaded_file($filesFileTmpName)) {
            return new PwgError(103, 'Failed to move uploaded file.');
        }

        // Read binary input stream and append it to temp file
        if (!$in = \Piwigo\Core\Filesystem::tryFopen($filesFileTmpName, 'rb')) {
            return new PwgError(101, 'Failed to open input stream.');
        }
    } else {
        if (!$in = \Piwigo\Core\Filesystem::tryFopen('php://input', 'rb')) {
            return new PwgError(101, 'Failed to open input stream.');
        }
    }

    if (is_resource($in) && is_resource($out)) {
        while ($buff = fread($in, 4096)) {
            fwrite($out, $buff);
        }
    }

    if (is_resource($out)) {
        fclose($out);
    }
    if (is_resource($in)) {
        fclose($in);
    }

    $add_status = 'add';
    // Check if file has been uploaded
    if (!$chunks || $chunk == $chunks - 1) {
        // Strip the temp .part suffix off
        rename("{$filePath}.part", $filePath);

        include_once(PHPWG_ROOT_PATH.'admin/include/functions_upload.inc.php');

        if (isset($params['format_of'])) {
            $format_of_id = is_numeric($params['format_of']) ? (int) $params['format_of'] : 0;
            $query = '
SELECT *
  FROM '.IMAGES_TABLE.'
  WHERE id = '. $format_of_id .'
;';
            $images = query2array($query);
            if (count($images) == 0) {
                return new PwgError(404, __FUNCTION__.' : image_id not found');
            }

            $image = $images[0];
            $image_id_str = isset($image['id']) ? (string) $image['id'] : '';

            $add_status = add_format($filePath, $format_ext ?? '', $image_id_str);

            return [
              'image_id' => $image['id'] ?? null,
              'src' => DerivativeImage::thumb_url($image),
              'square_src' => DerivativeImage::url(ImageStdParams::get_by_type(IMG_SQUARE), $image),
              'name' => $image['name'] ?? null,
              'add_status' => $add_status,
            ];
        }

        $name = pwg_db_real_escape_string(stripslashes(is_scalar($params['name']) ? (string) $params['name'] : ''));
        $id_image = null; //null by default

        $p_category = is_array($params['category']) ? $params['category'] : [];
        $p_category_int = array_map(fn ($v): int => is_numeric($v) ? (int) $v : 0, $p_category);
        $p_category_first = $p_category_int[0] ?? 0;

        if ($params['update_mode']) {
            $query = '
SELECT
  id
  FROM '.IMAGES_TABLE.' AS i
    INNER JOIN '.IMAGE_CATEGORY_TABLE.' as ic ON ic.image_id = i.id
  WHERE i.file = \''.$name.'\'
  AND ic.category_id = '.$p_category_first.'
;';
            $images = query2array($query);
            if ($images != null) {
                $img0 = $images[0];
                $id_image = isset($img0['id']) && is_numeric($img0['id']) ? (int) $img0['id'] : null;
                $add_status = 'update';
            }
        }

        $image_id = add_uploaded_file(
            $filePath,
            $name, // function add_uploaded_file will secure before insert
            $p_category_int,
            is_numeric($params['level']) ? (int) $params['level'] : null,
            $id_image
        );

        $catRepo2 = \Piwigo\Core\ServiceLocator::get(\Piwigo\Category\CategoryRepository::class);
        $image_infos = \Piwigo\Core\ServiceLocator::get(\Piwigo\Image\ImageRepository::class)
            ->findById(is_numeric($image_id) ? (int) $image_id : 0);
        $category_infos = ['nb_photos' => $catRepo2->countImagesByCategoryId($p_category_first)];

        $query = '
SELECT
    COUNT(*)
  FROM '.LOUNGE_TABLE.'
  WHERE category_id = '.$p_category_first.'
  AND image_id NOT IN (Select image_id from '.IMAGE_CATEGORY_TABLE.')
;';
        [$nb_photos_lounge] = pwg_db_fetch_row(pwg_query($query)) ?? [null];

        $category_name = get_cat_display_name_from_id($p_category_first, null);

        if ($image_infos === null) {
            return null;
        }

        return [
          'image_id' => $image_id,
          'src' => DerivativeImage::thumb_url($image_infos),
          'square_src' => DerivativeImage::url(ImageStdParams::get_by_type(IMG_SQUARE), $image_infos),
          'name' => $image_infos['name'],
          'category' => [
            'id' => $p_category_first,
            'nb_photos' => (int)($category_infos['nb_photos'] ?? 0) + (is_numeric($nb_photos_lounge) ? (int) $nb_photos_lounge : 0),
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
 * @param mixed[] $params
 *    @option string username
 *    @option string password
 *    @option chunk int number of the chunk
 *    @option string chunk_sum MD5 sum of the chunk
 *    @option chunks int total number of chunks for this image
 *    @option string original_sum MD5 sum of the final image
 *    @option int[] category
 *    @option string filename
 *    @option string name (optional)
 *    @option string author (optional)
 *    @option string comment (optional)
 *    @option string date_creation (optional)
 *    @option int level
 *    @option string tag_ids (optional) - "tag_id,tag_id"
 *    @option int image_id (optional)
 */
/**
 * @param array<mixed> $params
 * @param array<mixed> $params
 */
function ws_images_uploadAsync(array $params, \Piwigo\Ws\PwgServer &$service): mixed
{
    $logger = \Piwigo\Core\LoggerRegistry::current();

    // the username/password parameters have been used in include/user.inc.php
    // to authenticate the request (a much better time/place than here)

    // additional check for some parameters
    $p_original_sum = is_scalar($params['original_sum']) ? (string) $params['original_sum'] : '';
    if (!preg_match('/^[a-fA-F0-9]{32}$/', $p_original_sum)) {
        return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid original_sum');
    }

    $p_image_id_async = is_numeric($params['image_id']) ? (int) $params['image_id'] : 0;
    $p_chunk = is_numeric($params['chunk']) ? (int) $params['chunk'] : 0;
    $p_chunks = is_numeric($params['chunks']) ? (int) $params['chunks'] : 0;

    if ($p_image_id_async > 0 && !\Piwigo\Core\ServiceLocator::get(\Piwigo\Image\ImageRepository::class)->existsById($p_image_id_async)) {
        return new PwgError(404, __FUNCTION__.' : image_id not found');
    }

    // handle upload error as in ws_images_addSimple
    // if (isset($_FILES['image']['error']) && $_FILES['image']['error'] != 0)

    $p_user_id = (string) \Piwigo\Users\CurrentUser::get()->id;
    $output_filepath_prefix = \Piwigo\Config\Config::uploadDir().'/buffer/'.$p_original_sum.'-u'.$p_user_id;
    $chunkfile_path_pattern = $output_filepath_prefix.'-%03uof%03u.chunk';

    $chunkfile_path = sprintf($chunkfile_path_pattern, $p_chunk + 1, $p_chunks);

    // create the upload directory tree if not exists
    if (!mkgetdir(dirname($chunkfile_path), MKGETDIR_DEFAULT & ~MKGETDIR_DIE_ON_ERROR)) {
        return new PwgError(500, 'error during buffer directory creation');
    }
    secure_directory(dirname($chunkfile_path));

    // move uploaded file
    $filesFile2 = is_array($_FILES['file'] ?? null) ? $_FILES['file'] : [];
    $filesFile2TmpName = is_scalar($filesFile2['tmp_name'] ?? null) ? (string) $filesFile2['tmp_name'] : '';
    $chunkRoot   = PHPWG_ROOT_PATH . \Piwigo\Config\Config::uploadDir();
    $chunkAbsPath = PHPWG_ROOT_PATH . ltrim(str_replace(['\\', '/./'], ['/', '/'], $chunkfile_path), '/');
    $chunkRelPath = \Piwigo\Storage\StorageRegistry::stripRoot($chunkRoot, $chunkAbsPath);
    $chunkStream  = fopen($filesFile2TmpName, 'rb');
    if ($chunkStream !== false) {
        \Piwigo\Storage\StorageRegistry::disk('uploads')->writeStream($chunkRelPath, $chunkStream);
        fclose($chunkStream);
    }
    $logger->debug(__FUNCTION__.' uploaded '.$chunkfile_path);

    // MD5 checksum
    $chunk_md5 = md5_file($chunkfile_path);
    $p_chunk_sum = is_scalar($params['chunk_sum']) ? (string) $params['chunk_sum'] : '';
    if ($chunk_md5 != $p_chunk_sum) {
        unlink($chunkfile_path);
        $logger->error(__FUNCTION__.' '.$chunkfile_path.' MD5 checksum mismatched');
        return new PwgError(500, 'MD5 checksum chunk file mismatched');
    }

    // are all chunks uploaded?
    $chunk_ids_uploaded = [];
    for ($i = 1; $i <= $p_chunks; $i++) {
        $chunkfile = sprintf($chunkfile_path_pattern, $i, $p_chunks);
        if (file_exists($chunkfile) && ($fp = fopen($chunkfile, 'rb')) !== false) {
            $chunk_ids_uploaded[] = $i;
            fclose($fp);
        }
    }

    if ($p_chunks != count($chunk_ids_uploaded)) {
        // all chunks are not yet available
        $logger->debug(__FUNCTION__.' all chunks are not uploaded yet, maybe on next chunk, exit for now');
        return ['message' => 'chunks uploaded = '.implode(',', $chunk_ids_uploaded)];
    }

    // all chunks available
    $logger->debug(__FUNCTION__.' '.$p_original_sum.' '.$p_chunks.' chunks available, try now to get lock for merging');
    $output_filepath = $output_filepath_prefix.'.merged';

    // chunks already being merged?
    if (file_exists($output_filepath) && ($fp = fopen($output_filepath, 'rb')) !== false) {
        // merge file already exists
        fclose($fp);
        $logger->error(__FUNCTION__.' '.$output_filepath.' already exists, another merge is under process');
        return ['message' => 'chunks uploaded = '.implode(',', $chunk_ids_uploaded)];
    }

    // create merged and open it for writing only
    $fp = fopen($output_filepath, 'wb');
    if (!$fp) {
        // unable to create file and open it for writing only
        $logger->error(__FUNCTION__.' '.$chunkfile_path.' unable to create merge file');
        return new PwgError(500, 'error while creating merged '.$chunkfile_path);
    }

    // acquire an exclusive lock and keep it until merge completes
    // this postpones another uploadAsync task running in another thread
    if (!flock($fp, LOCK_EX)) {
        // unable to obtain lock
        fclose($fp);
        $logger->error(__FUNCTION__.' '.$chunkfile_path.' unable to obtain lock');
        return new PwgError(500, 'error while locking merged '.$chunkfile_path);
    }

    $logger->debug(__FUNCTION__.' lock obtained to merge chunks');

    // loop over all chunks
    foreach ($chunk_ids_uploaded as $chunk_id) {
        $chunkfile_path = sprintf($chunkfile_path_pattern, $chunk_id, is_numeric($params['chunks']) ? (int) $params['chunks'] : 0);

        // chunk deleted by preceding merge?
        if (!file_exists($chunkfile_path)) {
            // cancel merge
            $logger->error(__FUNCTION__.' '.$chunkfile_path.' already merged');
            flock($fp, LOCK_UN);
            fclose($fp);
            return ['message' => 'chunks uploaded = '.implode(',', $chunk_ids_uploaded)];
        }

        $chunkdata = file_get_contents($chunkfile_path);
        if ($chunkdata === false || !fwrite($fp, $chunkdata)) {
            // could not append chunk
            $logger->error(__FUNCTION__.' error merging chunk '.$chunkfile_path);
            flock($fp, LOCK_UN);
            fclose($fp);

            // delete merge file without returning an error
            \Piwigo\Core\Filesystem::tryUnlink($output_filepath);
            return new PwgError(500, 'error while merging chunk '.$chunk_id);
        }

        $logger->debug(__FUNCTION__.' original_sum='.$p_original_sum.', chunk '.$chunk_id.'/'.$p_chunks.' merged');

        // delete chunk and clear cache
        unlink($chunkfile_path);
    }

    // flush output before releasing lock
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    $logger->debug(__FUNCTION__.' merged file '.$output_filepath.' saved');

    // MD5 checksum
    $merged_md5 = md5_file($output_filepath);

    if ($merged_md5 != $p_original_sum) {
        unlink($output_filepath);
        $logger->error(__FUNCTION__.' '.$output_filepath.' MD5 checksum mismatched!');
        return new PwgError(500, 'MD5 checksum merged file mismatched');
    }

    $logger->debug(__FUNCTION__.' '.$output_filepath.' MD5 checksum OK');

    include_once(PHPWG_ROOT_PATH.'admin/include/functions_upload.inc.php');

    $p_filename = is_scalar($params['filename']) ? (string) $params['filename'] : null;
    $p_category_async = array_map(fn ($v): int => is_numeric($v) ? (int) $v : 0, is_array($params['category']) ? $params['category'] : []);
    $p_level_async = is_numeric($params['level']) ? (int) $params['level'] : null;
    $p_image_id_upload = is_numeric($params['image_id']) ? (int) $params['image_id'] : null;

    $image_id = add_uploaded_file(
        $output_filepath,
        $p_filename,
        $p_category_async,
        $p_level_async,
        $p_image_id_upload,
        $p_original_sum
    );

    $logger->debug(__FUNCTION__.' image_id after add_uploaded_file = '.$image_id);

    // and now, let's create tag associations
    if (isset($params['tag_ids']) and !empty($params['tag_ids'])) {
        set_tags(
            explode(',', is_scalar($params['tag_ids']) ? (string) $params['tag_ids'] : ''),
            $image_id
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
            IMAGES_TABLE,
            $update,
            ['id' => $image_id]
        );
    }

    // final step, reset user cache
    invalidate_user_cache();

    // trick to bypass get_sql_condition_FandF
    $userRef = &$GLOBALS['user'];
    if (is_array($userRef) && !empty($params['level']) && $params['level'] > ($userRef['level'] ?? 0)) {
        // this will not persist
        $userRef['level'] = $params['level'];
    }

    // delete chunks older than a week
    $now = time();
    foreach (glob(\Piwigo\Config\Config::uploadDir().'/buffer/'.'*.chunk') ?: [] as $file) {
        if (is_file($file)) {
            if ($now - filemtime($file) >= 60 * 60 * 24 * 7) { // 7 days
                $logger->info(__FUNCTION__.' delete '.$file);
                unlink($file);
            } else {
                $logger->debug(__FUNCTION__.' keep '.$file);
            }
        }
    }

    // delete merged older than a week
    foreach (glob(\Piwigo\Config\Config::uploadDir().'/buffer/'.'*.merged') ?: [] as $file) {
        if (is_file($file)) {
            if ($now - filemtime($file) >= 60 * 60 * 24 * 7) { // 7 days
                $logger->info(__FUNCTION__.' delete '.$file);
                unlink($file);
            } else {
                $logger->debug(__FUNCTION__.' keep '.$file);
            }
        }
    }

    return $service->invoke('pwg.images.getInfo', ['image_id' => $image_id]);
}

/**
 * API method
 * Check if an image exists by it's name or md5 sum
 * @param mixed[] $params
 *    @option string md5sum_list (optional)
 *    @option string filename_list (optional)
 * @return mixed[]
 */
/**
 * @return array<mixed>
 * @param array<mixed> $params
 * @param array<mixed> $params
 */
function ws_images_exist(array $params, \Piwigo\Ws\PwgServer $service): array
{
    $logger = \Piwigo\Core\LoggerRegistry::current();

    $logger->debug(__FUNCTION__, $params);

    $split_pattern = '/[\s,;\|]/';
    $result = [];

    if ('md5sum' == \Piwigo\Config\Config::uniquenessMode()) {
        // search among photos the list of photos already added, based on md5sum list
        $md5sums = preg_split(
            $split_pattern,
            is_scalar($params['md5sum_list']) ? (string) $params['md5sum_list'] : '',
            -1,
            PREG_SPLIT_NO_EMPTY
        ) ?: [];

        $query = '
SELECT id, md5sum
  FROM '. IMAGES_TABLE .'
  WHERE md5sum IN (\''. implode("','", $md5sums) .'\')
;';
        $id_of_md5 = query2array($query, 'md5sum', 'id');

        foreach ($md5sums as $md5sum) {
            $result[$md5sum] = null;
            if (isset($id_of_md5[$md5sum])) {
                $result[$md5sum] = $id_of_md5[$md5sum];
            }
        }
    } elseif ('filename' == \Piwigo\Config\Config::uniquenessMode()) {
        // search among photos the list of photos already added, based on
        // filename list
        $filenames = preg_split(
            $split_pattern,
            is_scalar($params['filename_list']) ? (string) $params['filename_list'] : '',
            -1,
            PREG_SPLIT_NO_EMPTY
        ) ?: [];

        $query = '
SELECT id, file
  FROM '.IMAGES_TABLE.'
  WHERE file IN (\''. implode("','", $filenames) .'\')
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
 * @param mixed[] $params
 *    @option string filename_list
 */
/**
 * @param array<mixed> $params
 * @param array<mixed> $params
 */
function ws_images_formats_searchImage(array $params, \Piwigo\Ws\PwgServer $service): mixed
{
    $logger = \Piwigo\Core\LoggerRegistry::current();

    $logger->debug(__FUNCTION__, $params);

    $candidates = json_decode(stripslashes(is_scalar($params['filename_list']) ? (string) $params['filename_list'] : ''), true);

    $unique_filenames_db = [];

    $query = '
SELECT
    id,
    file
  FROM '.IMAGES_TABLE.'
;';
    $result = pwg_query($query);
    while ($row = pwg_db_fetch_assoc($result)) {
        $filename_wo_ext = get_filename_wo_extension((string) ($row['file'] ?? ''));
        $unique_filenames_db[$filename_wo_ext][] = $row['id'];
    }

    // we want "long" format extensions first to match "cmyk.jpg" before "jpg" for example
    $format_extensions = \Piwigo\Config\Config::formatExtensions();
    usort($format_extensions, fn ($a, $b): int => strlen((string) $b) - strlen((string) $a));

    /** @var array<string, list<string>> $format_db */
    $format_db = [];
    foreach (\Piwigo\Core\ServiceLocator::get(\Piwigo\Image\ImageRepository::class)->findAllFormats() as $row) {
        $format_image_id = is_scalar($row['image_id'] ?? null) ? (string) $row['image_id'] : '';
        $format_ext_val = is_scalar($row['ext'] ?? null) ? (string) $row['ext'] : '';
        $format_db[$format_image_id][] = $format_ext_val;
    }

    $result = [];

    $candidates_array = is_array($candidates) ? $candidates : [];
    foreach ($candidates_array as $format_external_id => $format_filename) {
        $format_external_id_str = (string) $format_external_id;
        $format_filename_str = is_scalar($format_filename) ? (string) $format_filename : '';
        $candidate_filename_wo_ext = null;

        if (preg_match('/^(.*?)\.('.implode('|', \Piwigo\Config\Config::formatExtensions()).')$/', $format_filename_str, $matches)) {
            $candidate_filename_wo_ext = $matches[1];
        }

        if (empty($candidate_filename_wo_ext)) {
            $result[$format_external_id_str] = ['status' => 'not found'];
            continue;
        }

        if (isset($unique_filenames_db[$candidate_filename_wo_ext])) {
            if (count($unique_filenames_db[$candidate_filename_wo_ext]) > 1) {
                $result[$format_external_id_str] = ['status' => 'multiple'];
                continue;
            }
            $img_id_raw = $unique_filenames_db[$candidate_filename_wo_ext][0];
            $img_id_str = is_scalar($img_id_raw) ? (string) $img_id_raw : '';
            $mult_form = false;
            if (isset($format_db[$img_id_str])) {
                $format_ext = pathinfo($format_filename_str, PATHINFO_EXTENSION);
                if (array_search($format_ext, $format_db[$img_id_str]) !== false) {
                    $mult_form = true;
                }
            }
            $result[$format_external_id_str] = ['status' => 'found', 'image_id' => $img_id_raw, 'format_exist' => $mult_form];
            continue;
        }

        $result[$format_external_id_str] = ['status' => 'not found'];
    }

    return $result;
}

/**
 * API method
 * Remove a formats from the database and the file system
 *
 * @since 13
 * @param mixed[] $params
 *    @option int format_id
 *    @option string pwg_token
 */
/**
 * @param array<mixed> $params
 * @param array<mixed> $params
 */
function ws_images_formats_delete(array $params, \Piwigo\Ws\PwgServer $service): PwgError|bool
{
    if (get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    if (!is_array($params['format_id'])) {
        $params['format_id'] = preg_split(
            '/[\s,;\|]/',
            is_scalar($params['format_id']) ? (string) $params['format_id'] : '',
            -1,
            PREG_SPLIT_NO_EMPTY
        ) ?: [];
    }
    $params['format_id'] = array_map(fn ($v): int => is_numeric($v) ? (int) $v : 0, $params['format_id']);

    $format_ids = [];
    foreach ($params['format_id'] as $format_id) {
        if ($format_id >= 0) {
            $format_ids[] = $format_id;
        }
    }

    include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

    $image_ids = [];
    $formats_of = [];

    //Delete physical file
    $ok = true;

    $query = '
SELECT
    image_id,
    ext
  FROM '.IMAGE_FORMAT_TABLE.'
  WHERE format_id IN ('.implode(',', $format_ids).')
;';
    $result = pwg_query($query);
    /** @var array<string, list<string>> $formats_of */
    $formats_of = [];
    /** @var list<string> $image_ids */
    $image_ids = [];
    while ($row = pwg_db_fetch_assoc($result)) {
        $row_image_id = is_scalar($row['image_id'] ?? null) ? (string) $row['image_id'] : '';
        $row_ext = is_scalar($row['ext'] ?? null) ? (string) $row['ext'] : '';

        if (!isset($formats_of[$row_image_id])) {
            $image_ids[] = $row_image_id;
            $formats_of[$row_image_id] = [];
        }

        $formats_of[$row_image_id][] = $row_ext;
    }

    if (count($image_ids) == 0) {
        return new PwgError(404, 'No format found for the id(s) given');
    }

    $query = '
SELECT
    id,
    path,
    representative_ext
  FROM '.IMAGES_TABLE.'
  WHERE id IN ('.implode(',', $image_ids).')
;';
    $result = pwg_query($query);
    while ($row = pwg_db_fetch_assoc($result)) {
        $row_path = is_scalar($row['path'] ?? null) ? (string) $row['path'] : '';
        $row_id = is_scalar($row['id'] ?? null) ? (string) $row['id'] : '';
        if (url_is_remote($row_path)) {
            continue;
        }

        $files = [];
        $image_path = get_element_path($row);

        if (isset($formats_of[$row_id])) {
            foreach ($formats_of[$row_id] as $format_ext) {
                $files[] = original_to_format($image_path, $format_ext);
            }
        }

        foreach ($files as $path) {
            if (is_file($path) and !unlink($path)) {
                $ok = false;
                trigger_error('"'.$path.'" cannot be removed', E_USER_WARNING);
                break;
            }
        }
    }


    //Delete format in the database
    $query = '
DELETE FROM '.IMAGE_FORMAT_TABLE.'
  WHERE format_id IN ('.implode(',', $format_ids).')
;';
    pwg_query($query);

    invalidate_user_cache();

    return $ok;
}

/**
 * API method
 * Check is file has been update
 * @param mixed[] $params
 *    @option int image_id
 *    @option string file_sum
 */
/**
 * @return array<mixed>|\Piwigo\Ws\PwgError
 * @param array<mixed> $params
 * @param array<mixed> $params
 */
function ws_images_checkFiles(array $params, \Piwigo\Ws\PwgServer $service): PwgError|array
{
    $logger = \Piwigo\Core\LoggerRegistry::current();

    $logger->debug(__FUNCTION__, $params);

    $check_image_id = is_numeric($params['image_id']) ? (int) $params['image_id'] : 0;
    $query = '
SELECT path
  FROM '. IMAGES_TABLE .'
  WHERE id = '. $check_image_id .'
;';
    $result = pwg_query($query);

    if (pwg_db_num_rows($result) == 0) {
        return new PwgError(404, 'image_id not found');
    }

    [$path_raw] = pwg_db_fetch_row($result) ?? [null];
    $path = is_scalar($path_raw) ? (string) $path_raw : '';

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
        $logger->debug(__FUNCTION__.', md5_file($path) = '.md5_file($path));
        if (md5_file($path) != $params[$compare_type.'_sum']) {
            $ret[$compare_type] = 'differs';
        } else {
            $ret[$compare_type] = 'equals';
        }
    }

    $logger->debug(__FUNCTION__, $ret);

    return $ret;
}

/**
 * API method
 * Sets details of an image
 * @param mixed[] $params
 *    @option int image_id
 *    @option string file (optional)
 *    @option string name (optional)
 *    @option string author (optional)
 *    @option string date_creation (optional)
 *    @option string comment (optional)
 *    @option string categories (optional) - "cat_id[,rank];cat_id[,rank]"
 *    @option string tags_ids (optional) - "tag_id,tag_id"
 *    @option int level (optional)
 *    @option string single_value_mode
 *    @option string multiple_value_mode
 */
/**
 * @param array<mixed> $params
 * @param array<mixed> $params
 */
function ws_images_setInfo(array $params, \Piwigo\Ws\PwgServer $service): mixed
{
    if (isset($params['pwg_token']) and get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

    $set_image_id = is_numeric($params['image_id']) ? (int) $params['image_id'] : 0;
    $query = '
SELECT *
  FROM '. IMAGES_TABLE .'
  WHERE id = '. $set_image_id .'
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

    $single_value_mode = is_scalar($params['single_value_mode'] ?? null) ? (string) $params['single_value_mode'] : '';
    $multiple_value_mode = is_scalar($params['multiple_value_mode'] ?? null) ? (string) $params['multiple_value_mode'] : '';

    foreach ($info_columns as $key) {
        if (isset($params[$key])) {
            if (!\Piwigo\Config\Config::allowHtmlDescriptions() or !isset($params['pwg_token'])) {
                $params[$key] = strip_tags(is_scalar($params[$key]) ? (string) $params[$key] : '', '<b><strong><em><i>');
            }

            if ('fill_if_empty' == $single_value_mode) {
                if (empty($image_row[$key])) {
                    $update[$key] = $params[$key];
                }
            } elseif ('replace' == $single_value_mode) {
                $update[$key] = $params[$key];
            } else {
                return new PwgError(
                    500,
                    '[ws_images_setInfo]'
          .' invalid parameter single_value_mode "'.$single_value_mode.'"'
          .', possible values are {fill_if_empty, replace}.'
                );
            }
        }
    }

    if (isset($params['file'])) {
        if (!empty($image_row['storage_category_id'])) {
            return new PwgError(
                500,
                '[ws_images_setInfo] updating "file" is forbidden on photos added by synchronization'
            );
        }

        // prevent XSS, remove HTML tags
        $update['file'] = strip_tags(is_scalar($params['file']) ? (string) $params['file'] : '');
        if (empty($update['file'])) {
            unset($update['file']);
        }
    }

    if (count(array_keys($update)) > 0) {
        $update['id'] = $set_image_id;

        single_update(
            IMAGES_TABLE,
            $update,
            ['id' => $update['id']]
        );

        pwg_activity('photo', $set_image_id, 'edit');
    }

    if (isset($params['categories'])) {
        ws_add_image_category_relations(
            $set_image_id,
            is_string($params['categories']) ? $params['categories'] : '',
            ('replace' == $multiple_value_mode ? true : false)
        );
    }

    // and now, let's create tag associations
    if (isset($params['tag_ids'])) {
        $tag_ids = [];

        foreach (explode(',', is_scalar($params['tag_ids']) ? (string) $params['tag_ids'] : '') as $candidate) {
            $candidate = trim($candidate);

            if (preg_match(PATTERN_ID, $candidate)) {
                $tag_ids[] = $candidate;
            }
        }

        if ('replace' == $multiple_value_mode) {
            set_tags(
                $tag_ids,
                $set_image_id
            );
        } elseif ('append' == $multiple_value_mode) {
            add_tags(
                $tag_ids,
                [$set_image_id]
            );
        } else {
            return new PwgError(
                500,
                '[ws_images_setInfo]'
        .' invalid parameter multiple_value_mode "'.$multiple_value_mode.'"'
        .', possible values are {replace, append}.'
            );
        }
    }

    // Temporary use of the batch manager's unit mode,
    // not to be used by an external application,
    // as this code bellow will be deleted when a tag selector is added.
    if (isset($_REQUEST['tag_list'])) {
        if (isset($params['tag_ids'])) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'Do not use tag_list and tag_ids at the same time.');
        }

        // clean user input
        $request_tag_list = is_array($_REQUEST['tag_list']) ? $_REQUEST['tag_list'] : [];
        foreach ($request_tag_list as $idx => $tag_candidate) {
            $request_tag_list[$idx] = pwg_db_real_escape_string(strip_tags(stripslashes(is_scalar($tag_candidate) ? (string) $tag_candidate : '')));
        }

        $tag_list = get_tag_ids($request_tag_list);
        set_tags($tag_list, $set_image_id);
    }

    invalidate_user_cache();
    return null;
}

/**
 * API method
 * Deletes an image
 * @param mixed[] $params
 *    @option int|int[] image_id
 *    @option string pwg_token
 */
/**
 * @param array<mixed> $params
 * @param array<mixed> $params
 */
function ws_images_delete(array $params, \Piwigo\Ws\PwgServer $service): PwgError|int
{
    if (get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    $del_image_ids_raw = $params['image_id'];
    if (!is_array($del_image_ids_raw)) {
        $del_image_ids_raw = preg_split(
            '/[\s,;\|]/',
            is_scalar($del_image_ids_raw) ? (string) $del_image_ids_raw : '',
            -1,
            PREG_SPLIT_NO_EMPTY
        ) ?: [];
    }
    $del_image_ids_raw = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $del_image_ids_raw);

    $image_ids = [];
    foreach ($del_image_ids_raw as $image_id) {
        if ($image_id > 0) {
            $image_ids[] = $image_id;
        }
    }

    include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');
    $ret = delete_elements($image_ids, true);
    invalidate_user_cache();

    return $ret;
}

/**
 * API method
 * Checks if Piwigo is ready for upload
 * @param mixed[] $params
 */
function ws_images_checkUpload($params, \Piwigo\Ws\PwgServer $service): mixed
{
    include_once(PHPWG_ROOT_PATH.'admin/include/functions_upload.inc.php');

    $ret['message'] = ready_for_upload_message();
    $ret['ready_for_upload'] = true;
    if (!empty($ret['message'])) {
        $ret['ready_for_upload'] = false;
    }

    return $ret;
}

/**
 * API method
 * Empties the lounge, where photos may wait before taking off.
 * @since 12
 * @param mixed[] $params
 */
/**
 * @return array<mixed>
 * @param array<mixed> $params
 * @param array<mixed> $params
 */
function ws_images_emptyLounge(array $params, \Piwigo\Ws\PwgServer $service): array
{
    include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

    $ret = ['rows' => empty_lounge()];

    return $ret;
}

/**
 * API method
 * Empties the lounge, where photos may wait before taking off.
 * @since 12
 * @param mixed[] $params
 */
/**
 * @return array<mixed>|\Piwigo\Ws\PwgError
 * @param array<mixed> $params
 * @param array<mixed> $params
 */
function ws_images_uploadCompleted(array $params, \Piwigo\Ws\PwgServer $service): PwgError|array
{
    include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

    if (get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    $uc_image_ids_raw = $params['image_id'];
    if (!is_array($uc_image_ids_raw)) {
        $uc_image_ids_raw = preg_split(
            '/[\s,;\|]/',
            is_scalar($uc_image_ids_raw) ? (string) $uc_image_ids_raw : '',
            -1,
            PREG_SPLIT_NO_EMPTY
        ) ?: [];
    }
    $uc_image_ids_raw = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $uc_image_ids_raw);

    $image_ids = [];
    foreach ($uc_image_ids_raw as $image_id) {
        if ($image_id > 0) {
            $image_ids[] = $image_id;
        }
    }

    $uc_category_id = is_numeric($params['category_id']) ? (int) $params['category_id'] : 0;
    // the list of images moved from the lounge might not be the same than
    // $image_ids (canbe a subset or more image_ids from another upload too)
    $moved_from_lounge = empty_lounge();

    $query = '
SELECT
    COUNT(*) AS nb_photos
  FROM '.IMAGE_CATEGORY_TABLE.'
  WHERE category_id = '.$uc_category_id.'
;';
    $category_infos = pwg_db_fetch_assoc(pwg_query($query));
    $category_name = get_cat_display_name_from_id($uc_category_id, null);

    trigger_notify(
        'ws_images_uploadCompleted',
        [
        'image_ids' => $image_ids,
        'category_id' => $uc_category_id,
        'moved_from_lounge' => $moved_from_lounge,
    ]
    );

    return [
      'moved_from_lounge' => $moved_from_lounge,
      'category' => [
        'id' => $uc_category_id,
        'nb_photos' => $category_infos['nb_photos'] ?? 0,
        'label' => $category_name,
      ],
    ];
}

/**
 * API method
 * add md5sum at photos, by block. Returns how md5sum were added and how many are remaining.
 * @param mixed[] $params
 *    @option int block_size
 */
/**
 * @return array<mixed>|\Piwigo\Ws\PwgError
 * @param array<mixed> $params
 * @param array<mixed> $params
 */
function ws_images_setMd5sum(array $params, \Piwigo\Ws\PwgServer $service): PwgError|array
{
    if (get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

    $no_md5sum_ids = get_photos_no_md5sum();
    $added_count = 0;

    if (count($no_md5sum_ids) > 0) {
        $md5sum_ids_to_add = array_slice($no_md5sum_ids, 0, is_numeric($params['block_size']) ? (int) $params['block_size'] : null);
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
 * @param mixed[] $params
 *    @option int image_id
 */
/**
 * @return array<mixed>|\Piwigo\Ws\PwgError
 * @param array<mixed> $params
 * @param array<mixed> $params
 */
function ws_images_syncMetadata(array $params, \Piwigo\Ws\PwgServer $service): PwgError|array
{
    if (get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    $sync_image_ids_raw = $params['image_id'];
    if (!is_array($sync_image_ids_raw)) {
        $sync_image_ids_raw = preg_split(
            '/[\s,;\|]/',
            is_scalar($sync_image_ids_raw) ? (string) $sync_image_ids_raw : '',
            -1,
            PREG_SPLIT_NO_EMPTY
        ) ?: [];
    }

    $image_ids = [];
    foreach ($sync_image_ids_raw as $image_id) {
        $image_id = trim(is_scalar($image_id) ? (string) $image_id : '');

        if (!preg_match(PATTERN_ID, $image_id)) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid image_id "'.$image_id.'"');
        }

        $image_ids[] = $image_id;
    }

    if (empty($image_ids)) {
        return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid image_id (no value after filters)');
    }

    $query = '
SELECT id
  FROM '.IMAGES_TABLE.'
  WHERE id IN ('.implode(', ', $image_ids).')
;';
    $image_ids = array_map(static fn ($id) => (int)$id, query2array($query, null, 'id'));

    if (empty($image_ids)) {
        return new PwgError(403, 'No image found');
    }

    include_once(PHPWG_ROOT_PATH.'admin/include/functions_metadata.php');
    include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');
    sync_metadata($image_ids);

    return [
      'nb_synchronized' => count($image_ids),
    ];
}

/**
 * API method
 * Deletes orphan photos, by block. Returns how many orphans were deleted and how many are remaining.
 * @param mixed[] $params
 *    @option int block_size
 */
/**
 * @return array<mixed>|\Piwigo\Ws\PwgError
 * @param array<mixed> $params
 * @param array<mixed> $params
 */
function ws_images_deleteOrphans(array $params, \Piwigo\Ws\PwgServer $service): PwgError|array
{
    if (get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

    $orphan_ids_to_delete = array_slice(get_orphans(), 0, is_numeric($params['block_size']) ? (int) $params['block_size'] : null);
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
 * @param mixed[] $params
 *    @option int[] image_id
 *    @option int category_id
 *    @option string action
 *    @option string pwg_token
 */
/**
 * @param array<mixed> $params
 * @param array<mixed> $params
 */
function ws_images_setCategory(array $params, \Piwigo\Ws\PwgServer $service): mixed
{
    if (get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    $sc_category_id = is_numeric($params['category_id']) ? (int) $params['category_id'] : 0;
    $sc_image_ids = is_array($params['image_id']) ? array_map(fn ($v) => is_numeric($v) ? (int) $v : 0, $params['image_id']) : [];
    // does the category really exist?
    $query = '
SELECT
    id
  FROM '.CATEGORIES_TABLE.'
  WHERE id = '.$sc_category_id.'
;';
    $categories = query2array($query);

    if (count($categories) == 0) {
        return new PwgError(404, 'category_id not found');
    }

    include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

    $sc_action = is_string($params['action'] ?? null) ? $params['action'] : '';
    if ('associate' == $sc_action) {
        associate_images_to_categories($sc_image_ids, [$sc_category_id]);
    } elseif ('dissociate' == $sc_action) {
        dissociate_images_from_category($sc_image_ids, (string) $sc_category_id);
    } elseif ('move' == $sc_action) {
        move_images_to_categories($sc_image_ids, [$sc_category_id]);
    }

    invalidate_user_cache();
    return null;
}
