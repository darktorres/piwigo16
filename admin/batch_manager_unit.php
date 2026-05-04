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

/**
 * Management of elements set. Elements can belong to a category or to the
 * user caddie.
 *
 */

if (!defined('PHPWG_ROOT_PATH')) {
    throw new \Piwigo\Exception\AuthException('Hacking attempt!');
}

global $template, $user, $page, $persistent_cache, $lang, $pwg_loaded_plugins;


include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
check_status(ACCESS_ADMINISTRATOR);

trigger_notify('loc_begin_element_set_unit');

// +-----------------------------------------------------------------------+
// |                        unit mode form submission                      |
// +-----------------------------------------------------------------------+

if (isset($_POST['submit'])) {
    check_pwg_token();
    check_input_parameter('element_ids', $_POST, false, '/^\d+(,\d+)*$/');
    $collection = explode(',', is_scalar($_POST['element_ids']) ? (string) $_POST['element_ids'] : '');

    $datas = [];

    $query = '
SELECT id, date_creation
  FROM '.IMAGES_TABLE.'
  WHERE id IN ('.implode(',', $collection).')
;';
    $result = pwg_query($query);

    while ($row = pwg_db_fetch_assoc($result)) {
        $data = [];

        $data['id'] = $row['id'];
        $data['name'] = $_POST['name-'.$row['id']];
        $data['author'] = $_POST['author-'.$row['id']];
        $data['level'] = $_POST['level-'.$row['id']];

        $desc_key = 'description-'.(is_scalar($row['id']) ? (string) $row['id'] : '');
        if (\Piwigo\Config\Config::allowHtmlDescriptions()) {
            $data['comment'] = $_POST[$desc_key] ?? null;
        } else {
            $desc_val = $_POST[$desc_key] ?? null;
            $data['comment'] = strip_tags(is_scalar($desc_val) ? (string) $desc_val : '');
        }

        if (!empty($_POST['date_creation-'.$row['id']])) {
            $data['date_creation'] = $_POST['date_creation-'.$row['id']];
        } else {
            $data['date_creation'] = null;
        }

        $datas[] = $data;

        // tags management
        $tag_ids = [];
        $tags_key = 'tags-'.(is_scalar($row['id']) ? (string) $row['id'] : '');
        if (!empty($_POST[$tags_key])) {
            $tags_val = $_POST[$tags_key];
            if (is_array($tags_val)) {
                $tags_val = array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $tags_val);
            } else {
                $tags_val = is_scalar($tags_val) ? (string) $tags_val : '';
            }
            $tag_ids = get_tag_ids($tags_val);
        }
        $row_id = is_numeric($row['id']) ? (int) $row['id'] : 0;
        set_tags($tag_ids, $row_id);
    }

    mass_updates(
        IMAGES_TABLE,
        [
        'primary' => ['id'],
        'update' => ['name','author','level','comment','date_creation'],
        ],
        $datas
    );

    \Piwigo\Core\PageState::current()->addInfo(l10n('Photo informations updated'));
    invalidate_user_cache();
}

//collection
$collection = [];
if (isset($_POST['nb_photos_deleted'])) {
    check_input_parameter('nb_photos_deleted', $_POST, false, '/^\d+$/');

    // let's fake a collection (we don't know the image_ids so we use "null", we only
    // care about the number of items here)
    $collection = array_fill(0, is_numeric($_POST['nb_photos_deleted']) ? (int) $_POST['nb_photos_deleted'] : 0, null);
} elseif (isset($_POST['setSelected'])) {
    // Here we don't use check_input_parameter because preg_match has a limit in
    // the repetitive pattern. Found a limit to 3276 but may depend on memory.
    //
    // check_input_parameter('whole_set', $_POST, false, '/^\d+(,\d+)*$/');
    //
    // Instead, let's break the input parameter into pieces and check pieces one by one.
    $collection = explode(',', is_scalar($_POST['whole_set']) ? (string) $_POST['whole_set'] : '');

    foreach ($collection as $id) {
        if (!preg_match('/^\d+$/', $id)) {
            fatal_error('[Hacking attempt] the input parameter "whole_set" is not valid');
        }
    }
} elseif (isset($_POST['selection'])) {
    $collection = is_array($_POST['selection']) ? $_POST['selection'] : [];
}


