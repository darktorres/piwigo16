<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// Bootstrap globals, set by include/common.inc.php.
/**
 * @var array<string, mixed> $conf
 * @var \Template $template
 * @var array<string, mixed> $user
 */
global $conf, $template, $user;

/**
 * Management of elements set. Elements can belong to a category or to the
 * user caddie.
 */
if (! defined('PHPWG_ROOT_PATH')) {
    die('Hacking attempt!');
}

include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+

check_status(ACCESS_ADMINISTRATOR);

if (! empty($_POST)) {
    check_pwg_token();
}

trigger_notify('loc_begin_element_set_global');

check_input_parameter('del_tags', $_POST, true, PATTERN_ID);
check_input_parameter('associate', $_POST, true, PATTERN_ID);
check_input_parameter('move', $_POST, false, PATTERN_ID);
check_input_parameter('dissociate', $_POST, false, PATTERN_ID);

// +-----------------------------------------------------------------------+
// |                            current selection                          |
// +-----------------------------------------------------------------------+

$collection = [];
if (isset($_POST['nb_photos_deleted'])) {
    check_input_parameter('nb_photos_deleted', $_POST, false, '/^\d+$/');

    // let's fake a collection (we don't know the image_ids so we use 0 as a
    // placeholder, we only care about the number of items here): this
    // branch is reached only after an ajax-driven "delete" action (see
    // batchManagerGlobal.js), whose photos are already gone, so no
    // downstream code in the 'delete' action below needs real ids
    $nb_photos_deleted = is_numeric($_POST['nb_photos_deleted']) ? (int) $_POST['nb_photos_deleted'] : 0;
    $collection = array_fill(0, $nb_photos_deleted, 0);
} elseif (isset($_POST['setSelected'])) {
    // Here we don't use check_input_parameter because preg_match has a limit in
    // the repetitive pattern. Found a limit to 3276 but may depend on memory.
    //
    // check_input_parameter('whole_set', $_POST, false, '/^\d+(,\d+)*$/');
    //
    // Instead, let's break the input parameter into pieces and check pieces one by one.
    $whole_set = isset($_POST['whole_set']) && is_string($_POST['whole_set']) ? $_POST['whole_set'] : '';

    foreach (explode(',', $whole_set) as $id) {
        if (! preg_match('/^\d+$/', $id)) {
            fatal_error('[Hacking attempt] the input parameter "whole_set" is not valid');
        }
        $collection[] = (int) $id;
    }
} elseif (isset($_POST['selection']) && is_array($_POST['selection'])) {
    foreach ($_POST['selection'] as $selected_id) {
        if (is_numeric($selected_id)) {
            $collection[] = (int) $selected_id;
        }
    }
}

// +-----------------------------------------------------------------------+
// |                       global mode form submission                     |
// +-----------------------------------------------------------------------+

// Locally-typed snapshot of $_SESSION['bulk_manager_filter']. It is always
// written as an array by admin/batch_manager.php (which includes this
// file), before this file runs; this guards against corrupted/foreign
// session state and lets PHPStan track a real array shape for the reads
// below (this file never writes to $_SESSION['bulk_manager_filter']).
/** @var array<string, mixed> $bulk_manager_filter */
$bulk_manager_filter = isset($_SESSION['bulk_manager_filter']) && is_array($_SESSION['bulk_manager_filter']) ? $_SESSION['bulk_manager_filter'] : [];

// $page['prefilter'] is a shortcut to test if the current filter contains a
// given prefilter. The idea is to make conditions simpler to write in the
// code.
//
// $page is bootstrap-initialized by include/common.inc.php (always run
// before this file) with 'infos'/'errors'/'warnings' pre-populated as empty
// arrays; this file only ever appends to those three keys (never reassigns
// them wholesale), so the invariant holds for every use below. PHPStan
// can't see across the include boundary, hence the explicit narrowing.
/** @var array<string, mixed> $page */
assert(is_array($page['errors']));
assert(is_array($page['infos']));
assert(is_array($page['warnings']));
$page['prefilter'] = 'none';
if (isset($bulk_manager_filter['prefilter'])) {
    $page['prefilter'] = $bulk_manager_filter['prefilter'];
}

$get_page = isset($_GET['page']) && is_string($_GET['page']) ? $_GET['page'] : '';
$redirect_url = get_root_url() . 'admin.php?page=' . $get_page;

