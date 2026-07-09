<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

if (! defined('PHPWG_ROOT_PATH')) {
    die('Hacking attempt!');
}

// Bootstrap globals. $admin_photo_base_url is set by admin/photo.php before
// including this panel; the rest by include/common.inc.php.
global $admin_photo_base_url, $cache, $conf, $template, $user;

include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
check_status(ACCESS_ADMINISTRATOR);

check_input_parameter('image_id', $_GET, false, PATTERN_ID);
check_input_parameter('level', $_POST, false, '/^\d+$/');
check_input_parameter('date_creation', $_POST, false, '/^\d\d\d\d-\d\d-\d\d( \d\d:\d\d:\d\d)?$/');

// check_input_parameter() only validates the raw $_GET value against
// PATTERN_ID (or dies); it does not narrow $_GET's type for PHPStan, so
// re-derive a real int here for every later use.
$image_id = 0;
if (isset($_GET['image_id']) && is_numeric($_GET['image_id'])) {
    $image_id = (int) $_GET['image_id'];
}

// retrieving direct information about picture. This may have been already
// done on admin/photo.php but this page can also be accessed without
// photo.php as proxy.
if (! isset($page['image'])) {
    $page['image'] = get_image_infos($image_id, true);
}

// represent
$query = '
SELECT id
  FROM ' . CATEGORIES_TABLE . '
  WHERE representative_picture_id = ' . $image_id . '
;';
$represented_albums_raw = query2array($query, null, 'id');
$represented_albums = [];
foreach ($represented_albums_raw as $represented_album_value) {
    if (is_numeric($represented_album_value)) {
        $represented_albums[] = (int) $represented_album_value;
    }
}

// +-----------------------------------------------------------------------+
// |                             delete photo                              |
// +-----------------------------------------------------------------------+

if (isset($_GET['delete'])) {
    check_pwg_token();

    delete_elements([$image_id], true);
    invalidate_user_cache();

    // where to redirect the user now?
    //
    // 1. if a category is available in the URL, use it
    // 2. else use the first reachable linked category
    // 3. redirect to gallery root

    if ($custom_context = get_edit_context($image_id)) {
        // considering we have a context available, we fake one to build the url
        // and we replace it with the context found in the session for this image_id
        redirect(str_replace('list/1,2', $custom_context, make_index_url([
            'list' => [1, 2],
        ])));
    }

    redirect(make_index_url());
}

// +-----------------------------------------------------------------------+
// |                          synchronize metadata                         |
// +-----------------------------------------------------------------------+

if (isset($_GET['sync_metadata'])) {
    sync_metadata([$image_id]);
    $page['infos'][] = l10n('Metadata synchronized from file');
}

