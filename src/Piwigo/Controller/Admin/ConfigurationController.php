<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Doctrine\DBAL\Connection;
use Piwigo\Admin\Image\PwgImage;
use Piwigo\Admin\Tabsheet;
use Piwigo\Config\Config;
use Piwigo\Core\PageState;
use Piwigo\Core\ServiceLocator;
use Piwigo\Image\DerivativeParams;
use Piwigo\Url\UrlGenerator;
use Piwigo\Image\ImageStdParams;
use Piwigo\Template\TemplateRegistry;

final class ConfigurationController
{
    /** @var list<string> */
    public const array PAGES = [
        'configuration',
    ];

    public function handle(string $page): void
    {
        if ($page === 'configuration') { $this->configuration(); }
    }

    private function configuration(): void
    {
        $tpl = TemplateRegistry::current();
        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];

        require_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
        require_once PHPWG_ROOT_PATH . 'admin/include/functions_upload.inc.php';

        if (!is_webmaster()) {
            PageState::current()->addWarning(str_replace('%s', l10n('user_status_webmaster'), l10n('%s status is required to edit parameters.')));
        }

        check_input_parameter('section', $_GET, false, '/^[a-z]+$/i');

        $page['section'] = isset($_GET['section']) && is_scalar($_GET['section']) ? (string) $_GET['section'] : 'main';
        $section = (string) $page['section'];

        $main_checkboxes = [
            'allow_user_registration', 'obligatory_user_mail_address', 'rate', 'rate_anonymous',
            'allow_user_customization', 'log', 'history_admin', 'history_guest',
            'show_mobile_app_banner_in_gallery', 'show_mobile_app_banner_in_admin', 'upload_detect_duplicate',
        ];

        $sizes_checkboxes = ['original_resize'];

        $comments_checkboxes = [
            'activate_comments', 'comments_forall', 'comments_validation',
            'email_admin_on_comment', 'email_admin_on_comment_validation',
            'user_can_delete_comment', 'user_can_edit_comment',
            'email_admin_on_comment_edition', 'email_admin_on_comment_deletion',
            'comments_author_mandatory', 'comments_email_mandatory', 'comments_enable_website',
        ];

        $display_checkboxes = [
            'menubar_filter_icon', 'index_search_in_set_button', 'index_search_in_set_action',
            'index_sort_order_input', 'index_flat_icon', 'index_posted_date_icon', 'index_created_date_icon',
            'index_slideshow_icon', 'index_sizes_icon', 'index_new_icon', 'index_edit_icon', 'index_caddie_icon',
            'display_fromto', 'picture_metadata_icon', 'picture_slideshow_icon', 'picture_favorite_icon',
            'picture_sizes_icon', 'picture_download_icon', 'picture_edit_icon', 'picture_caddie_icon',
            'picture_representative_icon', 'picture_navigation_icons', 'picture_navigation_thumb', 'picture_menu',
        ];

        $display_info_checkboxes = ['author', 'created_on', 'posted_on', 'dimensions', 'file', 'filesize', 'tags', 'categories', 'visits', 'rating_score'];

        if (!Config::has('filters_views')) {
            Config::persist('filters_views', serialize(Config::defaultFiltersViews()));
        }

        $filters_views_raw = safe_unserialize(Config::filtersViews() ?? '');
        $filters_names_checkboxes = array_values(array_diff(array_keys($filters_views_raw), ['last_filters_conf']));

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

        $comments_order = ['ASC' => l10n('Show oldest comments first'), 'DESC' => l10n('Show latest comments first')];
        $mail_themes    = ['clear' => 'Clear', 'dark' => 'Dark'];

        // ── POST submission ───────────────────────────────────────────────────

