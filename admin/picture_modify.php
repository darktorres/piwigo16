<?php

declare(strict_types=1);

use Piwigo\Image\DerivativeImage;
use Piwigo\Image\SrcImage;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

if (!defined('PHPWG_ROOT_PATH')) {
    throw new \Piwigo\Exception\AuthException('Hacking attempt!');
}

global $template, $user, $page, $persistent_cache, $lang, $admin_photo_base_url;


include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
check_status(ACCESS_ADMINISTRATOR);

check_input_parameter('image_id', $_GET, false, PATTERN_ID);
check_input_parameter('level', $_POST, false, '/^\d+$/');
check_input_parameter('date_creation', $_POST, false, '/^\d\d\d\d-\d\d-\d\d( \d\d:\d\d:\d\d)?$/');

// retrieving direct information about picture. This may have been already
// done on admin/photo.php but this page can also be accessed without
// photo.php as proxy.
if (!isset($page['image'])) {
    $page['image'] = get_image_infos(is_scalar($_GET['image_id'] ?? null) ? (string)$_GET['image_id'] : '', true);
}

// represent
$query = '
SELECT id
  FROM '.CATEGORIES_TABLE.'
  WHERE representative_picture_id = '.(is_numeric($_GET['image_id'] ?? null) ? (int)$_GET['image_id'] : 0).'
;';
$represented_albums = \Piwigo\Db\QueryHelper::fetch($query, null, 'id');

// +-----------------------------------------------------------------------+
// |                             delete photo                              |
// +-----------------------------------------------------------------------+

if (isset($_GET['delete'])) {
    check_pwg_token();

    delete_elements([is_numeric($_GET['image_id'] ?? null) ? (int)$_GET['image_id'] : 0], true);
    invalidate_user_cache();

    // where to redirect the user now?
    //
    // 1. if a category is available in the URL, use it
    // 2. else use the first reachable linked category
    // 3. redirect to gallery root

    if ($custom_context = get_edit_context(is_numeric($_GET['image_id'] ?? null) ? (int)$_GET['image_id'] : 0)) {
        // considering we have a context available, we fake one to build the url
        // and we replace it with the context found in the session for this image_id
        redirect(str_replace('list/1,2', $custom_context, make_index_url(['list' => [1,2]])));
    }

    redirect(make_index_url());
}

// +-----------------------------------------------------------------------+
// |                          synchronize metadata                         |
// +-----------------------------------------------------------------------+

if (isset($_GET['sync_metadata'])) {
    sync_metadata([is_numeric($_GET['image_id'] ?? null) ? (int)$_GET['image_id'] : 0]);
    \Piwigo\Core\PageState::current()->addInfo(l10n('Metadata synchronized from file'));
}

