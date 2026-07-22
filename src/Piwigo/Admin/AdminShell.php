<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin;

use Doctrine\DBAL\Connection;
use Piwigo\Activity\ActivityRepository;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\Maintenance\FilesystemIntegrityChecker;
use Piwigo\Bootstrap\AdminDispatcher;
use Piwigo\Bootstrap\PageTail;
use Piwigo\Cache\UserCacheInvalidator;
use Piwigo\Config\ConfigService;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Group\GroupRepository;
use Piwigo\Html\HtmlService;
use Piwigo\Http\RequestFactory;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageService;
use Piwigo\Mail\MailService;
use Piwigo\Session\SessionService;
use Piwigo\Template\Template;
use Piwigo\Users\PreferencesService;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;

/**
 * The admin.php page-shell orchestration (P23 batch 10): access check,
 * direct GET actions, page-slug aliasing/validation, the admin frame's
 * template assigns (pending comments/caddie/orphans counters, whats-new),
 * sub-controller dispatch, and final page render. Folded here verbatim
 * from admin.php's former top-level body so the root file is a thin
 * bootstrap shell matching index.php's own final form.
 *
 * Legacy Coupling Retirement Track A batch A5.2i: the last real `global
 * $conf;`/`global $page;` reads/writes in run() are gone -- $conf was
 * dead (never referenced), $page's keys (page_banner/body_id/
 * nb_pending_comments/no_md5sum_number/nb_orphans/nb_photos_total) are
 * all PageState::current() calls now, consumed by IntroSubController/
 * PageHeaderRenderer/FilterPanelRenderer/SiteUpdateSubController.
 */
final class AdminShell
{
    public function __construct(
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
        private readonly ConfigService $configService,
        private readonly \Piwigo\Core\Paths $paths,
    ) {}

    /**
     * DRY extraction (Phase 1k DI-chain audit): the same ImageService
     * recipe was repeated verbatim at 2 sites in this file.
     */
    private static function imageService(Connection $conn): ImageService
    {
        return new ImageService(new ImageRepository($conn), new ActivityService(new ActivityRepository($conn)));
    }

