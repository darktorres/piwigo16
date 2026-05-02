<?php

declare(strict_types=1);

use Piwigo\Admin\Image\pwg_image;
use Piwigo\Admin\tabsheet;
use Piwigo\Image\ImageStdParams;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

if (!defined('PHPWG_ROOT_PATH')) {
    die('Hacking attempt!');
}

global $template, $user, $page, $persistent_cache, $lang;


if (!is_webmaster()) {
    \Piwigo\Core\PageState::current()->addWarning(str_replace('%s', l10n('user_status_webmaster'), l10n('%s status is required to edit parameters.')));
}

include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');
include_once(PHPWG_ROOT_PATH.'admin/include/functions_upload.inc.php');

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
check_status(ACCESS_ADMINISTRATOR);

//-------------------------------------------------------- sections definitions

check_input_parameter('section', $_GET, false, '/^[a-z]+$/i');

if (!isset($_GET['section'])) {
    $page['section'] = 'main';
} else {
    $page['section'] = $_GET['section'];
}

$main_checkboxes = [
    'allow_user_registration',
    'obligatory_user_mail_address',
    'rate',
    'rate_anonymous',
    'allow_user_customization',
    'log',
    'history_admin',
    'history_guest',
    'show_mobile_app_banner_in_gallery',
    'show_mobile_app_banner_in_admin',
    'upload_detect_duplicate',
   ];

$sizes_checkboxes = [
    'original_resize',
  ];

$comments_checkboxes = [
    'activate_comments',
    'comments_forall',
    'comments_validation',
    'email_admin_on_comment',
    'email_admin_on_comment_validation',
    'user_can_delete_comment',
    'user_can_edit_comment',
    'email_admin_on_comment_edition',
    'email_admin_on_comment_deletion',
    'comments_author_mandatory',
    'comments_email_mandatory',
    'comments_enable_website',
  ];

$display_checkboxes = [
    'menubar_filter_icon',
    'index_search_in_set_button',
    'index_search_in_set_action',
    'index_sort_order_input',
    'index_flat_icon',
    'index_posted_date_icon',
    'index_created_date_icon',
    'index_slideshow_icon',
    'index_sizes_icon',
    'index_new_icon',
    'index_edit_icon',
    'index_caddie_icon',
    'display_fromto',
    'picture_metadata_icon',
    'picture_slideshow_icon',
    'picture_favorite_icon',
    'picture_sizes_icon',
    'picture_download_icon',
    'picture_edit_icon',
    'picture_caddie_icon',
    'picture_representative_icon',
    'picture_navigation_icons',
    'picture_navigation_thumb',
    'picture_menu',
  ];

$display_info_checkboxes = [
    'author',
    'created_on',
    'posted_on',
    'dimensions',
    'file',
    'filesize',
    'tags',
    'categories',
    'visits',
    'rating_score',
  ];

if (!\Piwigo\Core\Config::has('filters_views')) {
    \Piwigo\Core\Config::persist('filters_views', \Piwigo\Core\Config::defaultFiltersViews());
}

$filters_views_raw = safe_unserialize(\Piwigo\Core\Config::filtersViews() ?? '');
$filters_names_checkboxes = array_values(array_diff(array_keys($filters_views_raw), ['last_filters_conf']));

// image order management
$sort_fields = [
  ''                    => '',
  'file ASC'            => l10n('File name, A &rarr; Z'),
  'file DESC'           => l10n('File name, Z &rarr; A'),
  'name ASC'            => l10n('Photo title, A &rarr; Z'),
  'name DESC'           => l10n('Photo title, Z &rarr; A'),
  'date_creation DESC'  => l10n('Date created, new &rarr; old'),
  'date_creation ASC'   => l10n('Date created, old &rarr; new'),
  'date_available DESC' => l10n('Date posted, new &rarr; old'),
  'date_available ASC'  => l10n('Date posted, old &rarr; new'),
  'rating_score DESC'   => l10n('Rating score, high &rarr; low'),
  'rating_score ASC'    => l10n('Rating score, low &rarr; high'),
  'hit DESC'            => l10n('Visits, high &rarr; low'),
  'hit ASC'             => l10n('Visits, low &rarr; high'),
  'id ASC'              => l10n('Numeric identifier, 1 &rarr; 9'),
  'id DESC'             => l10n('Numeric identifier, 9 &rarr; 1'),
  '`rank` ASC'          => l10n('Manual sort order'),
  ];

