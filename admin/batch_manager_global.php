<?php

declare(strict_types=1);

use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageStdParams;
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

global $template, $user, $page, $persistent_cache, $lang, $logger, $pwg_loaded_plugins;


include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+

check_status(ACCESS_ADMINISTRATOR);

if (!empty($_POST)) {
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
// |                       global mode form submission                     |
// +-----------------------------------------------------------------------+

// $page['prefilter'] is a shortcut to test if the current filter contains a
// given prefilter. The idea is to make conditions simpler to write in the
// code.
$page['prefilter'] = 'none';
/** @var array<string, mixed> $bmf */
$bmf = is_array($_SESSION['bulk_manager_filter'] ?? null) ? $_SESSION['bulk_manager_filter'] : [];
if (is_string($bmf['prefilter'] ?? null)) {
    $page['prefilter'] = $bmf['prefilter'];
}

$redirect_url = get_root_url().'admin.php?page='.(is_scalar($_GET['page'] ?? null) ? (string) $_GET['page'] : '');

/** @var array<int> $collection_int */
$collection_int = array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $collection);

if (isset($_POST['submit'])) {
    // if the user tries to apply an action, it means that there is at least 1
    // photo in the selection
    if (count($collection_int) == 0) {
        \Piwigo\Core\PageState::current()->addError(l10n('Select at least one photo'));
    }

    $action = is_scalar($_POST['selectAction'] ?? null) ? (string) $_POST['selectAction'] : '';
    $redirect = false;

    if ('remove_from_caddie' == $action) {
        \Piwigo\Core\ServiceLocator::get(\Piwigo\Image\ImageRepository::class)
            ->deleteUserCaddieByImageIds(is_numeric($user['id']) ? (int) $user['id'] : 0, $collection_int);

        // remove from caddie action available only in caddie so reload content
        $redirect = true;
    } elseif ('add_tags' == $action) {
        if (empty($_POST['add_tags'])) {
            \Piwigo\Core\PageState::current()->addError(l10n('Select at least one tag'));
        } else {
            $add_tags_raw = $_POST['add_tags'];
            if (is_array($add_tags_raw)) {
                $add_tags_val = array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $add_tags_raw);
            } else {
                $add_tags_val = is_scalar($add_tags_raw) ? (string) $add_tags_raw : '';
            }
            $tag_ids = get_tag_ids($add_tags_val);
            add_tags($tag_ids, $collection_int);

            if ('no_tag' == $page['prefilter']) {
                $redirect = true;
            }
        }
    } elseif ('del_tags' == $action) {
        $del_tags_post = is_array($_POST['del_tags'] ?? null) ? $_POST['del_tags'] : [];
        /** @var array<int> $del_tags_int */
        $del_tags_int = array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $del_tags_post);
        if (count($del_tags_int) > 0) {
            $taglist_before = get_image_tag_ids($collection_int);

            \Piwigo\Core\ServiceLocator::get(\Piwigo\Tag\TagRepository::class)
                ->deleteImageTagsByImageIdsAndTagIds($collection_int, $del_tags_int);

            $taglist_after = get_image_tag_ids($collection_int);
            $images_to_update_raw = compare_image_tag_lists($taglist_before, $taglist_after);
            /** @var array<int> $images_to_update */
            $images_to_update = array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $images_to_update_raw);
            update_images_lastmodified($images_to_update);

            $bmf_tags_arr = is_array($bmf['tags'] ?? null) ? $bmf['tags'] : [];
            /** @var array<int> $bmf_tags_int */
            $bmf_tags_int = array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $bmf_tags_arr);
            if (count(array_intersect($bmf_tags_int, $del_tags_int)) > 0) {
                $redirect = true;
            }
        } else {
            \Piwigo\Core\PageState::current()->addError(l10n('Select at least one tag'));
        }
    }

    if ('associate' == $action) {
        if (empty($_POST['associate'])) {
            \Piwigo\Core\PageState::current()->addError(l10n('Select at least one album'));
        } else {
            $associate_raw = is_array($_POST['associate']) ? array_map(fn ($v) => is_numeric($v) ? (int) $v : 0, $_POST['associate']) : [];
            associate_images_to_categories(
                $collection_int,
                $associate_raw
            );

            $_SESSION['page_infos'] = [
              l10n('Information data registered in database'),
              ];

            // let's refresh the page because we the current set might be modified
            if ('no_album' == $page['prefilter']) {
                $redirect = true;
            } elseif ('no_virtual_album' == $page['prefilter']) {
                $associate_id = is_scalar($_POST['associate']) ? (string) $_POST['associate'] : '';
                $category_info = get_cat_info($associate_id);
                if (empty($category_info['dir'])) {
                    $redirect = true;
                }
            }
        }
    } elseif ('move' == $action) {
        $move_id = is_scalar($_POST['move'] ?? null) ? (string) $_POST['move'] : '';
        $move_id_int = is_numeric($move_id) ? (int) $move_id : 0;
        move_images_to_categories($collection_int, [$move_id_int]);

        $_SESSION['page_infos'] = [
          l10n('Information data registered in database'),
          ];

        // let's refresh the page because we the current set might be modified
        if ('no_album' == $page['prefilter']) {
            $redirect = true;
        } elseif ('no_virtual_album' == $page['prefilter']) {
            $category_info = get_cat_info($move_id);
            if (empty($category_info['dir'])) {
                $redirect = true;
            }
        } elseif (isset($bmf['category'])
            and $move_id != (is_scalar($bmf['category']) ? (string) $bmf['category'] : '')) {
            $redirect = true;
        }
    } elseif ('dissociate' == $action) {
        $dissociate_raw = is_scalar($_POST['dissociate'] ?? null) ? (string) $_POST['dissociate'] : '';
        $nb_dissociated = dissociate_images_from_category($collection_int, $dissociate_raw);

        if ($nb_dissociated > 0) {
            $_SESSION['page_infos'] = [
              l10n('Information data registered in database'),
              ];

            // let's refresh the page because the current set might be modified
            $redirect = true;
        }
    }

    // author
    elseif ('author' == $action) {
        if (isset($_POST['remove_author'])) {
            $_POST['author'] = null;
        }

        $datas = [];
        foreach ($collection_int as $image_id) {
            $datas[] = [
              'id' => $image_id,
              'author' => $_POST['author'],
              ];
        }

        mass_updates(
            IMAGES_TABLE,
            ['primary' => ['id'], 'update' => ['author']],
            $datas
        );

        pwg_activity('photo', $collection_int, 'edit', ['action' => 'author']);
    }

    // title
    elseif ('title' == $action) {
        if (isset($_POST['remove_title'])) {
            $_POST['title'] = null;
        }

        $datas = [];
        foreach ($collection_int as $image_id) {
            $datas[] = [
              'id' => $image_id,
              'name' => $_POST['title'],
              ];
        }

        mass_updates(
            IMAGES_TABLE,
            ['primary' => ['id'], 'update' => ['name']],
            $datas
        );

        pwg_activity('photo', $collection_int, 'edit', ['action' => 'title']);
    }

    // date_creation
    elseif ('date_creation' == $action) {
        if (isset($_POST['remove_date_creation']) || empty($_POST['date_creation'])) {
            $date_creation = null;
        } else {
            $date_creation = $_POST['date_creation'];
        }

        $datas = [];
        foreach ($collection_int as $image_id) {
            $datas[] = [
              'id' => $image_id,
              'date_creation' => $date_creation,
              ];
        }

        mass_updates(
            IMAGES_TABLE,
            ['primary' => ['id'], 'update' => ['date_creation']],
            $datas
        );

        pwg_activity('photo', $collection_int, 'edit', ['action' => 'date_creation']);
    }

    // privacy_level
    elseif ('level' == $action) {
        $datas = [];
        foreach ($collection_int as $image_id) {
            $datas[] = [
              'id' => $image_id,
              'level' => $_POST['level'],
              ];
        }

        mass_updates(
            IMAGES_TABLE,
            ['primary' => ['id'], 'update' => ['level']],
            $datas
        );

        pwg_activity('photo', $collection_int, 'edit', ['action' => 'privacy_level']);

        if (isset($bmf['level'])) {
            $bmf_level_val = is_numeric($bmf['level']) ? (int) $bmf['level'] : 0;
            $post_level_val = is_numeric($_POST['level'] ?? null) ? (int) $_POST['level'] : 0;
            if ($post_level_val < $bmf_level_val) {
                $redirect = true;
            }
        }
    }

    // add_to_caddie
    elseif ('add_to_caddie' == $action) {
        fill_caddie($collection_int);
    }

    // delete
    elseif ('delete' == $action) {
        if (isset($_POST['confirm_deletion']) and 1 == $_POST['confirm_deletion']) {
            // now done with ajax calls, with blocks
            // $deleted_count = delete_elements($collection, true);
            if (count($collection_int) > 0) {
                if (!is_array($_SESSION['page_infos'] ?? null)) {
                    $_SESSION['page_infos'] = [];
                }
                /** @var array<mixed> $page_infos_ref */
                $page_infos_ref = &$_SESSION['page_infos'];
                $page_infos_ref[] = l10n_dec(
                    '%d photo was deleted',
                    '%d photos were deleted',
                    count($collection_int)
                );

                $redirect_url = get_root_url().'admin.php?page='.(is_scalar($_GET['page'] ?? null) ? (string) $_GET['page'] : '');
                $redirect = true;
            } else {
                \Piwigo\Core\PageState::current()->addError(l10n('No photo can be deleted'));
            }
        } else {
            \Piwigo\Core\PageState::current()->addError(l10n('You need to confirm deletion'));
        }
    }

    // synchronize metadata
    elseif ('metadata' == $action) {
        \Piwigo\Core\PageState::current()->addInfo(l10n('Metadata synchronized from file').' <span class="badge">'.count($collection_int).'</span>');
    } elseif ('delete_derivatives' == $action && !empty($_POST['del_derivatives_type'])) {
        foreach (\Piwigo\Core\ServiceLocator::get(\Piwigo\Image\ImageRepository::class)
            ->findPathsAndRepresentativesByIds($collection_int) as $info) {
            $del_types = is_array($_POST['del_derivatives_type']) ? $_POST['del_derivatives_type'] : [];
            foreach ($del_types as $type) {
                $type_str = is_scalar($type) ? (string) $type : '';
                delete_element_derivatives($info, $type_str);
            }
        }
    } elseif ('generate_derivatives' == $action) {
        if ($_POST['regenerateSuccess'] != '0') {
            \Piwigo\Core\PageState::current()->addInfo(l10n('%s photos have been regenerated', $_POST['regenerateSuccess']));
        }
        if ($_POST['regenerateError'] != '0') {
            \Piwigo\Core\PageState::current()->addWarning(l10n('%s photos can not be regenerated', $_POST['regenerateError']));
        }
    }

    if (!in_array($action, ['remove_from_caddie','add_to_caddie','delete_derivatives','generate_derivatives'])) {
        invalidate_user_cache();
    }

    trigger_notify('element_set_global_action', $action, $collection_int);

    if ($redirect) {
        redirect($redirect_url);
    }
}