    public function run(): void
    {
        $template = \Piwigo\Template\CurrentTemplate::get();
        $conn = DbConnection::build();

        CoreTabs::setUrlService($this->urlService);
        \Piwigo\PluginConfig\EventDispatcher::get()->addEventHandler('tabsheet_before_select', CoreTabs::addCoreTabs(...));

        \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('loc_begin_admin');

        // +-------------------------------------------------------------------+
        // | Check Access and exit when user status is not ok                  |
        // +-------------------------------------------------------------------+

        \Piwigo\Auth\AccessControl::checkStatus(AccessLevel::Administrator);

        new \Piwigo\Validation\InputValidator()
            ->validate('page', $_GET, false, '/^[a-zA-Z\d_-]+$/');
        new \Piwigo\Validation\InputValidator()
            ->validate('section', $_GET, false, '/^[a-z]+[a-z_\/-]*(\.php)?$/i');

        // +-------------------------------------------------------------------+
        // | Filesystem checks                                                 |
        // +-------------------------------------------------------------------+

        if (\Piwigo\Config\Config::fsQuickCheckPeriod() > 0) {
            $perform_fsqc = false;

            // Real invariant: fs_quick_check_last_check is only written (as an ISO
            // 8601 string, see fs_quick_check()) once the quick check has run at
            // least once — on a fresh install the config key genuinely does not
            // exist yet, so this isset() is a real "has it ever run" guard, not
            // just type-narrowing boilerplate.
            $fs_quick_check_last_check = \Piwigo\Config\Config::has('fs_quick_check_last_check') && is_string(\Piwigo\Config\Config::fsQuickCheckLastCheck())
                ? \Piwigo\Config\Config::fsQuickCheckLastCheck()
                : null;

            if ($fs_quick_check_last_check !== null) {
                $fs_quick_check_period = \Piwigo\Config\Config::fsQuickCheckPeriod();
                if (is_numeric($fs_quick_check_period) and strtotime($fs_quick_check_last_check) < strtotime($fs_quick_check_period . ' seconds ago')) {
                    $perform_fsqc = true;
                }
            } else {
                $perform_fsqc = true;
            }

            if ($perform_fsqc) {
                FilesystemIntegrityChecker::fsQuickCheck();
            }
        }

        // +-------------------------------------------------------------------+
        // | Direct actions                                                    |
        // +-------------------------------------------------------------------+

        // save plugins_new display order (AJAX action)
        if (isset($_GET['plugins_new_order'])) {
            SessionService::get()->setSessionVar('plugins_new_order', $_GET['plugins_new_order']);
            exit;
        }

        // theme changer
        if (isset($_GET['change_theme'])) {
            $admin_themes = ['roma', 'clear'];
            $admin_theme_param = new PreferencesService(new UserRepository($conn))
                ->getParam('admin_theme', 'clear');
            $admin_theme_array = [is_string($admin_theme_param) ? $admin_theme_param : 'clear'];
            $result = array_diff(
                $admin_themes,
                $admin_theme_array
            );

            $new_admin_theme = array_pop(
                $result
            );

            new PreferencesService(new UserRepository($conn))
                ->updateParam('admin_theme', $new_admin_theme);

            $url_params = [];
            foreach (['page', 'tab', 'section'] as $url_param) {
                if (isset($_GET[$url_param]) and is_scalar($_GET[$url_param])) {
                    $url_params[] = $url_param . '=' . $_GET[$url_param];
                }
            }

            $redirect_url = 'admin.php';
            if (count($url_params) > 0) {
                $redirect_url .= '?' . implode('&amp;', $url_params);
            }

            $this->redirectService->redirect($redirect_url);
        }

        // +-------------------------------------------------------------------+
        // | Synchronize user informations                                     |
        // +-------------------------------------------------------------------+

        // sync_user() is only useful when external authentication is activated
        if (\Piwigo\Config\Config::externalAuthentification()) {
            new UserService(
                new UserRepository($conn),
                new GroupRepository($conn),
                new MailService(),
                new ActivityService(new ActivityRepository($conn)),
                new HtmlService(),
                $conn
            )->syncUsers();
        }

        // +-------------------------------------------------------------------+
        // | Variables init                                                    |
        // +-------------------------------------------------------------------+

        $change_theme_url = $this->urlService->getRootUrl() . 'admin.php?';
        $test_get = $_GET;
        unset($test_get['page']);
        unset($test_get['section']);
        unset($test_get['tag']);
        $query_string = $_SERVER['QUERY_STRING'] ?? null;
        if (count($test_get) == 0 and is_string($query_string) and ! empty($query_string)) {
            $change_theme_url .= str_replace('&', '&amp;', $query_string) . '&amp;';
        }
        $change_theme_url .= 'change_theme=1';

        // ?page=plugin-community-pendings is an clean alias of
        // ?page=plugin&section=community/admin.php&tab=pendings
        if (isset($_GET['page']) and is_string($_GET['page']) and (bool) preg_match('/^plugin-([^-]*)(?:-(.*))?$/', $_GET['page'], $matches)) {
            $_GET['page'] = 'plugin';

            if ((bool) preg_match('/^piwigo_(videojs|openstreetmap)$/', $matches[1])) {
                $matches[1] = str_replace('_', '-', $matches[1]);
            }

            $_GET['section'] = $matches[1] . '/admin.php';
            if (isset($matches[2])) {
                $_GET['tab'] = $matches[2];
            }
        }

        // ?page=album-134-properties is an clean alias of
        // ?page=album&cat_id=134&tab=properties
        if (isset($_GET['page']) and is_string($_GET['page']) and (bool) preg_match('/^album-(\d+)(?:-(.*))?$/', $_GET['page'], $matches)) {
            $_GET['page'] = 'album';
            $_GET['cat_id'] = $matches[1];
            if (isset($matches[2])) {
                $_GET['tab'] = $matches[2];
            }
        }

        // ?page=photo-1234-properties is an clean alias of
        // ?page=photo&image_id=1234&tab=properties
        if (isset($_GET['page']) and is_string($_GET['page']) and (bool) preg_match('/^photo-(\d+)(?:-(.*))?$/', $_GET['page'], $matches)) {
            $_GET['page'] = 'photo';
            $_GET['image_id'] = $matches[1];
            if (isset($matches[2])) {
                $_GET['tab'] = $matches[2];
            }
        }

        // A valid page slug is exactly "registered in config/admin_pages.php" --
        // every admin page has been an AdminDispatcher sub-controller since P23
        // batch 6, and batch 9 removed the transitional is_file() half of this
        // check (admin/ holds no page files anymore, so it only matched the
        // anti-listing stub and popuphelp.php, neither a real admin page).
        // Anything else falls back to 'intro'.
        /** @var array<string, class-string<\Piwigo\Controller\Admin\AdminSubControllerInterface>> $admin_pages */
        $admin_pages = require $this->paths->root . 'config/admin_pages.php';

        if (isset($_GET['page'])
            and is_string($_GET['page'])
            and (bool) preg_match('/^[a-z_]*$/', $_GET['page'])
            and array_key_exists($_GET['page'], $admin_pages)) {
            $page_slug = $_GET['page'];
        } else {
            $page_slug = 'intro';
        }

        $link_start = $this->urlService->getRootUrl() . 'admin.php?page=';
        $conf_link = $link_start . 'configuration&amp;section=';

        // $_GET['tab'] is often used to perform and
        // include('admin_page_'.$_GET['tab'].'.php') : we need to protect it to
        // avoid any unexpected file inclusion
        new \Piwigo\Validation\InputValidator()
            ->validate('tab', $_GET, false, '/^[a-zA-Z\d_-]+$/');

        // +-------------------------------------------------------------------+
        // | Template init                                                     |
        // +-------------------------------------------------------------------+

        $title = Lang::t('Piwigo Administration'); // for the PageHeaderRenderer::render() call below
        \Piwigo\Core\PageState::current()->setPageBanner('<h1>' . Lang::t('Piwigo Administration') . '</h1>');
        \Piwigo\Core\PageState::current()->setBodyId('theAdminPage');

        $template->set_filenames([
            'admin' => 'admin.tpl',
        ]);

        $template->assign(
            [
                'USERNAME' => \Piwigo\Users\CurrentUser::get()->username,
                'ENABLE_SYNCHRONIZATION' => \Piwigo\Config\Config::enableSynchronization(),
                'U_SITE_MANAGER' => $link_start . 'site_manager',
                'U_HISTORY_STAT' => $link_start . 'stats&amp;year=' . date('Y') . '&amp;month=' . date('n'),
                'U_FAQ' => $link_start . 'help',
                'U_MAINTENANCE' => $link_start . 'maintenance',
                'U_NOTIFICATION_BY_MAIL' => $link_start . 'notification_by_mail',
                'U_CONFIG_GENERAL' => $link_start . 'configuration',
                'U_CONFIG_DISPLAY' => $conf_link . 'default',
                'U_CONFIG_EXTENTS' => $link_start . 'extend_for_templates',
                'U_CONFIG_MENUBAR' => $link_start . 'menubar',
                'U_CONFIG_LANGUAGES' => $link_start . 'languages',
                'U_CONFIG_THEMES' => $link_start . 'themes',
                'U_CATEGORIES' => $link_start . 'cat_list',
                'U_ALBUMS' => $link_start . 'albums',
                'U_CAT_OPTIONS' => $link_start . 'cat_options',
                'U_CAT_UPDATE' => $link_start . 'site_update&amp;site=1',
                'U_RATING' => $link_start . 'rating',
                'U_RECENT_SET' => $link_start . 'batch_manager&amp;filter=prefilter-last_import',
                'U_BATCH' => $link_start . 'batch_manager',
                'U_TAGS' => $link_start . 'tags',
                'U_USERS' => $link_start . 'user_list',
                'U_GROUPS' => $link_start . 'group_list',
                'U_RETURN' => $this->urlService->getGalleryHomeUrl(),
                'U_ADMIN' => $this->urlService->getRootUrl() . 'admin.php',
                'U_LOGOUT' => $this->urlService->getRootUrl() . 'index.php?act=logout',
                'U_PLUGINS' => $link_start . 'plugins',
                'U_ADD_PHOTOS' => $link_start . 'photos_add',
                'U_CHANGE_THEME' => $change_theme_url,
                'ADMIN_PAGE_TITLE' => 'Piwigo Administration Page',
                'ADMIN_PAGE_OBJECT_ID' => '',
                'U_SHOW_TEMPLATE_TAB' => \Piwigo\Config\Config::showTemplateInSideMenu(),
                'SHOW_RATING' => \Piwigo\Config\Config::rateEnabled(),
            ]
        );

        if (\Piwigo\Config\Config::enableCoreUpdate()) {
            $template->assign('U_UPDATES', $link_start . 'updates');
        }

        if (\Piwigo\Config\Config::activateComments()) {
            $template->assign('U_COMMENTS', $link_start . 'comments');

            // pending comments
            $query = '
SELECT COUNT(*)
  FROM ' . Tables::comments() . '
  WHERE validated=\'false\'
;';
            $row = $conn->fetchNumeric($query);
            $nb_comments = $row !== false ? $row[0] : 0;

            if ($nb_comments > 0) {
                $template->assign('NB_PENDING_COMMENTS', $nb_comments);
                \Piwigo\Core\PageState::current()->setNbPendingComments(is_numeric($nb_comments) ? (int) $nb_comments : 0);
            }
        }

        // any photo in the caddie?
        $user_id = \Piwigo\Users\CurrentUser::get()->id;
        $query = '
SELECT COUNT(*)
  FROM ' . Tables::caddie() . '
  WHERE user_id = ' . $user_id . '
;';
        $row = $conn->fetchNumeric($query);
        $nb_photos_in_caddie = $row !== false ? $row[0] : 0;

        if ($nb_photos_in_caddie > 0) {
            $template->assign(
                [
                    'NB_PHOTOS_IN_CADDIE' => $nb_photos_in_caddie,
                    'U_CADDIE' => $link_start . 'batch_manager&amp;filter=prefilter-caddie',
                ]
            );
        } else {
            $template->assign(
                [
                    'NB_PHOTOS_IN_CADDIE' => 0,
                    'U_CADDIE' => '',
                ]
            );
        }

        // any photos with no md5sum ?
        if (in_array($page_slug, ['site_update', 'batch_manager'])) {
            $imageService = self::imageService($conn);

            $nb_no_md5sum = count($imageService->getPhotosNoMd5sum());

            if ($nb_no_md5sum > 0) {
                \Piwigo\Core\PageState::current()->setNoMd5sumNumber($nb_no_md5sum);
            }
        }

        // only calculate number of orphans on all pages if the number of images is "not huge"
        $nb_orphans = 0;

        $row = $conn->fetchNumeric('SELECT COUNT(*) FROM ' . Tables::images());
        $nb_photos_total_raw = $row !== false ? $row[0] : 0;
        $nb_photos_total = is_numeric($nb_photos_total_raw) ? (int) $nb_photos_total_raw : 0;
        if ($nb_photos_total < 100000) { // 100k is already a big gallery
            $nb_orphans_raw = self::imageService($conn)
                ->countOrphans();
            $nb_orphans = is_numeric($nb_orphans_raw) ? (int) $nb_orphans_raw : 0;
        }
        \Piwigo\Core\PageState::current()->setNbPhotosTotal($nb_photos_total);
        \Piwigo\Core\PageState::current()->setNbOrphans($nb_orphans);

        $template->assign(
            [
                'NB_ORPHANS' => $nb_orphans,
                'U_ORPHANS' => $link_start . 'batch_manager&amp;filter=prefilter-no_album',
            ]
        );

        // +-------------------------------------------------------------------+
        // | Refresh permissions                                               |
        // +-------------------------------------------------------------------+

        // Only for pages witch change permissions
        if (
            in_array(
                $page_slug,
                [
                    'site_manager', // delete site
                    'site_update',  // ?only POST
                ]
            )
            or (! empty($_POST) and in_array(
                $page_slug,
                [
                    'album',        // public/private; lock/unlock, permissions
                    'albums',
                    'cat_options',  // public/private; lock/unlock
                    'user_list',    // group assoc; user level
                    'user_perm',
                ]
            )
            )
        ) {
            UserCacheInvalidator::invalidate();
        }

        $show_whats_new = false;

        $whats_new_major_version = \Piwigo\Core\VersionHelper::getBranchFromVersion(AppInfo::VERSION);

        if ((bool) new PreferencesService(new UserRepository($conn))->getParam('show_whats_new_' . $whats_new_major_version, true) and $this->configService->pwgIsDbconfWriteable()) {
            if (\Piwigo\Users\CurrentUser::get()->rawAttributes['registration_date'] > \Piwigo\Config\Config::lastMajorUpdate()) {
                new PreferencesService(new UserRepository($conn))
                    ->updateParam('show_whats_new_' . $whats_new_major_version, false);
            } else {
                // purge old whats_new_*
                $user_preferences = \Piwigo\Users\CurrentUser::get()->preferences;
                $userprefs_params_to_delete = [];

                foreach (array_keys($user_preferences) as $pref_param) {
                    if ((bool) preg_match('/^whats_new_/', $pref_param)) {
                        $userprefs_params_to_delete[] = $pref_param;
                    }
                }

                if (count($userprefs_params_to_delete) > 0) {
                    new PreferencesService(new UserRepository($conn))
                        ->deleteParam($userprefs_params_to_delete);
                }

                $show_whats_new = true;
            }
        }

        $release_note_url = AppInfo::URL . '/releases/' . $whats_new_major_version . '.0.0';

        $whats_new_imgs = [
            '1' => 'https://upstream.example.invalid/whats-new-1.png',
            '2' => 'https://upstream.example.invalid/whats-new-2.png',
            '3' => 'https://upstream.example.invalid/whats-new-3.png',
        ];

        // If last major update conf is less than a month old then display bell for whats new popin
        // Real invariant: include/common.inc.php always writes last_major_update as
        // a DB NOW() string (see the `! \Piwigo\Config\Config::has('last_major_update')` block
        // there) before this shell runs.
        $display_bell = false;
        $last_major_update = \Piwigo\Config\Config::lastMajorUpdate();
        if (is_string($last_major_update) and strtotime($last_major_update) > strtotime('1 month ago')) {
            $display_bell = true;
        }

        $template->assign(
            [
                'SHOW_WHATS_NEW' => $show_whats_new,
                'WHATS_NEW_MAJOR_VERSION' => $whats_new_major_version,
                'RELEASE_NOTE_URL' => $release_note_url,
                'WHATS_NEW_IMGS' => $whats_new_imgs,
                'DISPLAY_BELL' => $display_bell,
            ]
        );

        // +-------------------------------------------------------------------+
        // | Include specific page                                             |
        // +-------------------------------------------------------------------+

        \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('loc_begin_admin_page');

        // SEC-19: sub-controllers read input from this PSR-7 request
        // (getQueryParams()/getParsedBody()), not $_GET/$_POST directly.
        AdminDispatcher::dispatch($page_slug, RequestFactory::fromGlobals());

        $template->assign('ACTIVE_MENU', AdminUiHelper::getActiveMenu($page_slug));

        // +-------------------------------------------------------------------+
        // | Sending html code                                                 |
        // +-------------------------------------------------------------------+

        // Add the Piwigo Official menu
        $template->assign('pwgmenu', AdminUiHelper::pwgUrl());

        new \Piwigo\Page\PageHeaderRenderer()
            ->render($title);

        \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('loc_end_admin');

        new HtmlService()
            ->flushPageMessages();

        $template->pparse('admin');

        PageTail::render();
    }
}
