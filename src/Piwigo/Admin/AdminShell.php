<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin;

use Piwigo\Admin\Maintenance\FilesystemIntegrityChecker;
use Piwigo\Admin\Projection\AdminShellFramePageContext;
use Piwigo\Admin\Projection\AdminShellPostDispatchPageContext;
use Piwigo\Admin\Request\AdminShellRequest;
use Piwigo\Auth\AccessControl;
use Piwigo\Bootstrap\AdminDispatcher;
use Piwigo\Bootstrap\PageTail;
use Piwigo\Cache\PermissionCacheInvalidator;
use Piwigo\Caddie\CaddieEntity;
use Piwigo\Comment\CommentService;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Controller\Admin\AdminSubControllerInterface;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Core\VersionHelper;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Event\Admin\TabsheetBeforeSelect;
use Piwigo\Event\Location\LocBeginAdmin;
use Piwigo\Event\Location\LocBeginAdminPage;
use Piwigo\Event\Location\LocEndAdmin;
use Piwigo\Html\HtmlService;
use Piwigo\Http\RequestFactory;
use Piwigo\Http\ResponseEmitter;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Image\ImageService;
use Piwigo\Page\PageHeaderRenderer;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PreferencesService;
use Piwigo\Users\UserService;
use Piwigo\Validation\InputValidator;

/**
 * The admin.php page-shell orchestration: access check, direct GET
 * actions, page-slug aliasing/validation, the admin frame's template
 * assigns (pending comments/caddie/orphans counters, whats-new),
 * sub-controller dispatch, and final page render -- a thin bootstrap
 * shell matching index.php's own final form.
 *
 * $this->pageState carries page_banner/body_id/nb_pending_comments/
 * no_md5sum_number/nb_orphans/nb_photos_total, consumed by
 * IntroSubController/PageHeaderRenderer/FilterPanelRenderer/SiteUpdateSubController.
 */