// +-----------------------------------------------------------------------+
// |                             template init                             |
// +-----------------------------------------------------------------------+
$template->set_filenames(['batch_manager_global' => 'batch_manager_global.tpl']);

$base_url = get_root_url().'admin.php';

include(PHPWG_ROOT_PATH.'admin/include/batch_manager_filters.inc.php');

// +-----------------------------------------------------------------------+
// |                            caddie options                             |
// +-----------------------------------------------------------------------+
$template->assign('IN_CADDIE', 'caddie' == $page['prefilter']);

// +-----------------------------------------------------------------------+
// |                           global mode form                            |
// +-----------------------------------------------------------------------+

if (count($page['cat_elements_id']) > 0) {
    // remove tags
    $template->assign('associated_tags', get_common_tags($page['cat_elements_id'], -1));
}

// creation date
$template->assign(
    'DATE_CREATION',
    empty($_POST['date_creation']) ? date('Y-m-d').' 00:00:00' : $_POST['date_creation']
);

// image level options
$template->assign(
    [
      'level_options' => get_privacy_level_options(),
      'level_options_selected' => 0,
    ]
);

// metadata
include_once(PHPWG_ROOT_PATH.'admin/site_reader_local.php');
$site_reader = new LocalSiteReader('./');
$used_metadata = implode(', ', array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $site_reader->get_metadata_attributes()));