if (isset($_POST['submit'])) {
    // if the user tries to apply an action, it means that there is at least 1
    // photo in the selection
    if (count($collection) == 0) {
        $page['errors'][] = l10n('Select at least one photo');
    }

    $action = $_POST['selectAction'];
    $redirect = false;

    if ($action == 'remove_from_caddie') {
        // $user['id'] is always numeric: include/user.inc.php (part of the
        // include/common.inc.php bootstrap that always runs before this
        // file) sets it from $conf['guest_id'], $_SESSION['pwg_uid'], or
        // get_userid()/register_user(), all of which are ints.
        assert(is_numeric($user['id']));
        $query = '
DELETE
  FROM ' . CADDIE_TABLE . '
  WHERE element_id IN (' . implode(',', $collection) . ')
    AND user_id = ' . $user['id'] . '
;';
        pwg_query($query);

        // remove from caddie action available only in caddie so reload content
        $redirect = true;
    } elseif ($action == 'add_tags') {
        if (empty($_POST['add_tags']) || ! is_array($_POST['add_tags'])) {
            $page['errors'][] = l10n('Select at least one tag');
        } else {
            $add_tags = [];
            foreach ($_POST['add_tags'] as $raw_tag) {
                if (is_string($raw_tag)) {
                    $add_tags[] = $raw_tag;
                }
            }

            $tag_ids = get_tag_ids($add_tags);
            add_tags($tag_ids, $collection);

            if ($page['prefilter'] == 'no_tag') {
                $redirect = true;
            }
        }
    } elseif ($action == 'del_tags') {
        $del_tags = isset($_POST['del_tags']) && is_array($_POST['del_tags']) ? array_filter($_POST['del_tags'], 'is_scalar') : [];
        if (count($del_tags) > 0) {
            $taglist_before = get_image_tag_ids($collection);

            $query = '
DELETE
  FROM ' . IMAGE_TAG_TABLE . '
  WHERE image_id IN (' . implode(',', $collection) . ')
    AND tag_id IN (' . implode(',', $del_tags) . ')
;';
            pwg_query($query);

            $taglist_after = get_image_tag_ids($collection);
            $images_to_update = compare_image_tag_lists($taglist_before, $taglist_after);
            update_images_lastmodified($images_to_update);

            if (isset($bulk_manager_filter['tags']) && is_array($bulk_manager_filter['tags']) &&
              count(array_intersect(array_filter($bulk_manager_filter['tags'], 'is_scalar'), $del_tags))) {
                $redirect = true;
            }
        } else {
            $page['errors'][] = l10n('Select at least one tag');
        }
    }

    if ($action == 'associate') {
        if (empty($_POST['associate']) || ! is_array($_POST['associate'])) {
            $page['errors'][] = l10n('Select at least one album');
        } else {
            $associate_categories = [];
            foreach ($_POST['associate'] as $raw_category_id) {
                if (is_numeric($raw_category_id)) {
                    $associate_categories[] = (int) $raw_category_id;
                }
            }

            associate_images_to_categories(
                $collection,
                $associate_categories
            );

            $_SESSION['page_infos'] = [
                l10n('Information data registered in database'),
            ];

            // let's refresh the page because we the current set might be modified
            if ($page['prefilter'] == 'no_album') {
                $redirect = true;
            } elseif ($page['prefilter'] == 'no_virtual_album') {
                // "no_virtual_album" refresh only makes sense when we know
                // whether the target album has a physical directory; with
                // the multi-album selector, use the first selected album
                // (matches the single-select behavior this branch had
                // before "associate" became a multi-value field).
                $first_associate_category = reset($associate_categories);
                if ($first_associate_category !== false) {
                    $category_info = get_cat_info($first_associate_category);
                    if (empty($category_info['dir'])) {
                        $redirect = true;
                    }
                }
            }
        }
    } elseif ($action == 'move') {
        $move_category = isset($_POST['move']) && is_numeric($_POST['move']) ? (int) $_POST['move'] : null;
        move_images_to_categories($collection, $move_category !== null ? [$move_category] : []);

        $_SESSION['page_infos'] = [
            l10n('Information data registered in database'),
        ];

        // let's refresh the page because we the current set might be modified
        if ($page['prefilter'] == 'no_album') {
            $redirect = true;
        } elseif ($page['prefilter'] == 'no_virtual_album') {
            if ($move_category !== null) {
                $category_info = get_cat_info($move_category);
                if (empty($category_info['dir'])) {
                    $redirect = true;
                }
            }
        } elseif (isset($bulk_manager_filter['category'])
            and $move_category != $bulk_manager_filter['category']) {
            $redirect = true;
        }
    } elseif ($action == 'dissociate') {
        $dissociate_category = isset($_POST['dissociate']) && is_numeric($_POST['dissociate']) ? (int) $_POST['dissociate'] : 0;
        $nb_dissociated = dissociate_images_from_category($collection, $dissociate_category);

        if ($nb_dissociated > 0) {
            $_SESSION['page_infos'] = [
                l10n('Information data registered in database'),
            ];

            // let's refresh the page because the current set might be modified
            $redirect = true;
        }
    }

    // author
    elseif ($action == 'author') {
        if (isset($_POST['remove_author'])) {
            $_POST['author'] = null;
        }

        $datas = [];
        foreach ($collection as $image_id) {
            $datas[] = [
                'id' => $image_id,
                'author' => $_POST['author'],
            ];
        }

        mass_updates(
            IMAGES_TABLE,
            [
                'primary' => ['id'],
                'update' => ['author'],
            ],
            $datas
        );

        pwg_activity('photo', $collection, 'edit', [
            'action' => 'author',
        ]);
    }

    // title
    elseif ($action == 'title') {
        if (isset($_POST['remove_title'])) {
            $_POST['title'] = null;
        }

        $datas = [];
        foreach ($collection as $image_id) {
            $datas[] = [
                'id' => $image_id,
                'name' => $_POST['title'],
            ];
        }

        mass_updates(
            IMAGES_TABLE,
            [
                'primary' => ['id'],
                'update' => ['name'],
            ],
            $datas
        );

        pwg_activity('photo', $collection, 'edit', [
            'action' => 'title',
        ]);
    }

    // date_creation
    elseif ($action == 'date_creation') {
        if (isset($_POST['remove_date_creation']) || empty($_POST['date_creation'])) {
            $date_creation = null;
        } else {
            $date_creation = $_POST['date_creation'];
        }

        $datas = [];
        foreach ($collection as $image_id) {
            $datas[] = [
                'id' => $image_id,
                'date_creation' => $date_creation,
            ];
        }

        mass_updates(
            IMAGES_TABLE,
            [
                'primary' => ['id'],
                'update' => ['date_creation'],
            ],
            $datas
        );

        pwg_activity('photo', $collection, 'edit', [
            'action' => 'date_creation',
        ]);
    }

    // privacy_level
    elseif ($action == 'level') {
        $datas = [];
        foreach ($collection as $image_id) {
            $datas[] = [
                'id' => $image_id,
                'level' => $_POST['level'],
            ];
        }

        mass_updates(
            IMAGES_TABLE,
            [
                'primary' => ['id'],
                'update' => ['level'],
            ],
            $datas
        );

        pwg_activity('photo', $collection, 'edit', [
            'action' => 'privacy_level',
        ]);

        if (isset($bulk_manager_filter['level'])) {
            if ($_POST['level'] < $bulk_manager_filter['level']) {
                $redirect = true;
            }
        }
    }

    // add_to_caddie
    elseif ($action == 'add_to_caddie') {
        fill_caddie($collection);
    }

    // delete
    elseif ($action == 'delete') {
        if (isset($_POST['confirm_deletion']) and $_POST['confirm_deletion'] == 1) {
            // now done with ajax calls, with blocks
            // $deleted_count = delete_elements($collection, true);
            if (count($collection) > 0) {
                if (! isset($_SESSION['page_infos']) || ! is_array($_SESSION['page_infos'])) {
                    $_SESSION['page_infos'] = [];
                }
                $_SESSION['page_infos'][] = l10n_dec(
                    '%d photo was deleted',
                    '%d photos were deleted',
                    count($collection)
                );

                $redirect_url = get_root_url() . 'admin.php?page=' . $get_page;
                $redirect = true;
            } else {
                $page['errors'][] = l10n('No photo can be deleted');
            }
        } else {
            $page['errors'][] = l10n('You need to confirm deletion');
        }
    }

    // synchronize metadata
    elseif ($action == 'metadata') {
        $page['infos'][] = l10n('Metadata synchronized from file') . ' <span class="badge">' . count($collection) . '</span>';
    } elseif ($action == 'delete_derivatives' && isset($_POST['del_derivatives_type']) && is_array($_POST['del_derivatives_type']) && ! empty($_POST['del_derivatives_type'])) {
        $query = 'SELECT path,representative_ext FROM ' . IMAGES_TABLE . '
  WHERE id IN (' . implode(',', $collection) . ')';
        $result = pwg_query($query);
        while ($info = pwg_db_fetch_assoc($result)) {
            $derivative_infos = [
                'path' => (string) $info['path'],
            ];
            if (! empty($info['representative_ext'])) {
                $derivative_infos['representative_ext'] = (string) $info['representative_ext'];
            }
            foreach ($_POST['del_derivatives_type'] as $type) {
                if (is_string($type)) {
                    delete_element_derivatives($derivative_infos, $type);
                }
            }
        }
    } elseif ($action == 'generate_derivatives') {
        if ($_POST['regenerateSuccess'] != '0') {
            $page['infos'][] = l10n('%s photos have been regenerated', $_POST['regenerateSuccess']);
        }
        if ($_POST['regenerateError'] != '0') {
            $page['warnings'][] = l10n('%s photos can not be regenerated', $_POST['regenerateError']);
        }
    }

    if (! in_array($action, ['remove_from_caddie', 'add_to_caddie', 'delete_derivatives', 'generate_derivatives'])) {
        invalidate_user_cache();
    }

    trigger_notify('element_set_global_action', $action, $collection);

    if ($redirect) {
        redirect($redirect_url);
    }
}

