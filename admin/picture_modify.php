<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\admin\inc\functions_admin;
use Piwigo\admin\inc\functions_metadata_admin;
use Piwigo\inc\derivative_std_params;
use Piwigo\inc\DerivativeImage;
use Piwigo\inc\functions;
use Piwigo\inc\functions_category;
use Piwigo\inc\functions_html;
use Piwigo\inc\functions_plugins;
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;
use Piwigo\inc\SrcImage;

if (! defined('PHPWG_ROOT_PATH')) {
    exit('Hacking attempt!');
}

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
functions_user::check_status(ACCESS_ADMINISTRATOR);

functions::check_input_parameter('image_id', $_GET, false, PATTERN_ID);
functions::check_input_parameter('cat_id', $_GET, false, PATTERN_ID);

// retrieving direct information about picture. This may have been already
// done on admin/photo.php but this page can also be accessed without
// photo.php as proxy.
if (! isset($page['image'])) {
    $page['image'] = functions_admin::get_image_infos($_GET['image_id'], true);
}

// represent
$query = <<<SQL
    SELECT id
    FROM categories
    WHERE representative_picture_id = {$_GET['image_id']};
    SQL;
$represented_albums = $conf->sql_backend::query2array($query, null, 'id');

// +-----------------------------------------------------------------------+
// |                             delete photo                              |
// +-----------------------------------------------------------------------+

if (isset($_GET['delete'])) {
    functions::check_pwg_token();

    functions_admin::delete_elements([$_GET['image_id']], true);
    functions_admin::invalidate_user_cache();

    // where to redirect the user now?
    //
    // 1. if a category is available in the URL, use it
    // 2. else use the first reachable linked category
    // 3. redirect to gallery root

    if (isset($_GET['cat_id']) &&
        ! empty($_GET['cat_id'])
    ) {
        functions::redirect(
            functions_url::make_index_url(
                [
                    'category' => functions_category::get_cat_info($_GET['cat_id']),
                ]
            )
        );
    }

    $query = <<<SQL
        SELECT category_id
        FROM image_category
        WHERE image_id = {$_GET['image_id']};
        SQL;

    $authorizeds = array_diff(
        $conf->sql_backend::query2array($query, null, 'category_id'),
        explode(',', functions_user::calculate_permissions($user['id'], $user['status']))
    );

    foreach ($authorizeds as $category_id) {
        functions::redirect(
            functions_url::make_index_url(
                [
                    'category' => functions_category::get_cat_info($category_id),
                ]
            )
        );
    }

    functions::redirect(functions_url::make_index_url());
}

// +-----------------------------------------------------------------------+
// |                          synchronize metadata                         |
// +-----------------------------------------------------------------------+

if (isset($_GET['sync_metadata'])) {
    functions_metadata_admin::sync_metadata([intval($_GET['image_id'])]);
    $page['infos'][] = functions::l10n('Metadata synchronized from file');
}

//--------------------------------------------------------- update information
if (isset($_POST['submit'])) {
    functions::check_pwg_token();

    $data = [];
    $data['id'] = $_GET['image_id'];
    $data['name'] = $_POST['name'];
    $data['author'] = $_POST['author'];
    $data['level'] = $_POST['level'];

    $data['comment'] = $conf->allow_html_descriptions ? $_POST['description'] : strip_tags($_POST['description']);

    $data['date_creation'] = empty($_POST['date_creation']) ? null : $_POST['date_creation'];

    $data = functions_plugins::trigger_change('picture_modify_before_update', $data);

    $conf->sql_backend::single_update(
        'images',
        $data,
        [
            'id' => $data['id'],
        ]
    );

    // time to deal with tags
    $tag_ids = [];

    if (! empty($_POST['tags'])) {
        $tag_ids = functions_admin::get_tag_ids($_POST['tags']);
    }

    functions_admin::set_tags($tag_ids, $_GET['image_id']);

    // association to albums
    if (! isset($_POST['associate'])) {
        $_POST['associate'] = [];
    }

    functions::check_input_parameter('associate', $_POST, true, PATTERN_ID);
    functions_admin::move_images_to_categories([$_GET['image_id']], $_POST['associate']);

    functions_admin::invalidate_user_cache();

    // thumbnail for albums
    if (! isset($_POST['represent'])) {
        $_POST['represent'] = [];
    }

    functions::check_input_parameter('represent', $_POST, true, PATTERN_ID);

    $no_longer_thumbnail_for = array_diff($represented_albums, $_POST['represent']);

    if ($no_longer_thumbnail_for !== []) {
        functions_admin::set_random_representative($no_longer_thumbnail_for);
    }

    $new_thumbnail_for = array_diff($_POST['represent'], $represented_albums);

    if ($new_thumbnail_for !== []) {
        $image_ids = implode(', ', $new_thumbnail_for);
        $query = <<<SQL
            UPDATE categories
            SET representative_picture_id = {$_GET['image_id']}
            WHERE id IN ({$image_ids});
            SQL;
        $conf->sql_backend::pwg_query($query);
    }

    $represented_albums = $_POST['represent'];

    $page['infos'][] = functions::l10n('Photo information updated');
    functions::pwg_activity('photo', $_GET['image_id'], 'edit');

    // refresh page cache
    $page['image'] = functions_admin::get_image_infos($_GET['image_id'], true);
}