$comments_order = [
  'ASC' => l10n('Show oldest comments first'),
  'DESC' => l10n('Show latest comments first'),
  ];

$mail_themes = [
  'clear' => 'Clear',
  'dark' => 'Dark',
  ];

//------------------------------ verification and registration of modifications
if (isset($_POST['submit'])) {
    check_pwg_token();
    $int_pattern = '/^\d+$/';

    switch ($page['section']) {
        case 'main':
            {
                if (!\Piwigo\Core\Config::has('order_by_custom') and !\Piwigo\Core\Config::has('order_by_inside_category_custom')) {
                    if (!empty($_POST['order_by'])) {
                        check_input_parameter('order_by', $_POST, true, '/^('.implode('|', array_keys($sort_fields)).')$/');

                        $post_order_by = is_array($_POST['order_by']) ? $_POST['order_by'] : [];
                        $used = [];
                        foreach ($post_order_by as $i => $val) {
                            $val_str = is_scalar($val) ? (string) $val : '';
                            if (empty($val_str) or isset($used[$val_str])) {
                                unset($post_order_by[$i]);
                            } else {
                                $used[$val_str] = true;
                            }
                        }
                        $_POST['order_by'] = $post_order_by;
                        if (!count($post_order_by)) {
                            \Piwigo\Core\PageState::current()->addError(l10n('No order field selected'));
                        } else {
                            // limit to the number of available parameters
                            $order_by = $order_by_inside_category = array_slice($post_order_by, 0, (int) ceil(count($sort_fields) / 2));

                            // there is no rank outside categories
                            if (($i = array_search('`rank` ASC', $order_by)) !== false) {
                                unset($order_by[$i]);
                            }

                            // must define a default order_by if user want to order by rank only
                            if (count($order_by) == 0) {
                                $order_by = ['id ASC'];
                            }

                            $_POST['order_by'] = 'ORDER BY '.implode(', ', array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $order_by));
                            $_POST['order_by_inside_category'] = 'ORDER BY '.implode(', ', array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $order_by_inside_category));
                        }
                    } else {
                        \Piwigo\Core\PageState::current()->addError(l10n('No order field selected'));
                    }
                }

                if (empty($_POST['email_admin_on_new_user'])) {
                    $_POST['email_admin_on_new_user'] = 'none';
                } elseif ('all' == $_POST['email_admin_on_new_user_filter']) {
                    $_POST['email_admin_on_new_user'] = 'all';
                } else {
                    if (empty($_POST['email_admin_on_new_user_filter_group'])) {
                        $_POST['email_admin_on_new_user'] = 'all';
                    } else {
                        $_POST['email_admin_on_new_user'] = 'group:'.(is_scalar($_POST['email_admin_on_new_user_filter_group']) ? (string) $_POST['email_admin_on_new_user_filter_group'] : '');
                    }
                }

                foreach ($main_checkboxes as $checkbox) {
                    $_POST[$checkbox] = empty($_POST[$checkbox]) ? 'false' : 'true';
                }
                break;
            }
        case 'watermark':
            {
                include(PHPWG_ROOT_PATH.'admin/include/configuration_watermark_process.inc.php');
                break;
            }
        case 'sizes':
            {
                include(PHPWG_ROOT_PATH.'admin/include/configuration_sizes_process.inc.php');
                break;
            }
        case 'comments':
            {
                // the number of comments per page must be an integer between 5 and 50
                // included
                if (!preg_match($int_pattern, is_scalar($_POST['nb_comment_page']) ? (string) $_POST['nb_comment_page'] : '')
                     or (is_numeric($_POST['nb_comment_page']) && $_POST['nb_comment_page'] < 5)
                     or (is_numeric($_POST['nb_comment_page']) && $_POST['nb_comment_page'] > 50)) {
                    \Piwigo\Core\PageState::current()->addError(l10n('The number of comments a page must be between 5 and 50 included.'));
                }
                foreach ($comments_checkboxes as $checkbox) {
                    $_POST[$checkbox] = empty($_POST[$checkbox]) ? 'false' : 'true';
                }
                break;
            }
        case 'default':
            {
                // Never go here
                break;
            }
        case 'display':
            {
                if (!preg_match($int_pattern, is_scalar($_POST['nb_categories_page']) ? (string) $_POST['nb_categories_page'] : '')
                      or (is_numeric($_POST['nb_categories_page']) && $_POST['nb_categories_page'] < 4)) {
                    \Piwigo\Core\PageState::current()->addError(l10n('The number of albums a page must be above 4.'));
                }
                foreach ($display_checkboxes as $checkbox) {
                    $_POST[$checkbox] = empty($_POST[$checkbox]) ? 'false' : 'true';
                }
                $post_picture_informations = is_array($_POST['picture_informations'] ?? null) ? $_POST['picture_informations'] : [];
                foreach ($display_info_checkboxes as $checkbox) {
                    $post_picture_informations[$checkbox] =
                      empty($post_picture_informations[$checkbox]) ? false : true;
                }
                $_POST['picture_informations'] = addslashes(serialize($post_picture_informations));
                break;
            }
        case 'search':
            {
                $post_filters_views = is_array($_POST['filters_views'] ?? null) ? $_POST['filters_views'] : [];
                $post_filters_views_box = is_array($_POST['filters_views_box'] ?? null) ? $_POST['filters_views_box'] : [];
                foreach ($filters_names_checkboxes as $checkbox) {
                    $fv_entry = is_array($post_filters_views[$checkbox] ?? null) ? $post_filters_views[$checkbox] : [];
                    if (empty($post_filters_views_box[$checkbox])) {
                        $fv_entry['access'] = 'nobody';
                        $fv_entry['default'] = false;
                    } else {
                        $fv_entry['default'] = empty($fv_entry['default']) ? false : true;
                    }
                    $post_filters_views[$checkbox] = $fv_entry;
                }
                $post_filters_views['last_filters_conf'] =
                  empty($post_filters_views['last_filters_conf']) ? false : true;
                $_POST['filters_views'] = addslashes(serialize($post_filters_views));
            }
    }

    // updating configuration if no error found
    if (!in_array($page['section'], ['sizes', 'watermark']) and count($page['errors']) == 0 and is_webmaster()) {
        //echo '<pre>'; print_r($_POST); echo '</pre>';
        $result = pwg_query('SELECT param FROM '.CONFIG_TABLE);
        while ($row = pwg_db_fetch_assoc($result)) {
            $row_param = is_scalar($row['param']) ? (string) $row['param'] : '';
            if (isset($_POST[$row_param])) {
                $value = is_scalar($_POST[$row_param]) ? (string) $_POST[$row_param] : '';

                if ('gallery_title' == $row_param) {
                    if (!\Piwigo\Core\Config::allowHtmlDescriptions()) {
                        $value = strip_tags($value);
                    }
                }

                $query = '
UPDATE '.CONFIG_TABLE.'
SET value = \''. str_replace("\'", "''", $value).'\'
WHERE param = \''.$row_param.'\'
;';
                pwg_query($query);
            }
        }
        $template->assign(
            [
            'save_success' => l10n('Your configuration settings are saved'),
      ]
        );

        pwg_activity('system', ACTIVITY_SYSTEM_CORE, 'config', ['config_section' => $page['section']]);
    }

    //------------------------------------------------------ $conf reinitialization
    load_conf_from_db();
}

