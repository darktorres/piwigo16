<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * API method
 * Returns a list of tags
 *
 * @param array{sort_by_counter: bool, ...} $params non-null bool default,
 *   WS_TYPE_BOOL -- always present.
 * @return array{tags: PwgNamedArray}
 */
function ws_tags_getList(array $params, PwgServer &$service): array
{
    $tags = get_available_tags();
    if ($params['sort_by_counter']) {
        usort($tags, function (array $a, array $b): int {
            $a_counter = is_numeric($a['counter']) ? (float) $a['counter'] : 0.0;
            $b_counter = is_numeric($b['counter']) ? (float) $b['counter'] : 0.0;
            return $b_counter <=> $a_counter;
        });
    } else {
        usort($tags, tag_alpha_compare(...));
    }

    for ($i = 0; $i < count($tags); $i++) {
        $tags[$i]['id'] = is_numeric($tags[$i]['id']) ? (int) $tags[$i]['id'] : 0;
        $tags[$i]['counter'] = is_numeric($tags[$i]['counter']) ? (int) $tags[$i]['counter'] : 0;
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
 *
 * Only admin can run this method and permissions are not taken into
 * account.
 *
 * @param array<string, mixed> $params this method is registered with a
 *   null signature (zero registered params) -- $params is the raw,
 *   entirely unvalidated request array, but the body doesn't read it.
 * @return array{tags: PwgNamedArray}
 */
function ws_tags_getAdminList(array $params, PwgServer &$service): array
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
 *
 * @param array{tag_id: array<int, int>, tag_url_name: array<int, string>, tag_name: array<int, string>, tag_mode_and: bool, per_page: int, page: int, order: string|null, f_min_rate: float|null, f_max_rate: float|null, f_min_hit: int|null, f_max_hit: int|null, f_min_ratio: float|null, f_max_ratio: float|null, f_max_level: int|null, f_min_date_available: string|null, f_max_date_available: string|null, f_min_date_created: string|null, f_max_date_created: string|null, ...} $params
 *   tag_id/tag_url_name/tag_name: FORCE_ARRAY with a null default --
 *   makeArrayParam() converts the null default to [], always a list
 *   (tag_id: positive ints via WS_TYPE_ID; tag_url_name/tag_name:
 *   untyped, so strings). tag_mode_and/per_page/page: non-null default,
 *   always present. order: null default, no 'type' flag -- always
 *   present, string|null. f_* keys: the shared $f_params block merged
 *   into this registration, see
 *   ws_std_image_sql_filter()/ws_std_image_sql_order().
 * @return array{paging: PwgNamedStruct, images: PwgNamedArray}
 */
function ws_tags_getImages(array $params, PwgServer &$service): array
{
    // first build all the tag_ids we are interested in
    $tags = find_tags($params['tag_id'], $params['tag_url_name'], $params['tag_name']);
    $tags_by_id = [];
    foreach ($tags as $tag) {
        if (! is_array($tag)) {
            continue;
        }
        $tag_id = isset($tag['id']) && is_numeric($tag['id']) ? (int) $tag['id'] : 0;
        $tag['id'] = $tag_id;
        $tags_by_id[$tag_id] = $tag;
    }
    unset($tags);
    $tag_ids = array_keys($tags_by_id);

    $where_clauses = ws_std_image_sql_filter($params);
    $where_clauses = ! empty($where_clauses) ? implode(' AND ', $where_clauses) : '';

    $order_by = ws_std_image_sql_order($params, 'i.');
    if (! empty($order_by)) {
        $order_by = 'ORDER BY ' . $order_by;
    }
    $image_ids = get_image_ids_for_tags(
        $tag_ids,
        $params['tag_mode_and'] ? 'AND' : 'OR',
        $where_clauses,
        $order_by
    );
    // Cast to int at the source (not just at each read site) so
    // array_flip($image_ids) below produces int keys matching $row_id's
    // (int) cast, instead of leaving PHPStan-only string keys.
    $image_ids = array_values(array_map(intval(...), array_filter($image_ids, is_numeric(...))));

    $count_set = count($image_ids);
    $image_ids = array_slice($image_ids, $params['per_page'] * $params['page'], $params['per_page']);

    $image_tag_map = [];
    // build list of image ids with associated tags per image
    if (! empty($image_ids) and ! $params['tag_mode_and']) {
        $query = '
SELECT image_id, GROUP_CONCAT(tag_id) AS tag_ids
  FROM ' . IMAGE_TAG_TABLE . '
  WHERE tag_id IN (' . implode(',', $tag_ids) . ')
    AND image_id IN (' . implode(',', $image_ids) . ')
  GROUP BY image_id
;';
        $result = pwg_query($query);

        while ($row = pwg_db_fetch_assoc($result)) {
            $row['image_id'] = (int) $row['image_id'];
            $image_tag_map[$row['image_id']] = explode(',', (string) $row['tag_ids']);
        }
    }

    $images = [];
    if (! empty($image_ids)) {
        $rank_of = array_flip($image_ids);
        $favorite_ids = get_user_favorites();

        $query = '
SELECT *
  FROM ' . IMAGES_TABLE . '
  WHERE id IN (' . implode(',', $image_ids) . ')
;';
        $result = pwg_query($query);

        while ($row = pwg_db_fetch_assoc($result)) {
            if (! is_numeric($row['id'])) {
                continue;
            }
            $row_id = (int) $row['id'];

            $image = [];
            $image['rank'] = $rank_of[$row_id];
            $image['is_favorite'] = isset($favorite_ids[$row_id]);

            foreach (['id', 'width', 'height', 'hit'] as $k) {
                if (isset($row[$k])) {
                    $image[$k] = (int) $row[$k];
                }
            }
            foreach (['file', 'name', 'comment', 'date_creation', 'date_available'] as $k) {
                $image[$k] = $row[$k];
            }

            $rendered_name = trigger_change('render_element_name', $image['name'], __FUNCTION__);
            $image['name'] = strip_tags(is_string($rendered_name) ? $rendered_name : '');
            $image['comment'] = trigger_change('render_element_description', $image['comment'], __FUNCTION__);

            $image = array_merge($image, ws_std_get_urls($row));

            $image_tag_ids = ($params['tag_mode_and']) ? $tag_ids : $image_tag_map[$row_id];
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
                    'id' => (int) $tag_id,
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
 *
 * @param array{name: string, ...} $params no 'default' key -- mandatory,
 *   always present.
 * @return \PwgError|array{info: string, id: int|string, name: string, url_name: string}
 */
function ws_tags_add(array $params, PwgServer &$service): \PwgError|array
{
    include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

    $creation_output = create_tag($params['name']);

    if (isset($creation_output['error'])) {
        return new PwgError(WS_ERR_INVALID_PARAM, $creation_output['error']);
    }

    pwg_activity('tag', $creation_output['id'], 'add');

    $query = '
SELECT name, url_name
FROM `' . TAGS_TABLE . '`
WHERE id = ' . $creation_output['id'] . ';';

    $new_tag = query2array($query);
    $new_tag_name = $new_tag[0]['name'] ?? null;
    $new_tag_url_name = $new_tag[0]['url_name'] ?? null;

    return [
        'info' => $creation_output['info'],
        'id' => $creation_output['id'],
        'name' => is_string($new_tag_name) ? $new_tag_name : '',
        'url_name' => is_string($new_tag_url_name) ? $new_tag_url_name : '',
    ];
}

/**
 * API method
 * Delete tag(s) by ID
 *
 * @param array{tag_id: array<int, int>, pwg_token: string, ...} $params
 *   neither has a 'default' key -- both mandatory, always present;
 *   FORCE_ARRAY always coerces tag_id to a list of positive ints.
 * @return \PwgError|array{id: array<int, int>}
 */
function ws_tags_delete(array $params, PwgServer &$service): \PwgError|array
{
    include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

    if (get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    $query = '
SELECT COUNT(*)
  FROM `' . TAGS_TABLE . '`
  WHERE id in (' . implode(',', $params['tag_id']) . ')
;';
    $row = pwg_db_fetch_row(pwg_query($query));
    assert($row !== null);
    [$count] = $row;
    if ($count != count($params['tag_id'])) {
        return new PwgError(WS_ERR_INVALID_PARAM, 'All tags does not exist.');
    }

    $tag_ids = $params['tag_id'];

    if (count($tag_ids) > 0) {
        delete_tags($params['tag_id']);
        return [
            'id' => $tag_ids,
        ];
    } else {
        return [
            'id' => [],
        ];
    }
}

/**
 * API method
 * Rename tag
 *
 * @param array{tag_id: int, new_name: string, pwg_token: string, ...} $params
 *   none has a 'default' key -- all mandatory, always present, WS_TYPE_ID
 *   guarantees a plain int for tag_id.
 * @return \PwgError|array<string, mixed>
 */
function ws_tags_rename(array $params, PwgServer &$service): \PwgError|array
{
    include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

    if (get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    $tag_id = $params['tag_id'];
    $tag_name = strip_tags(stripslashes((string) $params['new_name']));

    // does the tag exist ?
    $query = '
SELECT COUNT(*)
  FROM `' . TAGS_TABLE . '`
  WHERE id = ' . $tag_id . '
;';
    $row = pwg_db_fetch_row(pwg_query($query));
    assert($row !== null);
    [$count] = $row;
    if ($count == 0) {
        return new PwgError(WS_ERR_INVALID_PARAM, 'This tag does not exist.');
    }

    $query = '
SELECT name
  FROM ' . TAGS_TABLE . '
  WHERE id != ' . $tag_id . '
;';
    $existing_names = array_from_query($query, 'name');

    $update = [];

    if (in_array($tag_name, $existing_names)) {
        return new PwgError(WS_ERR_INVALID_PARAM, 'This name is already token');
    }
    if (! empty($tag_name)) {
        $update = [
            'name' => pwg_db_real_escape_string($tag_name),
            'url_name' => trigger_change('render_tag_url', $tag_name),
        ];

    }

    pwg_activity('tag', $tag_id, 'edit');

    single_update(
        TAGS_TABLE,
        $update,
        [
            'id' => $tag_id,
        ]
    );

    $query = '
SELECT
    id,
    name,
    url_name
  FROM ' . TAGS_TABLE . '
  WHERE id = ' . $tag_id . '
;';

    $tag = query2array($query)[0];
    $tag['raw_name'] = $tag['name'];
    $tag['name'] = trigger_change('render_tag_name', $tag['raw_name'], $tag);
    $tag['alt_names'] = trigger_change('get_tag_alt_names', [], $tag['raw_name']);
    return $tag;
}

/**
 * API method
 * Create a copy of a tag
 *
 * @param array{tag_id: int, copy_name: string, pwg_token: string, ...} $params
 *   none has a 'default' key -- all mandatory, always present, WS_TYPE_ID
 *   guarantees a plain int for tag_id.
 * @return \PwgError|array{id: int|string, name: string, url_name: mixed, count: int}
 */
function ws_tags_duplicate(array $params, PwgServer &$service): \PwgError|array
{

    include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

    if (get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    $tag_id = $params['tag_id'];
    $copy_name = $params['copy_name'];

    // does the tag exist ?
    $query = '
SELECT COUNT(*)
  FROM `' . TAGS_TABLE . '`
  WHERE id = ' . $tag_id . '
;';
    $row = pwg_db_fetch_row(pwg_query($query));
    assert($row !== null);
    [$count] = $row;
    if ($count == 0) {
        return new PwgError(WS_ERR_INVALID_PARAM, 'This tag does not exist.');
    }

    $query = '
SELECT COUNT(*)
  FROM `' . TAGS_TABLE . '`
  WHERE name = "' . $copy_name . '"
;';
    $row = pwg_db_fetch_row(pwg_query($query));
    assert($row !== null);
    [$count] = $row;
    if ($count != 0) {
        return new PwgError(WS_ERR_INVALID_PARAM, 'This name is already taken.');
    }

    single_insert(
        TAGS_TABLE,
        [
            'name' => $copy_name,
            'url_name' => trigger_change('render_tag_url', $copy_name),
        ]
    );
    $destination_tag_id = pwg_db_insert_id();

    pwg_activity('tag', $destination_tag_id, 'add', [
        'action' => 'duplicate',
        'source_tag' => $tag_id,
    ]);

    $query = '
SELECT image_id
  FROM ' . IMAGE_TAG_TABLE . '
  WHERE tag_id = ' . $tag_id . '
;';
    $destination_tag_image_ids = array_from_query($query, 'image_id');
    $destination_tag_image_ids = array_values(array_filter($destination_tag_image_ids, is_string(...)));

    $inserts = [];

    foreach ($destination_tag_image_ids as $image_id) {
        $inserts[] = [
            'tag_id' => $destination_tag_id,
            'image_id' => $image_id,
        ];
        pwg_activity('photo', $image_id, 'edit', [
            'add-tag' => $destination_tag_id,
        ]);
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
        'name' => $copy_name,
        'url_name' => trigger_change('render_tag_url', $copy_name),
        'count' => count($inserts),
    ];
}

/**
 * API method
 * Merge tags in one other group
 *
 * @param array{destination_tag_id: int, merge_tag_id: array<int, int>, pwg_token: string, ...} $params
 *   none has a 'default' key -- all mandatory, always present;
 *   destination_tag_id: WS_TYPE_ID guarantees a plain int; merge_tag_id:
 *   FORCE_ARRAY always coerces to a list of positive ints.
 * @return \PwgError|array{destination_tag: int, deleted_tag: array<int, int>, images_in_merged_tag: array<int, mixed>}
 */
function ws_tags_merge(array $params, PwgServer &$service): \PwgError|array
{

    if (get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    $all_tags = $params['merge_tag_id'];
    array_push($all_tags, $params['destination_tag_id']);

    $all_tags = array_unique($all_tags);
    $merge_tag = array_diff($params['merge_tag_id'], [$params['destination_tag_id']]);

    $query = '
SELECT COUNT(*)
  FROM `' . TAGS_TABLE . '`
  WHERE id in (' . implode(',', $all_tags) . ')
;';
    $row = pwg_db_fetch_row(pwg_query($query));
    assert($row !== null);
    [$count] = $row;
    if ($count != count($all_tags)) {
        return new PwgError(WS_ERR_INVALID_PARAM, 'All tags does not exist.');
    }

    $image_in_merge_tags = [];
    $image_in_dest = [];
    $image_to_add = [];

    $query = '
SELECT DISTINCT(image_id)
  FROM `' . IMAGE_TAG_TABLE . '`
  WHERE
    tag_id IN (' . implode(',', $merge_tag) . ')
;';
    $image_in_merge_tags = query2array($query, null, 'image_id');

    $query = '
SELECT image_id
  FROM `' . IMAGE_TAG_TABLE . '`
  WHERE tag_id = ' . $params['destination_tag_id'] . '
;';

    $image_in_dest = query2array($query, null, 'image_id');

    $image_to_add = array_diff($image_in_merge_tags, $image_in_dest);
    $image_to_add = array_values(array_filter($image_to_add, is_string(...)));

    $inserts = [];
    foreach ($image_to_add as $image) {
        $inserts[] = [
            'tag_id' => $params['destination_tag_id'],
            'image_id' => $image,
        ];
    }

    mass_inserts(
        IMAGE_TAG_TABLE,
        ['tag_id', 'image_id'],
        $inserts,
        [
            'ignore' => true,
        ]
    );

    pwg_activity('tag', $params['destination_tag_id'], 'edit');
    foreach ($image_to_add as $image_id) {
        pwg_activity('photo', $image_id, 'edit', [
            'tag-add' => $params['destination_tag_id'],
        ]);
    }

    include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

    trigger_notify('merge_tags', $params['destination_tag_id'], $merge_tag);
    delete_tags($merge_tag);

    $image_in_merged = array_merge($image_in_dest, $image_to_add);

    return [
        'destination_tag' => $params['destination_tag_id'],
        'deleted_tag' => $params['merge_tag_id'],
        'images_in_merged_tag' => $image_in_merged,
    ];
}