// tags
$query = <<<SQL
    SELECT id, name
    FROM image_tag AS it
    JOIN tags AS t ON t.id = it.tag_id
    WHERE image_id = {$_GET['image_id']};
    SQL;
$tag_selection = functions_admin::get_taglist($query);

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
$admin_url_start .= isset($_GET['cat_id']) ? '&amp;cat_id=' . $_GET['cat_id'] : '';

$src_image = new SrcImage($row);

// in case the photo needs a rotation of 90 degrees (clockwise or counterclockwise), we switch width and height
if (in_array($row['rotation'], [1, 3])) {
    [$row['width'], $row['height']] = [$row['height'], $row['width']];
}

$template->assign(
    [
        'tag_selection' => $tag_selection,
        'U_DOWNLOAD' => 'action.php?id=' . $_GET['image_id'] . '&amp;part=e&amp;pwg_token=' . functions::get_pwg_token() . '&amp;download',
        'U_SYNC' => $admin_url_start . '&amp;sync_metadata=1',
        'U_DELETE' => $admin_url_start . '&amp;delete=1&amp;pwg_token=' . functions::get_pwg_token(),
        'U_HISTORY' => functions_url::get_root_url() . 'admin.php?page=history&amp;filter_image_id=' . $_GET['image_id'],

        'PATH' => $row['path'],

        'TN_SRC' => DerivativeImage::url(derivative_std_params::IMG_MEDIUM, $src_image),
        'FILE_SRC' => DerivativeImage::url(derivative_std_params::IMG_LARGE, $src_image),

        'NAME' =>
          isset($_POST['name']) ?
            stripslashes($_POST['name']) : $row['name'],

        'TITLE' => functions_html::render_element_name($row),

        'DIMENSIONS' => $row['width'] . ' * ' . $row['height'],

        'FORMAT' => ($row['width'] >= $row['height']) ? 1 : 0, //0:horizontal, 1:vertical

        'FILESIZE' => $row['filesize'] . ' KB',

        'REGISTRATION_DATE' => functions::format_date($row['date_available']),

        'AUTHOR' => htmlspecialchars(
            isset($_POST['author'])
            ? stripslashes($_POST['author'])
            : (empty($row['author']) ? '' : $row['author'])
        ),

        'DATE_CREATION' => $row['date_creation'],

        'DESCRIPTION' =>
          htmlspecialchars(isset($_POST['description']) ?
            stripslashes($_POST['description']) : (empty($row['comment']) ? '' : $row['comment'])),

        'F_ACTION' =>
            functions_url::get_root_url() . 'admin.php'
            . functions_url::get_query_string_diff(['sync_metadata']),
    ]
);

$added_by = 'N/A';
$query = <<<SQL
    SELECT {$conf->user_fields['username']} AS username
    FROM users
    WHERE {$conf->user_fields['id']} = {$row['added_by']};
    SQL;
$result = $conf->sql_backend::pwg_query($query);

while ($user_row = $conf->sql_backend::pwg_db_fetch_assoc($result)) {
    $row['added_by'] = $user_row['username'];
}

$extTab = explode('.', $row['file']);

$intro_vars = [
    'file' => functions::l10n('%s', $row['file']),
    'date' => functions::l10n('Posted the %s', functions::format_date($row['date_available'], ['day', 'month', 'year'])),
    'age' => functions::l10n(ucfirst(functions::time_since($row['date_available'], 'year'))),
    'added_by' => functions::l10n('Added by %s', $row['added_by']),
    'size' => functions::l10n('%s pixels, %.2f MB', $row['width'] . '&times;' . $row['height'], $row['filesize'] / 1024),
    'stats' => functions::l10n('Visited %d times', $row['hit']),
    'id' => functions::l10n($row['id']),
    'ext' => functions::l10n('%s file type', strtoupper(end($extTab))),
    'is_svg' => (strtoupper(end($extTab)) === 'SVG'),
];