// --------------------------------------------------------- update informations
if (isset($_POST['submit'])) {
    check_pwg_token();

    $data = [];
    $data['id'] = $image_id;
    $data['level'] = $_POST['level'];

    $to_sanitize_fields = ['name', 'author', 'comment'];
    foreach ($to_sanitize_fields as $field) {
        $raw_field_value = $_POST[$field] ?? null;
        $field_value = is_scalar($raw_field_value) ? (string) $raw_field_value : '';
        $data[$field] = $conf['allow_html_descriptions'] ? $field_value : strip_tags($field_value);
    }

    if (! empty($_POST['date_creation'])) {
        $data['date_creation'] = $_POST['date_creation'];
    } else {
        $data['date_creation'] = null;
    }

    $pre_hook_data = $data;
    $data = trigger_change('picture_modify_before_update', $data);
    if (! is_array($data)) {
        // 'picture_modify_before_update' handlers are documented to filter
        // the array<string, mixed> $data they receive and return the same
        // shape, but trigger_change()'s own return type is mixed (a handler
        // could misbehave) -- fall back to the pre-hook data rather than
        // silently dropping the admin's edit.
        $data = $pre_hook_data;
    }

    single_update(
        IMAGES_TABLE,
        $data,
        [
            'id' => $image_id,
        ]
    );

    // time to deal with tags
    $tag_ids = [];
    $raw_tags_post = $_POST['tags'] ?? null;
    if (! empty($raw_tags_post)) {
        if (is_array($raw_tags_post)) {
            $tag_ids = get_tag_ids(array_filter($raw_tags_post, 'is_string'));
        } elseif (is_string($raw_tags_post)) {
            $tag_ids = get_tag_ids($raw_tags_post);
        }
    }
    set_tags($tag_ids, $image_id);

    // association to albums
    if (! isset($_POST['associate'])) {
        $_POST['associate'] = [];
    }
    check_input_parameter('associate', $_POST, true, PATTERN_ID);

    $associate_categories = [];
    if (is_array($_POST['associate'])) {
        foreach ($_POST['associate'] as $associate_value) {
            if (is_numeric($associate_value)) {
                $associate_categories[] = (int) $associate_value;
            }
        }
    }
    move_images_to_categories([$image_id], $associate_categories);

    invalidate_user_cache();

    // thumbnail for albums
    if (! isset($_POST['represent'])) {
        $_POST['represent'] = [];
    }
    check_input_parameter('represent', $_POST, true, PATTERN_ID);

    $represent_categories = [];
    if (is_array($_POST['represent'])) {
        foreach ($_POST['represent'] as $represent_value) {
            if (is_numeric($represent_value)) {
                $represent_categories[] = (int) $represent_value;
            }
        }
    }

    $no_longer_thumbnail_for = array_diff($represented_albums, $represent_categories);
    if (count($no_longer_thumbnail_for) > 0) {
        set_random_representant($no_longer_thumbnail_for);
    }

    $new_thumbnail_for = array_diff($represent_categories, $represented_albums);
    if (count($new_thumbnail_for) > 0) {
        $query = '
UPDATE ' . CATEGORIES_TABLE . '
  SET representative_picture_id = ' . $image_id . '
  WHERE id IN (' . implode(',', $new_thumbnail_for) . ')
;';
        pwg_query($query);
    }

    $represented_albums = $represent_categories;

    $template->assign(
        [
            'save_success' => l10n('Photo informations updated'),
        ]
    );

    pwg_activity('photo', $image_id, 'edit');

    // refresh page cache
    $page['image'] = get_image_infos($image_id, true);
}

// tags
$query = '
SELECT
    id,
    name
  FROM ' . IMAGE_TAG_TABLE . ' AS it
    JOIN ' . TAGS_TABLE . ' AS t ON t.id = it.tag_id
  WHERE image_id = ' . $image_id . '
;';
$tag_selection = get_taglist($query);

$row = $page['image'];

if (isset($data['date_creation'])) {
    $row['date_creation'] = $data['date_creation'];
}

$storage_category_id = null;
if (! empty($row['storage_category_id'])) {
    $storage_category_id = $row['storage_category_id'];
}

$image_file = $row['file'];

// +-----------------------------------------------------------------------+
// |                             template init                             |
// +-----------------------------------------------------------------------+

$template->set_filenames(
    [
        'picture_modify' => 'picture_modify.tpl',
    ]
);

$admin_url_start = $admin_photo_base_url . '-properties';

$src_image = new SrcImage($row);

// in case the photo needs a rotation of 90 degrees (clockwise or counterclockwise), we switch width and height
if (in_array($row['rotation'], [1, 3])) {
    [$row['width'], $row['height']] = [$row['height'], $row['width']];
}

// $_POST['name']/['author']/['comment'] are mixed (raw superglobal reads);
// re-derive real strings here, falling back to the stored row value when the
// field wasn't (validly) resubmitted.
$post_name = $_POST['name'] ?? null;
$name_value = is_string($post_name) ? stripslashes($post_name) : (is_string($row['name'] ?? null) ? $row['name'] : '');

$post_author = $_POST['author'] ?? null;
$author_value = is_string($post_author) ? stripslashes($post_author) : (is_string($row['author'] ?? null) && $row['author'] !== '' ? $row['author'] : '');