final readonly class AdminShell
{
    public function __construct(
        private Lang $lang,
        private AccessControl $accessControl,
        private RedirectServiceInterface $redirectService,
        private UrlServiceInterface $urlService,
        private ConfigService $configService,
        private Paths $paths,
        private FilesystemIntegrityChecker $filesystemIntegrityChecker,
        private CoreTabs $coreTabs,
        private SessionService $sessionService,
        private EventDispatcher $eventDispatcher,
        private DeploymentPolicy $deploymentPolicy,
        private PageState $pageState,
        private CurrentUser $currentUser,
        private CurrentTemplate $currentTemplate,
        private CommentService $commentService,
        private ImageService $imageService,
        private PreferencesService $preferencesService,
        private UserService $userService,
        private HtmlService $htmlService,
        private CurrentConfig $currentConfig,
        private InputValidator $inputValidator,
    ) {}

    /**
     * runDispatch() holds the full page-shell body, extracted into its own
     * method (rather than wrapping its ~430 lines in a try block directly)
     * so this thin wrapper is the one, easily-reviewed place that catches
     * Piwigo\Http\ResponseReadyException. AdminShell has no PSR-7
     * request/response of its own, so this reuses Piwigo\Http\
     * ResponseEmitter for just this exceptional-exit path.
     */
    public function run(): void
    {
        try {
            $this->runDispatch();
        } catch (ResponseReadyException $e) {
            new ResponseEmitter()
                ->emit($e->response());
        }
    }

    private function runDispatch(): void
    {
        $template = $this->currentTemplate->get();
        $conn = DbConnection::build();

        $this->eventDispatcher->addTypedHandler(TabsheetBeforeSelect::class, $this->coreTabs->addCoreTabs(...));

        $this->eventDispatcher->dispatchNotify(new LocBeginAdmin());

        $this->accessControl->checkStatus(AccessLevel::Administrator);

        $adminShellRequest = AdminShellRequest::fromGlobals($this->inputValidator);

        if ($this->currentConfig->fsQuickCheckPeriod > 0) {
            $perform_fsqc = false;

            // Real invariant: fs_quick_check_last_check is only written (as an ISO
            // 8601 string, see fs_quick_check()) once the quick check has run at
            // least once — on a fresh install it's genuinely still null, so this
            // is a real "has it ever run" guard, not just type-narrowing
            // boilerplate.
            $fs_quick_check_last_check = $this->currentConfig->fsQuickCheckLastCheck;

            if ($fs_quick_check_last_check !== null) {
                $fs_quick_check_period = $this->currentConfig->fsQuickCheckPeriod;
                if (strtotime($fs_quick_check_last_check) < strtotime($fs_quick_check_period . ' seconds ago')) {
                    $perform_fsqc = true;
                }
            } else {
                $perform_fsqc = true;
            }

            if ($perform_fsqc) {
                $this->filesystemIntegrityChecker->fsQuickCheck();
            }
        }

        // save plugins_new display order (AJAX action)
        if ($adminShellRequest->pluginsNewOrderPresent) {
            $this->sessionService->setSessionVar('plugins_new_order', $adminShellRequest->pluginsNewOrder);
            exit;
        }

        // theme changer
        if ($adminShellRequest->changeThemePresent) {
            $admin_themes = ['roma', 'clear'];
            $admin_theme_param = $this->preferencesService->getAdminThemePref() ?? $this->currentConfig->adminTheme;
            $admin_theme_array = [$admin_theme_param];
            $result = array_diff(
                $admin_themes,
                $admin_theme_array
            );

            $new_admin_theme = array_pop(
                $result
            );

            $this->preferencesService
                ->updateParam('admin_theme', $new_admin_theme);

            $url_params = [];
            foreach ($adminShellRequest->changeThemeUrlParams as $url_param => $url_value) {
                $url_params[] = $url_param . '=' . $url_value;
            }

            $redirect_url = 'admin.php';
            if (count($url_params) > 0) {
                $redirect_url .= '?' . implode('&amp;', $url_params);
            }

            $this->redirectService->redirect($redirect_url);
        }

        // sync_user() is only useful when external authentication is activated
        if ($this->deploymentPolicy->externalAuthentification) {
            $this->userService->syncUsers();
        }

        $change_theme_url = $this->urlService->getRootUrl() . 'admin.php?';
        $test_get = $adminShellRequest->testGet;
        $query_string = $_SERVER['QUERY_STRING'] ?? null;
        if (count($test_get) === 0 and is_string($query_string) and $query_string !== '') {
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

        // A valid page slug is one registered in config/admin_pages.php --
        // every admin page is dispatched to an AdminDispatcher
        // sub-controller. Anything else falls back to 'intro'.
        /** @var array<string, class-string<AdminSubControllerInterface>> $admin_pages */
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
        $this->inputValidator
            ->validate('tab', $_GET, false, '/^[a-zA-Z\d_-]+$/');

        $title = $this->lang->t('Piwigo Administration'); // for the PageHeaderRenderer::render() call below
        $this->pageState->setPageBanner('<h1>' . $this->lang->t('Piwigo Administration') . '</h1>');
        $this->pageState->setBodyId('theAdminPage');

        $u_updates = null;
        if ($this->currentConfig->enableCoreUpdate) {
            $u_updates = $link_start . 'updates';
        }

        $u_comments = null;
        $nb_pending_comments = null;
        if ($this->currentConfig->activateComments) {
            $u_comments = $link_start . 'comments';

            // pending comments
            $nb_comments = $this->commentService->countUnvalidated();

            if ($nb_comments > 0) {
                $nb_pending_comments = $nb_comments;
                $this->pageState->setNbPendingComments($nb_comments);
            }
        }

        // any photo in the caddie?
        $user_id = $this->currentUser->get()
            ->id->value;
        $nb_photos_in_caddie = count(EntityManagerFactory::build($conn)->getRepository(CaddieEntity::class)->findElementIdsForUser($user_id));

        if ($nb_photos_in_caddie > 0) {
            $u_caddie = $link_start . 'batch_manager&amp;filter=prefilter-caddie';
        } else {
            $nb_photos_in_caddie = 0;
            $u_caddie = '';
        }

        // any photos with no md5sum ?
        if (in_array($page_slug, ['site_update', 'batch_manager'], true)) {
            $imageService = $this->imageService;

            $nb_no_md5sum = count($imageService->getPhotosNoMd5sum());

            if ($nb_no_md5sum > 0) {
                $this->pageState->setNoMd5sumNumber($nb_no_md5sum);
            }
        }

        // only calculate number of orphans on all pages if the number of images is "not huge"
        $nb_orphans = 0;

        $nb_photos_total = $this->imageService->getTotalImageCount();
        if ($nb_photos_total < 100000) { // 100k is already a big gallery
            $nb_orphans = $this->imageService
                ->countOrphans();
        }
        $this->pageState->setNbPhotosTotal($nb_photos_total);
        $this->pageState->setNbOrphans($nb_orphans);

        $u_orphans = $link_start . 'batch_manager&amp;filter=prefilter-no_album';

        // Only for pages witch change permissions
        if (
            in_array(
                $page_slug,
                [
                    'site_manager', // delete site
                    'site_update',  // ?only POST
                ],
                true
            )
            or ($adminShellRequest->isPostNonEmpty and in_array(
                $page_slug,
                [
                    'album',        // public/private; lock/unlock, permissions
                    'albums',
                    'cat_options',  // public/private; lock/unlock
                    'user_list',    // group assoc; user level
                    'user_perm',
                ],
                true
            )
            )
        ) {
            PermissionCacheInvalidator::invalidate();
        }

        $show_whats_new = false;

        $whats_new_major_version = VersionHelper::getBranchFromVersion(AppInfo::VERSION);

        if ((bool) $this->preferencesService->getParam('show_whats_new_' . $whats_new_major_version, true) and $this->configService->pwgIsDbconfWriteable()) {
            // >=, not > -- confirmed live (VR suite regression, 2026-08-04)
            // that a fresh install/fixture-regen genuinely produces the
            // exact same timestamp for both sides: registration_date
            // (UserService::createUserInfos()) and last_major_update
            // (RequestBootstrap, "if not set, set it now") are both stamped
            // via Env::now(), which resolves to the identical frozen
            // PIWIGO_TEST_NOW instant in test mode regardless of real
            // elapsed time between the two writes. A strict `>` can never
            // be true for two genuinely-equal values, so a brand-new
            // account created at (or immediately after) install always
            // wrongly saw the popin, every time, on every fresh install --
            // and would keep failing this same way even if last_major_update
            // used the real DB clock instead, since that only "worked" by
            // coincidence for as long as regens happened to run before the
            // frozen reference date; production timing has since caught up
            // to and passed it. `>=` is also the correct semantic for this
            // exact-equal case on its own terms: an account created at the
            // same moment as the major-update stamp has no earlier state to
            // be notified about.
            if ($this->currentUser->get()->rawAttributes['registration_date'] >= $this->currentConfig->lastMajorUpdate) {
                $this->preferencesService
                    ->updateParam('show_whats_new_' . $whats_new_major_version, false);
            } else {
                // purge old whats_new_*
                $user_preferences = $this->currentUser->get()
                    ->preferences;
                $userprefs_params_to_delete = [];

                foreach (array_keys($user_preferences) as $pref_param) {
                    if ((bool) preg_match('/^whats_new_/', $pref_param)) {
                        $userprefs_params_to_delete[] = $pref_param;
                    }
                }

                if (count($userprefs_params_to_delete) > 0) {
                    $this->preferencesService
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
        // a DB NOW() string (see the `\Piwigo\Config\CurrentConfig::lastMajorUpdate() === null`
        // block there) before this shell runs.
        $display_bell = false;
        $last_major_update = $this->currentConfig->lastMajorUpdate;
        if (is_string($last_major_update) and strtotime($last_major_update) > strtotime('1 month ago')) {
            $display_bell = true;
        }

        $template->assignContext(new AdminShellFramePageContext(
            username: $this->currentUser->get()
                ->username?->value,
            enableSynchronization: $this->currentConfig->enableSynchronization,
            uSiteManager: $link_start . 'site_manager',
            uHistoryStat: $link_start . 'stats&amp;year=' . date('Y') . '&amp;month=' . date('n'),
            uFaq: $link_start . 'help',
            uMaintenance: $link_start . 'maintenance',
            uNotificationByMail: $link_start . 'notification_by_mail',
            uConfigGeneral: $link_start . 'configuration',
            uConfigDisplay: $conf_link . 'default',
            uConfigExtents: $link_start . 'extend_for_templates',
            uConfigMenubar: $link_start . 'menubar',
            uConfigLanguages: $link_start . 'languages',
            uConfigThemes: $link_start . 'themes',
            uCategories: $link_start . 'cat_list',
            uAlbums: $link_start . 'albums',
            uCatOptions: $link_start . 'cat_options',
            uCatUpdate: $link_start . 'site_update&amp;site=1',
            uRating: $link_start . 'rating',
            uRecentSet: $link_start . 'batch_manager&amp;filter=prefilter-last_import',
            uBatch: $link_start . 'batch_manager',
            uTags: $link_start . 'tags',
            uUsers: $link_start . 'user_list',
            uGroups: $link_start . 'group_list',
            uReturn: $this->urlService->getGalleryHomeUrl(),
            uAdmin: $this->urlService->getRootUrl() . 'admin.php',
            uLogout: $this->urlService->getRootUrl() . 'index.php?act=logout',
            uPlugins: $link_start . 'plugins',
            uAddPhotos: $link_start . 'photos_add',
            uChangeTheme: $change_theme_url,
            adminPageTitle: 'Piwigo Administration Page',
            adminPageObjectId: '',
            uShowTemplateTab: $this->currentConfig->showTemplateInSideMenu,
            showRating: $this->currentConfig->rateEnabled,
            uUpdates: $u_updates,
            uComments: $u_comments,
            nbPendingComments: $nb_pending_comments,
            nbPhotosInCaddie: $nb_photos_in_caddie,
            uCaddie: $u_caddie,
            nbOrphans: $nb_orphans,
            uOrphans: $u_orphans,
            showWhatsNew: $show_whats_new,
            whatsNewMajorVersion: $whats_new_major_version,
            releaseNoteUrl: $release_note_url,
            whatsNewImgs: $whats_new_imgs,
            displayBell: $display_bell,
        ));

        $this->eventDispatcher->dispatchNotify(new LocBeginAdminPage());

        // SEC-19: sub-controllers read input from this PSR-7 request
        // (getQueryParams()/getParsedBody()), not $_GET/$_POST directly.
        AdminDispatcher::dispatch($page_slug, RequestFactory::fromGlobals());

        // Add the Piwigo Official menu
        $template->assignContext(new AdminShellPostDispatchPageContext(
            activeMenu: AdminUiHelper::getActiveMenu($page_slug),
            pwgmenu: AdminUiHelper::pwgUrl(),
        ));

        new PageHeaderRenderer()
            ->render($title, $this->eventDispatcher, $this->pageState, $this->currentTemplate, $this->currentConfig);

        $this->eventDispatcher->dispatchNotify(new LocEndAdmin());

        $this->htmlService
            ->flushPageMessages();

        $template->pparse('admin.latte');

        PageTail::render();
    }
}