// +-----------------------------------------------------------------------+
// |                             template init                             |
// +-----------------------------------------------------------------------+

$template->set_filenames(
    ['batch_manager_unit' => 'batch_manager_unit.tpl']
);

$base_url = PHPWG_ROOT_PATH.'admin.php';


$template->assign(
    [

    'U_ELEMENTS_PAGE' => $base_url.get_query_string_diff(['display','start']),
    'level_options' => get_privacy_level_options(),
    'ADMIN_PAGE_TITLE' => l10n('Batch Manager'),
    'PWG_TOKEN' => get_pwg_token(),
    ]
);
include(PHPWG_ROOT_PATH.'admin/include/batch_manager_filters.inc.php');

$template->assign('page_data_json', json_encode([
    'str_are_you_sure' => l10n('Are you sure?'),
    'str_yes'          => l10n('Yes, delete'),
    'str_no'           => l10n('No, I have changed my mind'),
    'str_orphan'       => l10n('This photo is an orphan'),
    'str_meta_warning' => l10n('Warning ! Unsaved changes will be lost'),
    'str_meta_yes'     => l10n('I want to continue'),
    'str_title_ab'     => l10n('Associate to album'),
    'str_cancel'       => l10n('Cancel'),
], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));
// +-----------------------------------------------------------------------+
// |                        global mode thumbnails                         |
// +-----------------------------------------------------------------------+

$template->assign('ACTIVE_PLUGINS', array_keys($pwg_loaded_plugins));

// how many items to display on this page
if (!empty($_GET['display'])) {
    // conf_update_param('batch_manager_images_per_page_unit' , intval($_GET['display']));
    // $page['nb_images'] = \Piwigo\Config\Config::batchManagerImagesPerPageUnit();
    $page['nb_images'] = is_numeric($_GET['display']) ? (int) $_GET['display'] : 5;
} elseif (in_array(\Piwigo\Config\Config::batchManagerImagesPerPageUnit(), [5, 10, 50])) {
    $page['nb_images'] = \Piwigo\Config\Config::batchManagerImagesPerPageUnit();
} else {
    $page['nb_images'] = 5;
}
$template->assign('per_page', $page['nb_images']);