        if (isset($_POST['submit'])) {
            check_pwg_token();
            $int_pattern = '/^\d+$/';

            switch ($section) {
                case 'main':
                    if (!Config::has('order_by_custom') && !Config::has('order_by_inside_category_custom')) {
                        if (!empty($_POST['order_by'])) {
                            check_input_parameter('order_by', $_POST, true, '/^(' . implode('|', array_keys($sort_fields)) . ')$/');
                            $post_order_by = is_array($_POST['order_by']) ? $_POST['order_by'] : [];
                            $used = [];
                            foreach ($post_order_by as $i => $val) {
                                $val_str = is_scalar($val) ? (string) $val : '';
                                if (empty($val_str) || isset($used[$val_str])) { unset($post_order_by[$i]); }
                                else { $used[$val_str] = true; }
                            }
                            $_POST['order_by'] = $post_order_by;
                            if (!count($post_order_by)) {
                                PageState::current()->addError(l10n('No order field selected'));
                            } else {
                                $order_by = $order_by_inside_category = array_slice($post_order_by, 0, (int) ceil(count($sort_fields) / 2));
                                if (($idx = array_search('`rank` ASC', $order_by)) !== false) { unset($order_by[$idx]); }
                                if (count($order_by) == 0) { $order_by = ['id ASC']; }
                                $_POST['order_by'] = 'ORDER BY ' . implode(', ', array_map(fn ($v): string => is_scalar($v) ? (string) $v : '', $order_by));
                                $_POST['order_by_inside_category'] = 'ORDER BY ' . implode(', ', array_map(fn ($v): string => is_scalar($v) ? (string) $v : '', $order_by_inside_category));
                            }
                        } else {
                            PageState::current()->addError(l10n('No order field selected'));
                        }
                    }

                    if (empty($_POST['email_admin_on_new_user'])) {
                        $_POST['email_admin_on_new_user'] = 'none';
                    } elseif ('all' == $_POST['email_admin_on_new_user_filter']) {
                        $_POST['email_admin_on_new_user'] = 'all';
                    } else {
                        $_POST['email_admin_on_new_user'] = empty($_POST['email_admin_on_new_user_filter_group']) ? 'all' : 'group:' . (is_scalar($_POST['email_admin_on_new_user_filter_group']) ? (string) $_POST['email_admin_on_new_user_filter_group'] : '');
                    }

                    foreach ($main_checkboxes as $checkbox) {
                        $_POST[$checkbox] = empty($_POST[$checkbox]) ? 'false' : 'true';
                    }
                    break;

                case 'watermark':
                    require PHPWG_ROOT_PATH . 'admin/include/configuration_watermark_process.inc.php';
                    break;

                case 'sizes':
                    require PHPWG_ROOT_PATH . 'admin/include/configuration_sizes_process.inc.php';
                    break;

                case 'comments':
                    if (!preg_match($int_pattern, is_scalar($_POST['nb_comment_page']) ? (string) $_POST['nb_comment_page'] : '')
                        || (is_numeric($_POST['nb_comment_page']) && $_POST['nb_comment_page'] < 5)
                        || (is_numeric($_POST['nb_comment_page']) && $_POST['nb_comment_page'] > 50)) {
                        PageState::current()->addError(l10n('The number of comments a page must be between 5 and 50 included.'));
                    }
                    foreach ($comments_checkboxes as $checkbox) {
                        $_POST[$checkbox] = empty($_POST[$checkbox]) ? 'false' : 'true';
                    }
                    break;

                case 'display':
                    if (!preg_match($int_pattern, is_scalar($_POST['nb_categories_page']) ? (string) $_POST['nb_categories_page'] : '')
                        || (is_numeric($_POST['nb_categories_page']) && $_POST['nb_categories_page'] < 4)) {
                        PageState::current()->addError(l10n('The number of albums a page must be above 4.'));
                    }
                    foreach ($display_checkboxes as $checkbox) {
                        $_POST[$checkbox] = empty($_POST[$checkbox]) ? 'false' : 'true';
                    }
                    $post_picture_informations = is_array($_POST['picture_informations'] ?? null) ? $_POST['picture_informations'] : [];
                    foreach ($display_info_checkboxes as $checkbox) {
                        $post_picture_informations[$checkbox] = empty($post_picture_informations[$checkbox]) ? false : true;
                    }
                    $_POST['picture_informations'] = addslashes(serialize($post_picture_informations));
                    break;

                case 'search':
                    $post_filters_views     = is_array($_POST['filters_views'] ?? null) ? $_POST['filters_views'] : [];
                    $post_filters_views_box = is_array($_POST['filters_views_box'] ?? null) ? $_POST['filters_views_box'] : [];
                    foreach ($filters_names_checkboxes as $checkbox) {
                        $fv_entry = is_array($post_filters_views[$checkbox] ?? null) ? $post_filters_views[$checkbox] : [];
                        if (empty($post_filters_views_box[$checkbox])) { $fv_entry['access'] = 'nobody'; $fv_entry['default'] = false; }
                        else { $fv_entry['default'] = empty($fv_entry['default']) ? false : true; }
                        $post_filters_views[$checkbox] = $fv_entry;
                    }
                    $post_filters_views['last_filters_conf'] = empty($post_filters_views['last_filters_conf']) ? false : true;
                    $_POST['filters_views'] = addslashes(serialize($post_filters_views));
                    break;
            }

            $pageErrors = is_array($page['errors'] ?? null) ? $page['errors'] : [];
            if (!in_array($section, ['sizes', 'watermark']) && count($pageErrors) == 0 && is_webmaster()) {
                foreach (ServiceLocator::get(Connection::class)->executeQuery('SELECT param FROM ' . CONFIG_TABLE)->fetchFirstColumn() as $row_param) {
                    $row_param = is_scalar($row_param) ? (string) $row_param : '';
                    if (isset($_POST[$row_param])) {
                        $value = is_scalar($_POST[$row_param]) ? (string) $_POST[$row_param] : '';
                        if ('gallery_title' == $row_param && !Config::allowHtmlDescriptions()) { $value = strip_tags($value); }
                        conf_update_param($row_param, $value);
                    }
                }
                $tpl->assign(['save_success' => l10n('Your configuration settings are saved')]);
                pwg_activity('system', ACTIVITY_SYSTEM_CORE, 'config', ['config_section' => $section]);
            }

            load_conf_from_db();
        }