$template->assign(
    [
      'used_metadata' => $used_metadata,
    ]
);

//derivatives
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
if (!empty($_GET['display'])) {
    if ('all' == $_GET['display']) {
        $page['nb_images'] = count($page['cat_elements_id']);
    } else {
        $page['nb_images'] = is_numeric($_GET['display']) ? (int) $_GET['display'] : 20;
    }
} elseif (in_array(\Piwigo\Config\Config::batchManagerImagesPerPageGlobal(), [20, 50, 100])) {
    $page['nb_images'] = \Piwigo\Config\Config::batchManagerImagesPerPageGlobal();
} else {
    $page['nb_images'] = 20;
}

$nb_thumbs_page = 0;

if (count($page['cat_elements_id']) > 0) {
    $nav_bar = create_navigation_bar(
        $base_url.get_query_string_diff(['start']),
        count($page['cat_elements_id']),
        $page['start'],
        $page['nb_images']
    );
    $template->assign('navbar', $nav_bar);

    $is_category = false;
    if (isset($bmf['category'])
        and !isset($bmf['category_recursive'])) {
        $is_category = true;
    }
    $bmf_category_val = is_numeric($bmf['category'] ?? null) ? (int) $bmf['category'] : 0;

    // If using the 'duplicates' filter,
    // order by the fields that are used to find duplicates.
    if (is_string($bmf['prefilter'] ?? null)
        and 'duplicates' === $bmf['prefilter']
        and isset($duplicates_on_fields)) {
        // The $duplicates_on_fields variable is defined in ./batch_manager.php
        $order_by_fields = array_merge($duplicates_on_fields, [ 'id' ]);
        \Piwigo\Config\Config::override('order_by', ' ORDER BY '.join(', ', $order_by_fields));
    }

    $query = '
SELECT id,path,representative_ext,file,filesize,level,name,width,height,rotation
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
    $batchRows = \Piwigo\Core\ServiceLocator::get(\Doctrine\DBAL\Connection::class)
        ->executeQuery($query)
        ->fetchAllAssociative();

    $thumb_params = ImageStdParams::get_by_type(IMG_SQUARE);
    // template thumbnail initialization
    foreach ($batchRows as $row) {
        $nb_thumbs_page++;
        $src_image = new SrcImage($row);

        $ttitle = render_element_name($row);
        $row_file = is_scalar($row['file'] ?? null) ? (string) $row['file'] : '';
        if ($ttitle != get_name_from_file($row_file)) {
            $ttitle .= ' ('.$row_file.')';
        }

        $row_filesize = is_numeric($row['filesize'] ?? null) ? (float) $row['filesize'] : 0.0;
        $ttitle .= '<br>'.$row['width'].'&times;'.$row['height'].' pixels, '.sprintf('%.2f', $row_filesize / 1024).'MB';

        $template->append(
            'thumbnails',
            array_merge(
                $row,
                [
        'thumb' => new DerivativeImage($thumb_params, $src_image),
        'TITLE' => $ttitle,
        'FILE_SRC' => DerivativeImage::url(IMG_LARGE, $src_image),
        'U_EDIT' => get_root_url().'admin.php?page=photo-'.$row['id'],
        ]
            )
        );
    }
    $template->assign('thumb_params', $thumb_params);
}