//--------------------------------------------------------- update informations
if (isset($_POST['submit'])) {
    check_pwg_token();

    $data = [];
    $data['id'] = is_numeric($_GET['image_id'] ?? null) ? (int)$_GET['image_id'] : 0;
    $data['level'] = is_numeric($_POST['level'] ?? null) ? (int)$_POST['level'] : 0;

    $to_sanitize_fields = ['name', 'author', 'comment'];
    foreach ($to_sanitize_fields as $field) {
        $post_field = $_POST[$field] ?? null;
        $data[$field] = \Piwigo\Config\Config::allowHtmlDescriptions() ? $post_field : strip_tags(is_scalar($post_field) ? (string) $post_field : '');
    }

    if (!empty($_POST['date_creation'])) {
        $data['date_creation'] = $_POST['date_creation'];
    } else {
        $data['date_creation'] = null;
    }

    $data = trigger_change('picture_modify_before_update', $data);

    single_update(
        IMAGES_TABLE,
        $data,
        ['id' => $data['id']]
    );

    // time to deal with tags
    $tag_ids = [];
    if (!empty($_POST['tags'])) {
        $tags_post = $_POST['tags'];
        if (is_scalar($tags_post)) {
            $tag_ids = get_tag_ids((string) $tags_post);
        } elseif (is_array($tags_post)) {
            $tag_ids = get_tag_ids(array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $tags_post));
        }
    }
    set_tags($tag_ids, is_numeric($_GET['image_id'] ?? null) ? (int)$_GET['image_id'] : 0);

    // association to albums
    if (!isset($_POST['associate'])) {
        $_POST['associate'] = [];
    }
    check_input_parameter('associate', $_POST, true, PATTERN_ID);
    move_images_to_categories(
        [is_numeric($_GET['image_id'] ?? null) ? (int)$_GET['image_id'] : 0],
        is_array($_POST['associate']) ? array_map(fn ($v) => is_numeric($v) ? (int) $v : 0, $_POST['associate']) : []
    );

    invalidate_user_cache();

    // thumbnail for albums
    if (!isset($_POST['represent'])) {
        $_POST['represent'] = [];
    }
    check_input_parameter('represent', $_POST, true, PATTERN_ID);

    $represented_albums_int = array_map(fn ($v) => is_numeric($v) ? (int) $v : 0, $represented_albums);
    $represent_post_int = is_array($_POST['represent']) ? array_map(fn ($v) => is_numeric($v) ? (int) $v : 0, $_POST['represent']) : [];
    $no_longer_thumbnail_for = array_diff($represented_albums_int, $represent_post_int);
    if (count($no_longer_thumbnail_for) > 0) {
        set_random_representant(array_values($no_longer_thumbnail_for));
    }

    $new_thumbnail_for = array_diff($represent_post_int, $represented_albums_int);
    if (count($new_thumbnail_for) > 0) {
        \Piwigo\Core\ServiceLocator::get(\Piwigo\Category\CategoryRepository::class)
            ->setRepresentativePicture(
                array_map(fn ($v) => (int) $v, $new_thumbnail_for),
                is_numeric($_GET['image_id'] ?? null) ? (int) $_GET['image_id'] : 0
            );
    }

    $represented_albums = is_array($_POST['represent']) ? array_map(fn ($v) => is_numeric($v) ? (int) $v : 0, $_POST['represent']) : [];

    $template->assign(
        [
        'save_success' => l10n('Photo informations updated'),
    ]
    );

    pwg_activity('photo', is_numeric($_GET['image_id'] ?? null) ? (int)$_GET['image_id'] : 0, 'edit');

    // refresh page cache
    $page['image'] = get_image_infos(is_scalar($_GET['image_id'] ?? null) ? (string)$_GET['image_id'] : '', true);
}

// tags
$tag_selection = get_taglist_from_rows(
    \Piwigo\Core\ServiceLocator::get(\Piwigo\Tag\TagRepository::class)
        ->findTagsByImageId(is_numeric($_GET['image_id'] ?? null) ? (int) $_GET['image_id'] : 0)
);

$row = $page['image'];

if (isset($data['date_creation'])) {
    $row['date_creation'] = $data['date_creation'];
}

$storage_category_id = null;
if (!empty($row['storage_category_id'])) {
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

$admin_url_start = $admin_photo_base_url.'-properties';

$src_image = new SrcImage($row);

// in case the photo needs a rotation of 90 degrees (clockwise or counterclockwise), we switch width and height
if (in_array($row['rotation'], [1, 3])) {
    [$row['width'], $row['height']] = [$row['height'], $row['width']];
}

$template->assign(
    [
    'tag_selection' => $tag_selection,
    'U_DOWNLOAD' => 'action.php?id='.(is_scalar($_GET['image_id'] ?? null) ? (string)$_GET['image_id'] : '').'&amp;part=e&amp;pwg_token='.get_pwg_token().'&amp;download',
    'U_SYNC' => $admin_url_start.'&amp;sync_metadata=1',
    'U_DELETE' => $admin_url_start.'&amp;delete=1&amp;pwg_token='.get_pwg_token(),
    'U_HISTORY' => get_root_url().'admin.php?page=history&amp;filter_image_id='.(is_scalar($_GET['image_id'] ?? null) ? (string)$_GET['image_id'] : ''),
    'U_ACTIVITY' => get_root_url().'admin.php?page=user_activity&photo='.(is_scalar($_GET['image_id'] ?? null) ? (string)$_GET['image_id'] : ''),

    'PATH' => $row['path'],

    'TN_SRC' => DerivativeImage::url(IMG_MEDIUM, $src_image),
    'FILE_SRC' => DerivativeImage::url(IMG_LARGE, $src_image),

    'NAME' =>
      isset($_POST['name']) ?
        stripslashes(is_scalar($_POST['name']) ? (string) $_POST['name'] : '') : ($row['name'] ?? null),

    'TITLE' => render_element_name($row),

    'DIMENSIONS' => ($row['width'] ?? '').' * '.($row['height'] ?? ''),

    'FORMAT' => ($row['width'] >= $row['height']) ? 1 : 0,//0:horizontal, 1:vertical

    'FILESIZE' => ($row['filesize'] ?? '').' KB',

    'REGISTRATION_DATE' => format_date($row['date_available']),

    'AUTHOR' => htmlspecialchars(
        isset($_POST['author'])
        ? stripslashes(is_scalar($_POST['author']) ? (string) $_POST['author'] : '')
        : (empty($row['author']) ? '' : (is_scalar($row['author']) ? (string) $row['author'] : ''))
    ),

    'DATE_CREATION' => $row['date_creation'],

    'DESCRIPTION' =>
      htmlspecialchars(
          isset($_POST['comment']) ?
          stripslashes(is_scalar($_POST['comment']) ? (string) $_POST['comment'] : '') :
          (empty($row['comment']) ? '' : (is_scalar($row['comment']) ? (string) $row['comment'] : ''))
      ),

    'F_ACTION' =>
        get_root_url().'admin.php'
        .get_query_string_diff(['sync_metadata']),
    ]
);

$added_by = 'N/A';
$userFields = \Piwigo\Config\Config::userFields();
$foundUsername = \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\UserRepository::class)
    ->findUsernameById(
        $userFields['id'],
        $userFields['username'],
        USERS_TABLE,
        is_numeric($row['added_by'] ?? null) ? (int) $row['added_by'] : 0
    );
