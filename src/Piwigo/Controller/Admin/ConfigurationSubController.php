<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\Image\pwg_image;
use Piwigo\Admin\tabsheet;
use Piwigo\Core\ActivitySystem;
use Piwigo\Db\Tables;
use Piwigo\Image\DerivativeParams;
use Piwigo\Image\ImageStdParams;
use Piwigo\Template\Template;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/configuration.php (page slug "configuration") -- a large
 * tabbed page (main/watermark/sizes/comments/default/display/search),
 * folded directly into this controller -- same shape as every prior P23
 * batch 6 sub-batch's shell folding. Its own `?section=` tab dispatch
 * stays inline (matches plugins.php/themes.php's own tab-dispatch shape,
 * confirmed too deeply tied to this single file's local $page['section']
 * switch to be worth splitting into 7 separate sub-controllers).
 *
 * admin.php itself already gates every page behind
 * check_status(AccessLevel::Administrator) before dispatch (admin.php:65),
 * so the original file's own (redundant, same level) check_status() call
 * is dropped here -- same precedent as MaintenanceSubController/
 * IntroSubController.
 *
 * Real write paths verified during this batch: the "watermark"/"sizes"
 * tabs delegate to admin/include/configuration_{watermark,sizes}_process.
 * inc.php, both already writing through typed abstractions
 * (ImageStdParams::save()/set_and_save(), UploadService::
 * saveUploadFormConfig()) with no raw SQL. The "default" tab's
 * build_user()/save_profile_from_post() calls are task #343's own
 * already-closed scope -- the same include/profile_functions.inc.php
 * pair the standalone admin/profile.php page once shared before P23
 * batch 6c deleted it as upstream-dead code (bug:3122, 2014: upstream
 * folded "edit a user's profile" into this file's own "default" tab
 * years ago, never as a separate admin page). The one remaining
 * generic-config-row UPDATE loop already double-quotes its value
 * (str_replace("\'", "''", ...)) before splicing it into SQL -- safe,
 * just stylistically raw; left as-is rather than routed through
 * ConfigService (Doctrine ORM + container-injected, unlike every other
 * P21 service so far, which are plain-DBAL and self-construct inline --
 * introducing that plumbing for an already-safe write path isn't
 * proportionate to this batch).
 *
 * This batch also fixed a real, verified bug in this file: $lang['day']
 * is never actually defined by any language/*\/common.lang.php (confirmed
 * across every locale) nor any runtime code, so the direct (unguarded)
 * read on the "main" tab threw "Undefined array key" -- fixed with the
 * same ?? guard already used for this exact key elsewhere (admin/intro.php,
 * format_date_legacy() in include/functions.inc.php).
 *
 * P23 batch 6j-3 fixed a real, previously-uncaught CSRF gap: the "sizes"
 * tab's "Reset to default values" action (`?action=restore_settings`,
 * resets ImageStdParams to Piwigo's built-in defaults) had zero
 * check_pwg_token() *and* zero is_webmaster() gate, unlike every other
 * write path in this file (the main POST-save loop and both process
 * includes each check is_webmaster()). Fixed by gating the whole block on
 * is_webmaster() (matching the sibling process-includes' own shape) plus
 * check_pwg_token(); the template's own link now carries the token too
 * (see admin/themes/default/template/configuration_sizes.tpl).
 *
 * order_by_is_local() was a top-level function declared inside this
 * file's own 'main' case with zero external callers (confirmed via a
 * direct grep) -- folded into a private static method here. This is a
 * real correctness fix, not just style: once this switch lives inside a
 * reusable class method instead of a raw top-level include, a second
 * same-process call to handle() with section=main would otherwise fatal
 * with "Cannot redeclare function order_by_is_local()" -- the same risk
 * already converted away from by AlbumsPageRenderer/IntroSubController.
 */