$cache_keys = get_admin_client_cache_keys(['tags', 'categories']);
$batch_manager_global_page_data = [
  'CACHE_KEYS' => $cache_keys,
  'ROOT_URL' => get_root_url(),
  'associated_categories' => $associated_categories ?? [],
  'str_create' => l10n('Create'),
  'nb_thumbs_page' => $nb_thumbs_page,
  'nb_thumbs_set' => count($page['cat_elements_id']),
  'all_elements' => $page['cat_elements_id'],
  'lang' => [
    'Cancel'                => l10n('Cancel'),
    'deleteProgressMessage' => l10n('Deletion in progress'),
    'syncProgressMessage'   => l10n('Synchronization in progress'),
    'AreYouSure'            => l10n('Are you sure?'),
    'generateMsg'           => l10n('Generate multiple size images'),
  ],
  'str_add_alb_associate'  => l10n('Add Album'),
  'str_select_alb_associate' => l10n('Select an album'),
  'applyOnDetails_pattern' => l10n('on the %d selected photos'),
  'selectedMessage_pattern' => l10n('%d of %d photos selected'),
  'selectedMessage_none'   => l10n('No photo selected, %d photos in current set'),
  'selectedMessage_all'    => l10n('All %d photos are selected'),
];

$template->assign([
  'nb_thumbs_page' => $nb_thumbs_page,
  'nb_thumbs_set' => count($page['cat_elements_id']),
  'CACHE_KEYS' => $cache_keys,
  'batch_manager_global_page_data_json' => json_encode($batch_manager_global_page_data),
  ]);

trigger_notify('loc_end_element_set_global');

//----------------------------------------------------------- sending html code
$template->assign_var_from_handle('ADMIN_CONTENT', 'batch_manager_global');