if ($conf->rate &&
    ! empty($row['rating_score'])
) {
    $query = <<<SQL
        SELECT COUNT(*) AS "COUNT(*)"
        FROM rate
        WHERE element_id = {$_GET['image_id']};
        SQL;
    [$row['nb_rates']] = $conf->sql_backend::pwg_db_fetch_row($conf->sql_backend::pwg_query($query));

    $intro_vars['stats'] .= ', ' . sprintf(functions::l10n('Rated %d times, score : %.2f'), $row['nb_rates'], $row['rating_score']);
}

$query = <<<SQL
    SELECT *
    FROM image_format
    WHERE image_id = {$row['id']};
    SQL;
$formats = $conf->sql_backend::query2array($query);

if ($formats !== []) {
    $format_strings = [];

    foreach ($formats as $format) {
        $format_strings[] = sprintf('%s (%.2fMB)', $format['ext'], $format['filesize'] / 1024);
    }

    $intro_vars['formats'] = functions::l10n('Formats: %s', implode(', ', $format_strings));
}

$template->assign('INTRO', $intro_vars);

if (in_array(functions::get_extension($row['path']), $conf->picture_ext)) {
    $template->assign('U_COI', functions_url::get_root_url() . 'admin.php?page=picture_coi&amp;image_id=' . $_GET['image_id']);
}

// image level options
$selected_level = $_POST['level'] ?? $row['level'];
$template->assign(
    [
        'level_options' => functions::get_privacy_level_options(),
        'level_options_selected' => [$selected_level],
    ]
);

// categories
$query = <<<SQL
    SELECT category_id, uppercats, dir
    FROM image_category AS ic
    INNER JOIN categories AS c ON c.id = ic.category_id
    WHERE image_id = {$_GET['image_id']};
    SQL;
$result = $conf->sql_backend::pwg_query($query);

$related_categories = [];
$related_categories_ids = [];

while ($row = $conf->sql_backend::pwg_db_fetch_assoc($result)) {
    $name =
      functions_html::get_cat_display_name_cache(
          $row['uppercats'],
          functions_url::get_root_url() . 'admin.php?page=album-'
      );

    if ($row['category_id'] == $storage_category_id) {
        $template->assign('STORAGE_CATEGORY', $name);
    }

    $related_categories[$row['category_id']] = [
        'name' => $name,
        'unlinkable' => $row['category_id'] != $storage_category_id,
    ];
    $related_categories_ids[] = $row['category_id'];
}

$template->assign('related_categories', $related_categories);
$template->assign('related_categories_ids', $related_categories_ids);

// jump to link
//
// 1. find all linked categories that are reachable for the current user.
// 2. if a category is available in the URL, use it if reachable
// 3. if URL category not available or reachable, use the first reachable
//    linked category
// 4. if no category reachable, no jumpto link
// 5. if level is too high for current user, no jumpto link

$query = <<<SQL
    SELECT category_id
    FROM image_category
    WHERE image_id = {$_GET['image_id']};
    SQL;

$authorizeds = array_diff(
    $conf->sql_backend::query2array($query, null, 'category_id'),
    explode(
        ',',
        functions_user::calculate_permissions($user['id'], $user['status'])
    )
);

if (isset($_GET['cat_id']) &&
    in_array($_GET['cat_id'], $authorizeds)
) {
    $url_img = functions_url::make_picture_url(
        [
            'image_id' => $_GET['image_id'],
            'image_file' => $image_file,
            'category' => $cache['cat_names'][$_GET['cat_id']],
        ]
    );
} else {
    foreach ($authorizeds as $category) {
        $url_img = functions_url::make_picture_url(
            [
                'image_id' => $_GET['image_id'],
                'image_file' => $image_file,
                'category' => $cache['cat_names'][$category],
            ]
        );
        break;
    }
}

if (isset($url_img) &&
    $user['level'] >= $page['image']['level']
) {
    $template->assign('U_JUMPTO', $url_img);
}

// associate to albums
$query = <<<SQL
    SELECT id
    FROM categories
    INNER JOIN image_category ON id = category_id
    WHERE image_id = {$_GET['image_id']};
    SQL;
$associated_albums = $conf->sql_backend::query2array($query, null, 'id');

$template->assign([
    'associated_albums' => $associated_albums,
    'represented_albums' => $represented_albums,
    'STORAGE_ALBUM' => $storage_category_id,
    'CACHE_KEYS' => functions_admin::get_admin_client_cache_keys(['tags', 'categories']),
    'PWG_TOKEN' => functions::get_pwg_token(),
]);

functions_plugins::trigger_notify('loc_end_picture_modify');

//----------------------------------------------------------- sending html code

$template->assign_var_from_handle('ADMIN_CONTENT', 'picture_modify');