// restore default derivatives settings
if ('sizes' == $page['section'] and isset($_GET['action']) and 'restore_settings' == $_GET['action']) {
    ImageStdParams::restore_default();
    clear_derivative_cache();

    // reset conf
    load_conf_from_db();

    $template->assign(
        [
        'save_success' => l10n('Your configuration settings are saved'),
    ]
    );

    pwg_activity('system', ACTIVITY_SYSTEM_CORE, 'config', ['config_section' => $page['section'],'config_action' => $_GET['action']]);
}

//----------------------------------------------------- template initialization
$section_str = is_scalar($page['section']) ? (string) $page['section'] : 'main';
$template->set_filename('config', 'configuration_' . $section_str . '.tpl');

// TabSheet
$tabsheet = new tabsheet();
$tabsheet->set_id('configuration');
$tabsheet->select($section_str);
$tabsheet->assign();

$action = get_root_url().'admin.php?page=configuration';
$action .= '&amp;section='.$section_str;

$template->assign(
    [
    'U_HELP' => get_root_url().'admin/popuphelp.php?page=configuration',
    'PWG_TOKEN' => get_pwg_token(),
    'F_ACTION' => $action,
    ]
);

switch ($page['section']) {
    case 'main':
        {

            function order_by_is_local(): bool
            {
                $conf = [];
                include(PHPWG_ROOT_PATH . 'include/config_default.inc.php');
                @include(PHPWG_ROOT_PATH. 'local/config/config.inc.php');
                if (\Piwigo\Core\Config::has('local_dir_site')) {
                    @include(PHPWG_ROOT_PATH.PWG_LOCAL_DIR. 'config/config.inc.php');
                }

                return \Piwigo\Core\Config::has('order_by') or \Piwigo\Core\Config::has('order_by_inside_category');
            }

            if (order_by_is_local()) {
                \Piwigo\Core\PageState::current()->addWarning(l10n('You have specified <i>' . '$' . 'conf[\'order_by\']</i> in your local configuration file, this parameter in deprecated, please remove it or rename it into <i>' . '$' . 'conf[\'order_by_custom\']</i> !'));
            }

            if (\Piwigo\Core\Config::has('order_by_custom') or \Piwigo\Core\Config::has('order_by_inside_category_custom')) {
                $order_by = [''];
                $template->assign('ORDER_BY_IS_CUSTOM', true);
            } else {
                $out = [];
                $order_by = trim((string) \Piwigo\Core\Config::orderByInsideCategory());
                $order_by = str_replace('ORDER BY ', '', $order_by);
                $order_by = explode(', ', $order_by);
            }

            $template->assign(
                'main',
                [
                'CONF_GALLERY_TITLE' => htmlspecialchars((string) \Piwigo\Core\Config::galleryTitle()),
                'CONF_PAGE_BANNER' => htmlspecialchars((string) \Piwigo\Core\Config::pageBanner()),
                'week_starts_on_options' => [
                  'sunday' => $lang['day'][0],
                  'monday' => $lang['day'][1],
                  ],
                'week_starts_on_options_selected' => \Piwigo\Core\Config::weekStartsOn(),
                'mail_theme' => \Piwigo\Core\Config::mailTheme(),
                'mail_theme_options' => $mail_themes,
                'order_by' => $order_by,
                'order_by_options' => $sort_fields,
                'email_admin_on_new_user' => 'none' != \Piwigo\Core\Config::emailAdminOnNewUser(),
                'email_admin_on_new_user_filter' => in_array(\Piwigo\Core\Config::emailAdminOnNewUser(), ['none', 'all']) ? 'all' : 'group',
                'email_admin_on_new_user_filter_group' => preg_match('/^group:(\d+)$/', \Piwigo\Core\Config::emailAdminOnNewUser(), $matches) ? $matches[1] : -1,
                ]
            );

            // list of groups
            $query = '
    SELECT
        id,
        name
      FROM `'.GROUPS_TABLE.'`
    ;';
            $groups = query2array($query, 'id', 'name');
            natcasesort($groups);

            $template->assign(
                [
                'group_options' => $groups,
                ]
            );

            foreach ($main_checkboxes as $checkbox) {
                $template->append(
                    'main',
                    [
                      $checkbox => \Piwigo\Core\Config::get($checkbox),
                      ],
                    true
                );
            }

            $template->assign('page_data_json', json_encode([
                'order_by_is_custom' => \Piwigo\Core\Config::has('order_by_custom') || \Piwigo\Core\Config::has('order_by_inside_category_custom'),
                'order_by_options_count' => count($sort_fields),
            ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));
            break;
        }
    case 'comments':
        {
            $template->assign(
                'comments',
                [
                'NB_COMMENTS_PAGE' => \Piwigo\Core\Config::nbCommentPage(),
                'comments_order' => \Piwigo\Core\Config::commentsOrder(),
                'comments_order_options' => $comments_order,
                ]
            );

            foreach ($comments_checkboxes as $checkbox) {
                $template->append(
                    'comments',
                    [
                      $checkbox => \Piwigo\Core\Config::get($checkbox),
                      ],
                    true
                );
            }
            break;
        }
    case 'default':
        {
            $edit_user = build_user(\Piwigo\Core\Config::guestId(), false);
            include_once(PHPWG_ROOT_PATH.'profile.php');

            $errors = [];
            if (save_profile_from_post($edit_user, $errors)) {
                // Reload user
                $edit_user = build_user(\Piwigo\Core\Config::guestId(), false);
                \Piwigo\Core\PageState::current()->addInfo(l10n('Information data registered in database'));
            }
            $page['errors'] = array_merge($page['errors'], $errors);

            load_profile_in_template(
                $action,
                '',
                $edit_user,
                'GUEST_'
            );
            $template->assign('default', []);
            break;
        }
    case 'display':
        {
            foreach ($display_checkboxes as $checkbox) {
                $template->append(
                    'display',
                    [
                      $checkbox => \Piwigo\Core\Config::get($checkbox),
                      ],
                    true
                );
            }
            $template->append(
                'display',
                [
                  'picture_informations' => @unserialize(is_string(\Piwigo\Core\Config::pictureInformations()) ? \Piwigo\Core\Config::pictureInformations() : ''),
                  'NB_CATEGORIES_PAGE' => \Piwigo\Core\Config::nbCategoriesPage(),
                  ],
                true
            );
            break;
        }
    case 'sizes':
        {
            // we only load the derivatives if it was not already loaded: it occurs
            // when submitting the form and an error remains
            if (!isset($page['sizes_loaded_in_tpl'])) {
                $is_gd = (pwg_image::get_library() == 'gd') ? true : false;
                $template->assign('is_gd', $is_gd);
                $template->assign(
                    'sizes',
                    [
                    'original_resize_maxwidth' => \Piwigo\Core\Config::originalResizeMaxwidth(),
                    'original_resize_maxheight' => \Piwigo\Core\Config::originalResizeMaxheight(),
                    'original_resize_quality' => \Piwigo\Core\Config::originalResizeQuality(),
                    ]
                );

                foreach ($sizes_checkboxes as $checkbox) {
                    $template->append(
                        'sizes',
                        [
                        $checkbox => \Piwigo\Core\Config::get($checkbox),
                        ],
                        true
                    );
                }

                // derivatives = multiple size
                $enabled = ImageStdParams::get_defined_type_map();
                $disabled_raw = safe_unserialize(ImageStdParams::get_disabled_type_map());
                $disabled = $disabled_raw;

                $tpl_vars = [];
                foreach (ImageStdParams::get_all_types() as $type) {
                    $tpl_var = [];

                    $tpl_var['must_square'] = ($type == IMG_SQUARE ? true : false);
                    $tpl_var['must_enable'] = ($type == IMG_SQUARE || $type == IMG_THUMB || $type == \Piwigo\Core\Config::derivativeDefaultSize()) ? true : false;

                    $params = $enabled[$type] ?? null;
                    if ($params !== null) {
                        $tpl_var['enabled'] = true;
                    } else {
                        $tpl_var['enabled'] = false;
                        $params_raw = $disabled[$type] ?? null;
                        $params = ($params_raw instanceof \Piwigo\Image\DerivativeParams) ? $params_raw : null;
                    }

                    if ($params instanceof \Piwigo\Image\DerivativeParams) {
                        [$tpl_var['w'], $tpl_var['h']] = $params->sizing->ideal_size;
                        if (($tpl_var['crop'] = round(100 * $params->sizing->max_crop)) > 0) {
                            [$tpl_var['minw'], $tpl_var['minh']] = $params->sizing->min_size;
                        } else {
                            $tpl_var['minw'] = $tpl_var['minh'] = '';
                        }
                        $tpl_var['sharpen'] = $params->sharpen;
                    }
                    $tpl_vars[$type] = $tpl_var;
                }
                $template->assign('derivatives', $tpl_vars);
                $template->assign('resize_quality', ImageStdParams::$quality);

                $tpl_vars = [];
                $now = time();
                foreach (ImageStdParams::$custom as $custom => $time) {
                    $time_int = is_numeric($time) ? (int) $time : 0;
                    $tpl_vars[$custom] = ($now - $time_int <= 24 * 3600) ? l10n('today') : time_since($time_int, 'day');
                }
                $template->assign('custom_derivatives', $tpl_vars);
            }

            break;
        }
    case 'watermark':
        {
            $watermark_files = [];
            foreach (glob(PHPWG_ROOT_PATH.'themes/default/watermarks/*.png') ?: [] as $file) {
                $watermark_files[] = substr($file, strlen(PHPWG_ROOT_PATH));
            }
            if (($glob = glob(PHPWG_ROOT_PATH.PWG_LOCAL_DIR.'watermarks/*.png')) !== false) {
                foreach ($glob as $file) {
                    $watermark_files[] = substr($file, strlen(PHPWG_ROOT_PATH));
                }
            }
            $watermark_filemap = [ '' => '---' ];
            foreach ($watermark_files as $file) {
                $display = basename($file);
                $watermark_filemap[$file] = $display;
            }
            $template->assign('watermark_files', $watermark_filemap);

            if ($template->get_template_vars('watermark') === null) {
                $wm = ImageStdParams::get_watermark();

                $position = 'custom';
                if ($wm->xpos == 0 and $wm->ypos == 0) {
                    $position = 'topleft';
                }
                if ($wm->xpos == 100 and $wm->ypos == 0) {
                    $position = 'topright';
                }
                if ($wm->xpos == 50 and $wm->ypos == 50) {
                    $position = 'middle';
                }
                if ($wm->xpos == 0 and $wm->ypos == 100) {
                    $position = 'bottomleft';
                }
                if ($wm->xpos == 100 and $wm->ypos == 100) {
                    $position = 'bottomright';
                }

                if ($wm->xrepeat != 0 || $wm->yrepeat != 0) {
                    $position = 'custom';
                }

                $template->assign(
                    'watermark',
                    [
                    'file' => $wm->file,
                    'minw' => $wm->min_size[0],
                    'minh' => $wm->min_size[1],
                    'xpos' => $wm->xpos,
                    'ypos' => $wm->ypos,
                    'xrepeat' => $wm->xrepeat,
                    'yrepeat' => $wm->yrepeat,
                    'opacity' => $wm->opacity,
                    'position' => $position,
                    ]
                );
            }

            $template->assign('page_data_json', json_encode([
                'root_url' => get_root_url(),
            ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));

            break;
        }
    case 'search':
        {
            $template->assign(
                'search',
                [
                  'filters_views' => safe_unserialize(\Piwigo\Core\Config::filtersViews() ?? ''),
                  'filters_names' => $filters_names_checkboxes,
                ],
            );
            $template->assign('SHOW_FILTER_RATINGS', \Piwigo\Core\Config::rateEnabled());
        }
}

$template->assign('isWebmaster', (is_webmaster()) ? 1 : 0);
$template->assign('ADMIN_PAGE_TITLE', l10n('Configuration'));

//----------------------------------------------------------- sending html code
$template->assign_var_from_handle('ADMIN_CONTENT', 'config');
