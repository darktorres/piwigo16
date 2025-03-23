<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\inc\ws_functions;

use Exception;
use Piwigo\admin\inc\functions_admin;
use Piwigo\admin\inc\functions_metadata_admin;
use Piwigo\admin\inc\functions_upload;
use Piwigo\inc\dblayer\functions_mysqli;
use Piwigo\inc\derivative_std_params;
use Piwigo\inc\DerivativeImage;
use Piwigo\inc\functions;
use Piwigo\inc\functions_category;
use Piwigo\inc\functions_comment;
use Piwigo\inc\functions_html;
use Piwigo\inc\functions_plugins;
use Piwigo\inc\functions_rate;
use Piwigo\inc\functions_search;
use Piwigo\inc\functions_tag;
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;
use Piwigo\inc\ImageStdParams;
use Piwigo\inc\PwgError;
use Piwigo\inc\PwgNamedArray;
use Piwigo\inc\PwgNamedStruct;
use Piwigo\inc\PwgServer;
use Piwigo\inc\ws_functions;
use Random\RandomException;

final class pwg_images
{
    // +-----------------------------------------------------------------------+
    // | UTILITIES                                                             |
    // +-----------------------------------------------------------------------+

    /**
     * Sets associations of an image
     * @param string $categories_string - "cat_id[,rank];cat_id[,rank]"
     * @param bool $replace_mode - removes old associations
     */
    public static function ws_add_image_category_relations(
        int $image_id,
        string $categories_string,
        bool $replace_mode = false
    ): bool|PwgError|null {
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

        $tokens = explode(';', $categories_string);

        foreach ($tokens as $token) {
            list($cat_id, $rank) = explode(',', $token);

            if (! preg_match('/^\d+$/', $cat_id)) {
                continue;
            }

            $cat_ids[] = $cat_id;

            if (! isset($rank)) {
                $rank = 'auto';
            }

            $rank_on_category[$cat_id] = $rank;

            if ($rank == 'auto') {
                $search_current_ranks = true;
            }
        }

        $cat_ids = array_unique($cat_ids);

        if (count($cat_ids) == 0) {
            return new PwgError(
                500,
                '[ws_add_image_category_relations] there is no category defined in "' . $categories_string . '"'
            );
        }

        $category_ids = implode(', ', $cat_ids);
        $query = <<<SQL
            SELECT id
            FROM categories
            WHERE id IN ({$category_ids});
            SQL;
        $db_cat_ids = functions_mysqli::query2array($query, null, 'id');

        $unknown_cat_ids = array_diff($cat_ids, $db_cat_ids);

        if (count($unknown_cat_ids) != 0) {
            return new PwgError(
                500,
                '[ws_add_image_category_relations] the following categories are unknown: ' . implode(', ', $unknown_cat_ids)
            );
        }

        $to_update_cat_ids = [];

        // in case of replace mode, we first check the existing associations
        $query = <<<SQL
            SELECT category_id
            FROM image_category
            WHERE image_id = {$image_id};
            SQL;
        $existing_cat_ids = functions_mysqli::query2array($query, null, 'category_id');

        if ($replace_mode) {
            $to_remove_cat_ids = array_diff($existing_cat_ids, $cat_ids);

            if (count($to_remove_cat_ids) > 0) {
                $category_ids_to_remove = implode(', ', $to_remove_cat_ids);
                $query = <<<SQL
                    DELETE FROM image_category
                    WHERE image_id = {$image_id}
                        AND category_id IN ({$category_ids_to_remove});
                    SQL;
                functions_mysqli::pwg_query($query);
                functions_admin::update_category($to_remove_cat_ids);
            }
        }

        $new_cat_ids = array_diff($cat_ids, $existing_cat_ids);

        if (count($new_cat_ids) == 0) {
            return true;
        }

        if ($search_current_ranks) {
            $category_ids = implode(', ', $new_cat_ids);
            $query = <<<SQL
                SELECT category_id, MAX(`rank`) AS max_rank
                FROM image_category
                WHERE `rank` IS NOT NULL
                    AND category_id IN ({$category_ids})
                GROUP BY category_id;
                SQL;
            $current_rank_of = functions_mysqli::query2array(
                $query,
                'category_id',
                'max_rank'
            );

            foreach ($new_cat_ids as $cat_id) {
                if (! isset($current_rank_of[$cat_id])) {
                    $current_rank_of[$cat_id] = 0;
                }

                if ($rank_on_category[$cat_id] == 'auto') {
                    $rank_on_category[$cat_id] = $current_rank_of[$cat_id] + 1;
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

        functions_mysqli::mass_inserts(
            'image_category',
            array_keys($inserts[0]),
            $inserts
        );

        functions_admin::update_category($new_cat_ids);
        return null;
    }

    /**
     * Merge chunks added by pwg.images.addChunk
     */
    public static function merge_chunks(
        string $output_filepath,
        string $original_sum,
        string $type
    ): ?PwgError {
        global $conf, $logger;

        $logger->debug('[merge_chunks] input parameter $output_filepath : ' . $output_filepath);

        if (is_file($output_filepath)) {
            unlink($output_filepath);

            if (is_file($output_filepath)) {
                return new PwgError(500, '[merge_chunks] error while trying to remove existing ' . $output_filepath);
            }
        }

        $upload_dir = $conf['upload_dir'] . '/buffer';
        $pattern = '/' . $original_sum . '-' . $type . '/';
        $chunks = [];
        $handle = opendir($upload_dir);

        if ($handle) {
            while (false !== ($file = readdir($handle))) {
                if (preg_match($pattern, $file)) {
                    $logger->debug($file);
                    $chunks[] = $upload_dir . '/' . $file;
                }
            }

            closedir($handle);
        }

        sort($chunks);

        if (function_exists('memory_get_usage')) {
            $logger->debug('[merge_chunks] memory_get_usage before loading chunks: ' . memory_get_usage());
        }

        $i = 0;

        foreach ($chunks as $chunk) {
            $string = file_get_contents($chunk);

            if (function_exists('memory_get_usage')) {
                $logger->debug('[merge_chunks] memory_get_usage on chunk ' . ++$i . ': ' . memory_get_usage());
            }

            if (! file_put_contents($output_filepath, $string, FILE_APPEND)) {
                return new PwgError(500, '[merge_chunks] error while writing chunks for ' . $output_filepath);
            }

            unlink($chunk);
        }

        if (function_exists('memory_get_usage')) {
            $logger->debug('[merge_chunks] memory_get_usage after loading chunks: ' . memory_get_usage());
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
    public static function remove_chunks(
        string $original_sum,
        string $type
    ): void {
        global $conf;

        $upload_dir = $conf['upload_dir'] . '/buffer';
        $pattern = '/' . $original_sum . '-' . $type . '/';
        $chunks = [];
        $handle = opendir($upload_dir);

        if ($handle) {
            while (false !== ($file = readdir($handle))) {
                if (preg_match($pattern, $file)) {
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
     * @param array{
     *     image_id: int,
     *     author: string,
     *     content: string,
     *     key: string,
     * } $params
     * @throws Exception
     */
    public static function ws_images_addComment(
        array $params,
        PwgServer $service
    ): PwgError|array {
        $sql_conditions = functions_user::get_sql_condition_FandF(
            [
                'forbidden_categories' => 'id',
                'visible_categories' => 'id',
                'visible_images' => 'image_id',
            ],
            'AND'
        );

        $query = <<<SQL
            SELECT DISTINCT image_id
            FROM image_category
            INNER JOIN categories ON category_id=id
            WHERE commentable = "true"
                AND image_id = {$params['image_id']}
                {$sql_conditions};
            SQL;

        if (! functions_mysqli::pwg_db_num_rows(functions_mysqli::pwg_query($query))) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid image_id');
        }

        $comm = [
            'author' => trim($params['author']),
            'content' => trim($params['content']),
            'image_id' => $params['image_id'],
        ];

        require_once __DIR__ . '/../../inc/functions_comment.php';

        $comment_action = functions_comment::insert_user_comment($comm, $params['key'], $infos);

        switch ($comment_action) {
            case 'reject':
                $infos[] = functions::l10n('Your comment has NOT been registered because it did not pass the validation rules');
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
     * @param array{
     *     image_id: int|string,
     *     comments_page: int,
     *     comments_per_page: int,
     * } $params
     */
    public static function ws_images_getInfo(
        array $params,
        PwgServer $service
    ): array|PwgError {
        global $user, $conf;

        $sql_conditions = functions_user::get_sql_condition_FandF(
            [
                'visible_images' => 'id',
            ],
            'AND'
        );

        $query = <<<SQL
            SELECT *
            FROM images
            WHERE id = {$params['image_id']}
                {$sql_conditions}
            LIMIT 1;
            SQL;
        $result = functions_mysqli::pwg_query($query);

        if (functions_mysqli::pwg_db_num_rows($result) == 0) {
            return new PwgError(404, 'image_id not found');
        }

        $image_row = functions_mysqli::pwg_db_fetch_assoc($result);
        $image_row = array_merge($image_row, ws_functions::ws_std_get_urls($image_row));

        //-------------------------------------------------------- related categories
        $sql_conditions = functions_user::get_sql_condition_FandF(
            [
                'forbidden_categories' => 'category_id',
            ],
            'AND'
        );

        $query = <<<SQL
            SELECT id, name, permalink, uppercats, global_rank, commentable
            FROM image_category
            INNER JOIN categories ON category_id = id
            WHERE image_id = {$image_row['id']}
                {$sql_conditions};
            SQL;
        $result = functions_mysqli::pwg_query($query);

        $is_commentable = false;
        $related_categories = [];

        while ($row = functions_mysqli::pwg_db_fetch_assoc($result)) {
            if ($row['commentable'] == 'true') {
                $is_commentable = true;
            }

            unset($row['commentable']);

            $row['url'] = functions_url::make_index_url(
                [
                    'category' => $row,
                ]
            );

            $row['page_url'] = functions_url::make_picture_url(
                [
                    'image_id' => $image_row['id'],
                    'image_file' => $image_row['file'],
                    'category' => $row,
                ]
            );

            $row['id'] = (int) $row['id'];
            $related_categories[] = $row;
        }

        usort($related_categories, functions_category::global_rank_compare(...));

        if (empty($related_categories) and
            ! functions_user::is_admin()
        ) {
            // photo might be in the lounge? or simply orphan. A standard user should not get
            // info. An admin should still be able to get info.
            return new PwgError(401, 'Access denied');
        }

        //-------------------------------------------------------------- related tags
        $related_tags = functions_tag::get_common_tags([$image_row['id']], -1);

        foreach ($related_tags as $i => $tag) {
            $tag['url'] = functions_url::make_index_url(
                [
                    'tags' => [$tag],
                ]
            );
            $tag['page_url'] = functions_url::make_picture_url(
                [
                    'image_id' => $image_row['id'],
                    'image_file' => $image_row['file'],
                    'tags' => [$tag],
                ]
            );

            unset($tag['counter']);
            $tag['id'] = (int) $tag['id'];
            $related_tags[$i] = $tag;
        }

        //------------------------------------------------------------- related rates
        $rating = [
            'score' => $image_row['rating_score'],
            'count' => 0,
            'average' => null,
        ];

        if (isset($rating['score'])) {
            $query = <<<SQL
                SELECT COUNT(rate) AS count, ROUND(AVG(rate), 2) AS average
                FROM rate
                WHERE element_id = {$image_row['id']};
                SQL;
            $row = functions_mysqli::pwg_db_fetch_assoc(functions_mysqli::pwg_query($query));

            $rating['score'] = (float) $rating['score'];
            $rating['average'] = (float) $row['average'];
            $rating['count'] = (int) $row['count'];
        }

        //---------------------------------------------------------- related comments
        $related_comments = [];

        $where_comments = 'image_id = ' . $image_row['id'];

        if (! functions_user::is_admin()) {
            $where_comments .= ' AND validated="true"';
        }

        $query = <<<SQL
            SELECT COUNT(id) AS nb_comments
            FROM comments
            WHERE {$where_comments};
            SQL;
        list($nb_comments) = functions_mysqli::query2array($query, null, 'nb_comments');
        $nb_comments = (int) $nb_comments;

        if ($nb_comments > 0 and
            $params['comments_per_page'] > 0
        ) {
            $offset = $params['comments_per_page'] * $params['comments_page'];
            $query = <<<SQL
                SELECT id, date, author, content
                FROM comments
                WHERE {$where_comments}
                ORDER BY date
                LIMIT {$params['comments_per_page']} OFFSET {$offset};
                SQL;
            $result = functions_mysqli::pwg_query($query);

            while ($row = functions_mysqli::pwg_db_fetch_assoc($result)) {
                $row['id'] = (int) $row['id'];
                $related_comments[] = $row;
            }
        }

        $comment_post_data = null;

        if ($is_commentable and
           (! functions_user::is_a_guest() or
           (functions_user::is_a_guest() and $conf['comments_forall']))
        ) {
            $comment_post_data['author'] = stripslashes($user['username']);
            $comment_post_data['key'] = functions::get_ephemeral_key(2, $params['image_id']);
        }

        $ret = $image_row;

        foreach (['id', 'width', 'height', 'hit', 'filesize'] as $k) {
            if (isset($ret[$k])) {
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
            ws_functions::ws_std_get_tag_xml_attributes()
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
        }

        return [
            'image' => new PwgNamedStruct($ret, null, ['name', 'comment']),
        ];

    }

    /**
     * API method
     * Rates an image
     * @param array{
     *     image_id: int,
     *     rate: float,
     * } $params
     */
    public static function ws_images_rate(
        array $params,
        PwgServer $service
    ): PwgError|array {
        $sql_conditions = functions_user::get_sql_condition_FandF(
            [
                'forbidden_categories' => 'category_id',
                'forbidden_images' => 'id',
            ],
            'AND'
        );

        $query = <<<SQL
            SELECT DISTINCT id
            FROM images
            INNER JOIN image_category ON id = image_id
            WHERE id = {$params['image_id']}
                {$sql_conditions}
            LIMIT 1;
            SQL;
        if (functions_mysqli::pwg_db_num_rows(functions_mysqli::pwg_query($query)) == 0) {
            return new PwgError(404, 'Invalid image_id or access denied');
        }

        $res = functions_rate::rate_picture($params['image_id'], (int) $params['rate']);

        if ($res == false) {
            global $conf;
            return new PwgError(403, 'Forbidden or rate not in ' . implode(',', $conf['rate_items']));
        }

        return $res;
    }

    /**
     * API method
     * Returns a list of elements corresponding to a query search
     * @param array{
     *     query: string,
     *     per_page: int,
     *     page: int,
     *     order?: string,
     * } $params
     */
    public static function ws_images_search(
        array $params,
        PwgServer $service
    ): array {
        $images = [];
        $where_clauses = ws_functions::ws_std_image_sql_filter($params, 'i.');
        $order_by = ws_functions::ws_std_image_sql_order($params, 'i.');

        $super_order_by = false;

        if (! empty($order_by)) {
            global $conf;
            $conf['order_by'] = 'ORDER BY ' . $order_by;
            $super_order_by = true; // quick_search_result might be faster
        }

        $search_result = functions_search::get_quick_search_results(
            $params['query'],
            [
                'super_order_by' => $super_order_by,
                'images_where' => implode(' AND ', $where_clauses),
            ]
        );

        $image_ids = array_slice(
            $search_result['items'],
            $params['page'] * $params['per_page'],
            $params['per_page']
        );

        if (count($image_ids)) {
            $image_ids_list = implode(', ', $image_ids);
            $query = <<<SQL
                SELECT *
                FROM images
                WHERE id IN ({$image_ids_list});
                SQL;
            $result = functions_mysqli::pwg_query($query);
            $image_ids = array_flip($image_ids);
            $favorite_ids = functions_url::get_user_favorites();

            while ($row = functions_mysqli::pwg_db_fetch_assoc($result)) {
                $image = [];
                $image['is_favorite'] = isset($favorite_ids[$row['id']]);

                foreach (['id', 'width', 'height', 'hit'] as $k) {
                    if (isset($row[$k])) {
                        $image[$k] = (int) $row[$k];
                    }
                }

                foreach (['file', 'name', 'comment', 'date_creation', 'date_available'] as $k) {
                    $image[$k] = $row[$k];
                }

                $image = array_merge($image, ws_functions::ws_std_get_urls($row));
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
                    'total_count' => count($search_result['items']),
                ]
            ),
            'images' => new PwgNamedArray(
                $images,
                'image',
                ws_functions::ws_std_get_image_xml_attributes()
            ),
        ];
    }

    /**
     * API method
     * Registers a new search
     * @param array{
     *     query: string,
     *     search_id: mixed,
     *     allwords: mixed,
     *     allwords_mode: mixed,
     *     allwords_fields: mixed,
     *     tags: array,
     *     tags_mode: mixed,
     *     categories: array<int>,
     *     categories_withsubs: bool,
     *     filetypes: array,
     *     added_by: array,
     *     date_posted: mixed,
     *     authors: array,
     * } $params
     */
    public static function ws_images_filteredSearch_create(
        array $params,
        PwgServer $service
    ): PwgError|array {
        global $user;

        // * check the search exists
        if (isset($params['search_id'])) {
            if (empty(functions_search::get_search_id_pattern($params['search_id']))) {
                return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid search_id input parameter.');
            }

            $search_info = functions_search::get_search_info($params['search_id']);

            if (empty($search_info)) {
                return new PwgError(WS_ERR_INVALID_PARAM, 'This search does not exist.');
            }
        }

        $search = [
            'mode' => 'AND',
        ];

        // * check all parameters
        if (isset($params['allwords'])) {
            $search['fields']['allwords'] = [];

            if (! isset($params['allwords_mode'])) {
                $params['allwords_mode'] = 'AND';
            }

            if (! preg_match('/^(OR|AND)$/', $params['allwords_mode'])) {
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

            $search['fields']['allwords']['words'] = functions_search::split_allwords($params['allwords']);
        }

        if (isset($params['tags'])) {
            foreach ($params['tags'] as $tag_id) {
                if (! preg_match('/^\d+$/', $tag_id)) {
                    return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid parameter tags');
                }
            }

            if (! isset($params['tags_mode'])) {
                $params['tags_mode'] = 'AND';
            }

            if (! preg_match('/^(OR|AND)$/', $params['tags_mode'])) {
                return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid parameter tags_mode');
            }

            $search['fields']['tags'] = [
                'words' => $params['tags'],
                'mode' => $params['tags_mode'],
            ];
        }

        if (isset($params['categories'])) {
            foreach ($params['categories'] as $cat_id) {
                if (! preg_match('/^\d+$/', (string) $cat_id)) {
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
                if (! preg_match('/^[a-z0-9]+$/i', $ext)) {
                    return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid parameter filetypes');
                }
            }

            $search['fields']['filetypes'] = $params['filetypes'];
        }

        if (isset($params['added_by'])) {
            foreach ($params['added_by'] as $user_id) {
                if (! preg_match('/^\d+$/', (string) $user_id)) {
                    return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid parameter added_by');
                }
            }

            $search['fields']['added_by'] = $params['added_by'];
        }

        if (isset($params['date_posted'])) {
            if (! preg_match('/^(24h|7d|30d|3m|6m|y\d+|)$/', $params['date_posted'])) {
                return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid parameter date_posted');
            }

            $search['fields']['date_posted'] = $params['date_posted'];
        }

        list($search_uuid, $search_url) = functions_search::save_search($search, $search_info['id'] ?? null);

        return [
            'search_id' => $search_uuid,
            'search_url' => $search_url,
        ];
    }

    /**
     * API method
     * Sets the level of an image
     * @param array{
     *     image_id: array<int>,
     *     level: int,
     * } $params
     */
    public static function ws_images_setPrivacyLevel(
        array $params,
        PwgServer $service
    ): int|string|PwgError {
        global $conf;

        if (! in_array($params['level'], $conf['available_permission_levels'])) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid level');
        }

        $image_ids_list = implode(', ', $params['image_id']);
        $query = <<<SQL
            UPDATE images
            SET level = {$params['level']}
            WHERE id IN ({$image_ids_list});
            SQL;
        $result = functions_mysqli::pwg_query($query);

        functions::pwg_activity('photo', $params['image_id'], 'edit');

        $affected_rows = functions_mysqli::pwg_db_changes($result);

        if ($affected_rows) {
            functions_admin::invalidate_user_cache();
        }

        return $affected_rows;
    }

    /**
     * API method
     * Sets the rank of an image in a category
     * @param array{
     *     image_id: array<int>|int,
     *     category_id: int,
     *     rank: int,
     * } $params
     */
    public static function ws_images_setRank(
        array $params,
        PwgServer $service
    ): PwgError|array {
        if (count($params['image_id']) > 1) {
            functions_admin::save_images_order(
                $params['category_id'],
                $params['image_id']
            );

            $query = <<<SQL
                SELECT image_id
                FROM image_category
                WHERE category_id = {$params['category_id']}
                ORDER BY `rank` ASC;
                SQL;
            $image_ids = functions_mysqli::query2array($query, null, 'image_id');

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
        $query = <<<SQL
            SELECT COUNT(*)
            FROM images
            WHERE id = {$params['image_id']};
            SQL;
        list($count) = functions_mysqli::pwg_db_fetch_row(functions_mysqli::pwg_query($query));

        if ($count == 0) {
            return new PwgError(404, 'image_id not found');
        }

        // is the image associated to this category?
        $query = <<<SQL
            SELECT COUNT(*)
            FROM image_category
            WHERE image_id = {$params['image_id']}
                AND category_id = {$params['category_id']};
            SQL;
        list($count) = functions_mysqli::pwg_db_fetch_row(functions_mysqli::pwg_query($query));

        if ($count == 0) {
            return new PwgError(404, 'This image is not associated to this category');
        }

        // what is the current higher rank for this category?
        $query = <<<SQL
            SELECT MAX(`rank`) AS max_rank
            FROM image_category
            WHERE category_id = {$params['category_id']};
            SQL;
        $row = functions_mysqli::pwg_db_fetch_assoc(functions_mysqli::pwg_query($query));

        if (is_numeric($row['max_rank'])) {
            if ($params['rank'] > $row['max_rank']) {
                $params['rank'] = $row['max_rank'] + 1;
            }
        } else {
            $params['rank'] = 1;
        }

        // update rank for all other photos in the same category
        $query = <<<SQL
            UPDATE image_category
            SET `rank` = `rank` + 1
            WHERE category_id = {$params['category_id']}
                AND `rank` IS NOT NULL
                AND `rank` >= {$params['rank']};
            SQL;
        functions_mysqli::pwg_query($query);

        // set the new rank for the photo
        $query = <<<SQL
            UPDATE image_category
            SET `rank` = {$params['rank']}
            WHERE image_id = {$params['image_id']}
                AND category_id = {$params['category_id']};
            SQL;
        functions_mysqli::pwg_query($query);

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
     * @param array{
     *     data: string,
     *     original_sum: string,
     *     type: string,
     *     position: int,
     * } $params
     *     type = 'file'
     */
    public static function ws_images_add_chunk(
        array $params,
        PwgServer $service
    ): ?PwgError {
        global $conf, $logger;

        foreach ($params as $param_key => $param_value) {
            if ($param_key == 'data') {
                continue;
            }

            $logger->debug(sprintf(
                '[ws_images_add_chunk] input param "%s" : "%s"',
                $param_key,
                $param_value === null ? 'NULL' : $param_value
            ));
        }

        $upload_dir = $conf['upload_dir'] . '/buffer';

        // create the upload directory tree if not exists
        if (! functions::mkgetdir($upload_dir, functions::MKGETDIR_DEFAULT & ~functions::MKGETDIR_DIE_ON_ERROR)) {
            return new PwgError(500, 'error during buffer directory creation');
        }

        $filename = sprintf(
            '%s-%s-%05u.block',
            $params['original_sum'],
            $params['type'],
            $params['position']
        );

        $logger->debug('[ws_images_add_chunk] data length : ' . strlen($params['data']));

        $bytes_written = file_put_contents(
            $upload_dir . '/' . $filename,
            base64_decode($params['data'])
        );

        if ($bytes_written === false) {
            return new PwgError(
                500,
                'an error has occurred while writing chunk ' . $params['position'] . ' for ' . $params['type']
            );
        }

        return null;
    }

    /**
     * API method
     * Adds a file
     * @param array{
     *     image_id: int|string,
     *     type: string,
     *     sum: string,
     * } $params
     *     type = 'file'
     */
    public static function ws_images_addFile(
        array $params,
        PwgServer $service
    ): bool|PwgError|null {
        global $conf, $logger;

        $logger->debug(__FUNCTION__, $params);

        // what is the path and other infos about the photo?
        $query = <<<SQL
            SELECT path, file, md5sum, width, height, filesize
            FROM images
            WHERE id = {$params['image_id']};
            SQL;
        $result = functions_mysqli::pwg_query($query);

        if (functions_mysqli::pwg_db_num_rows($result) == 0) {
            return new PwgError(404, 'image_id not found');
        }

        $image = functions_mysqli::pwg_db_fetch_assoc($result);

        // since Piwigo 2.4 and derivatives, we do not take the imported "thumb" into account
        if ($params['type'] == 'thumb') {
            self::remove_chunks($image['md5sum'], $type);
            return true;
        }

        // since Piwigo 2.4 and derivatives, we only care about the "original"
        $original_type = 'file';

        if ($params['type'] == 'high') {
            $original_type = 'high';
        }

        $file_path = $conf['upload_dir'] . '/buffer/' . $image['md5sum'] . '-original';

        self::merge_chunks($file_path, $image['md5sum'], $original_type);
        chmod($file_path, 0644);

        require_once __DIR__ . '/../../admin/inc/functions_upload.php';

        // if we receive the "file", we only update the original if the "file" is
        // bigger than current original
        if ($params['type'] == 'file') {
            $do_update = false;

            $infos = functions_upload::pwg_image_infos($file_path);

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

        $image_id = functions_upload::add_uploaded_file(
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
     * @param array{
     *     original_sum: string,
     *     original_filename?: string,
     *     name?: string,
     *     author?: string,
     *     date_creation?: string,
     *     comment?: string,
     *     categories?: string,
     *     tags_ids?: string,
     *     level: int,
     *     check_uniqueness: bool,
     *     image_id?: int,
     *     high_sum: mixed,
     *     tag_ids: mixed,
     * } $params
     */
    public static function ws_images_add(
        array $params,
        PwgServer $service
    ): PwgError|array {
        global $conf, $user, $logger;

        foreach ($params as $param_key => $param_value) {
            $logger->debug(sprintf(
                '[pwg.images.add] input param "%s" : "%s"',
                $param_key,
                $param_value === null ? 'NULL' : $param_value
            ));
        }

        if ($params['image_id'] > 0) {
            $query = <<<SQL
                SELECT COUNT(*)
                FROM images
                WHERE id = {$params['image_id']};
                SQL;
            list($count) = functions_mysqli::pwg_db_fetch_row(functions_mysqli::pwg_query($query));
            if ($count == 0) {
                return new PwgError(404, 'image_id not found');
            }
        }

        // does the image already exists ?
        if ($params['check_uniqueness']) {
            if ($conf['uniqueness_mode'] == 'md5sum') {
                $where_clause = "md5sum = '" . $params['original_sum'] . "'";
            }

            if ($conf['uniqueness_mode'] == 'filename') {
                $where_clause = "file = '" . $params['original_filename'] . "'";
            }

            $query = <<<SQL
                SELECT COUNT(*)
                FROM images
                WHERE {$where_clause};
                SQL;
            list($counter) = functions_mysqli::pwg_db_fetch_row(functions_mysqli::pwg_query($query));

            if ($counter != 0) {
                return new PwgError(500, 'file already exists');
            }
        }

        // due to the new feature "derivatives" (multiple sizes) introduced for
        // Piwigo 2.4, we only take the biggest photos sent on
        // pwg.images.addChunk. If "high" is available we use it as "original"
        // else we use "file".
        self::remove_chunks($params['original_sum'], 'thumb');

        if (isset($params['high_sum'])) {
            $original_type = 'high';
            self::remove_chunks($params['original_sum'], 'file');
        } else {
            $original_type = 'file';
        }

        $file_path = $conf['upload_dir'] . '/buffer/' . $params['original_sum'] . '-original';

        self::merge_chunks($file_path, $params['original_sum'], $original_type);
        chmod($file_path, 0644);

        require_once __DIR__ . '/../../admin/inc/functions_upload.php';

        $image_id = functions_upload::add_uploaded_file(
            $file_path,
            $params['original_filename'],
            null, // categories
            isset($params['level']) ? $params['level'] : null,
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
            functions_mysqli::single_update(
                'images',
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
            self::ws_add_image_category_relations($image_id, $params['categories']);

            if (preg_match('/^\d+/', $params['categories'], $matches)) {
                $category_id = $matches[0];

                $query = <<<SQL
                    SELECT id, name, permalink
                    FROM categories
                    WHERE id = {$category_id};
                    SQL;
                $result = functions_mysqli::pwg_query($query);
                $category = functions_mysqli::pwg_db_fetch_assoc($result);

                $url_params['section'] = 'categories';
                $url_params['category'] = $category;
            }
        }

        // and now, let's create tag associations
        if (isset($params['tag_ids']) and
            ! empty($params['tag_ids'])
        ) {
            functions_admin::set_tags(
                explode(',', $params['tag_ids']),
                $image_id
            );
        }

        functions_admin::invalidate_user_cache();

        return [
            'image_id' => $image_id,
            'url' => functions_url::make_picture_url($url_params),
        ];
    }

    /**
     * API method
     * Adds a image (simple way)
     * @param array{
     *     category: array<int>,
     *     name?: string,
     *     author?: string,
     *     comment?: string,
     *     level: int,
     *     tags: string|array<string>,
     *     image_id?: int,
     * } $params
     */
    public static function ws_images_addSimple(
        array $params,
        PwgServer $service
    ): PwgError|array {
        global $conf, $logger;

        if (! isset($_FILES['image'])) {
            return new PwgError(405, 'The image (file) is missing');
        }

        if (isset($_FILES['image']['error']) &&
            $_FILES['image']['error'] != 0
        ) {
            switch ($_FILES['image']['error']) {
                case UPLOAD_ERR_INI_SIZE:
                    $message = 'The uploaded file exceeds the upload_max_filesize directive in php.ini.';
                    break;

                case UPLOAD_ERR_FORM_SIZE:
                    $message = 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.';
                    break;

                case UPLOAD_ERR_PARTIAL:
                    $message = 'The uploaded file was only partially uploaded.';
                    break;

                case UPLOAD_ERR_NO_FILE:
                    $message = 'No file was uploaded.';
                    break;

                case UPLOAD_ERR_NO_TMP_DIR:
                    $message = 'Missing a temporary folder.';
                    break;

                case UPLOAD_ERR_CANT_WRITE:
                    $message = 'Failed to write file to disk.';
                    break;

                case UPLOAD_ERR_EXTENSION:
                    $message = 'A PHP extension stopped the file upload. ' .
                    'PHP does not provide a way to ascertain which extension caused the file ' .
                    'upload to stop; examining the list of loaded extensions with phpinfo() may help.';
                    break;

                default:
                    $message = "Error number {$_FILES['image']['error']} occurred while uploading a file.";
            }

            $logger->error(__FUNCTION__ . ' ' . $message);
            return new PwgError(500, $message);
        }

        if ($params['image_id'] > 0) {
            $query = <<<SQL
                SELECT COUNT(*)
                FROM images
                WHERE id = {$params['image_id']};
                SQL;
            list($count) = functions_mysqli::pwg_db_fetch_row(functions_mysqli::pwg_query($query));

            if ($count == 0) {
                return new PwgError(404, 'image_id not found');
            }
        }

        require_once __DIR__ . '/../../admin/inc/functions_upload.php';

        $image_id = functions_upload::add_uploaded_file(
            $_FILES['image']['tmp_name'],
            $_FILES['image']['name'],
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

        functions_mysqli::single_update(
            'images',
            $update,
            [
                'id' => $image_id,
            ]
        );

        if (isset($params['tags']) and
            ! empty($params['tags'])
        ) {
            $tag_ids = [];

            if (is_array($params['tags'])) {
                foreach ($params['tags'] as $tag_name) {
                    $tag_ids[] = functions_admin::tag_id_from_tag_name($tag_name);
                }
            } else {
                $tag_names = preg_split('~(?<!\\\),~', $params['tags']);

                foreach ($tag_names as $tag_name) {
                    $tag_ids[] = functions_admin::tag_id_from_tag_name(preg_replace('#\\\\*,#', ',', $tag_name));
                }
            }

            functions_admin::add_tags($tag_ids, [$image_id]);
        }

        $url_params = [
            'image_id' => $image_id,
        ];

        if (! empty($params['category'])) {
            $query = <<<SQL
                SELECT id, name, permalink
                FROM categories
                WHERE id = {$params['category'][0]};
                SQL;
            $result = functions_mysqli::pwg_query($query);
            $category = functions_mysqli::pwg_db_fetch_assoc($result);

            $url_params['section'] = 'categories';
            $url_params['category'] = $category;
        }

        // update metadata from the uploaded file (exif/iptc), even if the sync
        // was already performed by add_uploaded_file().
        functions_metadata_admin::sync_metadata([$image_id]);

        return [
            'image_id' => $image_id,
            'url' => functions_url::make_picture_url($url_params),
        ];
    }

    /**
     * API method
     * Adds a image (simple way)
     * @param array{
     *     category: array<int>,
     *     name?: string,
     *     author?: string,
     *     comment?: string,
     *     level: int,
     *     tags: string|array<string>,
     *     image_id?: int,
     *     pwg_token: mixed,
     *     format_of: mixed,
     * } $params
     */
    public static function ws_images_upload(
        array $params,
        PwgServer $service
    ): array|PwgError|null {
        global $conf;

        if (functions::get_pwg_token() != $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        if (isset($params['format_of'])) {
            $format_ext = null;

            // are formats enabled?
            if (! $conf['enable_formats']) {
                return new PwgError(401, 'formats are disabled');
            }

            // We must check if the extension is in the authorized list.
            if (preg_match('/\.(' . implode('|', $conf['format_ext']) . ')$/', $params['name'], $matches)) {
                $format_ext = $matches[1];
            }

            if (empty($format_ext)) {
                return new PwgError(401, 'unexpected format extension of file "' . $params['name'] . '" (authorized extensions: ' . implode(', ', $conf['format_ext']) . ')');
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

        $upload_dir = $conf['upload_dir'] . '/buffer';

        // create the upload directory tree if not exists
        if (! functions::mkgetdir($upload_dir, functions::MKGETDIR_DEFAULT & ~functions::MKGETDIR_DIE_ON_ERROR)) {
            return new PwgError(500, 'error during buffer directory creation');
        }

        // Get a file name
        if (isset($_REQUEST['name'])) {
            $fileName = $_REQUEST['name'];
        } elseif (! empty($_FILES)) {
            $fileName = $_FILES['file']['name'];
        } else {
            $fileName = uniqid('file_');
        }

        // change the name of the file in the buffer to avoid any unexpected
        // extension. Function add_uploaded_file will eventually clean the mess.
        $fileName = md5($fileName);

        $filePath = $upload_dir . DIRECTORY_SEPARATOR . $fileName;

        // Chunking might be enabled
        $chunk = isset($_REQUEST['chunk']) ? intval($_REQUEST['chunk']) : 0;
        $chunks = isset($_REQUEST['chunks']) ? intval($_REQUEST['chunks']) : 0;

        // file_put_contents('/tmp/plupload.log', "[".date('c')."] ".__FUNCTION__.', '.$fileName.' '.($chunk+1).'/'.$chunks."\n", FILE_APPEND);

        // Open temp file
        $out = fopen("{$filePath}.part", $chunks ? 'ab' : 'wb');

        if (! $out) {
            die('{"jsonrpc" : "2.0", "error" : {"code": 102, "message": "Failed to open output stream."}, "id" : "id"}');
        }

        if (! empty($_FILES)) {
            if ($_FILES['file']['error'] ||
                ! is_uploaded_file($_FILES['file']['tmp_name'])
            ) {
                die('{"jsonrpc" : "2.0", "error" : {"code": 103, "message": "Failed to move uploaded file."}, "id" : "id"}');
            }

            // Read binary input stream and append it to temp file
            $in = fopen($_FILES['file']['tmp_name'], 'rb');

            if (! $in) {
                die('{"jsonrpc" : "2.0", "error" : {"code": 101, "message": "Failed to open input stream."}, "id" : "id"}');
            }
        } else {
            $in = fopen('php://input', 'rb');

            if (! $in) {
                die('{"jsonrpc" : "2.0", "error" : {"code": 101, "message": "Failed to open input stream."}, "id" : "id"}');
            }
        }

        while ($buff = fread($in, 4096)) {
            fwrite($out, $buff);
        }

        fclose($out);
        fclose($in);

        // Check if file has been uploaded
        if (! $chunks ||
            $chunk == $chunks - 1
        ) {
            // Strip the temp .part suffix off
            rename("{$filePath}.part", $filePath);

            require_once __DIR__ . '/../../admin/inc/functions_upload.php';

            if (isset($params['format_of'])) {
                $query = <<<SQL
                    SELECT *
                    FROM images
                    WHERE id = {$params['format_of']};
                    SQL;
                $images = functions_mysqli::query2array($query);

                if (count($images) == 0) {
                    return new PwgError(404, __FUNCTION__ . ' : image_id not found');
                }

                $image = $images[0];

                functions_upload::add_format($filePath, $format_ext, $image['id']);

                return [
                    'image_id' => $image['id'],
                    'src' => DerivativeImage::thumb_url($image),
                    'square_src' => DerivativeImage::url(ImageStdParams::get_by_type(derivative_std_params::IMG_SQUARE), $image),
                    'name' => $image['name'],
                ];
            }

            $image_id = functions_upload::add_uploaded_file(
                $filePath,
                stripslashes($params['name']), // function add_uploaded_file will secure before insert
                $params['category'],
                $params['level'],
                null // image_id = not provided, this is a new photo
            );

            $query = <<<SQL
                SELECT id, name, representative_ext, path
                FROM images
                WHERE id = {$image_id};
                SQL;
            $image_infos = functions_mysqli::pwg_db_fetch_assoc(functions_mysqli::pwg_query($query));

            $query = <<<SQL
                SELECT COUNT(*) AS nb_photos
                FROM image_category
                WHERE category_id = {$params['category'][0]};
                SQL;
            $category_infos = functions_mysqli::pwg_db_fetch_assoc(functions_mysqli::pwg_query($query));

            $query = <<<SQL
                SELECT COUNT(*)
                FROM lounge
                WHERE category_id = {$params['category'][0]};
                SQL;
            list($nb_photos_lounge) = functions_mysqli::pwg_db_fetch_row(functions_mysqli::pwg_query($query));

            $category_name = functions_html::get_cat_display_name_from_id($params['category'][0], null);

            return [
                'image_id' => $image_id,
                'src' => DerivativeImage::thumb_url($image_infos),
                'square_src' => DerivativeImage::url(ImageStdParams::get_by_type(derivative_std_params::IMG_SQUARE), $image_infos),
                'name' => $image_infos['name'],
                'category' => [
                    'id' => $params['category'][0],
                    'nb_photos' => $category_infos['nb_photos'] + $nb_photos_lounge,
                    'label' => $category_name,
                ],
            ];
        }

        return null;
    }

    /**
     * API method
     * Adds a chunk of an image. Chunks don't have to be uploaded in the right sort order. When the last chunk is added, they get merged.
     * @param array{
     *     username: string,
     *     password: string,
     *     chunk: int,
     *     chunk_sum: string,
     *     chunks: int,
     *     original_sum: string,
     *     category: array<int>,
     *     filename: string,
     *     name?: string,
     *     author?: string,
     *     comment?: string,
     *     date_creation?: string,
     *     level: int,
     *     tag_ids?: string,
     *     image_id?: int|string,
     * } $params
     */
    public static function ws_images_uploadAsync(
        array $params,
        PwgServer &$service
    ): array|bool|PwgError|string|null {
        global $conf, $user, $logger;

        // the username/password parameters have been used in inc/user.php
        // to authenticate the request (a much better time/place than here)

        // additional check for some parameters
        if (! preg_match('/^[a-fA-F0-9]{32}$/', $params['original_sum'])) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid original_sum');
        }

        if ($params['image_id'] > 0) {
            $query = <<<SQL
                SELECT COUNT(*)
                FROM images
                WHERE id = {$params['image_id']};
                SQL;
            list($count) = functions_mysqli::pwg_db_fetch_row(functions_mysqli::pwg_query($query));

            if ($count == 0) {
                return new PwgError(404, __FUNCTION__ . ' : image_id not found');
            }
        }

        // handle upload error as in ws_images_addSimple
        // if (isset($_FILES['image']['error']) && $_FILES['image']['error'] != 0)

        $output_filepath_prefix = $conf['upload_dir'] . '/buffer/' . $params['original_sum'] . '-u' . $user['id'];
        $chunkfile_path_pattern = $output_filepath_prefix . '-%03uof%03u.chunk';

        $chunkfile_path = sprintf($chunkfile_path_pattern, $params['chunk'] + 1, $params['chunks']);

        // create the upload directory tree if not exists
        if (! functions::mkgetdir(dirname($chunkfile_path), functions::MKGETDIR_DEFAULT & ~functions::MKGETDIR_DIE_ON_ERROR)) {
            return new PwgError(500, 'error during buffer directory creation');
        }

        functions::secure_directory(dirname($chunkfile_path));

        // move uploaded file
        move_uploaded_file($_FILES['file']['tmp_name'], $chunkfile_path);
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

            if (file_exists($chunkfile)) {
                $fp = fopen($chunkfile, 'rb');

                if ($fp !== false) {
                    $chunk_ids_uploaded[] = $i;
                    fclose($fp);
                }
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
        if (file_exists($output_filepath)) {
            $fp = fopen($output_filepath, 'rb');

            if ($fp !== false) {
                // merge file already exists
                fclose($fp);
                $logger->error(__FUNCTION__ . ' ' . $output_filepath . ' already exists, another merge is under process');
                return [
                    'message' => 'chunks uploaded = ' . implode(',', $chunk_ids_uploaded),
                ];
            }
        }

        // create merged and open it for writing only
        $fp = fopen($output_filepath, 'wb');

        if (! $fp) {
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

            if (! fwrite($fp, file_get_contents($chunkfile_path))) {
                // could not append chunk
                $logger->error(__FUNCTION__ . ' error merging chunk ' . $chunkfile_path);
                flock($fp, LOCK_UN);
                fclose($fp);

                // delete merge file without returning an error
                unlink($output_filepath);
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

        require_once __DIR__ . '/../../admin/inc/functions_upload.php';

        $image_id = functions_upload::add_uploaded_file(
            $output_filepath,
            $params['filename'],
            $params['category'],
            $params['level'],
            $params['image_id'],
            $params['original_sum']
        );

        $logger->debug(__FUNCTION__ . ' image_id after add_uploaded_file = ' . $image_id);

        // and now, let's create tag associations
        if (isset($params['tag_ids']) and
            ! empty($params['tag_ids'])
        ) {
            functions_admin::set_tags(
                explode(',', $params['tag_ids']),
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
            functions_mysqli::single_update(
                'images',
                $update,
                [
                    'id' => $image_id,
                ]
            );
        }

        // final step, reset user cache
        functions_admin::invalidate_user_cache();

        // trick to bypass get_sql_condition_FandF
        if (! empty($params['level']) and
            $params['level'] > $user['level']
        ) {
            // this will not persist
            $user['level'] = $params['level'];
        }

        // delete chunks older than a week
        $now = time();

        foreach (glob($conf['upload_dir'] . '/buffer/*.chunk') as $file) {
            if (is_file($file)) {
                if ($now - filemtime($file) >= 60 * 60 * 24 * 7) { // 7 days
                    $logger->info(__FUNCTION__ . ' delete ' . $file);
                    unlink($file);
                } else {
                    $logger->debug(__FUNCTION__ . ' keep ' . $file);
                }
            }
        }

        // delete merged older than a week
        foreach (glob($conf['upload_dir'] . '/buffer/*.merged') as $file) {
            if (is_file($file)) {
                if ($now - filemtime($file) >= 60 * 60 * 24 * 7) { // 7 days
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
     * @param array{
     *     md5sum_list?: string,
     *     filename_list?: string,
     * } $params
     */
    public static function ws_images_exist(
        array $params,
        PwgServer $service
    ): array {
        global $conf, $logger;

        $logger->debug(__FUNCTION__, $params);

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

            $md5sum_list = implode("','", $md5sums);
            $query = <<<SQL
                SELECT id, md5sum
                FROM images
                WHERE md5sum IN ('{$md5sum_list}');
                SQL;
            $id_of_md5 = functions_mysqli::query2array($query, 'md5sum', 'id');

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
                $params['filename_list'],
                -1,
                PREG_SPLIT_NO_EMPTY
            );

            $filename_list = implode("','", $filenames);
            $query = <<<SQL
                SELECT id, file
                FROM images
                WHERE file IN ('{$filename_list}');
                SQL;
            $id_of_filename = functions_mysqli::query2array($query, 'file', 'id');

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
     * @param array{
     *     category_id?: string,
     *     filename_list: string,
     * } $params
     */
    public static function ws_images_formats_searchImage(
        array $params,
        PwgServer $service
    ): array {
        global $conf, $logger;

        $logger->debug(__FUNCTION__, $params);

        $candidates = json_decode(stripslashes($params['filename_list']), true);

        $unique_filenames_db = [];

        $query = <<<SQL
            SELECT id, file
            FROM images;
            SQL;
        $result = functions_mysqli::pwg_query($query);

        while ($row = functions_mysqli::pwg_db_fetch_assoc($result)) {
            $filename_wo_ext = functions::get_filename_wo_extension($row['file']);
            $unique_filenames_db[$filename_wo_ext][] = $row['id'];
        }

        // we want "long" format extensions first to match "cmyk.jpg" before "jpg" for example
        usort($conf['format_ext'], function (string $a, string $b): int {
            return strlen($b) - strlen($a);
        });

        $result = [];

        foreach ($candidates as $format_external_id => $format_filename) {
            $candidate_filename_wo_ext = null;

            if (preg_match('/^(.*?)\.(' . implode('|', $conf['format_ext']) . ')$/', $format_filename, $matches)) {
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

                $result[$format_external_id] = [
                    'status' => 'found',
                    'image_id' => $unique_filenames_db[$candidate_filename_wo_ext][0],
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
     * @param array{
     *     format_id: array<int>|string,
     *     pwg_token: string,
     * } $params
     */
    public static function ws_images_formats_delete(
        array $params,
        PwgServer $service
    ): PwgError|bool {
        if (functions::get_pwg_token() != $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        if (! is_array($params['format_id'])) {
            $params['format_id'] = preg_split(
                '/[\s,;\|]/',
                $params['format_id'],
                -1,
                PREG_SPLIT_NO_EMPTY
            );
        }

        $params['format_id'] = array_map(intval(...), $params['format_id']);

        $format_ids = [];

        foreach ($params['format_id'] as $format_id) {
            if ($format_id >= 0) {
                $format_ids[] = $format_id;
            }
        }

        $image_ids = [];
        $formats_of = [];

        //Delete physical file
        $ok = true;

        $format_id_list = implode(', ', $format_ids);
        $query = <<<SQL
            SELECT image_id, ext
            FROM image_format
            WHERE format_id IN ({$format_id_list});
            SQL;
        $result = functions_mysqli::pwg_query($query);

        while ($row = functions_mysqli::pwg_db_fetch_assoc($result)) {
            if (! isset($formats_of[$row['image_id']])) {
                $image_ids[] = $row['image_id'];
                $formats_of[$row['image_id']] = [];
            }

            $formats_of[$row['image_id']][] = $row['ext'];
        }

        if (count($image_ids) == 0) {
            return new PwgError(404, 'No format found for the id(s) given');
        }

        $image_id_list = implode(', ', $image_ids);
        $query = <<<SQL
            SELECT id, path, representative_ext
            FROM images
            WHERE id IN ({$image_id_list});
            SQL;
        $result = functions_mysqli::pwg_query($query);

        while ($row = functions_mysqli::pwg_db_fetch_assoc($result)) {
            if (functions_url::url_is_remote($row['path'])) {
                continue;
            }

            $files = [];
            $image_path = functions::get_element_path($row);

            if (isset($formats_of[$row['id']])) {
                foreach ($formats_of[$row['id']] as $format_ext) {
                    $files[] = functions::original_to_format($image_path, $format_ext);
                }
            }

            foreach ($files as $path) {
                if (is_file($path) and
                    ! unlink($path)
                ) {
                    $ok = false;
                    trigger_error('"' . $path . '" cannot be removed', E_USER_WARNING);
                    break;
                }
            }
        }

        //Delete format in the database
        $format_id_list = implode(', ', $format_ids);
        $query = <<<SQL
            DELETE FROM image_format
            WHERE format_id IN ({$format_id_list});
            SQL;
        functions_mysqli::pwg_query($query);

        functions_admin::invalidate_user_cache();

        return $ok;
    }

    /**
     * API method
     * Check is file has been update
     * @param array{
     *     image_id: int,
     *     file_sum: string,
     *     thumbnail_sum: mixed,
     *     high_sum: mixed,
     * } $params
     */
    public static function ws_images_checkFiles(
        array $params,
        PwgServer $service
    ): PwgError|array {
        global $logger;

        $logger->debug(__FUNCTION__, $params);

        $query = <<<SQL
            SELECT path
            FROM images
            WHERE id = {$params['image_id']};
            SQL;
        $result = functions_mysqli::pwg_query($query);

        if (functions_mysqli::pwg_db_num_rows($result) == 0) {
            return new PwgError(404, 'image_id not found');
        }

        list($path) = functions_mysqli::pwg_db_fetch_row($result);

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
            $logger->debug(__FUNCTION__ . ', md5_file($path) = ' . md5_file($path));

            if (md5_file($path) != $params[$compare_type . '_sum']) {
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
     * @param array{
     *     image_id: int,
     *     file?: string,
     *     name?: string,
     *     author?: string,
     *     date_creation?: string,
     *     comment?: string,
     *     categories?: string,
     *     tags_ids?: string,
     *     level?: int,
     *     single_value_mode: string,
     *     multiple_value_mode: string,
     *     pwg_token: string,
     *     tag_ids: array|string,
     * } $params
     */
    public static function ws_images_setInfo(
        array $params,
        PwgServer $service
    ): ?PwgError {
        global $conf;

        if (isset($params['pwg_token']) and
            functions::get_pwg_token() != $params['pwg_token']
        ) {
            return new PwgError(403, 'Invalid security token');
        }

        $query = <<<SQL
            SELECT *
            FROM images
            WHERE id = {$params['image_id']};
            SQL;
        $result = functions_mysqli::pwg_query($query);

        if (functions_mysqli::pwg_db_num_rows($result) == 0) {
            return new PwgError(404, 'image_id not found');
        }

        $image_row = functions_mysqli::pwg_db_fetch_assoc($result);

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
                if (! $conf['allow_html_descriptions'] or
                    ! isset($params['pwg_token'])
                ) {
                    $params[$key] = strip_tags($params[$key], '<b><strong><em><i>');
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

            functions_mysqli::single_update(
                'images',
                $update,
                [
                    'id' => $update['id'],
                ]
            );

            functions::pwg_activity('photo', $update['id'], 'edit');
        }

        if (isset($params['categories'])) {
            self::ws_add_image_category_relations(
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

                if (preg_match(PATTERN_ID, $candidate)) {
                    $tag_ids[] = $candidate;
                }
            }

            if ($params['multiple_value_mode'] == 'replace') {
                functions_admin::set_tags(
                    $tag_ids,
                    $params['image_id']
                );
            } elseif ($params['multiple_value_mode'] == 'append') {
                functions_admin::add_tags(
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

        functions_admin::invalidate_user_cache();
        return null;
    }

    /**
     * API method
     * Deletes an image
     * @param array{
     *     image_id: int|array<int>|string,
     *     pwg_token: string,
     * } $params
     */
    public static function ws_images_delete(
        array $params,
        PwgServer $service
    ): PwgError|int {
        if (functions::get_pwg_token() != $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        if (! is_array($params['image_id'])) {
            $params['image_id'] = preg_split(
                '/[\s,;\|]/',
                $params['image_id'],
                -1,
                PREG_SPLIT_NO_EMPTY
            );
        }

        $params['image_id'] = array_map(intval(...), $params['image_id']);

        $image_ids = [];

        foreach ($params['image_id'] as $image_id) {
            if ($image_id > 0) {
                $image_ids[] = $image_id;
            }
        }

        $ret = functions_admin::delete_elements($image_ids, true);
        functions_admin::invalidate_user_cache();

        return $ret;
    }

    /**
     * API method
     * Checks if Piwigo is ready for upload
     */
    public static function ws_images_checkUpload(
        array $params,
        PwgServer $service
    ): array {
        require_once __DIR__ . '/../../admin/inc/functions_upload.php';

        $ret['message'] = functions_upload::ready_for_upload_message();
        $ret['ready_for_upload'] = true;

        if (! empty($ret['message'])) {
            $ret['ready_for_upload'] = false;
        }

        return $ret;
    }

    /**
     * API method
     * Empties the lounge, where photos may wait before taking off.
     * @throws RandomException
     */
    public static function ws_images_emptyLounge(
        array $params,
        PwgServer $service
    ): array {
        $ret = [
            'rows' => functions_admin::empty_lounge(),
        ];

        return $ret;
    }

    /**
     * API method
     * Empties the lounge, where photos may wait before taking off.
     * @throws RandomException
     */
    public static function ws_images_uploadCompleted(
        array $params,
        PwgServer $service
    ): PwgError|array {
        if (functions::get_pwg_token() != $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        if (! is_array($params['image_id'])) {
            $params['image_id'] = preg_split(
                '/[\s,;\|]/',
                $params['image_id'],
                -1,
                PREG_SPLIT_NO_EMPTY
            );
        }

        $params['image_id'] = array_map(intval(...), $params['image_id']);

        $image_ids = [];

        foreach ($params['image_id'] as $image_id) {
            if ($image_id > 0) {
                $image_ids[] = $image_id;
            }
        }

        // the list of images moved from the lounge might not be the same than
        // $image_ids (can be a subset or more image_ids from another upload too)
        $moved_from_lounge = functions_admin::empty_lounge();

        $query = <<<SQL
            SELECT COUNT(*) AS nb_photos
            FROM image_category
            WHERE category_id = {$params['category_id']};
            SQL;
        $category_infos = functions_mysqli::pwg_db_fetch_assoc(functions_mysqli::pwg_query($query));
        $category_name = functions_html::get_cat_display_name_from_id($params['category_id'], null);

        functions_plugins::trigger_notify(
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
     * @param array{
     *     block_size: int,
     *     pwg_token: string,
     * } $params
     */
    public static function ws_images_setMd5sum(
        array $params,
        PwgServer $service
    ): PwgError|array {
        if (functions::get_pwg_token() != $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        $nb_no_md5sum = count(functions_admin::get_photos_no_md5sum());
        $added_count = 0;

        if ($nb_no_md5sum > 0) {
            $md5sum_ids_to_add = array_slice(functions_admin::get_photos_no_md5sum(), 0, $params['block_size']);
            $added_count = functions_admin::add_md5sum($md5sum_ids_to_add);
        }

        return [
            'nb_added' => $added_count,
            'nb_no_md5sum' => count(functions_admin::get_photos_no_md5sum()),
        ];
    }

    /**
     * API method
     * Synchronize metadatas photos. Returns how many metadatas were sync.
     * @param array{
     *     image_id: array<int>,
     *     pwg_token: string,
     * } $params
     */
    public static function ws_images_syncMetadata(
        array $params,
        PwgServer $service
    ): PwgError|array {
        if (functions::get_pwg_token() != $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        $image_ids = implode(', ', $params['image_id']);
        $query = <<<SQL
            SELECT id
            FROM images
            WHERE id IN ({$image_ids});
            SQL;
        $params['image_id'] = functions_mysqli::query2array($query, null, 'id');

        if (empty($params['image_id'])) {
            return new PwgError(403, 'No image found');
        }

        functions_metadata_admin::sync_metadata($params['image_id']);

        return [
            'nb_synchronized' => count($params['image_id']),
        ];
    }

    /**
     * API method
     * Deletes orphan photos, by block. Returns how many orphans were deleted and how many are remaining.
     * @param array{
     *     block_size: int,
     *     pwg_token: string,
     * } $params
     */
    public static function ws_images_deleteOrphans(
        array $params,
        PwgServer $service
    ): PwgError|array {
        if (functions::get_pwg_token() != $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        $orphan_ids_to_delete = array_slice(functions_admin::get_orphans(), 0, $params['block_size']);
        $deleted_count = functions_admin::delete_elements($orphan_ids_to_delete, true);

        return [
            'nb_deleted' => $deleted_count,
            'nb_orphans' => count(functions_admin::get_orphans()),
        ];
    }

    /**
     * API method
     * Associate/Dissociate/Move photos with an album.
     *
     * @param array{
     *     image_id: array<int>,
     *     category_id: int,
     *     action: string,
     *     pwg_token: string,
     * } $params
     */
    public static function ws_images_setCategory(
        array $params,
        PwgServer $service
    ): ?PwgError {
        if (functions::get_pwg_token() != $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        // does the category really exist?
        $query = <<<SQL
            SELECT id
            FROM categories
            WHERE id = {$params['category_id']};
            SQL;
        $categories = functions_mysqli::query2array($query);

        if (count($categories) == 0) {
            return new PwgError(404, 'category_id not found');
        }

        if ($params['action'] == 'associate') {
            functions_admin::associate_images_to_categories($params['image_id'], [$params['category_id']]);
        } elseif ($params['action'] == 'dissociate') {
            functions_admin::dissociate_images_from_category($params['image_id'], $params['category_id']);
        } elseif ($params['action'] == 'move') {
            functions_admin::move_images_to_categories($params['image_id'], [$params['category_id']]);
        }

        functions_admin::invalidate_user_cache();
        return null;
    }
}