$post_comment = $_POST['comment'] ?? null;
$comment_value = is_string($post_comment) ? stripslashes($post_comment) : (is_string($row['comment'] ?? null) && $row['comment'] !== '' ? $row['comment'] : '');

$template->assign(
    [
        'tag_selection' => $tag_selection,
        'U_DOWNLOAD' => 'action.php?id=' . $image_id . '&amp;part=e&amp;pwg_token=' . get_pwg_token() . '&amp;download',
        'U_SYNC' => $admin_url_start . '&amp;sync_metadata=1',
        'U_DELETE' => $admin_url_start . '&amp;delete=1&amp;pwg_token=' . get_pwg_token(),
        'U_HISTORY' => get_root_url() . 'admin.php?page=history&amp;filter_image_id=' . $image_id,
        'U_ACTIVITY' => get_root_url() . 'admin.php?page=user_activity&photo=' . $image_id,

        'PATH' => $row['path'],

        'TN_SRC' => DerivativeImage::url(IMG_MEDIUM, $src_image),
        'FILE_SRC' => DerivativeImage::url(IMG_LARGE, $src_image),

        'NAME' => $name_value,

        'TITLE' => render_element_name($row),

        'DIMENSIONS' => @$row['width'] . ' * ' . @$row['height'],

        'FORMAT' => ($row['width'] >= $row['height']) ? 1 : 0, // 0:horizontal, 1:vertical

        'FILESIZE' => @$row['filesize'] . ' KB',

        'REGISTRATION_DATE' => format_date($row['date_available']),

        'AUTHOR' => htmlspecialchars($author_value),

        'DATE_CREATION' => $row['date_creation'],

        'DESCRIPTION' => htmlspecialchars($comment_value),

        'F_ACTION' => get_root_url() . 'admin.php'
            . get_query_string_diff(['sync_metadata']),
    ]
);

$added_by = 'N/A';
$query = '
SELECT ' . $conf['user_fields']['username'] . ' AS username
  FROM ' . USERS_TABLE . '
  WHERE ' . $conf['user_fields']['id'] . ' = ' . $row['added_by'] . '
;';
$result = pwg_query($query);
while ($user_row = pwg_db_fetch_assoc($result)) {
    $row['added_by'] = $user_row['username'];
}

$extTab = explode('.', (string) $row['file']);

$intro_vars = [
    'file' => l10n('%s', $row['file']),
    'date' => l10n('Posted the %s', format_date($row['date_available'], ['day', 'month', 'year'])),
    'age' => l10n(ucfirst(time_since($row['date_available'], 'year'))),
    'added_by' => l10n('Added by %s', $row['added_by']),
    'size' => l10n('%s pixels, %.2f MB', $row['width'] . '&times;' . $row['height'], $row['filesize'] / 1024),
    'stats' => l10n('Visited %d times', $row['hit']),
    'id' => l10n($row['id']),
    'ext' => l10n('%s file type', strtoupper(end($extTab))),
    'is_svg' => (strtoupper(end($extTab)) == 'SVG'),
];

if ($conf['rate'] and ! empty($row['rating_score'])) {
    $query = '
SELECT
    COUNT(*)
  FROM ' . RATE_TABLE . '
  WHERE element_id = ' . $image_id . '
;';
    $rate_row = pwg_db_fetch_row(pwg_query($query));
    assert($rate_row !== null);
    [$row['nb_rates']] = $rate_row;

    $intro_vars['stats'] .= ', ' . sprintf(l10n('Rated %d times, score : %.2f'), $row['nb_rates'], $row['rating_score']);
}

$query = '
SELECT *
  FROM ' . IMAGE_FORMAT_TABLE . '
  WHERE image_id = ' . $row['id'] . '
;';
$formats = query2array($query);

if (! empty($formats)) {
    $format_strings = [];

    foreach ($formats as $format) {
        $format_filesize = is_numeric($format['filesize']) ? (float) $format['filesize'] : 0.0;
        $format_strings[] = sprintf('%s (%.2fMB)', $format['ext'], $format_filesize / 1024);
    }

    $intro_vars['formats'] = l10n('Formats: %s', implode(', ', $format_strings));
}