// +-----------------------------------------------------------------------+
// |                             template init                             |
// +-----------------------------------------------------------------------+
$template->set_filenames([
    'batch_manager_global' => 'batch_manager_global.tpl',
]);

$base_url = get_root_url() . 'admin.php';

include PHPWG_ROOT_PATH . 'admin/include/batch_manager_filters.inc.php';

// +-----------------------------------------------------------------------+
// |                            caddie options                             |
// +-----------------------------------------------------------------------+
$template->assign('IN_CADDIE', $page['prefilter'] == 'caddie');

// $page['cat_elements_id'] is always set (a list of scalar image ids) by
// admin/batch_manager.php (which includes this file) before this file runs;
// PHPStan cannot see across that include boundary, so we narrow it once here
// for every downstream use in this file.
$cat_elements_id = is_array($page['cat_elements_id'])
    ? array_map('intval', array_filter($page['cat_elements_id'], 'is_numeric'))
    : [];

// +-----------------------------------------------------------------------+
// |                           global mode form                            |
// +-----------------------------------------------------------------------+

if (count($cat_elements_id) > 0) {
    // remove tags
    $template->assign('associated_tags', get_common_tags($cat_elements_id, -1));
}

// creation date
$template->assign(
    'DATE_CREATION',
    empty($_POST['date_creation']) ? date('Y-m-d') . ' 00:00:00' : $_POST['date_creation']
);