if ($foundUsername !== null) {
    $row['added_by'] = $foundUsername;
}

$extTab = explode('.', (string) $row['file']);

$intro_vars = [
  'file' => l10n('%s', $row['file']),
  'date' => l10n('Posted the %s', format_date($row['date_available'], ['day', 'month', 'year'])),
  'age' => l10n(ucfirst(time_since($row['date_available'], 'year'))),
  'added_by' => l10n('Added by %s', $row['added_by']),
  'size' => l10n('%s pixels, %.2f MB', $row['width'].'&times;'.$row['height'], (is_numeric($row['filesize'] ?? null) ? (float)$row['filesize'] : 0.0) / 1024),
  'stats' => l10n('Visited %d times', $row['hit']),
  'id' => l10n(is_scalar($row['id'] ?? null) ? (string)$row['id'] : ''),
  'ext' => l10n('%s file type', strtoupper(end($extTab))),
  'is_svg' => (strtoupper(end($extTab)) == 'SVG'),
  ];

if (\Piwigo\Config\Config::rateEnabled() and !empty($row['rating_score'])) {
    $row['nb_rates'] = \Piwigo\Core\ServiceLocator::get(\Piwigo\Rate\RateRepository::class)
        ->countByElementId(is_numeric($_GET['image_id'] ?? null) ? (int) $_GET['image_id'] : 0);

    $intro_vars['stats'] .= ', '.sprintf(l10n('Rated %d times, score : %.2f'), $row['nb_rates'], $row['rating_score']);
}

$query = '
SELECT *
  FROM '.IMAGE_FORMAT_TABLE.'
  WHERE image_id = '.$row['id'].'
;';
$formats = \Piwigo\Db\QueryHelper::fetch($query);

if (!empty($formats)) {
    $format_strings = [];

    foreach ($formats as $format) {
        $format_strings[] = sprintf('%s (%.2fMB)', $format['ext'], (int)$format['filesize'] / 1024);
    }

    $intro_vars['formats'] = l10n('Formats: %s', implode(', ', $format_strings));
}

$template->assign('INTRO', $intro_vars);