$template->assign('INTRO', $intro_vars);

if (in_array(get_extension($row['path']), $conf['picture_ext'])) {
    $template->assign('U_COI', get_root_url() . 'admin.php?page=picture_coi&amp;image_id=' . $image_id);
}

// image level options
$selected_level = $_POST['level'] ?? $row['level'];
$template->assign(
    [
        'level_options' => get_privacy_level_options(),
        'level_options_selected' => [$selected_level],
    ]
);

// categories
$query = '
SELECT category_id, uppercats, dir
  FROM ' . IMAGE_CATEGORY_TABLE . ' AS ic
    INNER JOIN ' . CATEGORIES_TABLE . ' AS c
      ON c.id = ic.category_id
  WHERE image_id = ' . $image_id . '
;';
$result = pwg_query($query);

$related_categories = [];
$related_categories_ids = [];

while ($row = pwg_db_fetch_assoc($result)) {
    $row_category_id = is_string($row['category_id']) ? $row['category_id'] : '';
    $row_uppercats = is_string($row['uppercats']) ? $row['uppercats'] : '';

    $name =
      get_cat_display_name_cache(
          $row_uppercats,
          get_root_url() . 'admin.php?page=album-'
      );

    if ($row_category_id == $storage_category_id) {
        $template->assign('STORAGE_CATEGORY', $name);
    }

    $related_categories[$row_category_id] = [
        'name' => $name,
        'unlinkable' => $row_category_id != $storage_category_id,
    ];
    $related_categories_ids[] = $row_category_id;
}

$template->assign('related_categories', $related_categories);
$template->assign('related_categories_ids', $related_categories_ids);

// jump to link
//
// 1. if an edit_context is available, we use it (without checking permissions)
// 2. else if user level is higher than image level, randomly find an authorized category
// 3. else no jumpto link

if ($custom_context = get_edit_context($image_id)) {
    $template->assign('U_JUMPTO', make_picture_url([
        'image_id' => $image_id,
    ]) . '/' . $custom_context);
} elseif ($user['level'] >= $page['image']['level']) {
    $query = '
SELECT category_id
  FROM ' . IMAGE_CATEGORY_TABLE . '
  WHERE image_id = ' . $image_id . '
;';

    // array_from_query() is deprecated and returns array<int|string, mixed>;
    // query2array() is its typed replacement, giving list<string|null> here
    // since only the 'category_id' column is selected.
    $authorized_category_ids = array_filter(query2array($query, null, 'category_id'), 'is_string');

    $authorizeds = array_diff(
        $authorized_category_ids,
        explode(
            ',',
            calculate_permissions($user['id'], $user['status'])
        )
    );

    if (count($authorizeds) > 0) {
        $authorizeds_values = array_values($authorizeds);
        $category = $authorizeds_values[random_int(0, count($authorizeds_values) - 1)];

        $url_img = make_picture_url(
            [
                'image_id' => $image_id,
                'image_file' => $image_file,
                'category' => $cache['cat_names'][$category],
            ]
        );

        $template->assign('U_JUMPTO', $url_img);
    }
}

// associate to albums
$query = '
SELECT id
  FROM ' . CATEGORIES_TABLE . '
    INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' ON id = category_id
  WHERE image_id = ' . $image_id . '
;';
$associated_albums = query2array($query, null, 'id');

$template->assign([
    'associated_albums' => $associated_albums,
    'represented_albums' => $represented_albums,
    'STORAGE_ALBUM' => $storage_category_id,
    'CACHE_KEYS' => get_admin_client_cache_keys(['tags', 'categories']),
    'PWG_TOKEN' => get_pwg_token(),
]);

trigger_notify('loc_end_picture_modify');

// ----------------------------------------------------------- sending html code

$template->assign_var_from_handle('ADMIN_CONTENT', 'picture_modify');
