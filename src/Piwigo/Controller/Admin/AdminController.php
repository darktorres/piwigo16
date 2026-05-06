<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Comment\CommentRepository;
use Piwigo\Config\Config;
use Piwigo\Controller\ControllerInterface;
use Piwigo\Core\ServiceLocator;
use Piwigo\Http\ResponseFactory;
use Piwigo\Image\ImageRepository;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Users\UserRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Handles all admin pages (/admin{rest}).
 * Corresponds to the former admin.php entry-point.
 *
 * Dispatches to typed sub-controllers (AlbumController, PhotoController, …)
 * via dispatchToSubController().  Complex admin sub-pages use an explicit
 * static require inside the controller method; AlbumController inlines its
 * page bodies fully.
 *
 * This controller sends output directly (template pparse + page_tail) and
 * returns an empty 200 — the caller must NOT use ResponseEmitter.
 */
final class AdminController implements ControllerInterface
{
    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        defined('IN_ADMIN') or define('IN_ADMIN', true);

        /** @var array<string, mixed> $user */
        $user = &$GLOBALS['user'];
        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];

        require_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
        require_once PHPWG_ROOT_PATH . 'admin/include/functions_plugins.inc.php';
        require_once PHPWG_ROOT_PATH . 'admin/include/add_core_tabs.inc.php';

        trigger_notify('loc_begin_admin');

        check_status(ACCESS_ADMINISTRATOR);

        check_input_parameter('page', $_GET, false, '/^[a-zA-Z\d_-]+$/');
        check_input_parameter('section', $_GET, false, '/^[a-z]+[a-z_\/-]*(\.php)?$/i');

        // ── Filesystem quick-check ────────────────────────────────────────────

        if (Config::fsQuickCheckPeriod() > 0) {
            $perform_fsqc = false;
            if (Config::has('fs_quick_check_last_check')) {
                $lastCheckStr = Config::fsQuickCheckLastCheck();
                $lastCheckTs  = $lastCheckStr !== null ? strtotime($lastCheckStr) : false;
                $thresholdTs  = strtotime(Config::fsQuickCheckPeriod() . ' seconds ago');
                if ($lastCheckTs !== false && $thresholdTs !== false && $lastCheckTs < $thresholdTs) {
                    $perform_fsqc = true;
                }
            } else {
                $perform_fsqc = true;
            }
            if ($perform_fsqc) {
                fs_quick_check();
            }
        }

        // ── Direct / AJAX actions ─────────────────────────────────────────────

        $plugins_new_order = input_string('plugins_new_order', null, $_GET);
        if ($plugins_new_order !== null) {
            pwg_set_session_var('plugins_new_order', $plugins_new_order);
            exit;
        }

        if (input_string('change_theme', null, $_GET) !== null) {
            $admin_themes     = ['roma', 'clear'];
            $rawTheme         = userprefs_get_param('admin_theme', 'roma');
            $admin_theme_array = [is_scalar($rawTheme) ? (string) $rawTheme : 'roma'];
            $result           = array_diff($admin_themes, $admin_theme_array);
            $new_admin_theme  = array_pop($result);

            userprefs_update_param('admin_theme', $new_admin_theme);

            $url_params = [];
            foreach (['page', 'tab', 'section'] as $url_param) {
                if (isset($_GET[$url_param])) {
                    $url_params[] = $url_param . '=' . (is_scalar($_GET[$url_param]) ? (string) $_GET[$url_param] : '');
                }
            }

            $redirect_url = 'admin.php';
            if (count($url_params) > 0) {
                $redirect_url .= '?' . implode('&amp;', $url_params);
            }

            redirect($redirect_url);
        }

        // ── Sync user info ────────────────────────────────────────────────────

        if (Config::externalAuthentification()) {
            sync_users();
        }

        // ── Variables init ────────────────────────────────────────────────────

        $change_theme_url = PHPWG_ROOT_PATH . 'admin.php?';
        $test_get         = $_GET;
        unset($test_get['page'], $test_get['section'], $test_get['tag']);
        $qsRaw = is_scalar($_SERVER['QUERY_STRING'] ?? null) ? (string) $_SERVER['QUERY_STRING'] : '';
        if (count($test_get) === 0 && $qsRaw !== '') {
            $change_theme_url .= str_replace('&', '&amp;', $qsRaw) . '&amp;';
        }
        $change_theme_url .= 'change_theme=1';

        // URL aliases: ?page=plugin-community-pendings
        $getPage = isset($_GET['page']) && is_string($_GET['page']) ? $_GET['page'] : '';
        if ($getPage !== '' && preg_match('/^plugin-([^-]*)(?:-(.*))?$/', $getPage, $matches)) {
            $_GET['page'] = 'plugin';
            if (preg_match('/^piwigo_(videojs|openstreetmap)$/', $matches[1])) {
                $matches[1] = str_replace('_', '-', $matches[1]);
            }
            $_GET['section'] = $matches[1] . '/admin.php';
            if (isset($matches[2])) {
                $_GET['tab'] = $matches[2];
            }
            $getPage = 'plugin';
        }

        // URL aliases: ?page=album-134-properties
        if ($getPage !== '' && preg_match('/^album-(\d+)(?:-(.*))?$/', $getPage, $matches)) {
            $_GET['page'] = 'album';
            $_GET['cat_id'] = $matches[1];
            if (isset($matches[2])) {
                $_GET['tab'] = $matches[2];
            }
            $getPage = 'album';
        }

        // URL aliases: ?page=photo-1234-properties
        if ($getPage !== '' && preg_match('/^photo-(\d+)(?:-(.*))?$/', $getPage, $matches)) {
            $_GET['page'] = 'photo';
            $_GET['image_id'] = $matches[1];
            if (isset($matches[2])) {
                $_GET['tab'] = $matches[2];
            }
            $getPage = 'photo';
        }

        if ($getPage !== ''
            && preg_match('/^[a-z_]*$/', $getPage)
            && is_file(PHPWG_ROOT_PATH . 'admin/' . $getPage . '.php')
        ) {
            $page['page'] = $getPage;
        } else {
            $page['page'] = 'intro';
        }

        $adminPage  = (string) $page['page'];
        $link_start = PHPWG_ROOT_PATH . 'admin.php?page=';
        $conf_link  = $link_start . 'configuration&amp;section=';

        check_input_parameter('tab', $_GET, false, '/^[a-zA-Z\d_-]+$/');

        // ── Template init ─────────────────────────────────────────────────────

        $title               = l10n('Piwigo Administration');
        $page['page_banner'] = '<h1>' . l10n('Piwigo Administration') . '</h1>';
        $page['body_id']     = 'theAdminPage';

        $tpl = TemplateRegistry::current();
        $tpl->set_filenames(['admin' => 'admin.tpl']);

        $username = is_scalar($user['username'] ?? null) ? (string) $user['username'] : '';
        $tpl->assign([
            'USERNAME'               => $username,
            'ENABLE_SYNCHRONIZATION' => Config::enableSynchronization(),
            'U_SITE_MANAGER'         => $link_start . 'site_manager',
            'U_HISTORY_STAT'         => $link_start . 'stats&amp;year=' . date('Y') . '&amp;month=' . date('n'),
            'U_FAQ'                  => $link_start . 'help',
            'U_SITES'                => $link_start . 'remote_site',
            'U_MAINTENANCE'          => $link_start . 'maintenance',
            'U_NOTIFICATION_BY_MAIL' => $link_start . 'notification_by_mail',
            'U_CONFIG_GENERAL'       => $link_start . 'configuration',
            'U_CONFIG_DISPLAY'       => $conf_link . 'default',
            'U_CONFIG_EXTENTS'       => $link_start . 'extend_for_templates',
            'U_CONFIG_MENUBAR'       => $link_start . 'menubar',
            'U_CONFIG_LANGUAGES'     => $link_start . 'languages',
            'U_CONFIG_THEMES'        => $link_start . 'themes',
            'U_CATEGORIES'           => $link_start . 'cat_list',
            'U_ALBUMS'               => $link_start . 'albums',
            'U_CAT_OPTIONS'          => $link_start . 'cat_options',
            'U_CAT_SEARCH'           => $link_start . 'cat_search',
            'U_CAT_UPDATE'           => $link_start . 'site_update&amp;site=1',
            'U_RATING'               => $link_start . 'rating',
            'U_RECENT_SET'           => $link_start . 'batch_manager&amp;filter=prefilter-last_import',
            'U_BATCH'                => $link_start . 'batch_manager',
            'U_TAGS'                 => $link_start . 'tags',
            'U_USERS'                => $link_start . 'user_list',
            'U_GROUPS'               => $link_start . 'group_list',
            'U_RETURN'               => get_gallery_home_url(),
            'U_ADMIN'                => PHPWG_ROOT_PATH . 'admin.php',
            'U_LOGOUT'               => PHPWG_ROOT_PATH . 'index.php?act=logout',
            'U_PLUGINS'              => $link_start . 'plugins',
            'U_ADD_PHOTOS'           => $link_start . 'photos_add',
            'U_CHANGE_THEME'         => $change_theme_url,
            'ADMIN_PAGE_TITLE'       => 'Piwigo Administration Page',
            'ADMIN_PAGE_OBJECT_ID'   => '',
            'U_SHOW_TEMPLATE_TAB'    => Config::showTemplateInSideMenu(),
            'SHOW_RATING'            => Config::rateEnabled(),
        ]);

        if (Config::enableCoreUpdate()) {
            $tpl->assign('U_UPDATES', $link_start . 'updates');
        }

        if (Config::activateComments()) {
            $tpl->assign('U_COMMENTS', $link_start . 'comments');
            $nb_comments = ServiceLocator::get(CommentRepository::class)->countUnvalidated();
            if ($nb_comments > 0) {
                $tpl->assign('NB_PENDING_COMMENTS', $nb_comments);
                $page['nb_pending_comments'] = $nb_comments;
            }
        }

        $nb_photos_in_caddie = ServiceLocator::get(UserRepository::class)
            ->countCaddieByUserId(is_numeric($user['id'] ?? null) ? (int) $user['id'] : 0);

        if ($nb_photos_in_caddie > 0) {
            $tpl->assign([
                'NB_PHOTOS_IN_CADDIE' => $nb_photos_in_caddie,
                'U_CADDIE'            => $link_start . 'batch_manager&amp;filter=prefilter-caddie',
            ]);
        } else {
            $tpl->assign([
                'NB_PHOTOS_IN_CADDIE' => 0,
                'U_CADDIE'            => '',
            ]);
        }

        if (in_array($adminPage, ['site_update', 'batch_manager'], true)) {
            $nb_no_md5sum = count(get_photos_no_md5sum());
            if ($nb_no_md5sum > 0) {
                $page['no_md5sum_number'] = $nb_no_md5sum;
            }
        }

        $page['nb_orphans']      = 0;
        $page['nb_photos_total'] = ServiceLocator::get(ImageRepository::class)->countAll();
        if ($page['nb_photos_total'] < 100000) {
            $page['nb_orphans'] = count_orphans();
        }

        $tpl->assign([
            'NB_ORPHANS' => $page['nb_orphans'],
            'U_ORPHANS'  => $link_start . 'batch_manager&amp;filter=prefilter-no_album',
        ]);

        // ── Refresh permissions ───────────────────────────────────────────────

        if (
            in_array($adminPage, ['site_manager', 'site_update'], true)
            || (!empty($_POST) && in_array($adminPage, ['album', 'albums', 'cat_options', 'user_list', 'user_perm'], true))
        ) {
            invalidate_user_cache();
        }

        // ── What's new ────────────────────────────────────────────────────────

        $show_whats_new          = false;
        $whats_new_major_version = get_branch_from_version(PHPWG_VERSION);

        if (userprefs_get_param('show_whats_new_' . $whats_new_major_version, true) && pwg_is_dbconf_writeable()) {
            $registrationDate = is_scalar($user['registration_date'] ?? null) ? (string) $user['registration_date'] : '';
            $lastMajorUpdate  = Config::lastMajorUpdate() ?? '';
            if ($registrationDate > $lastMajorUpdate) {
                userprefs_update_param('show_whats_new_' . $whats_new_major_version, false);
            } else {
                $userPreferences            = is_array($user['preferences'] ?? null) ? $user['preferences'] : [];
                $userprefs_params_to_delete = [];
                foreach (array_keys($userPreferences) as $pref_param) {
                    if (preg_match('/^whats_new_/', (string) $pref_param)) {
                        $userprefs_params_to_delete[] = $pref_param;
                    }
                }
                if (count($userprefs_params_to_delete) > 0) {
                    userprefs_delete_param($userprefs_params_to_delete);
                }
                $show_whats_new = true;
            }
        }

        $release_note_url = PHPWG_URL . '/releases/' . $whats_new_major_version . '.0.0';

        $whats_new_imgs = [
            '1' => 'https://ressources.piwigo.com/uploads/c/v/7/cv7jpz6hf8//2025/11/12/20251112112645-7e309b67.png',
            '2' => 'https://ressources.piwigo.com/uploads/c/v/7/cv7jpz6hf8//2025/11/12/20251112112645-61f2fcd0.png',
            '3' => 'https://ressources.piwigo.com/uploads/c/v/7/cv7jpz6hf8//2025/11/12/20251112112646-b322153b.png',
        ];

        $lastMajorTs  = strtotime(Config::lastMajorUpdate() ?? '');
        $display_bell = $lastMajorTs !== false && $lastMajorTs > strtotime('1 month ago');

        $tpl->assign([
            'SHOW_WHATS_NEW'          => $show_whats_new,
            'WHATS_NEW_MAJOR_VERSION' => $whats_new_major_version,
            'RELEASE_NOTE_URL'        => $release_note_url,
            'WHATS_NEW_IMGS'          => $whats_new_imgs,
            'DISPLAY_BELL'            => $display_bell,
        ]);

        // ── Album selector JSON ───────────────────────────────────────────────

        $tpl->assign('album_selector_page_data_json', json_encode([
            'str_album_modal_title'       => l10n('Select an album'),
            'str_album_modal_placeholder' => l10n('Search'),
            'str_no_search_in_progress'   => l10n('No search in progress'),
            'str_root'                    => l10n('Root'),
            'str_root_album_select'       => l10n('Root'),
            'str_album_selected'          => l10n('Album already selected'),
            'str_result_limit'            => l10n('<b>%d+</b> albums found, try to refine the search'),
            'str_album_found'             => l10n('<b>1</b> album found'),
            'str_albums_found'            => l10n('<b>%d</b> albums found'),
            'str_plus_albums_found'       => l10n('Only the first %d albums are displayed, out of %d.'),
            'str_create_and_select'       => l10n('Create and select'),
            'str_add_subcat_of'           => l10n('Add a sub-album to "%s"'),
            'str_complete_name_field'     => l10n('Name field must not be empty'),
            'str_an_error_has_occured'    => l10n('An error has occured'),
        ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));

        // ── Dispatch to admin sub-page ────────────────────────────────────────

        trigger_notify('loc_begin_admin_page');
        $this->dispatchToSubController($adminPage);

        $tpl->assign('ACTIVE_MENU', get_active_menu($adminPage));

        // ── Render ────────────────────────────────────────────────────────────

        $tpl->assign('pwgmenu', pwg_URL());

        require PHPWG_ROOT_PATH . 'include/page_header.php';

        trigger_notify('loc_end_admin');

        flush_page_messages();

        $tpl->pparse('admin');

        require PHPWG_ROOT_PATH . 'include/page_tail.php';

        return ResponseFactory::create(200);
    }

    private function dispatchToSubController(string $page): void
    {
        if (in_array($page, AlbumController::PAGES, true)) {
            new AlbumController()->handle($page);
        } elseif (in_array($page, PhotoController::PAGES, true)) {
            new PhotoController()->handle($page);
        } elseif (in_array($page, BatchManagerController::PAGES, true)) {
            new BatchManagerController()->handle($page);
        } elseif (in_array($page, ConfigurationController::PAGES, true)) {
            new ConfigurationController()->handle($page);
        } elseif (in_array($page, UsersController::PAGES, true)) {
            new UsersController()->handle($page);
        } elseif (in_array($page, GroupsController::PAGES, true)) {
            new GroupsController()->handle($page);
        } elseif (in_array($page, ExtensionsController::PAGES, true)) {
            new ExtensionsController()->handle($page);
        } elseif (in_array($page, MaintenanceController::PAGES, true)) {
            new MaintenanceController()->handle($page);
        } else {
            new MiscController()->handle($page);
        }
    }
}