        // ── Restore default derivatives ───────────────────────────────────────

        if ($section === 'sizes' && isset($_GET['action']) && 'restore_settings' == $_GET['action']) {
            ImageStdParams::restore_default();
            clear_derivative_cache();
            load_conf_from_db();
            $tpl->assign(['save_success' => l10n('Your configuration settings are saved')]);
            pwg_activity('system', ACTIVITY_SYSTEM_CORE, 'config', ['config_section' => $section, 'config_action' => $_GET['action']]);
        }

        // ── Template init ─────────────────────────────────────────────────────

        $tpl->set_filename('config', 'configuration_' . $section . '.tpl');

        $tabsheet = new Tabsheet();
        $tabsheet->set_id('configuration');
        $tabsheet->select($section);
        $tabsheet->assign();

        $action = ServiceLocator::get(UrlGenerator::class)->admin('configuration') . '&amp;section=' . $section;

        $tpl->assign([
            'U_HELP'    => get_root_url() . 'admin/popuphelp.php?page=configuration',
            'PWG_TOKEN' => get_pwg_token(),
            'F_ACTION'  => $action,
        ]);

        // ── Section display ───────────────────────────────────────────────────

        switch ($section) {
            case 'main':
                if ($this->orderByIsLocal()) {
                    PageState::current()->addWarning(l10n('You have specified <i>' . '$' . 'conf[\'order_by\']</i> in your local configuration file, this parameter in deprecated, please remove it or rename it into <i>' . '$' . 'conf[\'order_by_custom\']</i> !'));
                }

                if (Config::has('order_by_custom') || Config::has('order_by_inside_category_custom')) {
                    $order_by = [''];
                    $tpl->assign('ORDER_BY_IS_CUSTOM', true);
                } else {
                    $order_by_str = trim((string) Config::orderByInsideCategory());
                    $order_by_str = str_replace('ORDER BY ', '', $order_by_str);
                    $order_by     = explode(', ', $order_by_str);
                }

                /** @var array<string, mixed> $lang */
                $lang    = is_array($GLOBALS['lang']) ? $GLOBALS['lang'] : [];
                $langDay = is_array($lang['day'] ?? null) ? $lang['day'] : [];

                $tpl->assign('main', [
                    'CONF_GALLERY_TITLE'                    => htmlspecialchars((string) Config::galleryTitle()),
                    'CONF_PAGE_BANNER'                      => htmlspecialchars((string) Config::pageBanner()),
                    'week_starts_on_options'                => [
                        'sunday' => is_scalar($langDay[0] ?? null) ? (string) $langDay[0] : 'Sunday',
                        'monday' => is_scalar($langDay[1] ?? null) ? (string) $langDay[1] : 'Monday',
                    ],
                    'week_starts_on_options_selected'       => Config::weekStartsOn(),
                    'mail_theme'                            => Config::mailTheme(),
                    'mail_theme_options'                    => $mail_themes,
                    'order_by'                              => $order_by,
                    'order_by_options'                      => $sort_fields,
                    'email_admin_on_new_user'               => 'none' != Config::emailAdminOnNewUser(),
                    'email_admin_on_new_user_filter'        => in_array(Config::emailAdminOnNewUser(), ['none', 'all']) ? 'all' : 'group',
                    'email_admin_on_new_user_filter_group'  => preg_match('/^group:(\d+)$/', Config::emailAdminOnNewUser(), $matches) ? $matches[1] : -1,
                ]);

                $groups = array_map(
                    fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
                    array_column(get_dbal_connection()->executeQuery('SELECT id, name FROM `' . GROUPS_TABLE . '`;')->fetchAllAssociative(), 'name', 'id')
                );
                natcasesort($groups);
                $tpl->assign(['group_options' => $groups]);

                foreach ($main_checkboxes as $checkbox) {
                    $tpl->append('main', [$checkbox => Config::raw($checkbox)], true);
                }

                $tpl->assign('page_data_json', json_encode([
                    'order_by_is_custom'        => Config::has('order_by_custom') || Config::has('order_by_inside_category_custom'),
                    'order_by_options_count'    => count($sort_fields),
                ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));
                break;

            case 'comments':
                $tpl->assign('comments', [
                    'NB_COMMENTS_PAGE'    => Config::nbCommentPage(),
                    'comments_order'      => Config::commentsOrder(),
                    'comments_order_options' => $comments_order,
                ]);
                foreach ($comments_checkboxes as $checkbox) {
                    $tpl->append('comments', [$checkbox => Config::raw($checkbox)], true);
                }
                break;

            case 'default':
                $edit_user = build_user(Config::guestId(), false);
                require_once PHPWG_ROOT_PATH . 'include/profile_functions.php';
                $errors = [];
                if (save_profile_from_post($edit_user, $errors)) {
                    $edit_user = build_user(Config::guestId(), false);
                    PageState::current()->addInfo(l10n('Information data registered in database'));
                }
                $pageErrors2 = is_array($page['errors'] ?? null) ? $page['errors'] : [];
                $page['errors'] = array_merge($pageErrors2, $errors);

                load_profile_in_template($action, '', $edit_user, 'GUEST_');
                $tpl->assign('default', []);
                break;

            case 'display':
                foreach ($display_checkboxes as $checkbox) {
                    $tpl->append('display', [$checkbox => Config::raw($checkbox)], true);
                }
                $tpl->append('display', [
                    'picture_informations' => safe_unserialize(is_string(Config::pictureInformations()) ? Config::pictureInformations() : ''),
                    'NB_CATEGORIES_PAGE'   => Config::nbCategoriesPage(),
                ], true);
                break;

            case 'sizes':
                if (!isset($page['sizes_loaded_in_tpl'])) {
                    $is_gd = (PwgImage::get_library() == 'gd');
                    $tpl->assign('is_gd', $is_gd);
                    $tpl->assign('sizes', [
                        'original_resize_maxwidth'  => Config::originalResizeMaxwidth(),
                        'original_resize_maxheight' => Config::originalResizeMaxheight(),
                        'original_resize_quality'   => Config::originalResizeQuality(),
                    ]);
                    foreach ($sizes_checkboxes as $checkbox) {
                        $tpl->append('sizes', [$checkbox => Config::raw($checkbox)], true);
                    }

                    $enabled      = ImageStdParams::get_defined_type_map();
                    $disabled_raw = safe_unserialize(ImageStdParams::get_disabled_type_map());
                    $disabled     = $disabled_raw;

                    $tpl_vars = [];
                    foreach (ImageStdParams::get_all_types() as $type) {
                        $tpl_var = [];
                        $tpl_var['must_square']  = ($type == IMG_SQUARE);
                        $tpl_var['must_enable']  = ($type == IMG_SQUARE || $type == IMG_THUMB || $type == Config::derivativeDefaultSize());
                        $params = $enabled[$type] ?? null;
                        if ($params !== null) {
                            $tpl_var['enabled'] = true;
                        } else {
                            $tpl_var['enabled'] = false;
                            $params_raw = $disabled[$type] ?? null;
                            $params = ($params_raw instanceof DerivativeParams) ? $params_raw : null;
                        }
                        if ($params instanceof DerivativeParams) {
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
                    $tpl->assign('derivatives', $tpl_vars);
                    $tpl->assign('resize_quality', ImageStdParams::$quality);

                    $tpl_vars = [];
                    $now = time();
                    foreach (ImageStdParams::$custom as $custom => $time) {
                        $time_int         = is_numeric($time) ? (int) $time : 0;
                        $tpl_vars[$custom] = ($now - $time_int <= 24 * 3600) ? l10n('today') : time_since($time_int, 'day');
                    }
                    $tpl->assign('custom_derivatives', $tpl_vars);
                }

                $tpl->assign('page_data_json', json_encode([
                    'str_restore_confirm' => l10n('Are you sure you want to restore to default settings?'),
                    'str_max_width'       => l10n('Maximum width'),
                    'str_width'           => l10n('Width'),
                    'str_max_height'      => l10n('Maximum height'),
                    'str_height'          => l10n('Height'),
                ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));
                break;

            case 'watermark':
                $watermark_files = [];
                foreach (glob(PHPWG_ROOT_PATH . 'themes/default/watermarks/*.png') ?: [] as $file) {
                    $watermark_files[] = substr($file, strlen(PHPWG_ROOT_PATH));
                }
                if (($glob = glob(PHPWG_ROOT_PATH . PWG_LOCAL_DIR . 'watermarks/*.png')) !== false) {
                    foreach ($glob as $file) {
                        $watermark_files[] = substr($file, strlen(PHPWG_ROOT_PATH));
                    }
                }
                $watermark_filemap = ['' => '---'];
                foreach ($watermark_files as $file) {
                    $watermark_filemap[$file] = basename($file);
                }
                $tpl->assign('watermark_files', $watermark_filemap);

                if ($tpl->get_template_vars('watermark') === null) {
                    $wm = ImageStdParams::get_watermark();
                    $position = 'custom';
                    if ($wm->xpos == 0   && $wm->ypos == 0)   { $position = 'topleft'; }
                    if ($wm->xpos == 100 && $wm->ypos == 0)   { $position = 'topright'; }
                    if ($wm->xpos == 50  && $wm->ypos == 50)  { $position = 'middle'; }
                    if ($wm->xpos == 0   && $wm->ypos == 100) { $position = 'bottomleft'; }
                    if ($wm->xpos == 100 && $wm->ypos == 100) { $position = 'bottomright'; }
                    if ($wm->xrepeat != 0 || $wm->yrepeat != 0) { $position = 'custom'; }

                    $tpl->assign('watermark', [
                        'file'     => $wm->file,
                        'minw'     => $wm->min_size[0],
                        'minh'     => $wm->min_size[1],
                        'xpos'     => $wm->xpos,
                        'ypos'     => $wm->ypos,
                        'xrepeat'  => $wm->xrepeat,
                        'yrepeat'  => $wm->yrepeat,
                        'opacity'  => $wm->opacity,
                        'position' => $position,
                    ]);
                }

                $tpl->assign('page_data_json', json_encode(['root_url' => get_root_url()], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));
                break;

            case 'search':
                $tpl->assign('search', [
                    'filters_views' => safe_unserialize(Config::filtersViews() ?? ''),
                    'filters_names' => $filters_names_checkboxes,
                ]);
                $tpl->assign('SHOW_FILTER_RATINGS', Config::rateEnabled());
                $tpl->assign('page_data_json', json_encode(['filters_names' => $filters_names_checkboxes], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));
                break;
        }

        $tpl->assign('isWebmaster', is_webmaster() ? 1 : 0);
        $tpl->assign('ADMIN_PAGE_TITLE', l10n('Configuration'));
        $tpl->assign_var_from_handle('ADMIN_CONTENT', 'config');
    }

    private function orderByIsLocal(): bool
    {
        $candidates = [
            PHPWG_ROOT_PATH . 'local/config/config.inc.php',
            PHPWG_ROOT_PATH . PWG_LOCAL_DIR . 'config/config.inc.php',
        ];
        foreach ($candidates as $path) {
            $real = realpath($path);
            if ($real === false) { continue; }
            $content = is_readable($real) ? file_get_contents($real) : false;
            if ($content !== false && preg_match('/\$conf\s*\[\s*[\'"](order_by|order_by_inside_category)[\'"]\s*\]\s*=/', $content) === 1) {
                return true;
            }
        }
        return false;
    }
}
