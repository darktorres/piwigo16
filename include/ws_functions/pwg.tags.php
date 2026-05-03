<?php

declare(strict_types=1);

global $persistent_cache;

use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgNamedArray;
use Piwigo\Ws\PwgNamedStruct;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * API method
 * Returns a list of tags
 * @param mixed[] $params
 *    @option bool sort_by_counter
 */
/**
 * @return array<mixed>
 * @param array<mixed> $params
 * @param array<mixed> $params
 */function ws_tags_getList(array $params, \Piwigo\Ws\PwgServer &$service): array
{
    /** @var array<int, array<string, mixed>> $tags */
    $tags = get_available_tags();
    if ($params['sort_by_counter']) {
        usort($tags, fn (array $a, array $b): int => (is_numeric($b['counter'] ?? null) ? (int) $b['counter'] : 0) - (is_numeric($a['counter'] ?? null) ? (int) $a['counter'] : 0));
    } else {
        usort($tags, tag_alpha_compare(...));
    }

    for ($i = 0; $i < count($tags); $i++) {
        $tags[$i]['id'] = is_numeric($tags[$i]['id'] ?? null) ? (int) $tags[$i]['id'] : 0;
        $tags[$i]['counter'] = is_numeric($tags[$i]['counter'] ?? null) ? (int) $tags[$i]['counter'] : 0;
        $tags[$i]['url'] = make_index_url(
            [
            'section' => 'tags',
            'tags' => [$tags[$i]],
            ]
        );
    }

    return [
      'tags' => new PwgNamedArray(
          $tags,
          'tag',
          ws_std_get_tag_xml_attributes()
      ),
      ];
}

/**
 * API method
 * Returns the list of tags as you can see them in administration
 * @param mixed[] $params
 *
 * Only admin can run this method and permissions are not taken into
 * account.
 */
/**
 * @param array<mixed> $params
 * @return array<mixed>
 */
function ws_tags_getAdminList(array $params, \Piwigo\Ws\PwgServer &$service): array
{
    return [
      'tags' => new PwgNamedArray(
          get_all_tags(),
          'tag',
          ws_std_get_tag_xml_attributes()
      ),
      ];
}

/**
 * API method
 * Returns a list of images for tags
 * @param mixed[] $params
 *    @option int[] tag_id (optional)
 *    @option string[] tag_url_name (optional)
 *    @option string[] tag_name (optional)
 *    @option bool tag_mode_and
 *    @option int per_page
 *    @option int page
 *    @option string order
 */