// image level options
$template->assign(
    [
        'level_options' => get_privacy_level_options(),
        'level_options_selected' => 0,
    ]
);

// metadata
include_once PHPWG_ROOT_PATH . 'admin/site_reader_local.php';
$site_reader = new LocalSiteReader('./');
$used_metadata = implode(', ', $site_reader->get_metadata_attributes());

$template->assign(
    [
        'used_metadata' => $used_metadata,
    ]
);

// derivatives
$del_deriv_map = [];
foreach (ImageStdParams::get_defined_type_map() as $params) {
    $del_deriv_map[$params->type] = l10n($params->type);
}
$gen_deriv_map = $del_deriv_map;
$del_deriv_map[IMG_CUSTOM] = l10n(IMG_CUSTOM);
$template->assign(
    [
        'del_derivatives_types' => $del_deriv_map,
        'generate_derivatives_types' => $gen_deriv_map,
    ]
);

// +-----------------------------------------------------------------------+
// |                        global mode thumbnails                         |
// +-----------------------------------------------------------------------+

// how many items to display on this page
if (! empty($_GET['display'])) {
    if ($_GET['display'] == 'all') {
        $page['nb_images'] = count($cat_elements_id);
    } else {
        $page['nb_images'] = is_numeric($_GET['display']) ? intval($_GET['display']) : 0;
    }
} elseif (in_array($conf['batch_manager_images_per_page_global'], [20, 50, 100])) {
    $page['nb_images'] = $conf['batch_manager_images_per_page_global'];
} else {
    $page['nb_images'] = 20;
}

// Locally-typed snapshot of $page['nb_images']/$page['start']. 'nb_images'
// is always set to an int by the block above; 'start' is always set (0 or a
// validated numeric $_REQUEST value) by admin/batch_manager.php (which
// includes this file) before this file runs. $page's general
// array<string, mixed> shape means PHPStan still sees these offsets as mixed
// at the read sites below, so we narrow once here.
$nb_images = is_numeric($page['nb_images']) ? (int) $page['nb_images'] : 20;
$start = is_numeric($page['start']) ? (int) $page['start'] : 0;

