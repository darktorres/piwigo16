<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\AdminPageRegistry;
use Piwigo\Admin\AdminService;
use Piwigo\Admin\Image\ImageAdminService;
use Piwigo\Admin\Users\UserAdminService;
use Piwigo\Comment\CommentRepository;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigService;
use Piwigo\Controller\ControllerInterface;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\StringUtil;
use Piwigo\Event\Admin\AdminPagesRegistering;
use Piwigo\Event\Location\LocBeginAdmin;
use Piwigo\Event\Location\LocBeginAdminPage;
use Piwigo\Event\Location\LocEndAdmin;
use Piwigo\Html\HtmlService;
use Piwigo\Http\RedirectResponder;
use Piwigo\Http\RequestContext;
use Piwigo\Http\RequestContextRegistry;
use Piwigo\Http\ResponseFactory;
use Piwigo\Image\ImageRepository;
use Piwigo\Page\PageHeaderRenderer;
use Piwigo\Page\PageTailRenderer;
use Piwigo\Session\SessionService;
use Piwigo\Template\Template;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlGenerator;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Piwigo\Users\PreferencesService;
use Piwigo\Users\UserRepository;
use Piwigo\Validation\InputValidator;
use Psr\EventDispatcher\EventDispatcherInterface;
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
final readonly class AdminController implements ControllerInterface
{
    public function __construct(
        private AdminService $adminService,
        private CommentRepository $commentRepository,
        private ConfigService $configService,
        private HtmlService $htmlService,
        private ImageAdminService $imageAdminService,
        private ImageRepository $imageRepository,
        private PermissionService $permissionService,
        private PreferencesService $preferencesService,
        private SessionService $sessionService,
        private UrlGenerator $urlGenerator,
        private UrlService $urlService,
        private UserAdminService $userAdminService,
        private UserRepository $userRepository,
        private InputValidator $inputValidator,
        private RedirectResponder $redirectResponder,
        private EventDispatcherInterface $dispatcher,
        private AdminPageRegistry $pageRegistry,
        private Paths $paths,
    ) {
    }

    #[\Override]
    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        RequestContextRegistry::set(RequestContext::Admin);

        // common.inc.php creates the frontend template before the controller
        // runs. Replace it with the admin-theme template now.
        $admin_theme_raw = $this->preferencesService->userprefsGetParam('admin_theme', 'dark');
        $admin_theme     = is_scalar($admin_theme_raw) ? (string) $admin_theme_raw : 'dark';
        $adminTpl        = new Template($this->paths->root . 'themes/admin', $admin_theme);
        TemplateRegistry::set($adminTpl);

        /** @var array<string, mixed> $user */
        $user = CurrentUser::get()->rawAttributes;

        // tabsheet_before_select listener now registers at boot via
        // Piwigo\Listener\TabsheetBeforeSelectSubscriber.
        $this->dispatcher->dispatch(new LocBeginAdmin());

        // Build the admin-page registry: core pages register first via
        // AdminPagesRegisteringSubscriber, then any plugin subscriber.
        // Once populated, the registry drives both slug validation and
        // sub-controller dispatch below.
        $this->dispatcher->dispatch(new AdminPagesRegistering($this->pageRegistry));

        $this->permissionService->checkStatus(AccessLevel::Administrator);

        $this->inputValidator->check('page', $_GET, false, '/^[a-zA-Z\d_-]+$/');
        $this->inputValidator->check('section', $_GET, false, '/^[a-z]+[a-z_\/-]*(\.php)?$/i');

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
                $this->imageAdminService->fsQuickCheck();
            }
        }

        // ── Direct / AJAX actions ─────────────────────────────────────────────

        $plugins_new_order = StringUtil::inputString('plugins_new_order', null, $_GET);
        if ($plugins_new_order !== null) {
            $this->sessionService->setSessionVar('plugins_new_order', $plugins_new_order);
            exit;
        }

        if (StringUtil::inputString('change_theme', null, $_GET) !== null) {
            $admin_themes = ['dark', 'light'];
            $rawTheme         = $this->preferencesService->userprefsGetParam('admin_theme', 'dark');
            $admin_theme_array = [is_scalar($rawTheme) ? (string) $rawTheme : 'dark'];
            $result           = array_diff($admin_themes, $admin_theme_array);
            $new_admin_theme  = array_pop($result);

            $this->preferencesService->userprefsUpdateParam('admin_theme', $new_admin_theme);

            $url_params = [];
            foreach (['page', 'tab', 'section'] as $url_param) {
                if (isset($_GET[$url_param])) {
                    $url_params[] = $url_param . '=' . (is_scalar($_GET[$url_param]) ? $_GET[$url_param] : '');
                }
            }

            $redirect_url = $this->urlGenerator->admin();
            if (count($url_params) > 0) {
                $redirect_url .= '&' . implode('&', $url_params);
            }

            $this->redirectResponder->redirect($redirect_url);
        }

        // ── Sync user info ────────────────────────────────────────────────────

        if (Config::externalAuthentification()) {
            $this->userAdminService->syncUsers();
        }

        // ── Variables init ────────────────────────────────────────────────────

        $change_theme_url = $this->urlGenerator->admin() . '&';
        $test_get         = $_GET;
        unset($test_get['page'], $test_get['section'], $test_get['tag']);
        $qsRawVal = $_SERVER['QUERY_STRING'] ?? null;
        $qsRaw = is_string($qsRawVal) ? $qsRawVal : '';
        if (count($test_get) === 0 && $qsRaw !== '') {
            $change_theme_url .= $qsRaw . '&';
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

        // Validate $getPage against the registry populated by the
        // AdminPagesRegistering subscriber chain above. The regex still
        // enforces slug shape (no quoting/path traversal) before the
        // registry lookup.
        $adminPage = ($getPage !== '' && preg_match('/^[a-z_]*$/', $getPage) && $this->pageRegistry->has($getPage))
            ? $getPage
            : 'intro';
        $adminBase  = $this->urlGenerator->admin();
        $adminSep   = str_contains($adminBase, '?') ? '&' : '?';
        $wsBase     = $this->urlGenerator->ws();
        $wsSep      = str_contains($wsBase, '?') ? '&' : '?';
        $link_start = $adminBase . $adminSep . 'page=';
        $conf_link  = $link_start . 'configuration&section=';

        $this->inputValidator->check('tab', $_GET, false, '/^[a-zA-Z\d_-]+$/');

        // ── Template init ─────────────────────────────────────────────────────

        $title = Lang::t('Piwigo Administration');
        $ps = PageState::current();
        $ps->pageBanner = '<h1>' . Lang::t('Piwigo Administration') . '</h1>';
        $ps->bodyId     = 'theAdminPage';

        $tpl = TemplateRegistry::current();

        $username = is_scalar($user['username'] ?? null) ? (string) $user['username'] : '';
        $tpl->assign([
            'USERNAME'               => $username,
            'ENABLE_SYNCHRONIZATION' => Config::enableSynchronization(),
            'U_SITE_MANAGER'         => $link_start . 'site_manager',
            'U_HISTORY_STAT'         => $link_start . 'stats&year=' . date('Y') . '&month=' . date('n'),
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
            'U_CAT_UPDATE'           => $link_start . 'site_update&site=1',
            'U_RATING'               => $link_start . 'rating',
            'U_RECENT_SET'           => $link_start . 'batch_manager&filter=prefilter-last_import',
            'U_BATCH'                => $link_start . 'batch_manager',
            'U_TAGS'                 => $link_start . 'tags',
            'U_USERS'                => $link_start . 'user_list',
            'U_GROUPS'               => $link_start . 'group_list',
            'U_RETURN'               => $this->urlService->getGalleryHomeUrl(),
            'U_ADMIN'                => $this->urlGenerator->admin(),
            'U_LOGOUT'               => UrlService::getRootUrl() . '?act=logout',
            'U_PLUGINS'              => $link_start . 'plugins',
            'U_ADD_PHOTOS'           => $link_start . 'photos_add',
            'U_CHANGE_THEME'         => $change_theme_url,
            'ADMIN_PAGE_TITLE'       => 'Piwigo Administration Page',
            'ADMIN_PAGE_OBJECT_ID'   => '',
            'U_SHOW_TEMPLATE_TAB'    => Config::showTemplateInSideMenu(),
            'SHOW_RATING'            => Config::rateEnabled(),
            'WS_URL'                 => $wsBase . $wsSep,
            'ADMIN_URL'              => $adminBase . $adminSep,
        ]);

        if (Config::enableCoreUpdate()) {
            $tpl->assign('U_UPDATES', $link_start . 'updates');
        }

        if (Config::activateComments()) {
            $tpl->assign('U_COMMENTS', $link_start . 'comments');
            $nb_comments = $this->commentRepository->countUnvalidated();
            if ($nb_comments > 0) {
                $tpl->assign('NB_PENDING_COMMENTS', $nb_comments);
            }
        }

        $nb_photos_in_caddie = $this->userRepository
            ->countCaddieByUserId(is_numeric($user['id'] ?? null) ? (int) $user['id'] : 0);

        if ($nb_photos_in_caddie > 0) {
            $tpl->assign([
                'NB_PHOTOS_IN_CADDIE' => $nb_photos_in_caddie,
                'U_CADDIE'            => $link_start . 'batch_manager&filter=prefilter-caddie',
            ]);
        } else {
            $tpl->assign([
                'NB_PHOTOS_IN_CADDIE' => 0,
                'U_CADDIE'            => '',
            ]);
        }

        if (in_array($adminPage, ['site_update', 'batch_manager'], true)) {
            $nb_no_md5sum = count($this->imageAdminService->getPhotosNoMd5sum());
            $tpl->assign('NB_NO_MD5SUM', $nb_no_md5sum > 0 ? $nb_no_md5sum : '');
        }

        $nbPhotosTotal = $this->imageRepository->countAll();
        $nbOrphans     = $nbPhotosTotal < 100000 ? $this->imageAdminService->countOrphans() : 0;

        $tpl->assign([
            'NB_ORPHANS' => $nbOrphans,
            'U_ORPHANS'  => $link_start . 'batch_manager&filter=prefilter-no_album',
        ]);

        // ── Refresh permissions ───────────────────────────────────────────────

        if (
            in_array($adminPage, ['site_manager', 'site_update'], true)
            || (!empty($_POST) && in_array($adminPage, ['album', 'albums', 'cat_options', 'user_list', 'user_perm'], true))
        ) {
            $this->userAdminService->invalidateUserCache();
        }

        // ── What's new ────────────────────────────────────────────────────────

        $show_whats_new          = false;
        $whats_new_major_version = AppInfo::branchFromVersion(AppInfo::VERSION);

        if ($this->preferencesService->userprefsGetParam('show_whats_new_' . $whats_new_major_version, true) && $this->configService->pwgIsDbconfWriteable()) {
            $registrationDate = is_scalar($user['registration_date'] ?? null) ? (string) $user['registration_date'] : '';
            $lastMajorUpdate  = Config::lastMajorUpdate() ?? '';
            if ($registrationDate > $lastMajorUpdate) {
                $this->preferencesService->userprefsUpdateParam('show_whats_new_' . $whats_new_major_version, false);
            } else {
                $userPreferences            = is_array($user['preferences'] ?? null) ? $user['preferences'] : [];
                $userprefs_params_to_delete = [];
                foreach (array_keys($userPreferences) as $pref_param) {
                    if (preg_match('/^whats_new_/', (string) $pref_param)) {
                        $userprefs_params_to_delete[] = $pref_param;
                    }
                }
                if (count($userprefs_params_to_delete) > 0) {
                    $this->preferencesService->userprefsDeleteParam($userprefs_params_to_delete);
                }
                $show_whats_new = true;
            }
        }

        $release_note_url = AppInfo::PROJECT_URL . '/releases/' . $whats_new_major_version . '.0.0';

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
            'str_album_modal_title'       => Lang::t('Select an album'),
            'str_album_modal_placeholder' => Lang::t('Search'),
            'str_no_search_in_progress'   => Lang::t('No search in progress'),
            'str_root'                    => Lang::t('Root'),
            'str_root_album_select'       => Lang::t('Root'),
            'str_album_selected'          => Lang::t('Album already selected'),
            'str_result_limit'            => Lang::t('<b>%d+</b> albums found, try to refine the search'),
            'str_album_found'             => Lang::t('<b>1</b> album found'),
            'str_albums_found'            => Lang::t('<b>%d</b> albums found'),
            'str_plus_albums_found'       => Lang::t('Only the first %d albums are displayed, out of %d.'),
            'str_create_and_select'       => Lang::t('Create and select'),
            'str_add_subcat_of'           => Lang::t('Add a sub-album to "%s"'),
            'str_complete_name_field'     => Lang::t('Name field must not be empty'),
            'str_an_error_has_occured'    => Lang::t('An error has occured'),
        ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));

        // ── Dispatch to admin sub-page ────────────────────────────────────────

        $this->dispatcher->dispatch(new LocBeginAdminPage());
        $this->dispatchToSubController($adminPage);

        $tpl->assign('ACTIVE_MENU', $this->adminService->getActiveMenu($adminPage));

        // ── Render ────────────────────────────────────────────────────────────

        $tpl->assign('pwgmenu', $this->adminService->pwgURL());

        PageHeaderRenderer::render($title);

        $this->dispatcher->dispatch(new LocEndAdmin());

        $this->htmlService->flushPageMessages();

        $tpl->pparse('admin.latte');

        PageTailRenderer::render();

        return ResponseFactory::create(200);
    }

    private function dispatchToSubController(string $page): void
    {
        $entry = $this->pageRegistry->find($page);
        // The slug-validation block earlier rejected anything not in the
        // registry, so a missing entry here is impossible in practice.
        // Treat it as a logic error if it ever fires.
        if ($entry === null) {
            throw new \LogicException("AdminController dispatched unregistered page '{$page}'");
        }
        Kernel::service($entry->controllerClass)->handle($page);
    }
}