/**
 * @return array<mixed>
 * @param array<mixed> $params
 * @param array<mixed> $params
 */function ws_tags_getImages(array $params, \Piwigo\Ws\PwgServer &$service): array
{
    // first build all the tag_ids we are interested in
    $tag_id_arr = is_array($params['tag_id']) ? array_map(fn ($v): string => is_scalar($v) ? (string) $v : '', $params['tag_id']) : [];
    $tag_url_name_arr = is_array($params['tag_url_name']) ? array_map(fn ($v): string => is_scalar($v) ? (string) $v : '', $params['tag_url_name']) : [];
    $tag_name_arr = is_array($params['tag_name']) ? array_map(fn ($v): string => is_scalar($v) ? (string) $v : '', $params['tag_name']) : [];
    /** @var array<int, array<string, mixed>> $tags_result */
    $tags_result = find_tags($tag_id_arr, $tag_url_name_arr, $tag_name_arr);
    $tags_by_id = [];
    foreach ($tags_result as $tag) {
        $tag_id_val = is_numeric($tag['id'] ?? null) ? (int) $tag['id'] : 0;
        $tags_by_id[$tag_id_val] = $tag;
    }
    $tag_ids = array_keys($tags_by_id);

    /** @var string[] $where_clauses_arr */
    $where_clauses_arr = ws_std_image_sql_filter($params);
    $where_clauses = !empty($where_clauses_arr) ? implode(' AND ', $where_clauses_arr) : null;

    $order_by = ws_std_image_sql_order($params, 'i.');
    if (!empty($order_by)) {
        $order_by = 'ORDER BY '.$order_by;
    }
    $image_ids = get_image_ids_for_tags(
        $tag_ids,
        $params['tag_mode_and'] ? 'AND' : 'OR',
        $where_clauses,
        $order_by
    );

    $count_set = count($image_ids);
    $per_page = is_numeric($params['per_page']) ? (int) $params['per_page'] : 0;
    $page = is_numeric($params['page']) ? (int) $params['page'] : 0;
    $image_ids = array_slice($image_ids, $per_page * $page, $per_page);

    $image_tag_map = [];
    // build list of image ids with associated tags per image
    if (!empty($image_ids) and !$params['tag_mode_and']) {
        $query = '
SELECT image_id, GROUP_CONCAT(tag_id) AS tag_ids
  FROM '. IMAGE_TAG_TABLE .'
  WHERE tag_id IN ('. implode(',', $tag_ids) .')
    AND image_id IN ('. implode(',', $image_ids) .')
  GROUP BY image_id
;';
        $result = pwg_query($query);

        while ($row = pwg_db_fetch_assoc($result)) {
            $row['image_id'] = (int)$row['image_id'];
            $image_tag_map[ $row['image_id'] ] = explode(',', (string) $row['tag_ids']);
        }
    }

    $images = [];
    if (!empty($image_ids)) {
        $rank_of = array_flip($image_ids);
        $favorite_ids = get_user_favorites();

        $query = '
SELECT *
  FROM '. IMAGES_TABLE .'
  WHERE id IN ('. implode(',', $image_ids) .')
;';
        $result = pwg_query($query);

        while ($row = pwg_db_fetch_assoc($result)) {
            $image = [];
            $row_id_key = is_scalar($row['id']) ? (string) $row['id'] : '';
            $image['rank'] = $rank_of[$row_id_key] ?? 0;
            $image['is_favorite'] = isset($favorite_ids[$row_id_key]);

            foreach (['id', 'width', 'height', 'hit'] as $k) {
                if (isset($row[$k])) {
                    $image[$k] = (int)$row[$k];
                }
            }
            foreach (['file', 'name', 'comment', 'date_creation', 'date_available'] as $k) {
                $image[$k] = $row[$k];
            }

            $img_name_str = (string)($image['name'] ?? '');
            $rendered_tag_name = trigger_change('render_element_name', $img_name_str, __FUNCTION__);
            $image['name'] = strip_tags($rendered_tag_name);
            $image['comment'] = trigger_change('render_element_description', $image['comment'] ?? null, __FUNCTION__);

            $image = array_merge($image, ws_std_get_urls($row));

            $img_id_key = is_numeric($image['id']) ? (int) $image['id'] : 0;
            $image_tag_ids = ($params['tag_mode_and']) ? $tag_ids : ($image_tag_map[$img_id_key] ?? []);
            $image_tags = [];
            foreach ($image_tag_ids as $tag_id) {
                $url = make_index_url(
                    [
                    'section' => 'tags',
                    'tags' => [$tags_by_id[$tag_id]],
                    ]
                );
                $page_url = make_picture_url(
                    [
                    'section' => 'tags',
                    'tags' => [$tags_by_id[$tag_id]],
                    'image_id' => $row['id'],
                    'image_file' => $row['file'],
                    ]
                );
                $image_tags[] = [
                  'id' => (int)$tag_id,
                  'url' => $url,
                  'page_url' => $page_url,
                  ];
            }

            $image['tags'] = new PwgNamedArray($image_tags, 'tag', ws_std_get_tag_xml_attributes());
            $images[] = $image;
        }

        usort($images, rank_compare(...));
        unset($rank_of);
    }

    return [
      'paging' => new PwgNamedStruct(
          [
          'page' => $params['page'],
          'per_page' => $params['per_page'],
          'count' => count($images),
          'total_count' => $count_set,
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
 * Adds a tag
 * @param mixed[] $params
 *    @option string name
 */
/**
 * @return array<mixed>|\Piwigo\Ws\PwgError
 * @param array<mixed> $params
 * @param array<mixed> $params
 */function ws_tags_add(array $params, \Piwigo\Ws\PwgServer &$service): PwgError|array
{
    include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

    $creation_output = create_tag(is_scalar($params['name']) ? (string) $params['name'] : '');

    if (isset($creation_output['error'])) {
        return new PwgError(WS_ERR_INVALID_PARAM, is_scalar($creation_output['error']) ? (string) $creation_output['error'] : '');
    }

    $tag_add_id = is_numeric($creation_output['id'] ?? null) ? (int) $creation_output['id'] : (is_scalar($creation_output['id'] ?? null) ? (string) $creation_output['id'] : 0);
    pwg_activity('tag', $tag_add_id, 'add');

    $query = '
SELECT name, url_name
FROM `'.TAGS_TABLE.'`
WHERE id = '.$tag_add_id.';';

    $new_tag = query2array($query);

    return [
      'info' => $creation_output['info'],
      'id' => $creation_output['id'],
      'name' => $new_tag[0]['name'],
      'url_name' => $new_tag[0]['url_name'],
    ];
}

/**
 * @return array<mixed>|\Piwigo\Ws\PwgError
 * @param array<mixed> $params
 * @param array<mixed> $params
 */function ws_tags_delete(array $params, \Piwigo\Ws\PwgServer &$service): PwgError|array
{
    include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

    if (get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    $tag_ids_raw = is_array($params['tag_id']) ? $params['tag_id'] : [];
    /** @var int[] $tag_ids_del */
    $tag_ids_del = array_map(fn ($v): int => is_numeric($v) ? (int) $v : 0, $tag_ids_raw);

    $query = '
SELECT COUNT(*)
  FROM `'. TAGS_TABLE .'`
  WHERE id in ('.implode(',', $tag_ids_del) .')
;';
    [$count] = pwg_db_fetch_row(pwg_query($query)) ?? [null];
    if ($count != count($tag_ids_del)) {
        return new PwgError(WS_ERR_INVALID_PARAM, 'All tags does not exist.');
    }

    if (count($tag_ids_del) > 0) {
        delete_tags($tag_ids_del);
        return ['id' => $tag_ids_del];
    } else {
        return ['id' => []];
    }
}

/**
 * @param array<mixed> $params
 * @param array<mixed> $params
 */function ws_tags_rename(array $params, \Piwigo\Ws\PwgServer &$service): mixed
{
    include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

    if (get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    $tag_id = is_numeric($params['tag_id']) ? (int) $params['tag_id'] : (is_scalar($params['tag_id']) ? (string) $params['tag_id'] : 0);
    $tag_name = strip_tags(stripslashes(is_scalar($params['new_name']) ? (string) $params['new_name'] : ''));

    // does the tag exist ?
    $query = '
SELECT COUNT(*)
  FROM `'. TAGS_TABLE .'`
  WHERE id = '. $tag_id .'
;';
    [$count] = pwg_db_fetch_row(pwg_query($query)) ?? [null];
    if ($count == 0) {
        return new PwgError(WS_ERR_INVALID_PARAM, 'This tag does not exist.');
    }

    $query = '
SELECT name
  FROM '.TAGS_TABLE.'
  WHERE id != '.$tag_id.'
;';
    $existing_names = query2array($query, null, 'name');

    $update = [];

    if (in_array($tag_name, $existing_names)) {
        return new PwgError(WS_ERR_INVALID_PARAM, 'This name is already token');
    } elseif (!empty($tag_name)) {
        $update = [
          'name' => pwg_db_real_escape_string($tag_name),
          'url_name' => trigger_change('render_tag_url', $tag_name),
        ];

    }

    pwg_activity('tag', $tag_id, 'edit');

    single_update(
        TAGS_TABLE,
        $update,
        ['id' => $tag_id]
    );

    $query = '
SELECT
    id,
    name,
    url_name
  FROM '.TAGS_TABLE.'
  WHERE id = '.$tag_id.'
;';

    $tag = query2array($query)[0];
    $tag['raw_name'] = $tag['name'];
    $tag['name'] = trigger_change('render_tag_name', $tag['raw_name'], $tag);
    $tag['alt_names'] = trigger_change('get_tag_alt_names', [], $tag['raw_name']);
    return $tag;
}


/**
 * @return array<mixed>|\Piwigo\Ws\PwgError
 * @param array<mixed> $params
 * @param array<mixed> $params
 */function ws_tags_duplicate(array $params, \Piwigo\Ws\PwgServer &$service): PwgError|array
{

    include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

    if (get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    $dup_tag_id = is_numeric($params['tag_id']) ? (int) $params['tag_id'] : (is_scalar($params['tag_id']) ? (string) $params['tag_id'] : 0);
    $dup_copy_name = is_scalar($params['copy_name']) ? (string) $params['copy_name'] : '';

    // does the tag exist ?
    $query = '
SELECT COUNT(*)
  FROM `'. TAGS_TABLE .'`
  WHERE id = '. $dup_tag_id .'
;';
    [$count] = pwg_db_fetch_row(pwg_query($query)) ?? [null];
    if ($count == 0) {
        return new PwgError(WS_ERR_INVALID_PARAM, 'This tag does not exist.');
    }

    $query = '
SELECT COUNT(*)
  FROM `'. TAGS_TABLE .'`
  WHERE name = "'. $dup_copy_name .'"
;';
    [$count] = pwg_db_fetch_row(pwg_query($query)) ?? [null];
    if ($count != 0) {
        return new PwgError(WS_ERR_INVALID_PARAM, 'This name is already taken.');
    }

    single_insert(
        TAGS_TABLE,
        [
        'name' => $dup_copy_name,
        'url_name' => trigger_change('render_tag_url', $dup_copy_name),
    ]
    );
    $destination_tag_id = pwg_db_insert_id();

    pwg_activity('tag', $destination_tag_id, 'add', ['action' => 'duplicate', 'source_tag' => $dup_tag_id]);

    $query = '
SELECT image_id
  FROM '.IMAGE_TAG_TABLE.'
  WHERE tag_id = '.$dup_tag_id.'
;';
    $destination_tag_image_ids = query2array($query, null, 'image_id');

    $inserts = [];

    foreach ($destination_tag_image_ids as $image_id) {
        $img_id_val = is_numeric($image_id) ? (int) $image_id : (is_scalar($image_id) ? (string) $image_id : 0);
        $inserts[] = [
          'tag_id' => $destination_tag_id,
          'image_id' => $img_id_val,
        ];
        pwg_activity('photo', $img_id_val, 'edit', ['add-tag' => $destination_tag_id]);
    }

    if (count($inserts) > 0) {
        mass_inserts(
            IMAGE_TAG_TABLE,
            array_keys($inserts[0]),
            $inserts
        );
    }

    return [
      'id' => $destination_tag_id,
      'name' => $dup_copy_name,
      'url_name' => trigger_change('render_tag_url', $dup_copy_name),
      'count' => count($inserts),
    ];
}

/**
 * @return array<mixed>|\Piwigo\Ws\PwgError
 * @param array<mixed> $params
 * @param array<mixed> $params
 */function ws_tags_merge(array $params, \Piwigo\Ws\PwgServer &$service): PwgError|array
{

    if (get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    $merge_dest_id = is_numeric($params['destination_tag_id']) ? (int) $params['destination_tag_id'] : (is_scalar($params['destination_tag_id']) ? (string) $params['destination_tag_id'] : 0);
    $merge_tag_ids_raw = is_array($params['merge_tag_id']) ? $params['merge_tag_id'] : [];
    $merge_tag_ids = array_map(fn ($v): int => is_numeric($v) ? (int) $v : 0, $merge_tag_ids_raw);

    $all_tags = $merge_tag_ids;
    $all_tags[] = $merge_dest_id;
    $all_tags = array_unique($all_tags);
    $merge_tag = array_diff($merge_tag_ids, [$merge_dest_id]);

    $query = '
SELECT COUNT(*)
  FROM `'. TAGS_TABLE .'`
  WHERE id in ('.implode(',', $all_tags) .')
;';
    [$count] = pwg_db_fetch_row(pwg_query($query)) ?? [null];
    if ($count != count($all_tags)) {
        return new PwgError(WS_ERR_INVALID_PARAM, 'All tags does not exist.');
    }

    $query = '
SELECT DISTINCT(image_id)
  FROM `'. IMAGE_TAG_TABLE .'`
  WHERE
    tag_id IN ('.implode(',', $merge_tag) .')
;';
    $image_in_merge_tags = query2array($query, null, 'image_id');

    $query = '
SELECT image_id
  FROM `'. IMAGE_TAG_TABLE .'`
  WHERE tag_id = '.$merge_dest_id.'
;';

    $image_in_dest = query2array($query, null, 'image_id');

    $image_to_add = array_diff($image_in_merge_tags, $image_in_dest);

    $inserts = [];
    foreach ($image_to_add as $image) {
        $inserts[] = [
          'tag_id' => $merge_dest_id,
          'image_id' => $image,
          ];
    }

    mass_inserts(
        IMAGE_TAG_TABLE,
        ['tag_id', 'image_id'],
        $inserts,
        ['ignore' => true]
    );

    pwg_activity('tag', $merge_dest_id, 'edit');
    foreach ($image_to_add as $image_id) {
        $img_id_val = is_numeric($image_id) ? (int) $image_id : (is_scalar($image_id) ? (string) $image_id : 0);
        pwg_activity('photo', $img_id_val, 'edit', ['tag-add' => $merge_dest_id]);
    }

    include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

    trigger_notify('merge_tags', $merge_dest_id, $merge_tag);
    delete_tags($merge_tag);

    $image_in_merged = array_merge($image_in_dest, $image_to_add);

    return [
      'destination_tag' => $merge_dest_id,
      'deleted_tag' => $merge_tag_ids,
      'images_in_merged_tag' => $image_in_merged,
    ];
}