$nb_thumbs_page = 0;

if (count($cat_elements_id) > 0) {
    $nav_bar = create_navigation_bar(
        $base_url . get_query_string_diff(['start']),
        count($cat_elements_id),
        $start,
        $nb_images
    );
    $template->assign('navbar', $nav_bar);

    $is_category = false;
    $filter_category_id = 0;
    if (isset($bulk_manager_filter['category']) && is_numeric($bulk_manager_filter['category'])
        and ! isset($bulk_manager_filter['category_recursive'])) {
        $is_category = true;
        $filter_category_id = (int) $bulk_manager_filter['category'];
    }

    // If using the 'duplicates' filter,
    // order by the fields that are used to find duplicates.
    if (isset($bulk_manager_filter['prefilter'])
        and $bulk_manager_filter['prefilter'] === 'duplicates'
        and isset($duplicates_on_fields) and is_array($duplicates_on_fields)) {
        // The $duplicates_on_fields variable is defined in ./batch_manager.php
        // (always a list of column-name strings when set); PHPStan can't see
        // across that include boundary, hence the is_array()/is_string() checks.
        $duplicates_on_fields = array_filter($duplicates_on_fields, 'is_string');
        $order_by_fields = array_merge($duplicates_on_fields, ['id']);
        $conf['order_by'] = ' ORDER BY ' . join(', ', $order_by_fields);
    }

    $query = '
SELECT id,path,representative_ext,file,filesize,level,name,width,height,rotation
  FROM ' . IMAGES_TABLE;

    if ($is_category) {
        $category_info = get_cat_info($filter_category_id);

        $conf['order_by'] = $conf['order_by_inside_category'];
        if (! empty($category_info['image_order']) && is_string($category_info['image_order'])) {
            $conf['order_by'] = ' ORDER BY ' . $category_info['image_order'];
        }

        $query .= '
    JOIN ' . IMAGE_CATEGORY_TABLE . ' ON id = image_id';
    }

    $query .= '
  WHERE id IN (' . implode(',', $cat_elements_id) . ')';

    if ($is_category) {
        $query .= '
    AND category_id = ' . $filter_category_id;
    }

    // $conf['order_by'] is always a string here: it's either loaded verbatim
    // from the piwigo_config DB table by load_conf_from_db() in
    // include/common.inc.php (install/config.sql always inserts this row),
    // or overwritten above with a string built from string concatenation.
    $order_by = is_string($conf['order_by']) ? $conf['order_by'] : '';

    $query .= '
  ' . $order_by . '
  LIMIT ' . $nb_images . ' OFFSET ' . $start . '
;';
    $result = pwg_query($query);

    $thumb_params = ImageStdParams::get_by_type(IMG_SQUARE);
    // template thumbnail initialization
    while ($row = pwg_db_fetch_assoc($result)) {
        $nb_thumbs_page++;
        $src_image = new SrcImage($row);

        $ttitle = render_element_name($row);
        $row_file = is_string($row['file']) ? $row['file'] : '';
        if ($ttitle != get_name_from_file($row_file)) {
            $ttitle .= ' (' . $row['file'] . ')';
        }

        $row_filesize = is_numeric($row['filesize']) ? (float) $row['filesize'] : 0.0;
        $ttitle .= '<br>' . $row['width'] . '&times;' . $row['height'] . ' pixels, ' . sprintf('%.2f', $row_filesize / 1024) . 'MB';

        $template->append(
            'thumbnails',
            array_merge(
                $row,
                [
                    'thumb' => new DerivativeImage($thumb_params, $src_image),
                    'TITLE' => $ttitle,
                    'FILE_SRC' => DerivativeImage::url(IMG_LARGE, $src_image),
                    'U_EDIT' => get_root_url() . 'admin.php?page=photo-' . $row['id'],
                ]
            )
        );
    }
    $template->assign('thumb_params', $thumb_params);
}

$template->assign([
    'nb_thumbs_page' => $nb_thumbs_page,
    'nb_thumbs_set' => count($cat_elements_id),
    'CACHE_KEYS' => get_admin_client_cache_keys(['tags', 'categories']),
]);

trigger_notify('loc_end_element_set_global');

// ----------------------------------------------------------- sending html code
$template->assign_var_from_handle('ADMIN_CONTENT', 'batch_manager_global');