if (count($page['cat_elements_id']) > 0) {
    $nav_bar = create_navigation_bar(
        $base_url.get_query_string_diff(['start']),
        count($page['cat_elements_id']),
        $page['start'],
        $page['nb_images']
    );
    $template->assign(['navbar' => $nav_bar]);

    $element_ids = [];

    /** @var array<string, mixed> $bmf */
    $bmf = is_array($_SESSION['bulk_manager_filter'] ?? null) ? $_SESSION['bulk_manager_filter'] : [];

    $is_category = false;
    if (isset($bmf['category'])
        and !isset($bmf['category_recursive'])) {
        $is_category = true;
    }
    $bmf_category_val = is_numeric($bmf['category'] ?? null) ? (int) $bmf['category'] : 0;

    if (is_string($bmf['prefilter'] ?? null)
        and 'duplicates' == $bmf['prefilter']) {
        \Piwigo\Config\Config::override('order_by', ' ORDER BY file, id');
    }


    $query = '
SELECT *
  FROM '.IMAGES_TABLE;

    if ($is_category) {
        $category_info = get_cat_info($bmf_category_val);

        \Piwigo\Config\Config::override('order_by', \Piwigo\Config\Config::orderByInsideCategory());
        if (!empty($category_info['image_order'])) {
            \Piwigo\Config\Config::override('order_by', ' ORDER BY '.(is_scalar($category_info['image_order']) ? (string) $category_info['image_order'] : ''));
        }

        $query .= '
    JOIN '.IMAGE_CATEGORY_TABLE.' ON id = image_id';
    }

    $query .= '
  WHERE id IN ('.implode(',', $page['cat_elements_id']).')';

    if ($is_category) {
        $query .= '
    AND category_id = '.$bmf_category_val;
    }

    $query .= '
  '.\Piwigo\Config\Config::orderBy().'
  LIMIT '.$page['nb_images'].' OFFSET '.$page['start'].'
;';
    // $result = pwg_query($query);
    $images = query2array($query);
    $added_by_ids = array_unique(array_column($images, 'added_by'));
    if (count($added_by_ids) > 0) {
        $query = '
SELECT 
    '.\Piwigo\Config\Config::userFields()['username'].' AS username,
    '.\Piwigo\Config\Config::userFields()['id'].' AS id
  FROM '.USERS_TABLE.'
  WHERE '.\Piwigo\Config\Config::userFields()['id'].' IN ( '.implode(',', $added_by_ids).' )
;';
        $added_by_username_of = query2array($query, 'id', 'username');
    }

    $storage_category_id = null;
    if (!empty($row['storage_category_id'])) {
        $storage_category_id = $row['storage_category_id'];
    }

    foreach ($images as $row) {
        $element_ids[] = $row['id'];

        $src_image = new SrcImage($row);

        $image_file = $row['file'];



        $query = '
SELECT
    id,
    name
  FROM '.IMAGE_TAG_TABLE.' AS it
    JOIN '.TAGS_TABLE.' AS t ON t.id = it.tag_id
  WHERE image_id = '.$row['id'].'
;';

        $tag_selection = get_taglist($query);

        $legend = render_element_name($row);
        $row_file_str = is_scalar($row['file'] ?? null) ? (string) $row['file'] : '';
        if ($legend != get_name_from_file($row_file_str)) {
            $legend .= ' ('.$row_file_str.')';
        }
        $extTab = explode('.', is_scalar($row['path'] ?? null) ? (string) $row['path'] : '');



        // represent

        // categories

        $query = '
    SELECT category_id, uppercats, dir
      FROM '.IMAGE_CATEGORY_TABLE.' AS ic
        INNER JOIN '.CATEGORIES_TABLE.' AS c
          ON c.id = ic.category_id
      WHERE image_id = '.$row['id'].'
    ;';

        $sub_result = pwg_query($query);
        $related_categories = [];
        $related_category_ids = [];
        $row_id_int = is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
        $media['image'] = get_image_infos($row_id_int, true);

        while ($item = pwg_db_fetch_assoc($sub_result)) {
            $item_uppercats = is_scalar($item['uppercats'] ?? null) ? (string) $item['uppercats'] : '';
            $name =
              get_cat_display_name_cache(
                  $item_uppercats,
                  get_root_url().'admin.php?page=album-'
              );

            if ($item['category_id'] == $storage_category_id) {
                $template->assign('STORAGE_CATEGORY', $name);
            }

            $item_cat_id = is_numeric($item['category_id'] ?? null) ? (int) $item['category_id'] : 0;
            $related_categories[$item_cat_id] = ['name' => $name, 'unlinkable' => $item_cat_id != $storage_category_id];
            $related_category_ids[] = $item_cat_id;
        }

        // jump to link
        $image_file = $row['file'];

        $query = '
    SELECT category_id
    FROM '.IMAGE_CATEGORY_TABLE.'
    WHERE image_id = '.$row['id'].'
    ;';
        $authorizeds = array_diff(
            query2array($query, null, 'category_id'),
            explode(
                ',',
                calculate_permissions($user['id'], $user['status'])
            )
        );

        $catNames = \Piwigo\Cache\RequestCache::remember('cat_names', 'all', static function () {
            return query2array('SELECT id, name, permalink FROM '.CATEGORIES_TABLE.';', 'id') ?: [];
        });
        if (isset($row['cat_id'])
        and in_array($row['cat_id'], $authorizeds)) {
            $url_img = make_picture_url(
                [
                'image_id' => $row['id'],
                'image_file' => $image_file,
                'category' => (is_array($catNames) && (is_int($row['cat_id']) || is_string($row['cat_id']))) ? ($catNames[$row['cat_id']] ?? null) : null,
                ]
            );
        } else {
            foreach ($authorizeds as $category) {
                $url_img = make_picture_url(
                    [
                    'image_id' => $row['id'],
                    'image_file' => $image_file,
                    'category' => (is_array($catNames) && (is_int($category) || is_string($category))) ? ($catNames[$category] ?? null) : null,
                    ]
                );
                break;
            }
        }
        $admin_photo_base_url = get_root_url().'admin.php?page=photo-'.$row['id'];
        $admin_url_start = $admin_photo_base_url.'-properties';
        $admin_url_start .= isset($row['cat_id']) ? '&amp;cat_id='.$row['cat_id'] : '';
        $selected_level = $row['level'] ?? $row['level'];


        $template->append(
            'elements',
            array_merge(
                $row,
                [
        'ID' => $row['id'],
        'TN_SRC' => DerivativeImage::url(IMG_MEDIUM, $src_image),
        'FILE_SRC' => DerivativeImage::url(IMG_LARGE, $src_image),
        'LEGEND' => $legend,
        'U_EDIT' => get_root_url().'admin.php?page=photo-'.$row['id'],
        'NAME' => htmlspecialchars((string) ($row['name'] ?? '')),
        'AUTHOR' => htmlspecialchars((string) ($row['author'] ?? '')),
        'LEVEL' => !empty($row['level']) ? $row['level'] : '0',
        'DESCRIPTION' => htmlspecialchars((string) ($row['comment'] ?? '')),
        'DATE_CREATION' => $row['date_creation'],
        'TAGS' => $tag_selection,
        'is_svg' => (strtoupper(end($extTab)) == 'SVG'),
        'TITLE' => render_element_name($row),
        'DIMENSIONS' => ($row['width'] ?? '').'x'.($row['height'] ?? '').' px',
        'FORMAT' => ($row['width'] >= $row['height']) ? 1 : 0,//0:horizontal, 1:vertical
        'FILESIZE' => l10n('%.2f MB', (is_numeric($row['filesize'] ?? null) ? (float) $row['filesize'] : 0.0) / 1024),
        'REGISTRATION_DATE' => format_date(is_string($row['date_available'] ?? null) ? $row['date_available'] : (is_int($row['date_available'] ?? null) ? $row['date_available'] : null)),
        'EXT' => l10n('%s file type', end($extTab)),
        'POST_DATE' => l10n('Added on %s', format_date(is_string($row['date_available'] ?? null) ? $row['date_available'] : (is_int($row['date_available'] ?? null) ? $row['date_available'] : null), ['day', 'month', 'year'])),
        'AGE' => l10n(ucfirst(time_since(is_string($row['date_available'] ?? null) ? $row['date_available'] : (is_int($row['date_available'] ?? null) ? $row['date_available'] : null), 'year'))),
        'ADDED_BY' => l10n('Added by %s', $added_by_username_of[ $row['added_by'] ] ?? l10n('N/A')),
        'STATS' => l10n('Visited %d times', $row['hit']),
        'FILE' => l10n('%s', $row['file']),
        'related_categories' => $related_categories,
        'related_category_ids' => json_encode($related_category_ids),
        'U_JUMPTO' => (isset($url_img) and $user['level'] >= ($media['image']['level'] ?? 0)) ? $url_img : null,
        'tag_selection' => $tag_selection,
        'U_DOWNLOAD' => 'action.php?id='.$row['id'].'&amp;part=e&amp;pwg_token='.get_pwg_token().'&amp;download',
        'U_HISTORY' => get_root_url().'admin.php?page=history&amp;filter_image_id='.$row['id'],
        'U_ACTIVITY' => get_root_url().'admin.php?page=user_activity&photo='.$row['id'],
        'U_DELETE' => $admin_url_start.'&amp;delete=1&amp;pwg_token='.get_pwg_token(),
        'U_SYNC' => $admin_url_start.'&amp;sync_metadata=1',
        'PATH' => $row['path'],
        'level_options_selected' => [$selected_level],


        ]
            )
        );
    }

    $template->assign([
      'ELEMENT_IDS' => implode(',', $element_ids),
    ]);
}

$cache_keys = get_admin_client_cache_keys(['tags', 'categories']);
$batch_manager_unit_page_data = [
  'CACHE_KEYS' => $cache_keys,
  'ROOT_URL' => get_root_url(),
  'associated_categories' => $associated_categories ?? [],
  'str_create' => l10n('Create'),
  'active_plugins' => array_keys($pwg_loaded_plugins),
];

$template->assign([
  'CACHE_KEYS' => $cache_keys,
  'batch_manager_unit_page_data_json' => json_encode($batch_manager_unit_page_data),
]);

trigger_notify('loc_end_element_set_unit');

// +-----------------------------------------------------------------------+
// |                           sending html code                           |
// +-----------------------------------------------------------------------+

$template->assign_var_from_handle('ADMIN_CONTENT', 'batch_manager_unit');