if (in_array(get_extension($row['path']), \Piwigo\Config\Config::pictureExtensions())) {
    $template->assign('U_COI', get_root_url().'admin.php?page=picture_coi&amp;image_id='.(is_scalar($_GET['image_id'] ?? null) ? (string)$_GET['image_id'] : ''));
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
$related_categories = [];
$related_categories_ids = [];

foreach (\Piwigo\Core\ServiceLocator::get(\Piwigo\Category\CategoryRepository::class)
    ->findCategoryInfosByImageId(is_numeric($_GET['image_id'] ?? null) ? (int) $_GET['image_id'] : 0) as $row) {
    $name =
      get_cat_display_name_cache(
          is_scalar($row['uppercats'] ?? null) ? (string)$row['uppercats'] : '',
          get_root_url().'admin.php?page=album-'
      );

    if ($row['category_id'] == $storage_category_id) {
        $template->assign('STORAGE_CATEGORY', $name);
    }

    $related_categories[is_scalar($row['category_id'] ?? null) ? (string)$row['category_id'] : ''] = ['name' => $name, 'unlinkable' => $row['category_id'] != $storage_category_id];
    $related_categories_ids[] = $row['category_id'];
}

$template->assign('related_categories', $related_categories);
$template->assign('related_categories_ids', $related_categories_ids);

// jump to link
//
// 1. if an edit_context is available, we use it (without checking permissions)
// 2. else if user level is higher than image level, randomly find an authorized category
// 3. else no jumpto link

if ($custom_context = get_edit_context(is_numeric($_GET['image_id'] ?? null) ? (int)$_GET['image_id'] : 0)) {
    $template->assign('U_JUMPTO', make_picture_url(['image_id' => is_scalar($_GET['image_id'] ?? null) ? (string)$_GET['image_id'] : '']).'/'.$custom_context);
} elseif ($user['level'] >= $page['image']['level']) {
    $query = '
SELECT category_id
  FROM '.IMAGE_CATEGORY_TABLE.'
  WHERE image_id = '.(is_numeric($_GET['image_id'] ?? null) ? (int)$_GET['image_id'] : 0).'
;';

    $authorizeds = array_diff(
        \Piwigo\Db\QueryHelper::fetch($query, null, 'category_id'),
        explode(
            ',',
            calculate_permissions($user['id'], $user['status'])
        )
    );

    if (count($authorizeds) > 0) {
        $category = $authorizeds[array_rand($authorizeds)];

        $catNames = \Piwigo\Cache\RequestCache::remember('cat_names', 'all', static function () {
            return \Piwigo\Db\QueryHelper::fetch('SELECT id, name, permalink FROM '.CATEGORIES_TABLE.';', 'id') ?: [];
        });
        $url_img = make_picture_url(
            [
            'image_id' => $_GET['image_id'],
            'image_file' => $image_file,
            'category' => (is_array($catNames) && (is_int($category) || is_string($category))) ? ($catNames[$category] ?? null) : null,
            ]
        );

        $template->assign('U_JUMPTO', $url_img);
    }
}

// associate to albums
$query = '
SELECT id
  FROM '.CATEGORIES_TABLE.'
    INNER JOIN '.IMAGE_CATEGORY_TABLE.' ON id = category_id
  WHERE image_id = '.(is_numeric($_GET['image_id'] ?? null) ? (int)$_GET['image_id'] : 0).'
;';
$associated_albums = \Piwigo\Db\QueryHelper::fetch($query, null, 'id');

$cache_keys = get_admin_client_cache_keys(['tags', 'categories']);
$picture_modify_page_data = [
  'CACHE_KEYS' => $cache_keys,
  'ROOT_URL' => get_root_url(),
  'associated_albums' => $associated_albums,
  'str_create' => l10n('Create'),
  'str_assoc_album_ab' => l10n('Associate to album'),
  'related_categories_ids' => $related_categories_ids,
  'str_orphan' => l10n('This photo is an orphan'),
  'str_are_you_sure' => l10n('Are you sure?'),
  'str_yes' => l10n('Yes, delete'),
  'str_no' => l10n('No, I have changed my mind'),
  'str_cancel' => l10n('Cancel'),
  'url_delete' => $admin_url_start.'&delete=1&pwg_token='.get_pwg_token(),
];

$template->assign([
  'associated_albums' => $associated_albums,
  'represented_albums' => $represented_albums,
  'STORAGE_ALBUM' => $storage_category_id,
  'CACHE_KEYS' => $cache_keys,
  'picture_modify_page_data_json' => json_encode($picture_modify_page_data),
  'PWG_TOKEN' => get_pwg_token(),
  ]);

trigger_notify('loc_end_picture_modify');

//----------------------------------------------------------- sending html code

$template->assign_var_from_handle('ADMIN_CONTENT', 'picture_modify');