final class ConfigurationSubController implements AdminSubControllerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        /**
         * @var array<string, mixed> $conf
         * @var array<string, mixed> $lang
         * @var Template $template
         */
        global $conf, $lang, $template;

        // $page['errors']/['warnings']/['infos'] are always initialized to [] by
        // include/common.inc.php, but that isn't visible across the include()
        // boundary -- narrow once here so every top-level append below type-checks.
        /** @var array<string, mixed> $page */
        global $page;
        if (! is_array($page['errors'] ?? null)) {
            $page['errors'] = [];
        }
        if (! is_array($page['warnings'] ?? null)) {
            $page['warnings'] = [];
        }
        if (! is_array($page['infos'] ?? null)) {
            $page['infos'] = [];
        }

        if (! is_webmaster()) {
            $page['warnings'][] = str_replace('%s', l10n('user_status_webmaster'), l10n('%s status is required to edit parameters.'));
        }

        include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
        include_once PHPWG_ROOT_PATH . 'admin/include/functions_upload.inc.php';

        // -------------------------------------------------------- sections definitions

        check_input_parameter('section', $_GET, false, '/^[a-z]+$/i');

        // check_input_parameter() above fatal_error()s unless $_GET['section'] is a
        // scalar matching /^[a-z]+$/i, but that guarantee isn't visible to static
        // analysis; re-derive it into a definite string (query string values from
        // $_GET are always strings in practice, never int/float/bool).
        if (! isset($_GET['section']) or ! is_string($_GET['section'])) {
            $page_section = 'main';
        } else {
            $page_section = $_GET['section'];
        }
        $page['section'] = $page_section;

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

        if (! isset($conf['filters_views'])) {
            conf_update_param('filters_views', $conf['default_filters_views'], true);
        }

        $filters_views_raw = $conf['filters_views'];
        $filters_views_unserialized = (is_array($filters_views_raw) or is_string($filters_views_raw))
            ? safe_unserialize($filters_views_raw)
            : [];
        $filters_views_default = is_array($filters_views_unserialized) ? $filters_views_unserialized : [];
        $filters_names_checkboxes = array_values(array_diff(array_keys($filters_views_default), ['last_filters_conf']));

        // image order management
        $sort_fields = [
            '' => '',
            'file ASC' => l10n('File name, A &rarr; Z'),
            'file DESC' => l10n('File name, Z &rarr; A'),
            'name ASC' => l10n('Photo title, A &rarr; Z'),
            'name DESC' => l10n('Photo title, Z &rarr; A'),
            'date_creation DESC' => l10n('Date created, new &rarr; old'),
            'date_creation ASC' => l10n('Date created, old &rarr; new'),
            'date_available DESC' => l10n('Date posted, new &rarr; old'),
            'date_available ASC' => l10n('Date posted, old &rarr; new'),
            'rating_score DESC' => l10n('Rating score, high &rarr; low'),
            'rating_score ASC' => l10n('Rating score, low &rarr; high'),
            'hit DESC' => l10n('Visits, high &rarr; low'),
            'hit ASC' => l10n('Visits, low &rarr; high'),
            'id ASC' => l10n('Numeric identifier, 1 &rarr; 9'),
            'id DESC' => l10n('Numeric identifier, 9 &rarr; 1'),
            '`rank` ASC' => l10n('Manual sort order'),
        ];

        $comments_order = [
            'ASC' => l10n('Show oldest comments first'),
            'DESC' => l10n('Show latest comments first'),
        ];

        $mail_themes = [
            'clear' => 'Clear',
            'dark' => 'Dark',
        ];

        // ------------------------------ verification and registration of modifications
        if (isset($_POST['submit'])) {
            check_pwg_token();
            $int_pattern = '/^\d+$/';

            switch ($page['section']) {
                case 'main':

                    if (! isset($conf['order_by_custom']) and ! isset($conf['order_by_inside_category_custom'])) {
                        if (! empty($_POST['order_by'])) {
                            check_input_parameter('order_by', $_POST, true, '/^(' . implode('|', array_keys($sort_fields)) . ')$/');

                            // check_input_parameter() above fatal_error()s unless
                            // $_POST['order_by'] is an array of scalars matching
                            // $pattern, but that guarantee isn't visible to static
                            // analysis; re-derive it into a local, string-only copy
                            // (values from an HTTP request are always strings here).
                            $order_by_input = is_array($_POST['order_by']) ? array_filter($_POST['order_by'], is_string(...)) : [];

                            $used = [];
                            foreach ($order_by_input as $i => $val) {
                                if (empty($val) or isset($used[$val])) {
                                    unset($order_by_input[$i]);
                                } else {
                                    $used[$val] = true;
                                }
                            }
                            if (! (bool) count($order_by_input)) {
                                $page['errors'][] = l10n('No order field selected');
                            } else {
                                // limit to the number of available parameters
                                $order_by = $order_by_inside_category = array_slice($order_by_input, 0, (int) ceil(count($sort_fields) / 2));

                                // there is no rank outside categories
                                if (($i = array_search('`rank` ASC', $order_by)) !== false) {
                                    unset($order_by[$i]);
                                }

                                // must define a default order_by if user want to order by rank only
                                if (count($order_by) == 0) {
                                    $order_by = ['id ASC'];
                                }

                                $_POST['order_by'] = 'ORDER BY ' . implode(', ', $order_by);
                                $_POST['order_by_inside_category'] = 'ORDER BY ' . implode(', ', $order_by_inside_category);
                            }
                        } else {
                            $page['errors'][] = l10n('No order field selected');
                        }
                    }

                    if (empty($_POST['email_admin_on_new_user'])) {
                        $_POST['email_admin_on_new_user'] = 'none';
                    } elseif ($_POST['email_admin_on_new_user_filter'] == 'all') {
                        $_POST['email_admin_on_new_user'] = 'all';
                    } else {
                        if (empty($_POST['email_admin_on_new_user_filter_group'])) {
                            $_POST['email_admin_on_new_user'] = 'all';
                        } else {
                            $filter_group = $_POST['email_admin_on_new_user_filter_group'];
                            $_POST['email_admin_on_new_user'] = 'group:' . (is_string($filter_group) ? $filter_group : '');
                        }
                    }

                    foreach ($main_checkboxes as $checkbox) {
                        $_POST[$checkbox] = empty($_POST[$checkbox]) ? 'false' : 'true';
                    }
                    break;

                case 'watermark':

                    include PHPWG_ROOT_PATH . 'admin/include/configuration_watermark_process.inc.php';
                    break;

                case 'sizes':

                    include PHPWG_ROOT_PATH . 'admin/include/configuration_sizes_process.inc.php';
                    break;

                case 'comments':

                    // the number of comments per page must be an integer between 5 and 50
                    // included
                    $nb_comment_page = $_POST['nb_comment_page'] ?? null;
                    if (! (bool) preg_match($int_pattern, is_scalar($nb_comment_page) ? (string) $nb_comment_page : '')
                         or $_POST['nb_comment_page'] < 5
                         or $_POST['nb_comment_page'] > 50) {
                        $page['errors'][] = l10n('The number of comments a page must be between 5 and 50 included.');
                    }
                    foreach ($comments_checkboxes as $checkbox) {
                        $_POST[$checkbox] = empty($_POST[$checkbox]) ? 'false' : 'true';
                    }
                    break;

                case 'default':

                    // Never go here
                    break;

                case 'display':

                    $nb_categories_page = $_POST['nb_categories_page'] ?? null;
                    if (! (bool) preg_match($int_pattern, is_scalar($nb_categories_page) ? (string) $nb_categories_page : '')
                          or $_POST['nb_categories_page'] < 4) {
                        $page['errors'][] = l10n('The number of albums a page must be above 4.');
                    }
                    foreach ($display_checkboxes as $checkbox) {
                        $_POST[$checkbox] = empty($_POST[$checkbox]) ? 'false' : 'true';
                    }
                    $picture_informations = is_array($_POST['picture_informations'] ?? null) ? $_POST['picture_informations'] : [];
                    foreach ($display_info_checkboxes as $checkbox) {
                        $picture_informations[$checkbox] =
                          empty($picture_informations[$checkbox]) ? false : true;
                    }
                    $_POST['picture_informations'] = addslashes(serialize($picture_informations));
                    break;

                case 'search':

                    $filters_views_box = is_array($_POST['filters_views_box'] ?? null) ? $_POST['filters_views_box'] : [];
                    $filters_views_post = is_array($_POST['filters_views'] ?? null) ? $_POST['filters_views'] : [];

                    foreach ($filters_names_checkboxes as $checkbox) {
                        $filter_conf = is_array($filters_views_post[$checkbox] ?? null) ? $filters_views_post[$checkbox] : [];

                        if (empty($filters_views_box[$checkbox])) {
                            $filter_conf['access'] = 'nobody';
                            $filter_conf['default'] = false;
                        } else {
                            $filter_conf['default'] =
                              empty($filter_conf['default']) ? false : true;
                        }

                        $filters_views_post[$checkbox] = $filter_conf;
                    }
                    $filters_views_post['last_filters_conf'] =
                      empty($filters_views_post['last_filters_conf']) ? false : true;
                    $_POST['filters_views'] = addslashes(serialize($filters_views_post));

            }

            // updating configuration if no error found
            // ($page['errors'] is already narrowed to array above).
            $page_errors_for_count = $page['errors'];
            if (! in_array($page_section, ['sizes', 'watermark']) and count($page_errors_for_count) == 0 and is_webmaster()) {
                // echo '<pre>'; print_r($_POST); echo '</pre>';
                $result = pwg_query('SELECT param FROM ' . Tables::config());
                while ((bool) ($row = pwg_db_fetch_assoc($result))) {
                    if (! is_string($row['param'])) {
                        // `param` is the config table's NOT NULL primary key; a
                        // non-string row here would mean the query result changed
                        // shape, not a real config param to update.
                        continue;
                    }

                    if (isset($_POST[$row['param']])) {
                        $post_value = $_POST[$row['param']];
                        $value = is_scalar($post_value) ? (string) $post_value : '';

                        if ($row['param'] == 'gallery_title') {
                            if (! (bool) $conf['allow_html_descriptions']) {
                                $value = strip_tags($value);
                            }
                        }

                        $query = '
UPDATE ' . Tables::config() . '
SET value = \'' . str_replace("\'", "''", $value) . '\'
WHERE param = \'' . $row['param'] . '\'
;';
                        pwg_query($query);
                    }
                }
                $template->assign(
                    [
                        'save_success' => l10n('Your configuration settings are saved'),
                    ]
                );

                pwg_activity('system', ActivitySystem::Core, 'config', [
                    'config_section' => $page['section'],
                ]);
            }

            // ------------------------------------------------------ $conf reinitialization
            load_conf_from_db();
        }

        // restore default derivatives settings
        if ($page['section'] == 'sizes' and isset($_GET['action']) and $_GET['action'] == 'restore_settings' and is_webmaster()) {
            check_pwg_token();

            ImageStdParams::restore_default();
            clear_derivative_cache();

            // reset conf
            load_conf_from_db();

            $template->assign(
                [
                    'save_success' => l10n('Your configuration settings are saved'),
                ]
            );

            pwg_activity('system', ActivitySystem::Core, 'config', [
                'config_section' => $page['section'],
                'config_action' => $_GET['action'],
            ]);
        }

        // ----------------------------------------------------- template initialization
        $template->set_filename('config', 'configuration_' . $page_section . '.tpl');

        // TabSheet
        $tabsheet = new tabsheet();
        $tabsheet->set_id('configuration');
        $tabsheet->select($page_section);
        $tabsheet->assign();

        $action = get_root_url() . 'admin.php?page=configuration';
        $action .= '&amp;section=' . $page_section;

        $template->assign(
            [
                'U_HELP' => get_root_url() . 'admin/popuphelp.php?page=configuration',
                'PWG_TOKEN' => get_pwg_token(),
                'F_ACTION' => $action,
            ]
        );

        switch ($page['section']) {
            case 'main':

                if (self::orderByIsLocal()) {
                    $page['warnings'][] = l10n('You have specified <i>$conf[\'order_by\']</i> in your local configuration file, this parameter in deprecated, please remove it or rename it into <i>$conf[\'order_by_custom\']</i> !');
                }

                if (isset($conf['order_by_custom']) or isset($conf['order_by_inside_category_custom'])) {
                    $order_by = [''];
                    $template->assign('ORDER_BY_IS_CUSTOM', true);
                } else {
                    $out = [];
                    $conf_order_by_inside_category = $conf['order_by_inside_category'];
                    $order_by = trim(is_string($conf_order_by_inside_category) ? $conf_order_by_inside_category : '');
                    $order_by = str_replace('ORDER BY ', '', $order_by);
                    $order_by = explode(', ', $order_by);
                }

                $conf_gallery_title = $conf['gallery_title'];
                $conf_page_banner = $conf['page_banner'];
                $conf_email_admin_on_new_user = $conf['email_admin_on_new_user'];
                $conf_email_admin_on_new_user_str = is_string($conf_email_admin_on_new_user) ? $conf_email_admin_on_new_user : '';
                // $lang['day'] is never actually set by any language/*/common.lang.php
                // in this codebase (confirmed by grep across every locale) nor by any
                // runtime code -- a genuinely dead key, not a per-locale gap. Guard
                // with ?? rather than a direct read, matching the same defensive
                // pattern already used for this exact key by admin/intro.php and
                // format_date_legacy() (include/functions.inc.php).
                $lang_day = $lang['day'] ?? null;
                $lang_day = is_array($lang_day) ? $lang_day : [];

                $template->assign(
                    'main',
                    [
                        'CONF_GALLERY_TITLE' => htmlspecialchars(is_string($conf_gallery_title) ? $conf_gallery_title : ''),
                        'CONF_PAGE_BANNER' => htmlspecialchars(is_string($conf_page_banner) ? $conf_page_banner : ''),
                        'week_starts_on_options' => [
                            'sunday' => $lang_day[0] ?? '',
                            'monday' => $lang_day[1] ?? '',
                        ],
                        'week_starts_on_options_selected' => $conf['week_starts_on'],
                        'mail_theme' => $conf['mail_theme'],
                        'mail_theme_options' => $mail_themes,
                        'order_by' => $order_by,
                        'order_by_options' => $sort_fields,
                        'email_admin_on_new_user' => $conf_email_admin_on_new_user != 'none',
                        'email_admin_on_new_user_filter' => in_array($conf_email_admin_on_new_user, ['none', 'all']) ? 'all' : 'group',
                        'email_admin_on_new_user_filter_group' => ((bool) preg_match('/^group:(\d+)$/', $conf_email_admin_on_new_user_str, $matches)) ? $matches[1] : -1,
                    ]
                );

                // list of groups
                $query = '
    SELECT
        id,
        name
      FROM `' . Tables::groups() . '`
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
                            $checkbox => $conf[$checkbox],
                        ],
                        true
                    );
                }
                break;

            case 'comments':

                $template->assign(
                    'comments',
                    [
                        'NB_COMMENTS_PAGE' => $conf['nb_comment_page'],
                        'comments_order' => $conf['comments_order'],
                        'comments_order_options' => $comments_order,
                    ]
                );

                foreach ($comments_checkboxes as $checkbox) {
                    $template->append(
                        'comments',
                        [
                            $checkbox => $conf[$checkbox],
                        ],
                        true
                    );
                }
                break;

            case 'default':

                // $conf['guest_id'] is set as a PHP int literal in
                // include/config_default.inc.php (never overridden from the DB
                // config table, which only stores admin-editable params); guard
                // rather than trust that invariant blindly since $conf is typed as
                // array<string, mixed> here.
                $conf_guest_id = $conf['guest_id'];
                $guest_id = is_numeric($conf_guest_id) ? (int) $conf_guest_id : 0;

                $edit_user = build_user($guest_id, false);
                // P22: profile.php's own save_profile_from_post()/
                // load_profile_in_template() moved to this shared include (root
                // profile.php is now pure bootstrap + dispatch, no free function
                // definitions left in it).
                include_once PHPWG_ROOT_PATH . 'include/profile_functions.inc.php';

                $errors = [];
                if (save_profile_from_post($edit_user, $errors)) {
                    // Reload user
                    $edit_user = build_user($guest_id, false);
                    $page['infos'][] = l10n('Information data registered in database');
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

            case 'display':

                foreach ($display_checkboxes as $checkbox) {
                    $template->append(
                        'display',
                        [
                            $checkbox => $conf[$checkbox],
                        ],
                        true
                    );
                }
                // config.value is stored as a serialized string (see the
                // addslashes(serialize(...)) write-back for this same param
                // earlier in this file); guard rather than trust that shape
                // blindly since $conf is typed as array<string, mixed> here.
                $conf_picture_informations = $conf['picture_informations'];
                $template->append(
                    'display',
                    [
                        'picture_informations' => is_string($conf_picture_informations) ? unserialize($conf_picture_informations) : [],
                        'NB_CATEGORIES_PAGE' => $conf['nb_categories_page'],
                    ],
                    true
                );
                break;

            case 'sizes':

                // we only load the derivatives if it was not already loaded: it occurs
                // when submitting the form and an error remains
                if (! isset($page['sizes_loaded_in_tpl'])) {
                    $is_gd = (pwg_image::get_library() == 'gd') ? true : false;
                    $template->assign('is_gd', $is_gd);
                    $template->assign(
                        'sizes',
                        [
                            'original_resize_maxwidth' => $conf['original_resize_maxwidth'],
                            'original_resize_maxheight' => $conf['original_resize_maxheight'],
                            'original_resize_quality' => $conf['original_resize_quality'],
                        ]
                    );

                    foreach ($sizes_checkboxes as $checkbox) {
                        $template->append(
                            'sizes',
                            [
                                $checkbox => $conf[$checkbox],
                            ],
                            true
                        );
                    }

                    // derivatives = multiple size
                    $enabled = ImageStdParams::get_defined_type_map();
                    $disabled_unserialized = safe_unserialize(ImageStdParams::get_disabled_type_map());
                    $disabled = is_array($disabled_unserialized) ? $disabled_unserialized : [];

                    $tpl_vars = [];
                    foreach (ImageStdParams::get_all_types() as $type) {
                        $tpl_var = [];

                        $tpl_var['must_square'] = ($type == IMG_SQUARE ? true : false);
                        $tpl_var['must_enable'] = ($type == IMG_SQUARE || $type == IMG_THUMB || $type == $conf['derivative_default_size']) ? true : false;

                        if ((bool) ($params = $enabled[$type] ?? null)) {
                            $tpl_var['enabled'] = true;
                        } else {
                            $tpl_var['enabled'] = false;
                            $disabled_candidate = $disabled[$type] ?? null;
                            $params = $disabled_candidate instanceof DerivativeParams ? $disabled_candidate : null;
                        }

                        if ((bool) $params) {
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
                        $tpl_vars[$custom] = ($now - $time <= 24 * 3600) ? l10n('today') : time_since($time, 'day');
                    }
                    $template->assign('custom_derivatives', $tpl_vars);
                }

                break;

            case 'watermark':

                $watermark_files = [];
                if (($glob = glob(PHPWG_ROOT_PATH . 'themes/default/watermarks/*.png')) !== false) {
                    foreach ($glob as $file) {
                        $watermark_files[] = substr($file, strlen(PHPWG_ROOT_PATH));
                    }
                }
                if (($glob = glob(PHPWG_ROOT_PATH . PWG_LOCAL_DIR . 'watermarks/*.png')) !== false) {
                    foreach ($glob as $file) {
                        $watermark_files[] = substr($file, strlen(PHPWG_ROOT_PATH));
                    }
                }
                $watermark_filemap = [
                    '' => '---',
                ];
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

                break;

            case 'search':

                $conf_filters_views_for_search = $conf['filters_views'];
                $conf_filters_views_for_search = (is_array($conf_filters_views_for_search) or is_string($conf_filters_views_for_search))
                    ? $conf_filters_views_for_search
                    : [];

                $template->assign(
                    'search',
                    [
                        'filters_views' => safe_unserialize($conf_filters_views_for_search),
                        'filters_names' => $filters_names_checkboxes,
                    ],
                );
                $template->assign('SHOW_FILTER_RATINGS', $conf['rate']);

        }

        $template->assign('isWebmaster', (is_webmaster()) ? 1 : 0);
        $template->assign('ADMIN_PAGE_TITLE', l10n('Configuration'));

        // ----------------------------------------------------------- sending html code
        $template->assign_var_from_handle('ADMIN_CONTENT', 'config');
    }

    /**
     * Whether $conf['order_by']/$conf['order_by_inside_category'] were set
     * by a site-owner-authored local config file (a deprecated pattern --
     * see the warning message this feeds).
     *
     * Same external-file rationale as the isset.offset/logicalOr.alwaysFalse
     * ignores inside this method's own body: PHPStan can't see
     * local/config/config.inc.php's content, so it proves this method always
     * returns false even though it can genuinely return true.
     */
    // @phpstan-ignore return.tooWideBool
    private static function orderByIsLocal(): bool
    {
        // include/config_default.inc.php never sets local_dir_site/
        // order_by/order_by_inside_category (confirmed: no such keys in
        // that file at all) — they only ever come from an optional,
        // site-owner-authored local/config/config.inc.php loaded at
        // runtime, whose content isn't knowable statically. Whether
        // $conf ends up with these keys genuinely depends on a file
        // that may not exist and isn't part of this codebase.
        $conf = [];
        include PHPWG_ROOT_PATH . 'include/config_default.inc.php';
        @include PHPWG_ROOT_PATH . 'local/config/config.inc.php';
        // @phpstan-ignore isset.offset
        if (isset($conf['local_dir_site'])) {
            @include PHPWG_ROOT_PATH . PWG_LOCAL_DIR . 'config/config.inc.php';
        }

        // @phpstan-ignore isset.offset, isset.offset, logicalOr.alwaysFalse
        return isset($conf['order_by']) or isset($conf['order_by_inside_category']);
    }
}
