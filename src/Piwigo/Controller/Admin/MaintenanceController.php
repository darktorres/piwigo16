<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Doctrine\DBAL\Connection;
use Latte\Runtime\Html;
use Piwigo\Activity\ActivityEvent;
use Piwigo\Activity\ActivityLogger;
use Piwigo\Activity\ActivityObject;
use Piwigo\Admin\AdminService;
use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Admin\History\HistoryAdminService;
use Piwigo\Admin\Image\ImageAdminService;
use Piwigo\Admin\Image\PwgImage;
use Piwigo\Admin\Integrity\CheckIntegrity;
use Piwigo\Admin\Integrity\IntegrityIgnoredAnomaliesRepository;
use Piwigo\Admin\MaintenanceService;
use Piwigo\Admin\Metadata\MetadataAdminService;
use Piwigo\Admin\Tabsheet;
use Piwigo\Admin\Tag\TagAdminService;
use Piwigo\Admin\Users\UserAdminService;
use Piwigo\Auth\CookieService;
use Piwigo\Category\CategoryService;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigService;
use Piwigo\Core\ActivitySystem;
use Piwigo\Core\AppInfo;
use Piwigo\Core\BoolUtil;
use Piwigo\Core\DateService;
use Piwigo\Core\Lang;
use Piwigo\Core\LoggerRegistry;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\StringUtil;
use Piwigo\Core\ValidationPattern;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\DbInfo;
use Piwigo\Db\Dml;
use Piwigo\Db\SchemaHelper;
use Piwigo\Db\Tables;
use Piwigo\Event\Admin\GetAdminAdvancedFeaturesLinks;
use Piwigo\Event\Album\GetAdminsSiteLinks;
use Piwigo\Exception\ConfigException;
use Piwigo\Exception\NotFoundException;
use Piwigo\Exception\ValidationException;
use Piwigo\History\HistoryRepository;
use Piwigo\Html\HtmlService;
use Piwigo\Http\RedirectResponder;
use Piwigo\Image\DerivativeSize;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageStdParams;
use Piwigo\Job\RegenerateAllDerivativesJob;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Rate\RateService;
use Piwigo\Search\SearchRepository;
use Piwigo\Session\SessionRepository;
use Piwigo\Session\SessionService;
use Piwigo\Site\LocalSiteReader;
use Piwigo\Site\SiteRepository;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlGenerator;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Piwigo\Users\UserRepository;
use Piwigo\Validation\InputValidator;
use Psr\Cache\CacheItemPoolInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final class MaintenanceController implements AdminSubControllerInterface
{
    /** @var list<string> */
    public const array PAGES = [
        'maintenance', 'maintenance_actions', 'maintenance_env', 'maintenance_sys',
        'history', 'stats', 'site_manager', 'site_reader_local', 'site_update',
    ];

    /** @var array<string, array<string, string>> */
    private array $maintActions = [];

    public function __construct(
        private readonly Connection $conn,
        private readonly AdminService $adminService,
        private readonly CategoryAdminService $categoryAdminService,
        private readonly CategoryService $categoryService,
        private readonly ConfigService $configService,
        private readonly CookieService $cookieService,
        private readonly DateService $dateService,
        private readonly HistoryAdminService $historyAdminService,
        private readonly HistoryRepository $historyRepository,
        private readonly ImageAdminService $imageAdminService,
        private readonly ImageRepository $imageRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly MetadataAdminService $metadataAdminService,
        private readonly PermissionRepository $permissionRepository,
        private readonly PermissionService $permissionService,
        private readonly RateService $rateService,
        private readonly SearchRepository $searchRepository,
        private readonly SessionRepository $sessionRepository,
        private readonly SessionService $sessionService,
        private readonly SiteRepository $siteRepository,
        private readonly TagAdminService $tagAdminService,
        private readonly UrlGenerator $urlGenerator,
        private readonly UrlService $urlService,
        private readonly UserAdminService $userAdminService,
        private readonly UserRepository $userRepository,
        private readonly ActivityLogger $activityLogger,
        private readonly CsrfService $csrfService,
        private readonly InputValidator $inputValidator,
        private readonly RedirectResponder $redirectResponder,
        private readonly CacheItemPoolInterface $pool,
        private readonly HtmlService $htmlService,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly IntegrityIgnoredAnomaliesRepository $integrityIgnoredAnomaliesRepo,
        private readonly Paths $paths,
    ) {
    }

    #[\Override]
    public function handle(string $page): void
    {
        if ($page === 'maintenance') {
            $this->maintenance();
        } elseif ($page === 'maintenance_actions') {
            $this->maintenanceActions();
        } elseif ($page === 'maintenance_env') {
            $this->maintenanceEnv();
        } elseif ($page === 'maintenance_sys') {
            $this->maintenanceSys();
        } elseif ($page === 'history') {
            $this->history();
        } elseif ($page === 'stats') {
            $this->stats();
        } elseif ($page === 'site_manager') {
            $this->siteManager();
        } elseif ($page === 'site_reader_local') {
            $this->siteReaderLocal();
        } elseif ($page === 'site_update') {
            $this->siteUpdate();
        }
    }

    // ── maintenance ───────────────────────────────────────────────────────────

    private function maintenance(): void
    {
        $tpl = TemplateRegistry::current();

        if (isset($_GET['action'])) {
            $this->csrfService->check();
        }

        $this->maintActions = [
            'derivatives'        => ['icon' => 'icon-trash-1',       'label' => Lang::t('Delete multiple size images')],
            'lock_gallery'       => ['icon' => 'icon-lock',           'label' => Lang::t('Lock gallery')],
            'unlock_gallery'     => ['icon' => 'icon-lock',           'label' => Lang::t('Unlock gallery')],
            'categories'         => ['icon' => 'icon-folder-open',    'label' => Lang::t('Update albums informations')],
            'images'             => ['icon' => 'icon-info-circled-1', 'label' => Lang::t('Update photos information')],
            'empty_lounge'       => ['icon' => 'icon-thumbs-up',      'label' => Lang::t('Empty lounge')],
            'delete_orphan_tags' => ['icon' => 'icon-tags',           'label' => Lang::t('Delete orphan tags')],
            'user_cache'         => ['icon' => 'icon-user-1',         'label' => Lang::t('Purge user cache')],
            'history_detail'     => ['icon' => 'icon-back-in-time',   'label' => Lang::t('Purge history detail')],
            'history_summary'    => ['icon' => 'icon-back-in-time',   'label' => Lang::t('Purge history summary')],
            'sessions'           => ['icon' => 'icon-th-list',        'label' => Lang::t('Purge sessions')],
            'feeds'              => ['icon' => 'icon-bell',           'label' => Lang::t('Purge never used notification feeds')],
            'database'           => ['icon' => 'icon-database',       'label' => Lang::t('Repair and optimize database')],
            'c13y'               => ['icon' => 'icon-ok',             'label' => Lang::t('Reinitialize check integrity')],
            'search'             => ['icon' => 'icon-search',         'label' => Lang::t('Purge search history')],
            'compiled-templates' => ['icon' => 'icon-file-code',      'label' => Lang::t('Purge compiled templates')],
        ];

        $this->inputValidator->check('tab', $_GET, false, '/^(actions|env|sys)$/');
        $tab = isset($_GET['tab']) && is_string($_GET['tab']) ? $_GET['tab'] : 'actions';

        $tabsheet = new Tabsheet();
        $tabsheet->setId('maintenance');
        $tabsheet->select($tab);
        $tabsheet->assign();
        if ($tab === 'actions') {
            $this->maintenanceActions();
        } elseif ($tab === 'env') {
            $this->maintenanceEnv();
        } elseif ($tab === 'sys') {
            $this->maintenanceSys();
        }

        $tpl->assign(['ADMIN_PAGE_TITLE' => Lang::t('Maintenance')]);
    }

    // ── maintenance_actions ───────────────────────────────────────────────────

    private function maintenanceActions(): void
    {
        $tpl = TemplateRegistry::current();

        $this->imageAdminService->fsQuickCheck();

        $maint_actions = $this->maintActions;

        $action = $_GET['action'] ?? '';
        $register_activity = true;

        switch ($action) {
            case 'phpinfo':
                phpinfo();
                exit();
            case 'lock_gallery':
                $this->configService->confUpdateParam('gallery_locked', 'true');
                $this->activityLogger->log(new ActivityEvent(ActivityObject::System, ActivitySystem::Core, 'maintenance', ['maintenance_action' => $action]));
                $this->redirectResponder->redirect($this->urlGenerator->admin('maintenance'));
                break;
            case 'unlock_gallery':
                $this->configService->confUpdateParam('gallery_locked', 'false');
                $_SESSION['page_infos'] = [Lang::t('Gallery unlocked')];
                $this->activityLogger->log(new ActivityEvent(ActivityObject::System, ActivitySystem::Core, 'maintenance', ['maintenance_action' => $action]));
                $this->redirectResponder->redirect($this->urlGenerator->admin('maintenance'));
                break;
            case 'categories':
                $this->categoryAdminService->imagesIntegrity();
                $this->categoryAdminService->categoriesIntegrity();
                $this->categoryAdminService->updateUppercats();
                $this->categoryAdminService->updateCategory('all');
                $this->categoryAdminService->updateGlobalRank();
                $this->userAdminService->invalidateUserCache(true);
                PageState::current()->addInfo(sprintf('%s : %s', Lang::t('Update albums informations'), Lang::t('action successfully performed.')));
                break;
            case 'images':
                $this->categoryAdminService->imagesIntegrity();
                $this->categoryAdminService->updatePath();
                $this->rateService->updateRatingScore();
                $this->userAdminService->invalidateUserCache();
                PageState::current()->addInfo(sprintf('%s : %s', Lang::t('Update photos information'), Lang::t('action successfully performed.')));
                break;
            case 'delete_orphan_tags':
                $this->tagAdminService->deleteOrphanTags();
                PageState::current()->addInfo(sprintf('%s : %s', Lang::t('Delete orphan tags'), Lang::t('action successfully performed.')));
                break;
            case 'user_cache':
                $this->userAdminService->invalidateUserCache();
                PageState::current()->addInfo(sprintf('%s : %s', Lang::t('Purge user cache'), Lang::t('action successfully performed.')));
                break;
            case 'history_detail':
                $this->historyRepository->deleteAll();
                PageState::current()->addInfo(sprintf('%s : %s', Lang::t('Purge history detail'), Lang::t('action successfully performed.')));
                break;
            case 'history_summary':
                $this->historyRepository->deleteAllSummary();
                PageState::current()->addInfo(sprintf('%s : %s', Lang::t('Purge history summary'), Lang::t('action successfully performed.')));
                break;
            case 'sessions':
                $this->sessionService->gc(0);
                $userRepo    = $this->userRepository;
                $sessionRepo = $this->sessionRepository;
                $sessions     = $userRepo->findAllSessions();
                $all_user_ids = $userRepo->findAllUserIdsAsSet(Config::userFields()['id'], Tables::users());
                $sessions_to_delete = [];
                foreach ($sessions as $session) {
                    if (preg_match('/pwg_uid\|i:(\d+);/', is_string($session['data'] ?? null) ? $session['data'] : '', $matches)) {
                        if (!isset($all_user_ids[$matches[1]])) {
                            $sessions_to_delete[] = is_string($session['id'] ?? null) ? $session['id'] : '';
                        }
                    }
                }
                if (count($sessions_to_delete) > 0) {
                    $sessionRepo->deleteByIds($sessions_to_delete);
                }
                PageState::current()->addInfo(sprintf('%s : %s', Lang::t('Purge sessions'), Lang::t('action successfully performed.')));
                break;
            case 'feeds':
                $this->userRepository->deleteNeverUsedFeeds();
                PageState::current()->addInfo(sprintf('%s : %s', Lang::t('Purge never used notification feeds'), Lang::t('action successfully performed.')));
                break;
            case 'database':
                MaintenanceService::repairAndOptimize();
                break;
            case 'c13y':
                $c13y = new CheckIntegrity($this->integrityIgnoredAnomaliesRepo);
                $c13y->maintenance();
                PageState::current()->addInfo(sprintf('%s : %s', Lang::t('Reinitialize check integrity'), Lang::t('action successfully performed.')));
                break;
            case 'empty_lounge':
                $rows = $this->categoryAdminService->emptyLounge();
                PageState::current()->addInfo(sprintf('%d photos were moved from the upload lounge to their albums', count($rows ?? [])));
                break;
            case 'search':
                $this->searchRepository->deleteAll();
                break;
            case 'compiled-templates':
                $tpl->deleteCompiledTemplates();
                $this->pool->clear();
                PageState::current()->addInfo(sprintf('%s : %s', Lang::t('Purge compiled templates'), Lang::t('action successfully performed.')));
                break;
            case 'derivatives':
                $types_str = is_string($rawGetType = $_GET['type'] ?? null) ? $rawGetType : '';
                $types     = $types_str === 'all' ? ['all'] : explode('_', $types_str);
                foreach ($types as $type_to_clear) {
                    $this->imageAdminService->clearDerivativeCache($type_to_clear);
                }
                $this->messageBus->dispatch(new RegenerateAllDerivativesJob($types));
                PageState::current()->addInfo(Lang::t('action successfully performed.'));
                break;
            case 'check_upgrade':
                $result = '';
                if (!$this->adminService->fetchRemote(PHPWG_URL . '/download/latest_version', $result) || !is_string($result)) {
                    PageState::current()->addError(Lang::t('Unable to check for upgrade.'));
                } else {
                    $versions = ['current' => AppInfo::VERSION];
                    $lines    = explode("\r\n", $result);
                    if (preg_match('/^BSF/', $versions['current'])) {
                        $versions['latest'] = trim($lines[0]);
                        foreach ($versions as $key => $value) {
                            $versions[$key] = preg_replace('/BSF_(\d{8})(\d{4})/', '$1.$2', $value);
                        }
                    } else {
                        $versions['latest'] = trim($lines[1]);
                    }
                    if ('' == $versions['latest']) {
                        PageState::current()->addError(Lang::t('Check for upgrade failed for unknown reasons.'));
                    } elseif (str_contains($versions['current'] ?? '', '%')) {
                        PageState::current()->addInfo(Lang::t('You are running on development sources, no check possible.'));
                    } elseif (version_compare($versions['current'] ?? '', $versions['latest']) < 0) {
                        PageState::current()->addInfo(Lang::t('A new version of Piwigo is available.'));
                    } else {
                        PageState::current()->addInfo(Lang::t('You are running the latest version of Piwigo.'));
                    }
                }
                PageState::current()->addInfo(Lang::t('action successfully performed.'));
                // fall through to default
                // no break
            default:
                $register_activity = false;
                break;
        }

        if ($register_activity) {
            $this->activityLogger->log(new ActivityEvent(ActivityObject::System, ActivitySystem::Core, 'maintenance', ['maintenance_action' => $action]));
        }

        $pwg_token    = $this->csrfService->getToken();
        $gallery_locked = Config::galleryLocked();
        $tpl->assign('page_data_json', json_encode([
            'unit_MB'                     => Lang::t('%s MB'),
            'no_time_elapsed'             => Lang::t('right now'),
            'pwg_token'                   => $pwg_token,
            'gallery_locked'              => $gallery_locked,
            'str_lock_gallery_tip'        => Lang::t('A locked gallery is only visible to administrators'),
            'str_lock_gallery_confirm'    => Lang::t('Are you sure you want to lock the gallery?'),
            'str_unlock_gallery_confirm'  => Lang::t('Are you sure you want to unlock the gallery?'),
            'str_purge_history_detail'    => Lang::t('Purge history detail'),
            'str_purge_history_summary'   => Lang::t('Purge history summary'),
            'str_purge_search_history'    => Lang::t('Purge search history'),
            'str_delete_all_sizes_confirm' => Lang::t('Are you sure you want to delete all sizes?'),
        ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));

        $url_format = $this->urlGenerator->admin('maintenance') . '&action=%s&pwg_token=' . $this->csrfService->getToken();
        if (!$this->permissionService->isWebmaster()) {
            PageState::current()->addWarning(str_replace('%s', Lang::t('user_status_webmaster'), Lang::t('%s status is required to edit parameters.')));
        }

        $purge_urls = [Lang::t('All') => 'all'];
        foreach (ImageStdParams::getDefinedTypeMap() as $params) {
            $purge_urls[Lang::t($params->type)] = $params->type;
        }
        $purge_urls[Lang::t(DerivativeSize::Custom->value)] = DerivativeSize::Custom->value;

        $php_current_timestamp = date('Y-m-d H:i:s');
        $db_version            = DbInfo::version();
        $db_current_date       = new \DateTimeImmutable()->format('Y-m-d H:i:s');

        $tpl->assign([
            'maint_actions'               => $maint_actions,
            'U_MAINT_CATEGORIES'          => sprintf($url_format, 'categories'),
            'U_MAINT_IMAGES'              => sprintf($url_format, 'images'),
            'U_MAINT_ORPHAN_TAGS'         => sprintf($url_format, 'delete_orphan_tags'),
            'U_MAINT_USER_CACHE'          => sprintf($url_format, 'user_cache'),
            'U_MAINT_HISTORY_DETAIL'      => sprintf($url_format, 'history_detail'),
            'U_MAINT_HISTORY_SUMMARY'     => sprintf($url_format, 'history_summary'),
            'U_MAINT_SESSIONS'            => sprintf($url_format, 'sessions'),
            'U_MAINT_FEEDS'               => sprintf($url_format, 'feeds'),
            'U_MAINT_DATABASE'            => sprintf($url_format, 'database'),
            'U_MAINT_C13Y'                => sprintf($url_format, 'c13y'),
            'U_MAINT_SEARCH'              => sprintf($url_format, 'search'),
            'U_MAINT_COMPILED_TEMPLATES'  => sprintf($url_format, 'compiled-templates'),
            'U_MAINT_DERIVATIVES'         => sprintf($url_format, 'derivatives'),
            'purge_derivatives'           => $purge_urls,
            'U_HELP'                      => $this->urlGenerator->adminPopupHelp('maintenance'),
            'PHPWG_URL'                   => PHPWG_URL,
            'PWG_VERSION'                 => AppInfo::VERSION,
            'U_CHECK_UPGRADE'             => sprintf($url_format, 'check_upgrade'),
            'OS'                          => PHP_OS,
            'PHP_VERSION'                 => phpversion(),
            'DB_ENGINE'                   => 'MySQL',
            'DB_VERSION'                  => $db_version,
            'U_PHPINFO'                   => sprintf($url_format, 'phpinfo'),
            'PHP_DATATIME'                => $php_current_timestamp,
            'DB_DATATIME'                 => $db_current_date,
            'pwg_token'                   => $pwg_token,
            'cache_sizes'                 => $this->loadCacheSizes(),
            'time_elapsed_since_last_calc' => $this->cacheSizesLastCalcLabel(),
        ]);

        switch (PwgImage::getLibrary()) {
            case 'ext_imagick':
                $library = 'External ImageMagick';
                $returnarray = [];
                exec(Config::extImagickDir() . PwgImage::getExtImagickCommand() . ' -version', $returnarray);
                if (!empty($returnarray[0]) && preg_match('/Version: ImageMagick (\d+\.\d+\.\d+-?\d*)/', $returnarray[0], $match)) {
                    $library .= ' ' . $match[1];
                }
                $tpl->assign('GRAPHICS_LIBRARY', $library);
                break;
            case 'imagick':
                $library = 'ImageMagick';
                $img     = new \Imagick();
                $version = $img->getVersion();
                if (preg_match('/ImageMagick \d+\.\d+\.\d+-?\d*/', $version['versionString'], $match)) {
                    $library = $match[0];
                }
                $tpl->assign('GRAPHICS_LIBRARY', $library);
                break;
            case 'gd':
                $gd_info = gd_info();
                $tpl->assign('GRAPHICS_LIBRARY', 'GD ' . (is_string($gd_info['GD Version'] ?? null) ? $gd_info['GD Version'] : ''));
                break;
        }

        if (Config::galleryLocked()) {
            $tpl->assign(['U_MAINT_UNLOCK_GALLERY' => sprintf($url_format, 'unlock_gallery')]);
        } else {
            $tpl->assign(['U_MAINT_LOCK_GALLERY' => sprintf($url_format, 'lock_gallery')]);
        }

        $nb_lounge = $this->imageRepository->countLoungeImages();
        if ($nb_lounge > 0) {
            $tpl->assign(['U_EMPTY_LOUNGE' => sprintf($url_format, 'empty_lounge'), 'LOUNGE_COUNTER' => $nb_lounge]);
        }

        $tpl->assign('isWebmaster', $this->permissionService->isWebmaster() ? 1 : 0);
        $featuresEvent = new GetAdminAdvancedFeaturesLinks([]);
        $this->dispatcher->dispatch($featuresEvent);
        $tpl->assign('advanced_features', $featuresEvent->advancedFeatures);
        $tpl->assignVarFromTemplate('ADMIN_CONTENT', 'maintenance_actions.latte');
    }

    // ── maintenance_env ───────────────────────────────────────────────────────

    private function maintenanceEnv(): void
    {
        $tpl = TemplateRegistry::current();

        if (isset($_GET['action'])) {
            $this->csrfService->check();
        }

        $action = $_GET['action'] ?? '';
        switch ($action) {
            case 'phpinfo':  phpinfo();
                exit();
            case 'lock_gallery':    $this->configService->confUpdateParam('gallery_locked', 'true');
                $this->redirectResponder->redirect($this->urlGenerator->admin('maintenance'));
                break;
            case 'unlock_gallery':  $this->configService->confUpdateParam('gallery_locked', 'false');
                $_SESSION['page_infos'] = [Lang::t('Gallery unlocked')];
                $this->redirectResponder->redirect($this->urlGenerator->admin('maintenance'));
                break;
            case 'categories':      $this->categoryAdminService->imagesIntegrity();
                $this->categoryAdminService->categoriesIntegrity();
                $this->categoryAdminService->updateUppercats();
                $this->categoryAdminService->updateCategory('all');
                $this->categoryAdminService->updateGlobalRank();
                $this->userAdminService->invalidateUserCache(true);
                break;
            case 'images':          $this->categoryAdminService->imagesIntegrity();
                $this->categoryAdminService->updatePath();
                $this->rateService->updateRatingScore();
                $this->userAdminService->invalidateUserCache();
                break;
            case 'delete_orphan_tags': $this->tagAdminService->deleteOrphanTags();
                break;
            case 'user_cache':      $this->userAdminService->invalidateUserCache();
                break;
            case 'history_detail':  $this->historyRepository->deleteAll();
                break;
            case 'history_summary': $this->historyRepository->deleteAllSummary();
                break;
            case 'sessions':
                $this->sessionService->gc(0);
                $userRepo    = $this->userRepository;
                $sessionRepo = $this->sessionRepository;
                $sessions     = $userRepo->findAllSessions();
                $all_user_ids = $userRepo->findAllUserIdsAsSet(Config::userFields()['id'], Tables::users());
                $sessions_to_delete = [];
                foreach ($sessions as $session) {
                    if (preg_match('/pwg_uid\|i:(\d+);/', is_string($session['data'] ?? null) ? $session['data'] : '', $matches)) {
                        if (!isset($all_user_ids[$matches[1]])) {
                            $sessions_to_delete[] = is_string($session['id'] ?? null) ? $session['id'] : '';
                        }
                    }
                }
                if (count($sessions_to_delete) > 0) {
                    $sessionRepo->deleteByIds($sessions_to_delete);
                }
                break;
            case 'feeds':           $this->userRepository->deleteNeverUsedFeeds();
                break;
            case 'database':        MaintenanceService::repairAndOptimize();
                break;
            case 'c13y':            $c13y = new CheckIntegrity($this->integrityIgnoredAnomaliesRepo);
                $c13y->maintenance();
                break;
            case 'search':          $this->searchRepository->deleteAll();
                break;
            case 'compiled-templates':
                $tpl->deleteCompiledTemplates();
                $this->pool->clear();
                break;
            case 'derivatives':
                $dtype = is_string($_GET['type'] ?? null) ? $_GET['type'] : '';
                $this->imageAdminService->clearDerivativeCache($dtype);
                break;
            case 'check_upgrade':
                $result = '';
                if (!$this->adminService->fetchRemote(PHPWG_URL . '/download/latest_version', $result) || !is_string($result)) {
                    PageState::current()->addError(Lang::t('Unable to check for upgrade.'));
                } else {
                    $versions = ['current' => AppInfo::VERSION];
                    $lines    = explode("\r\n", $result);
                    if (preg_match('/^BSF/', $versions['current'])) {
                        $versions['latest'] = trim($lines[0]);
                        foreach ($versions as $key => $value) {
                            $versions[$key] = preg_replace('/BSF_(\d{8})(\d{4})/', '$1.$2', $value);
                        }
                    } else {
                        $versions['latest'] = trim($lines[1]);
                    }
                    if ('' == $versions['latest']) {
                        PageState::current()->addError(Lang::t('Check for upgrade failed for unknown reasons.'));
                    } elseif (str_contains($versions['current'] ?? '', '%')) {
                        PageState::current()->addInfo(Lang::t('You are running on development sources, no check possible.'));
                    } elseif (version_compare($versions['current'] ?? '', $versions['latest']) < 0) {
                        PageState::current()->addInfo(Lang::t('A new version of Piwigo is available.'));
                        $update_url = $this->urlGenerator->admin('updates');
                        PageState::current()->addInfo(new Html('<a href="' . $update_url . '">' . Lang::t('Update to Piwigo %s', $versions['latest']) . '</a>'));
                    } else {
                        PageState::current()->addInfo(Lang::t('You are running the latest version of Piwigo.'));
                    }
                }
                break;
        }

        $tpl->assign('page_data_json', json_encode(['unit_MB' => Lang::t('%s MB'), 'no_time_elapsed' => Lang::t('right now'), 'no_active_plugin' => Lang::t('No plugin activated'), 'error_occured' => Lang::t('an error happened')], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));

        $url_format = $this->urlGenerator->admin('maintenance') . '&action=%s&pwg_token=' . $this->csrfService->getToken();

        $purge_urls = [];
        $purge_urls[Lang::t('All')] = sprintf($url_format, 'derivatives') . '&type=all';
        foreach (ImageStdParams::getDefinedTypeMap() as $params) {
            $purge_urls[Lang::t($params->type)] = sprintf($url_format, 'derivatives') . '&type=' . $params->type;
        }
        $purge_urls[Lang::t(DerivativeSize::Custom->value)] = sprintf($url_format, 'derivatives') . '&type=' . DerivativeSize::Custom->value;

        $php_current_timestamp = date('Y-m-d H:i:s');
        $db_version            = DbInfo::version();
        $db_current_date       = new \DateTimeImmutable()->format('Y-m-d H:i:s');
        [$container_name, $container_version] = StringUtil::getContainerInfo();
        if (!in_array($container_name, ['Official', 'none'])) {
            $container_name = '(unofficial) ' . $container_name;
        }

        $tpl->assign([
            'U_MAINT_CATEGORIES'          => sprintf($url_format, 'categories'),
            'U_MAINT_IMAGES'              => sprintf($url_format, 'images'),
            'U_MAINT_ORPHAN_TAGS'         => sprintf($url_format, 'delete_orphan_tags'),
            'U_MAINT_USER_CACHE'          => sprintf($url_format, 'user_cache'),
            'U_MAINT_HISTORY_DETAIL'      => sprintf($url_format, 'history_detail'),
            'U_MAINT_HISTORY_SUMMARY'     => sprintf($url_format, 'history_summary'),
            'U_MAINT_SESSIONS'            => sprintf($url_format, 'sessions'),
            'U_MAINT_FEEDS'               => sprintf($url_format, 'feeds'),
            'U_MAINT_DATABASE'            => sprintf($url_format, 'database'),
            'U_MAINT_C13Y'                => sprintf($url_format, 'c13y'),
            'U_MAINT_SEARCH'              => sprintf($url_format, 'search'),
            'U_MAINT_COMPILED_TEMPLATES'  => sprintf($url_format, 'compiled-templates'),
            'U_MAINT_DERIVATIVES'         => sprintf($url_format, 'derivatives'),
            'purge_derivatives'           => $purge_urls,
            'U_HELP'                      => $this->urlGenerator->adminPopupHelp('maintenance'),
            'PHPWG_URL'                   => PHPWG_URL,
            'PWG_VERSION'                 => AppInfo::VERSION,
            'U_CHECK_UPGRADE'             => sprintf($url_format, 'check_upgrade'),
            'OS'                          => PHP_OS,
            'CONTAINER_INFO'              => $container_name . (($container_version !== null && $container_version !== '') ? ' ' . $container_version : ''),
            'PHP_VERSION'                 => phpversion(),
            'DB_ENGINE'                   => 'MySQL',
            'DB_VERSION'                  => $db_version,
            'U_PHPINFO'                   => sprintf($url_format, 'phpinfo'),
            'PHP_DATATIME'                => $php_current_timestamp,
            'DB_DATATIME'                 => $db_current_date,
            'cache_sizes'                 => $this->loadCacheSizes(),
            'time_elapsed_since_last_calc' => $this->cacheSizesLastCalcLabel(),
        ]);

        $graphics_library = $this->adminService->getGraphicsLibraryLabel();
        if (!empty($graphics_library)) {
            $tpl->assign('GRAPHICS_LIBRARY', $graphics_library);
        }

        if (Config::galleryLocked()) {
            $tpl->assign(['U_MAINT_UNLOCK_GALLERY' => sprintf($url_format, 'unlock_gallery')]);
        } else {
            $tpl->assign(['U_MAINT_LOCK_GALLERY' => sprintf($url_format, 'lock_gallery')]);
        }

        $installed_on = $this->adminService->getInstallationDate();
        if ($installed_on !== null && $installed_on !== '') {
            $tpl->assign(['INSTALLED_ON' => $this->dateService->formatDate($installed_on, ['day', 'month', 'year']), 'INSTALLED_SINCE' => $this->dateService->timeSince($installed_on, 'day')]);
        }

        $featuresEvent = new GetAdminAdvancedFeaturesLinks([]);
        $this->dispatcher->dispatch($featuresEvent);
        $tpl->assign('advanced_features', $featuresEvent->advancedFeatures);
        $tpl->assignVarFromTemplate('ADMIN_CONTENT', 'maintenance_env.latte');
    }

    // ── maintenance_sys ───────────────────────────────────────────────────────

    private function maintenanceSys(): void
    {
        $tpl = TemplateRegistry::current();

        $maint_actions = $this->maintActions;

        if ($this->permissionService->isWebmaster()) {
            if (isset($_GET['method']) && 'pwg.activity_sys.getList' == $_GET['method']) {
                $response = [];
                $data     = [];
                $usernameField = Config::userFields()['username'];
                $idField       = Config::userFields()['id'];
                $activityRows  = $this->conn
                    ->executeQuery("SELECT activity_id, object, object_id, action, performed_by, occured_on, details, IF(performed_by = 0, 'System', $usernameField) AS username FROM " . Tables::activity() . ' LEFT JOIN ' . Tables::users() . " ON performed_by = $idField WHERE object = 'system' ORDER BY activity_id DESC")
                    ->fetchAllAssociative();

                foreach ($activityRows as $rows) {
                    $major_infos = false;
                    $object = $object_icon = $action_icon = $action_color = '';
                    $action = $rows['action'];
                    $date = $hour = '';
                    $detailsRaw = json_decode(is_string($rows['details'] ?? null) ? $rows['details'] : '', associative: true);
                    $details    = is_array($detailsRaw) ? $detailsRaw : [];
                    $detail     = ['type' => 'empty'];

                    switch ($rows['object_id']) {
                        case ActivitySystem::Core:
                            $object_icon = 'icon-piwigo';
                            $object = Lang::t('Core');
                            switch ($rows['action']) {
                                case 'install':   $action_icon = 'icon-download';
                                    $action_color = 'icon-green';
                                    $action = Lang::t('Install');
                                    break;
                                case 'config':
                                    $action_icon = 'icon-cog-alt';
                                    $action_color = 'icon-yellow';
                                    $action = Lang::t('Configuration');
                                    if (isset($details['config_section'])) {
                                        $c_icon = $c_text = '';
                                        switch ($details['config_section']) {
                                            case 'main':      $c_icon = 'icon-cog';
                                                $c_text = Lang::t('General');
                                                break;
                                            case 'watermark': $c_icon = 'icon-file-image';
                                                $c_text = Lang::t('Watermark');
                                                break;
                                            case 'sizes':
                                                $c_icon = 'icon-zoom-square';
                                                $c_text = Lang::t('Photo sizes');
                                                if (isset($details['config_action']) && 'restore_settings' == $details['config_action']) {
                                                    $detail[] = ['icon' => 'icon-back-in-time', 'text' => Lang::t('Set as default')];
                                                }
                                                break;
                                            case 'comments':  $c_icon = 'icon-chat';
                                                $c_text = Lang::t('Comments');
                                                break;
                                            case 'display':   $c_icon = 'icon-television';
                                                $c_text = Lang::t('Display');
                                                break;
                                            default:          $c_icon = 'icon-cog-alt';
                                                $c_text = is_string($details['config_section']) ? $details['config_section'] : '';
                                                break;
                                        }
                                        $detail['type'] = 'config_section';
                                        $detail[]       = ['icon' => $c_icon, 'text' => $c_text];
                                    }
                                    break;
                                case 'maintenance':
                                    $action_icon = 'icon-cone';
                                    $action_color = 'icon-yellow';
                                    $action = Lang::t('Maintenance');
                                    if (isset($details['maintenance_action'])) {
                                        $maintActionRaw = $details['maintenance_action'];
                                        $action_detail = is_string($maintActionRaw) ? $maintActionRaw : '';
                                        $maint_action_entry = is_array($maint_actions[$action_detail] ?? null) ? $maint_actions[$action_detail] : [];
                                        $detail = ['type' => 'maintenance_action', 'icon' => $maint_action_entry['icon'] ?? 'icon-cone', 'text' => $maint_action_entry['label'] ?? $action_detail];
                                    }
                                    break;
                                case 'update':     $action_icon = 'icon-arrows-cw';
                                    $action_color = 'icon-blue';
                                    $action = Lang::t('Update');
                                    $major_infos = true;
                                    break;
                                case 'autoupdate': $action_icon = 'icon-arrows-cw';
                                    $action_color = 'icon-blue';
                                    $action = Lang::t('Auto-update');
                                    $major_infos = true;
                                    break;
                                default:           $action_icon = 'icon-download';
                                    $action_color = 'icon-yellow';
                                    break;
                            }
                            break;

                        case ActivitySystem::Plugin:
                            $object_icon = 'icon-puzzle';
                            $object = 'plugin';
                            if (isset($details['plugin_id'])) {
                                $object = str_replace(['_', '-'], ' ', is_string($details['plugin_id']) ? $details['plugin_id'] : '');
                            }
                            switch ($rows['action']) {
                                case 'install':    $action_icon = 'icon-download';
                                    $action_color = 'icon-green';
                                    $action = Lang::t('Install');
                                    break;
                                case 'update':     $action_icon = 'icon-arrows-cw';
                                    $action_color = 'icon-blue';
                                    $action = Lang::t('Update');
                                    break;
                                case 'activate':   $action_icon = 'icon-check';
                                    $action_color = 'icon-green';
                                    $action = Lang::t('Activate');
                                    break;
                                case 'deactivate': $action_icon = 'icon-block';
                                    $action_color = 'icon-purple';
                                    $action = Lang::t('Deactivate');
                                    break;
                                case 'uninstall':  $action_icon = 'icon-trash-1';
                                    $action_color = 'icon-red';
                                    $action = Lang::t('Uninstall');
                                    break;
                                case 'restore':    $action_icon = 'icon-back-in-time';
                                    $action_color = 'icon-blue';
                                    $action = Lang::t('Restore');
                                    break;
                                case 'delete':
                                    $action_icon = 'icon-trash-1';
                                    $action_color = 'icon-red';
                                    $action = Lang::t('Delete');
                                    if (isset($details['db_version'])) {
                                        $detail['type'] = 'db_fs_version';
                                        $detail[] = ['icon' => 'icon-flow-branch', 'text' => 'database : ' . (is_string($details['db_version']) ? $details['db_version'] : '')];
                                    }
                                    if (isset($details['fs_version'])) {
                                        $detail['type'] = 'db_fs_version';
                                        $detail[] = ['icon' => 'icon-flow-branch', 'text' => 'filesystem : ' . (is_string($details['fs_version']) ? $details['fs_version'] : '')];
                                    }
                                    break;
                                case 'autoupdate': $action_icon = 'icon-arrows-cw';
                                    $action_color = 'icon-blue';
                                    $action = Lang::t('Auto-update');
                                    break;
                                default:           $action_icon = 'icon-puzzle';
                                    $action_color = 'icon-yellow';
                                    break;
                            }
                            break;

                        case ActivitySystem::Theme:
                            $object_icon = 'icon-brush';
                            $object = 'theme';
                            if (isset($details['theme_id'])) {
                                $object = str_replace(['_', '-'], ' ', is_string($details['theme_id']) ? $details['theme_id'] : '');
                            }
                            switch ($rows['action']) {
                                case 'install':    $action_icon = 'icon-download';
                                    $action_color = 'icon-green';
                                    $action = Lang::t('Install');
                                    break;
                                case 'activate':   $action_icon = 'icon-check';
                                    $action_color = 'icon-green';
                                    $action = Lang::t('Activate');
                                    break;
                                case 'deactivate': $action_icon = 'icon-block';
                                    $action_color = 'icon-purple';
                                    $action = Lang::t('Deactivate');
                                    break;
                                case 'delete':     $action_icon = 'icon-trash-1';
                                    $action_color = 'icon-red';
                                    $action = Lang::t('Delete');
                                    break;
                                case 'set_default': $action_icon = 'icon-star';
                                    $action_color = 'icon-yellow';
                                    $action = Lang::t('Set as default');
                                    break;
                                case 'update':     $action_icon = 'icon-arrows-cw';
                                    $action_color = 'icon-blue';
                                    $action = Lang::t('Update');
                                    break;
                                default:           $action_icon = 'icon-brush';
                                    $action_color = 'icon-yellow';
                                    break;
                            }
                            break;
                    }

                    if (isset($details['from_version'])) {
                        $toVersionRaw = $details['to_version'] ?? null;
                        $resultRaw = $details['result'] ?? null;
                        $detail = ['type' => 'from_to', ['icon' => 'icon-flow-branch', 'text' => is_string($details['from_version']) ? $details['from_version'] : ''], ['icon' => $toVersionRaw !== null ? 'icon-flow-branch' : 'icon-block', 'text' => is_scalar($toVersionRaw) ? (string) $toVersionRaw : (is_scalar($resultRaw) ? (string) $resultRaw : '')]];
                    } elseif (isset($details['version'])) {
                        $detail = ['type' => 'version', 'icon' => 'icon-flow-branch', 'text' => is_string($details['version']) ? $details['version'] : ''];
                    } elseif (isset($details['result'])) {
                        $detail = ['type' => 'error', 'icon' => 'icon-block', 'text' => is_string($details['result']) ? $details['result'] : ''];
                    }

                    [$date, $hour] = explode(' ', is_string($rows['occured_on'] ?? null) ? $rows['occured_on'] : '');
                    $data[] = ['major_infos' => $major_infos, 'id' => $rows['activity_id'], 'object_icon' => $object_icon, 'object' => ucwords($object), 'action_icon' => $action_icon, 'action_color' => $action_color, 'action' => $action, 'user_id' => $rows['performed_by'], 'username' => $rows['username'], 'date' => $this->dateService->formatDate($date), 'hour' => $hour, 'detail' => $detail];
                }

                $response = ['data' => $data];
                echo json_encode($response);
                exit;
            }
        } else {
            PageState::current()->addWarning(str_replace('%s', Lang::t('user_status_webmaster'), Lang::t('%s status is required to edit parameters.')));
        }

        $tpl->assign('isWebmaster', $this->permissionService->isWebmaster() ? 1 : 0);
        $tpl->assignVarFromTemplate('ADMIN_CONTENT', 'maintenance_sys.latte');
    }

    // ── history ───────────────────────────────────────────────────────────────

    private function history(): void
    {
        $tpl = TemplateRegistry::current();

        $types = array_merge(['none'], SchemaHelper::getEnums(Tables::history(), 'image_type'));

        $display_thumbnails = [
            'no_display_thumbnail'    => Lang::t('No display'),
            'display_thumbnail_classic' => Lang::t('Classic display'),
            'display_thumbnail_hoverbox' => Lang::t('Hoverbox display'),
        ];

        $this->inputValidator->check('filter_ip', $_GET, false, '/^[0-9.]+$/');
        $this->inputValidator->check('filter_image_id', $_GET, false, '/^\d+$/');
        $this->inputValidator->check('filter_user_id', $_GET, false, '/^\d+$/');

        $this->historyAdminService->historyTabsheet('history');
        $tpl->assign(['F_ACTION' => $this->urlGenerator->admin('history'), 'API_METHOD' => $this->urlGenerator->ws(['format' => 'json', 'method' => 'pwg.history.search'])]);

        $form = [
            'ip'                => null,
            'image_id'          => null,
            'filename'          => null,
            'start'             => date('Y-m-d'),
            'end'               => date('Y-m-d'),
            'types'             => $types,
            'display_thumbnail' => $this->cookieService->getCookieVar('display_thumbnail', 'no_display_thumbnail'),
        ];

        $getFilterIp     = $_GET['filter_ip']     ?? null;
        $getFilterImageId = $_GET['filter_image_id'] ?? null;
        $getFilterUserId  = $_GET['filter_user_id']  ?? null;
        $form_param = [];
        $form_param['ip']       = is_string($getFilterIp) ? $getFilterIp : $form['ip'];
        $form_param['image_id'] = is_string($getFilterImageId) ? $getFilterImageId : $form['image_id'];
        $form_param['user_id']  = is_string($getFilterUserId) ? $getFilterUserId : '-1';

        if (isset($_GET['filter_ip']) || isset($_GET['filter_image_id']) || isset($_GET['filter_user_id'])) {
            $form['start'] = '';
        }

        if ($form_param['user_id'] != '-1') {
            $userFields = Config::userFields();
            $foundUsername = $this->userRepository->findUsernameById($userFields['id'], $userFields['username'], Tables::users(), (int) $form_param['user_id']);
            if ($foundUsername !== null) {
                $form_param['user_name'] = $foundUsername;
            } else {
                $form_param['user_id'] = '-1';
            }
        }

        $tpl->assign([
            'USER_ID'   => $form_param['user_id'],
            'USER_NAME' => $form_param['user_name'] ?? null,
            'IMAGE_ID'  => $form_param['image_id'],
            'FILENAME'  => $form['filename'],
            'IP'        => $form_param['ip'],
            'START'     => $form['start'],
            'END'       => $form['end'],
        ]);

        $tpl->assign('display_thumbnails', $display_thumbnails);
        $tpl->assign('display_thumbnail_selected', $form['display_thumbnail']);
        $tpl->assign('guest_id', Config::guestId());
        $tpl->assign('ADMIN_PAGE_TITLE', Lang::t('History'));
        $tpl->assign('page_data_json', json_encode([
            'API_METHOD'                  => $this->urlGenerator->ws(['format' => 'json', 'method' => 'pwg.history.search']),
            'filter_user_name'            => $form_param['user_name'] ?? null,
            'guest_id'                    => Config::guestId(),
            'today'                       => date('Y-m-d'),
            'initial_user_id'             => $form_param['user_id'],
            'initial_image_id'            => $form_param['image_id'] ?? '',
            'initial_ip'                  => $form_param['ip'] ?? '',
            'new_user_item'               => null,
            'str_dwld'                    => Lang::t('Downloaded'),
            'str_most_visited'            => Lang::t('Most visited'),
            'str_best_rated'              => Lang::t('Best rated'),
            'str_list'                    => Lang::t('Random photo'),
            'str_favorites'               => Lang::t('Your favorites'),
            'str_recent_cats'             => Lang::t('Recent albums'),
            'str_recent_pics'             => Lang::t('Recent photos'),
            'str_memories'                => Lang::t('Memories'),
            'str_no_longer_exist_photo'   => Lang::t('This photo no longer exists'),
            'str_guest'                   => Lang::t('guest'),
            'str_contact_form'            => Lang::t('Contact Form'),
            'str_edit_img'                => Lang::t('Edit photo'),
            'unit_MB'                     => Lang::t('%s MB'),
            'str_and_more'                => Lang::t('and %d more'),
            'str_search_details'          => ['allwords' => Lang::t('Search for words'), 'date_posted' => Lang::t('Post date'), 'tags' => Lang::t('Tags'), 'cat' => Lang::t('Album'), 'author' => Lang::t('Author'), 'added_by' => Lang::t('Added by'), 'filetypes' => Lang::t('File type')],
        ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));

        $tpl->assignVarFromTemplate('ADMIN_CONTENT', 'history.latte');
    }

    // ── stats ─────────────────────────────────────────────────────────────────

    private function stats(): void
    {
        $tpl = TemplateRegistry::current();
        /** @var array<string, mixed> $user */
        $user = CurrentUser::get()->rawAttributes;

        $this->historyAdminService->historySummarize();

        $this->historyAdminService->historyTabsheet('stats');
        $tpl->assign(['U_HELP' => $this->urlGenerator->adminPopupHelp('history'), 'F_ACTION' => $this->urlGenerator->admin('history')]);

        $actual_date = new \DateTime();
        $actual_date->add(new \DateInterval('PT1S'));

        $first_date = new \DateTime();
        $last_hours  = $this->setMissingValues('hour', $this->getLast(72, 'hour'), $first_date->sub(new \DateInterval('P3D')), $actual_date);

        $first_date = new \DateTime();
        $last_days   = $this->setMissingValues('day', $this->getLast(90, 'day'), $first_date->sub(new \DateInterval('P90D')), $actual_date);

        $first_date  = new \DateTime();
        $last_months = $this->setMissingValues('month', $this->getLast(60, 'month'), $first_date->sub(new \DateInterval('P60M')), $actual_date);

        if (count($this->getLast(60, 'year')) > 1) {
            $last_years = $this->setMissingValues('year', $this->getLast(60, 'year'));
        } else {
            $last_year_date = new \DateTime();
            $last_years = $this->setMissingValues('year', $this->getLast(60, 'year'), $last_year_date->sub(new \DateInterval('P1Y')), new \DateTime());
        }

        $langMonth = Lang::months();
        ksort($langMonth);

        $tpl->assign([
            'compareYears' => $this->getMonthOfLastYears(Config::statCompareYearDisplayed()),
            'monthStats'   => $this->getMonthStats(),
            'lastHours'    => $last_hours,
            'lastDays'     => $last_days,
            'lastMonths'   => $last_months,
            'lastYears'    => $last_years,
            'ADMIN_PAGE_TITLE' => Lang::t('History'),
            'page_data_json' => json_encode([
                'str_avg'                => Lang::t('Average last 12 months'),
                'str_number_page_visited' => Lang::t('Page Visited'),
                'str_months'             => array_values($langMonth),
                'lang_code'              => is_scalar($user['language'] ?? null) ? (string) $user['language'] : '',
            ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE),
        ]);

        $tpl->assignVarFromTemplate('ADMIN_CONTENT', 'stats.latte');
    }

    // ── site_manager ──────────────────────────────────────────────────────────

    private function siteManager(): void
    {
        $tpl = TemplateRegistry::current();

        if (!Config::enableSynchronization()) {
            throw new ConfigException('synchronization is disabled');
        }

        if (!empty($_POST) || isset($_GET['action'])) {
            $this->csrfService->check();
        }

        $tabsheet = new Tabsheet();
        $tabsheet->setId('site_update');
        $tabsheet->select('site_maager');
        $tabsheet->assign();

        $rawGalleriesUrl = $_POST['galleries_url'] ?? null;
        if (isset($_POST['submit']) && isset($_POST['galleries_url']) && $rawGalleriesUrl !== null && $rawGalleriesUrl !== '') {
            $galleries_url = is_string($rawGalleriesUrl) ? $rawGalleriesUrl : '';
            $is_remote = UrlService::urlIsRemote($galleries_url);
            if ($is_remote) {
                HtmlService::fatalError('remote sites not supported');
            }
            $url = (string) preg_replace('/[\/]*$/', '', $galleries_url) . '/';
            if (!str_starts_with($url, '.')) {
                $url = './' . $url;
            }

            $siteRepo = $this->siteRepository;
            if ($siteRepo->countByUrl($url) > 0) {
                PageState::current()->addError(Lang::t('This site already exists') . ' [' . $url . ']');
            }

            if (count(PageState::current()->errors) == 0) {
                if (!file_exists($url)) {
                    PageState::current()->addError(Lang::t('Directory does not exist') . ' [' . $url . ']');
                }
            }
            if (count(PageState::current()->errors) == 0) {
                $siteRepo->insert($url);
                PageState::current()->addInfo($url . ' ' . Lang::t('created'));
            }
        }

        $siteIdSm = null;
        if (isset($_GET['site']) && is_numeric($_GET['site'])) {
            $siteIdSm = (int) $_GET['site'];
        }
        if (isset($_GET['action']) && $siteIdSm !== null) {
            $galleries_url = $this->siteRepository->findGalleriesUrlById($siteIdSm);
            switch ($_GET['action']) {
                case 'delete': $this->categoryAdminService->deleteSite($siteIdSm);
                    PageState::current()->addInfo(($galleries_url ?? '') . ' ' . Lang::t('deleted'));
                    break;
            }
        }

        $tpl->assign([
            'F_ACTION'   => $this->urlGenerator->admin() . $this->urlService->getQueryStringDiff(['action', 'site', 'pwg_token']),
            'PWG_TOKEN'  => $this->csrfService->getToken(),
            'ADMIN_PAGE_TITLE' => Lang::t('Synchronize'),
            'page_data_json' => json_encode(['str_delete_site_confirm' => Lang::t('Are you sure you want to delete this site?')], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE),
        ]);

        $sites_detail = array_column($this->conn->executeQuery('SELECT c.site_id, COUNT(DISTINCT c.id) AS nb_categories, COUNT(i.id) AS nb_images FROM ' . Tables::categories() . ' AS c LEFT JOIN ' . Tables::images() . ' AS i ON c.id=i.storage_category_id WHERE c.site_id IS NOT NULL GROUP BY c.site_id;')->fetchAllAssociative(), null, 'site_id');

        foreach ($this->siteRepository->findAll() as $row) {
            $row_id_str = is_scalar($row['id'] ?? null) ? (string) $row['id'] : '';
            $is_remote  = UrlService::urlIsRemote(is_string($row['galleries_url'] ?? null) ? $row['galleries_url'] : '');
            $base_url   = $this->urlGenerator->admin('site_manager') . '&site=' . $row_id_str . '&pwg_token=' . $this->csrfService->getToken() . '&action=';
            $update_url = $this->urlGenerator->admin('site_update') . '&site=' . $row_id_str;
            $site_id    = is_numeric($row['id']) ? (int) $row['id'] : 0;

            $tpl_var = [
                'NAME'       => $row['galleries_url'],
                'TYPE'       => Lang::t($is_remote ? 'Remote' : 'Local'),
                'CATEGORIES' => is_numeric($sites_detail[(string) $site_id]['nb_categories'] ?? null) ? (int) $sites_detail[(string) $site_id]['nb_categories'] : 0,
                'IMAGES'     => is_numeric($sites_detail[(string) $site_id]['nb_images'] ?? null) ? (int) $sites_detail[(string) $site_id]['nb_images'] : 0,
                'U_SYNCHRONIZE' => $update_url,
            ];

            if ($row['id'] != 1) {
                $tpl_var['U_DELETE'] = $base_url . 'delete';
            }
            $rowId = is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
            $siteLinksEvent = new GetAdminsSiteLinks([], $rowId, $is_remote);
            $this->dispatcher->dispatch($siteLinksEvent);
            $tpl_var['plugin_links'] = $siteLinksEvent->pluginLinks;
            $tpl->append('sites', $tpl_var);
        }

        $tpl->assignVarFromTemplate('ADMIN_CONTENT', 'site_manager.latte');
    }

    // ── site_reader_local ─────────────────────────────────────────────────────

    private function siteReaderLocal(): void
    {
    }

    // ── site_update ───────────────────────────────────────────────────────────

    private function siteUpdate(): void
    {
        $tpl    = TemplateRegistry::current();
        $logger = LoggerRegistry::current();
        /** @var array<string, mixed> $user */
        $user = CurrentUser::get()->rawAttributes;

        if (!Config::enableSynchronization()) {
            throw new ConfigException('synchronization is disabled');
        }

        if (!is_numeric($_GET['site'])) {
            throw new ValidationException('site param missing or invalid');
        }
        $site_id = $_GET['site'];

        $site_url = $this->siteRepository->findGalleriesUrlById((int) $site_id);
        if (!isset($site_url)) {
            throw new NotFoundException('site ' . $site_id . ' does not exist');
        }
        $site_url_str  = $site_url;
        $site_is_remote = UrlService::urlIsRemote($site_url_str);

        $now = new \DateTimeImmutable();
        $nowDateTime = $now->format('Y-m-d H:i:s');
        $today = $now->format('Y-m-d');

        $error_labels = [
            'PWG-UPDATE-1'    => [Lang::t('wrong filename'), Lang::t('The name of directories and files must be composed of letters, numbers, "-", "_" or "."')],
            'PWG-ERROR-NO-FS' => [Lang::t('File/directory read error'), Lang::t('The file or directory cannot be accessed (either it does not exist or the access is denied)')],
        ];
        $errors = [];
        $infos  = [];

        if ($site_is_remote) {
            HtmlService::fatalError('remote sites not supported');
        } else {
            $site_reader = new LocalSiteReader($site_url_str);
        }

        if (count($this->imageAdminService->getPhotosNoMd5sum()) > 0) {
            $tpl->assign(['save_error' => new Html('<a href="' . $this->urlGenerator->admin('batch_manager') . '&amp;filter=prefilter-no_sync_md5sum">' . Lang::t('Some checksums are missing.') . '<i class="icon-right"></i></a>')]);
        }

        $tabsheet = new Tabsheet();
        $tabsheet->setId('site_update');
        $tabsheet->select('synchronization');
        $tabsheet->assign();

        if (isset($_GET['quick_sync'])) {
            $this->csrfService->check();
            $_POST['sync'] = 'files';
            $_POST['display_info'] = '1';
            $_POST['add_to_caddie'] = '1';
            $_POST['privacy_level'] = '0';
            $_POST['sync_meta'] = '1';
            $_POST['simulate'] = '0';
            $_POST['subcats-included'] = '1';
            $_POST['submit'] = 'Quick Local Synchronization';
        }

        $general_failure = true;
        $simulate        = false;
        $counts          = [];
        $db_categories   = [];
        $basedir         = '';
        $to_delete       = [];
        $caddiables      = [];

        if (isset($_POST['submit'])) {
            if ($site_reader->open()) {
                $general_failure = false;
            }
            $simulate = (isset($_POST['simulate']) && $_POST['simulate'] == 1);
        }

        // ── directories / categories ──────────────────────────────────────────

        if (isset($_POST['submit']) && ($_POST['sync'] == 'dirs' || $_POST['sync'] == 'files')) {
            $counts['new_categories'] = $counts['del_categories'] = $counts['del_elements'] = $counts['new_elements'] = $counts['upd_elements'] = 0;
        }

        if (isset($_POST['submit']) && ($_POST['sync'] == 'dirs' || $_POST['sync'] == 'files') && !$general_failure) {
            $start = StringUtil::getMoment();
            $query = 'SELECT id, uppercats, global_rank, status, visible FROM ' . Tables::categories() . ' WHERE dir IS NOT NULL AND site_id = ' . $site_id;
            if (isset($_POST['cat']) && is_numeric($_POST['cat'])) {
                if (isset($_POST['subcats-included']) && $_POST['subcats-included'] == 1) {
                    $query .= ' AND uppercats ' . Dml::REGEX_OPERATOR . " '(^|,)" . $_POST['cat'] . "(,|$)'";
                } else {
                    $query .= ' AND id = ' . $_POST['cat'];
                }
            }
            $db_categories = array_column($this->conn->executeQuery($query)->fetchAllAssociative(), null, 'id');
            $db_fulldirs   = $this->categoryAdminService->getFulldirs(array_map(intval(...), array_keys($db_categories)));

            if (isset($_POST['cat']) && is_numeric($_POST['cat'])) {
                $basedir = $db_fulldirs[(int) $_POST['cat']] ?? '';
            } else {
                $basedir = (string) preg_replace('#/*$#', '', $site_url_str);
            }

            $db_fulldirs = array_flip($db_fulldirs);
            $next_rank   = ['NULL' => 1];
            $conn        = $this->conn;
            foreach ($conn->executeQuery('SELECT id FROM ' . Tables::categories())->fetchAllAssociative() as $row) {
                $rowIdKey = is_scalar($row['id'] ?? null) ? (string) $row['id'] : '';
                if ($rowIdKey !== '') {
                    $next_rank[$rowIdKey] = 1;
                }
            }
            foreach ($conn->executeQuery('SELECT id_uppercat, MAX(`rank`)+1 AS next_rank FROM ' . Tables::categories() . ' GROUP BY id_uppercat')->fetchAllAssociative() as $row) {
                if (!isset($row['id_uppercat']) || $row['id_uppercat'] == '') {
                    $row['id_uppercat'] = 'NULL';
                }
                $rowIdUppercatRaw = $row['id_uppercat'];
                $ruk = is_string($rowIdUppercatRaw) ? $rowIdUppercatRaw : 'NULL';
                $next_rank[$ruk] = $row['next_rank'];
            }

            $next_id_raw = $this->conn->executeQuery('SELECT IF(MAX(id)+1 IS NULL, 1, MAX(id)+1) FROM `' . Tables::categories() . '`')->fetchOne();
            $next_id     = is_numeric($next_id_raw) ? (int) $next_id_raw : 1;

            $fs_fulldirs = $site_reader->getFullDirectories($basedir);
            if (isset($_POST['cat'])) {
                $fs_fulldirs[] = $basedir;
            }
            if (!isset($_POST['subcats-included']) || $_POST['subcats-included'] != 1) {
                $fs_fulldirs = array_intersect($fs_fulldirs, array_keys($db_fulldirs));
            }

            $inserts = [];
            foreach (array_diff($fs_fulldirs, array_keys($db_fulldirs)) as $fulldir) {
                $dir = basename($fulldir);
                if (preg_match(Config::syncCharsRegex(), $dir)) {
                    $insert = ['id' => $next_id++, 'dir' => $dir, 'name' => str_replace('_', ' ', $dir), 'site_id' => $site_id, 'commentable' => BoolUtil::toInt(Config::newcatDefaultCommentable()), 'status' => Config::newcatDefaultStatus(), 'visible' => BoolUtil::toInt(Config::newcatDefaultVisible())];
                    if (isset($db_fulldirs[dirname($fulldir)])) {
                        $parent    = $db_fulldirs[dirname($fulldir)];
                        $parentKey = $parent;
                        $parentRow = is_array($db_categories[$parent] ?? null) ? $db_categories[$parent] : [];
                        $insert['id_uppercat'] = $parent;
                        $insert['uppercats']   = (is_string($parentRow['uppercats'] ?? null) ? $parentRow['uppercats'] : '') . ',' . $insert['id'];
                        $nextRankParent        = is_int($next_rank[$parentKey] ?? null) ? $next_rank[$parentKey] : 0;
                        $insert['rank']        = $nextRankParent;
                        $next_rank[$parentKey] = $nextRankParent + 1;
                        $insert['global_rank'] = (is_scalar($parentRow['global_rank'] ?? null) ? (string) $parentRow['global_rank'] : '') . '.' . $insert['rank'];
                        if ('private' == ($parentRow['status'] ?? '')) {
                            $insert['status'] = 'private';
                        }
                        if (isset($parentRow['visible']) && !BoolUtil::fromMixed($parentRow['visible'])) {
                            $insert['visible'] = 0;
                        }
                    } else {
                        $insert['uppercats']   = $insert['id'];
                        $nextRankNull          = is_int($next_rank['NULL'] ?? null) ? $next_rank['NULL'] : 0;
                        $insert['rank']        = $nextRankNull;
                        $next_rank['NULL']     = $nextRankNull + 1;
                        $insert['global_rank'] = $insert['rank'];
                    }
                    $inserts[] = $insert;
                    $infos[]   = ['path' => $fulldir, 'info' => Lang::t('added')];
                    $db_categories[$insert['id']] = ['id' => $insert['id'], 'parent' => $parent ?? null, 'status' => $insert['status'], 'visible' => $insert['visible'], 'uppercats' => $insert['uppercats'], 'global_rank' => $insert['global_rank']];
                    $db_fulldirs[$fulldir] = $insert['id'];
                    $next_rank[$insert['id']] = 1;
                } else {
                    $errors[] = ['path' => $fulldir, 'type' => 'PWG-UPDATE-1'];
                }
            }

            if (count($inserts) > 0 && !$simulate) {
                // `rank` is a MySQL 8.0 reserved word — backtick the array key per row.
                $catRows = array_map(static function (array $r): array {
                    $r['`rank`'] = $r['rank'];
                    unset($r['rank']);
                    return $r;
                }, $inserts);
                $this->conn->transactional(function () use ($catRows): void {
                    foreach ($catRows as $row) {
                        $this->conn->insert(Tables::categories(), $row);
                    }
                });
                $category_ids = $category_up = [];
                foreach ($inserts as $category) {
                    $category_ids[] = $category['id'];
                    if (isset($category['id_uppercat']) && $category['id_uppercat'] !== '' && $category['id_uppercat'] !== 0) {
                        $category_up[] = $category['id_uppercat'];
                    }
                }
                $this->activityLogger->log(new ActivityEvent(ActivityObject::Album, $category_ids, 'add', ['sync' => true]));
                $category_up_str = implode(',', array_unique($category_up));
                if (Config::inheritanceByDefault() && !empty($category_up_str)) {
                    $permRepoSync    = $this->permissionRepository;
                    $category_up_ids = array_map(intval(...), explode(',', $category_up_str));
                    $groupAccessRows = $permRepoSync->findGroupCategoryAccess($category_up_ids);
                    $granted_grps    = [];
                    if (!empty($groupAccessRows)) {
                        foreach ($groupAccessRows as $row) {
                            $cik = is_scalar($row['cat_id'] ?? null) ? (string) $row['cat_id'] : '';
                            if (!isset($granted_grps[$cik])) {
                                $granted_grps[$cik] = [];
                            }
                            array_push($granted_grps, [$cik => array_push($granted_grps[$cik], $row['group_id'])]);
                        }
                    }
                    $userAccessRows = $permRepoSync->findUserCategoryAccess($category_up_ids);
                    $granted_users  = [];
                    if (!empty($userAccessRows)) {
                        foreach ($userAccessRows as $row) {
                            $cik = is_scalar($row['cat_id'] ?? null) ? (string) $row['cat_id'] : '';
                            if (!isset($granted_users[$cik])) {
                                $granted_users[$cik] = [];
                            }
                            array_push($granted_users, [$cik => array_push($granted_users[$cik], $row['user_id'])]);
                        }
                    }
                    $insert_granted_users = $insert_granted_grps = [];
                    foreach ($category_ids as $ids) {
                        $idsRow    = is_array($db_categories[$ids] ?? null) ? $db_categories[$ids] : [];
                        $parent_id = is_numeric($idsRow['parent'] ?? null) ? (int) $idsRow['parent'] : null;
                        while ($parent_id !== null && in_array($parent_id, $category_ids)) {
                            $pidRow    = is_array($db_categories[$parent_id] ?? null) ? $db_categories[$parent_id] : [];
                            $parent_id = is_numeric($pidRow['parent'] ?? null) ? (int) $pidRow['parent'] : null;
                        }
                        if (($idsRow['status'] ?? '') == 'private' && $parent_id !== null) {
                            if (isset($granted_grps[$parent_id])) {
                                foreach ($granted_grps[$parent_id] as $granted_grp) {
                                    $insert_granted_grps[] = ['group_id' => $granted_grp, 'cat_id' => $ids];
                                }
                            }
                            if (isset($granted_users[$parent_id])) {
                                foreach ($granted_users[$parent_id] as $granted_user) {
                                    $insert_granted_users[] = ['user_id' => $granted_user, 'cat_id' => $ids];
                                }
                            }
                        }
                    }
                    $this->conn->transactional(function () use ($insert_granted_grps, $insert_granted_users): void {
                        foreach ($insert_granted_grps as $row) {
                            $this->conn->insert(Tables::groupAccess(), $row);
                        }
                        foreach (array_unique($insert_granted_users, SORT_REGULAR) as $row) {
                            $this->conn->insert(Tables::userAccess(), $row);
                        }
                    });
                } else {
                    $this->categoryAdminService->addPermissionOnCategory($category_ids, $this->userAdminService->getAdmins());
                }
            }
            $counts['new_categories'] = count($inserts);

            $to_delete = $to_delete_derivative_dirs = [];
            foreach (array_diff(array_keys($db_fulldirs), $fs_fulldirs) as $fulldir) {
                $to_delete[] = $db_fulldirs[$fulldir];
                unset($db_fulldirs[$fulldir]);
                $infos[] = ['path' => $fulldir, 'info' => Lang::t('deleted')];
                if (substr_compare($fulldir, '../', 0, 3) == 0) {
                    $fulldir = substr($fulldir, 3);
                }
                $to_delete_derivative_dirs[] = $this->paths->root . Config::derivativeDir() . $fulldir;
            }
            if (count($to_delete) > 0) {
                if (!$simulate) {
                    $this->categoryAdminService->deleteCategories($to_delete);
                    foreach ($to_delete_derivative_dirs as $to_delete_dir) {
                        if (is_dir($to_delete_dir)) {
                            $this->imageAdminService->clearDerivativeCacheRec($to_delete_dir, '#.+#');
                        }
                    }
                }
                $counts['del_categories'] = count($to_delete);
            }
            $tpl->append('footer_elements', '<!-- scanning dirs : ' . StringUtil::getElapsedTime($start, StringUtil::getMoment()) . ' -->');
        }

        // ── files / elements ──────────────────────────────────────────────────

        if (isset($_POST['submit']) && $_POST['sync'] == 'files' && !$general_failure) {
            $start_files = $start = StringUtil::getMoment();
            $fs = $site_reader->getElements($basedir);
            $tpl->append('footer_elements', '<!-- get_elements: ' . StringUtil::getElapsedTime($start, StringUtil::getMoment()) . ' -->');

            $cat_ids    = array_diff(array_keys($db_categories), $to_delete);
            $db_elements = [];
            if (count($cat_ids) > 0) {
                $query       = 'SELECT id, path FROM ' . Tables::images() . ' WHERE storage_category_id IN (' . wordwrap(implode(', ', $cat_ids), 160, "\n") . ')';
                $db_elements = array_column($this->conn->executeQuery($query)->fetchAllAssociative(), 'path', 'id');
            }

            $next_element_id_raw = $this->conn->executeQuery('SELECT IF(MAX(id)+1 IS NULL, 1, MAX(id)+1) FROM `' . Tables::images() . '`')->fetchOne();
            $next_element_id     = is_numeric($next_element_id_raw) ? (int) $next_element_id_raw : 1;

            $start             = StringUtil::getMoment();
            $inserts           = $insert_links = $insert_formats = $formats_to_delete = [];

            foreach (array_diff(array_keys($fs), array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $db_elements)) as $path) {
                $dirname  = dirname((string) $path);
                if (!isset($db_fulldirs[$dirname])) {
                    continue;
                }
                $filename = basename((string) $path);
                if (!preg_match(Config::syncCharsRegex(), $filename)) {
                    $errors[] = ['path' => $path, 'type' => 'PWG-UPDATE-1'];
                    continue;
                }
                $insert = ['id' => $next_element_id++, 'file' => $filename, 'name' => StringUtil::getNameFromFile($filename), 'date_available' => $nowDateTime, 'path' => $path, 'representative_ext' => is_array($fs[$path]) ? ($fs[$path]['representative_ext'] ?? null) : null, 'storage_category_id' => $db_fulldirs[$dirname], 'added_by' => $user['id']];
                if ($_POST['privacy_level'] != 0) {
                    $insert['level'] = $_POST['privacy_level'];
                }
                $inserts[]      = $insert;
                $insert_links[] = ['image_id' => $insert['id'], 'category_id' => $insert['storage_category_id']];
                $infos[]        = ['path' => $insert['path'], 'info' => Lang::t('added')];
                if (Config::isFormatsEnabled()) {
                    $fs_path_formats = is_array($fs[$path]) && is_array($fs[$path]['formats'] ?? null) ? $fs[$path]['formats'] : [];
                    foreach ($fs_path_formats as $ext => $filesize) {
                        $insert_formats[] = ['image_id' => $insert['id'], 'ext' => $ext, 'filesize' => $filesize];
                        $infos[] = ['path' => $insert['path'], 'info' => Lang::t('format %s added', $ext)];
                    }
                }
                $caddiables[] = $insert['id'];
            }

            if (Config::isFormatsEnabled()) {
                $db_elements_str  = array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $db_elements);
                $db_elements_flip = array_flip($db_elements_str);
                $existing_ids     = [];
                foreach (array_intersect_key($fs, $db_elements_flip) as $path => $existing) {
                    $existing_ids[] = $db_elements_flip[$path];
                }
                $logger->debug('existing_ids', $existing_ids);

                if (count($existing_ids) > 0) {
                    $db_formats = [];
                    foreach ($this->imageRepository->findFormatsByImageIds(array_map(intval(...), $existing_ids)) as $row) {
                        $row_image_id = is_scalar($row['image_id'] ?? null) ? (string) $row['image_id'] : '';
                        $row_ext      = is_scalar($row['ext'] ?? null) ? (string) $row['ext'] : '';
                        if (!isset($db_formats[$row_image_id])) {
                            $db_formats[$row_image_id] = [];
                        }
                        $db_formats[$row_image_id][$row_ext] = $row['format_id'];
                    }
                    foreach ($db_formats as $image_id => $formats) {
                        $db_elem_path  = is_scalar($db_elements[$image_id] ?? null) ? (string) ($db_elements[$image_id] ?? '') : '';
                        $fs_elem       = is_array($fs[$db_elem_path] ?? null) ? $fs[$db_elem_path] : [];
                        $fs_formats    = is_array($fs_elem['formats'] ?? null) ? $fs_elem['formats'] : [];
                        $image_formats_to_delete = array_diff_key($formats, $fs_formats);
                        $logger->debug('image_formats_to_delete', $image_formats_to_delete);
                        foreach ($image_formats_to_delete as $ext => $format_id) {
                            $formats_to_delete[] = $format_id;
                            $infos[] = ['path' => $db_elem_path, 'info' => Lang::t('format %s removed', $ext)];
                        }
                    }
                    foreach ($existing_ids as $image_id) {
                        $image_id_str  = (string) $image_id;
                        $path          = is_scalar($db_elements[$image_id_str] ?? null) ? (string) ($db_elements[$image_id_str] ?? '') : '';
                        $formats       = $db_formats[$image_id_str] ?? [];
                        $fs_path_data  = is_array($fs[$path] ?? null) ? $fs[$path] : [];
                        $fs_path_formats = is_array($fs_path_data['formats'] ?? null) ? $fs_path_data['formats'] : [];
                        $image_formats_to_insert = array_diff_key($fs_path_formats, $formats);
                        $logger->debug('image_formats_to_insert', $image_formats_to_insert);
                        foreach ($image_formats_to_insert as $ext => $filesize) {
                            $insert_formats[] = ['image_id' => $image_id, 'ext' => $ext, 'filesize' => $filesize];
                            $infos[] = ['path' => $path, 'info' => Lang::t('format %s added', $ext)];
                        }
                    }
                }
            }

            if (!$simulate) {
                if (count($inserts) > 0) {
                    $this->conn->transactional(function () use ($inserts, $insert_links): void {
                        foreach ($inserts as $row) {
                            $this->conn->insert(Tables::images(), $row);
                        }
                        foreach ($insert_links as $row) {
                            $this->conn->insert(Tables::imageCategory(), $row);
                        }
                    });
                    $this->activityLogger->log(new ActivityEvent(ActivityObject::Photo, $caddiables, 'add', ['sync' => true]));
                    if (isset($_POST['add_to_caddie']) && $_POST['add_to_caddie'] == 1) {
                        $this->imageRepository->addToUserCaddie(CurrentUser::get()->id, $caddiables);
                    }
                }
                if (count($insert_formats) > 0) {
                    $this->conn->transactional(function () use ($insert_formats): void {
                        foreach ($insert_formats as $row) {
                            $this->conn->insert(Tables::imageFormat(), $row);
                        }
                    });
                }
                if (count($formats_to_delete) > 0) {
                    $this->imageRepository->deleteFormatsByFormatIds(array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $formats_to_delete));
                }
            }
            $counts['new_elements'] = count($inserts);

            $to_delete_elements = [];
            foreach (array_diff(array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $db_elements), array_keys($fs)) as $path) {
                $found = array_search($path, $db_elements);
                if ($found !== false) {
                    $to_delete_elements[] = (int) $found;
                }
                $infos[] = ['path' => $path, 'info' => Lang::t('deleted')];
            }
            if (count($to_delete_elements) > 0) {
                if (!$simulate) {
                    $this->imageAdminService->deleteElements($to_delete_elements);
                } $counts['del_elements'] = count($to_delete_elements);
            }

            $tpl->append('footer_elements', '<!-- scanning files : ' . StringUtil::getElapsedTime($start_files, StringUtil::getMoment()) . ' -->');
        }

        // ── sync categories & files ───────────────────────────────────────────

        if (isset($_POST['submit']) && ($_POST['sync'] == 'dirs' || $_POST['sync'] == 'files') && !$general_failure) {
            if (!$simulate) {
                $start = StringUtil::getMoment();
                $this->categoryAdminService->updateCategory('all');
                $tpl->append('footer_elements', '<!-- $this->categoryAdminService->updateCategory(all) : ' . StringUtil::getElapsedTime($start, StringUtil::getMoment()) . ' -->');
                $start = StringUtil::getMoment();
                $this->categoryAdminService->updateGlobalRank();
                $tpl->append('footer_elements', '<!-- ordering categories : ' . StringUtil::getElapsedTime($start, StringUtil::getMoment()) . ' -->');
            }
            if ($_POST['sync'] == 'files') {
                $start = StringUtil::getMoment();
                $opts  = ['category_id' => '', 'recursive' => true];
                if (isset($_POST['cat'])) {
                    $opts['category_id'] = $_POST['cat'];
                    if (!isset($_POST['subcats-included']) || $_POST['subcats-included'] != 1) {
                        $opts['recursive'] = false;
                    }
                }
                $catIdOpt = is_string($opts['category_id']) ? $opts['category_id'] : '';
                $files = $this->metadataAdminService->getFilelist($catIdOpt, (int) $site_id, $opts['recursive'], false);
                $tpl->append('footer_elements', '<!-- get_filelist : ' . StringUtil::getElapsedTime($start, StringUtil::getMoment()) . ' -->');
                $start = StringUtil::getMoment();
                $datas = [];
                foreach ($files as $id => $file) {
                    $file_path = is_array($file) && is_scalar($file['path'] ?? null) ? (string) $file['path'] : '';
                    $data      = $site_reader->getElementUpdateAttributes($file_path);
                    $data['id'] = $id;
                    $datas[]   = $data;
                }
                $counts['upd_elements'] = count($datas);
                if (!$simulate && count($datas) > 0) {
                    Dml::massUpdates(Tables::images(), ['primary' => ['id'], 'update' => array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $site_reader->getUpdateAttributes())], $datas);
                }
                $tpl->append('footer_elements', '<!-- update files : ' . StringUtil::getElapsedTime($start, StringUtil::getMoment()) . ' -->');
            }
        }

        if (isset($_POST['submit']) && ($_POST['sync'] == 'dirs' || $_POST['sync'] == 'files')) {
            $tpl->assign('update_result', ['NB_NEW_CATEGORIES' => $counts['new_categories'] ?? 0, 'NB_DEL_CATEGORIES' => $counts['del_categories'] ?? 0, 'NB_NEW_ELEMENTS' => $counts['new_elements'] ?? 0, 'NB_DEL_ELEMENTS' => $counts['del_elements'] ?? 0, 'NB_UPD_ELEMENTS' => $counts['upd_elements'] ?? 0, 'NB_ERRORS' => count($errors)]);
        }

        // ── metadata sync ─────────────────────────────────────────────────────

        if (isset($_POST['submit']) && isset($_POST['sync_meta']) && !$general_failure) {
            $opts = ['only_new' => !isset($_POST['meta_all']), 'category_id' => '', 'recursive' => true];
            if (isset($_POST['cat'])) {
                $opts['category_id'] = $_POST['cat'];
                if (!isset($_POST['subcats-included']) || $_POST['subcats-included'] != 1) {
                    $opts['recursive'] = false;
                }
            }
            $start       = StringUtil::getMoment();
            $catIdMeta   = is_string($opts['category_id']) ? $opts['category_id'] : '';
            $files = $this->metadataAdminService->getFilelist($catIdMeta, (int) $site_id, $opts['recursive'], $opts['only_new']);
            $tpl->append('footer_elements', '<!-- get_filelist : ' . StringUtil::getElapsedTime($start, StringUtil::getMoment()) . ' -->');
            $start = StringUtil::getMoment();
            $datas = $tags_of = [];
            foreach ($files as $id => $element_infos) {
                $element_infos_arr = is_array($element_infos) ? $element_infos : [];
                $data = $site_reader->getElementMetadata($element_infos_arr);
                if (is_array($data)) {
                    $data['date_metadata_update'] = $today;
                    $data['id'] = $id;
                    $datas[]    = $data;
                    foreach (['keywords', 'tags'] as $key) {
                        if (isset($data[$key])) {
                            if (!isset($tags_of[$id])) {
                                $tags_of[$id] = [];
                            }
                            foreach (explode(',', is_scalar($data[$key]) ? (string) $data[$key] : '') as $tag_name) {
                                $tags_of[$id][] = $this->tagAdminService->tagIdFromTagName($tag_name);
                            }
                        }
                    }
                } else {
                    $errors[] = ['path' => $element_infos_arr['path'] ?? '', 'type' => 'PWG-ERROR-NO-FS'];
                }
            }
            if (!$simulate) {
                if (count($datas) > 0) {
                    Dml::massUpdates(Tables::images(), ['primary' => ['id'], 'update' => array_unique(array_merge(array_values(array_diff(array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $site_reader->getMetadataAttributes()), ['keywords', 'tags'])), ['date_metadata_update']))], $datas, isset($_POST['meta_empty_overrides']) ? 0 : Dml::SKIP_EMPTY);
                }
                $this->tagAdminService->setTagsOf($tags_of);
            }
            $tpl->append('footer_elements', '<!-- metadata update : ' . StringUtil::getElapsedTime($start, StringUtil::getMoment()) . ' -->');
            $tpl->assign('metadata_result', ['NB_ELEMENTS_DONE' => count($datas), 'NB_ELEMENTS_CANDIDATES' => count($files), 'NB_ERRORS' => count($errors)]);
        }

        // ── template ─────────────────────────────────────────────────────────

        $result_title  = $simulate ? '[' . Lang::t('Simulation') . '] ' : '';
        $used_metadata = implode(', ', array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $site_reader->getMetadataAttributes()));

        $tpl->assign([
            'SITE_URL'       => $site_url_str,
            'U_SITE_MANAGER' => $this->urlGenerator->admin('site_manager'),
            'L_RESULT_UPDATE' => $result_title . Lang::t('Search for new images in the directories'),
            'L_RESULT_METADATA' => $result_title . Lang::t('Metadata synchronization results'),
            'METADATA_LIST'  => $used_metadata,
            'U_HELP'         => $this->urlGenerator->adminPopupHelp('synchronize'),
            'ADMIN_PAGE_TITLE' => Lang::t('Synchronize'),
        ]);

        if (isset($_POST['submit'])) {
            $tpl_introduction = [
                'sync'                  => $_POST['sync'],
                'sync_meta'             => isset($_POST['sync_meta']),
                'display_info'          => isset($_POST['display_info']) && $_POST['display_info'] == 1,
                'add_to_caddie'         => isset($_POST['add_to_caddie']) && $_POST['add_to_caddie'] == 1,
                'subcats_included'      => isset($_POST['subcats-included']) && $_POST['subcats-included'] == 1,
                'privacy_level_selected' => (function (): int {
                    $v = $_POST['privacy_level'] ?? null;
                    return is_numeric($v) ? (int) $v : 0;
                })(),
                'meta_all'              => isset($_POST['meta_all']),
                'meta_empty_overrides'  => isset($_POST['meta_empty_overrides']),
            ];
            $catRaw = $_POST['cat'] ?? null;
            $cat_selected = is_numeric($catRaw) ? [(int) $catRaw] : [];
        } else {
            $tpl_introduction = ['sync' => 'dirs', 'sync_meta' => true, 'display_info' => false, 'add_to_caddie' => false, 'subcats_included' => true, 'privacy_level_selected' => 0, 'meta_all' => false, 'meta_empty_overrides' => false];
            $cat_selected = [];
            if (isset($_GET['cat_id'])) {
                $this->inputValidator->check('cat_id', $_GET, false, ValidationPattern::ID);
                $cat_selected = [is_numeric($_GET['cat_id']) ? (int) $_GET['cat_id'] : 0];
                $tpl_introduction['sync'] = 'files';
            }
        }

        $tpl_introduction['privacy_level_options'] = $this->htmlService->getPrivacyLevelOptions();
        $tpl->assign('introduction', $tpl_introduction);

        $this->categoryService->displaySelectCatWrapper('SELECT id,name,uppercats,global_rank FROM ' . Tables::categories() . ' WHERE site_id = ' . $site_id, $cat_selected, 'category_options', false);

        if (count($errors) > 0) {
            foreach ($errors as $error) {
                $tpl->append('sync_errors', ['ELEMENT' => $error['path'], 'LABEL' => $error['type'] . ' (' . $error_labels[$error['type']][0] . ')']);
            }
            foreach ($error_labels as $error_type => $error_description) {
                $tpl->append('sync_error_captions', ['TYPE' => $error_type, 'LABEL' => $error_description[1]]);
            }
        }

        if (count($infos) > 0 && isset($_POST['display_info']) && $_POST['display_info'] == 1) {
            foreach ($infos as $info) {
                $tpl->append('sync_infos', ['ELEMENT' => $info['path'], 'LABEL' => $info['info']]);
            }
        }

        $tpl->assignVarFromTemplate('ADMIN_CONTENT', 'site_update.latte');
    }

    // ── stats helper methods (from stats.php) ─────────────────────────────────

    /** @return array<mixed> */
    private function getLast(int $last_number = 60, string $type = 'year'): array
    {
        return $this->historyRepository->findSummaryByType($type, $last_number);
    }

    /** @return array<mixed> */
    private function getMonthOfLastYears(string|int $last = 'all'): array
    {
        $query = 'SELECT year, month, day, hour, nb_pages FROM ' . Tables::historySummary() . ' WHERE month IS NOT NULL AND day IS NULL ORDER BY year DESC, month DESC';
        if ($last !== 'all') {
            $date  = new \DateTime();
            $limit = ((int) $last - 1) * 12 + (int) $date->format('n') - 1;
            $query .= ' LIMIT ' . $limit;
            $result   = $this->conn->executeQuery($query . ';')->fetchAllAssociative();
            $lastDate = $date->sub(new \DateInterval('P' . ((int) $last - 1) . 'Y' . ((int) $date->format('n') - 1) . 'M'));
            return $this->setMissingValues('month', $result, $lastDate, new \DateTime());
        }
        if (count($this->conn->executeQuery($query . ';')->fetchAllAssociative()) > 1) {
            return $this->setMissingValues('month', $this->conn->executeQuery($query . ';')->fetchAllAssociative());
        } else {
            $last_year_date = new \DateTime();
            return $this->setMissingValues('month', $this->conn->executeQuery($query . ';')->fetchAllAssociative(), $last_year_date->sub(new \DateInterval('P1Y')), new \DateTime());
        }
    }

    /** @return array<mixed> */
    private function getMonthStats(): array
    {
        $result         = [];
        $date           = new \DateTime();
        $date_last_month = clone $date;
        $date_last_year  = clone $date;
        $date_last_month->sub(new \DateInterval('P1M'));
        $date_last_year->sub(new \DateInterval('P1Y'));

        $query = 'SELECT year, month, day, hour, nb_pages FROM ' . Tables::historySummary() .
            ' WHERE ((year = ' . $date->format('Y') . ' AND month = ' . $date->format('n') . ') OR (year = ' . $date_last_month->format('Y') . ' AND month = ' . $date_last_month->format('n') . ') OR (year = ' . $date_last_year->format('Y') . ' AND month = ' . $date_last_year->format('n') . ')) AND day IS NOT NULL AND hour IS NULL ORDER BY year DESC, month DESC;';

        $months = [];
        foreach ($this->conn->executeQuery($query)->fetchAllAssociative() as $value) {
            $dt = $this->getDateObject($value);
            $months[$dt->format('Y/m/1')][] = $value;
        }

        $actual_date = new \DateTime();
        if (!isset($months[$actual_date->format('Y/m/1')])) {
            $months[$actual_date->format('Y/m/1')][] = ['year' => $actual_date->format('Y'), 'month' => $actual_date->format('n'), 'day' => null, 'hour' => null, 'nb_pages' => 0];
        }

        foreach ($months as $key => $val) {
            $lastDate = new \DateTime($key)->add(new \DateInterval('P1M'))->sub(new \DateInterval('P1D'));
            if ($lastDate > new \DateTime()) {
                $lastDate = new \DateTime();
            }
            $result['month'][] = $this->setMissingValues('day', $val, new \DateTime($key), $lastDate);
        }

        $result['avg'] = $this->historyRepository->findCurrentPeriodDailyAvg((int) $date->format('Y'), (int) $date->format('n'));
        return $result;
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private function setMissingValues(string $unit, array $data, ?\DateTime $firstDate = null, ?\DateTime $lastDate = null): array
    {
        $result = [];
        if ($firstDate === null) {
            $data_last = $data[count($data) - 1] ?? null;
            $date      = is_array($data_last) ? $this->getDateObject($data_last) : new \DateTime();
        } else {
            $date = $firstDate;
        }
        if ($lastDate === null) {
            $data_first = $data[0] ?? null;
            $date_end   = is_array($data_first) ? $this->getDateObject($data_first) : new \DateTime();
        } else {
            $date_end = $lastDate;
        }

        $date_format = 'Y-m-d';
        $date_add    = 'P1D';
        if ($unit == 'year') {
            $date_format = 'Y';
            $date_add = 'P1Y';
        } elseif ($unit == 'month') {
            $date_format = 'Y-m';
            $date_add = 'P1M';
        } elseif ($unit == 'day') {
            $date_format = 'Y-m-d';
            $date_add = 'P1D';
        } elseif ($unit == 'hour') {
            $date_format = 'Y-m-d\TH:00';
            $date_add = 'PT1H';
        }

        while ($date <= $date_end) {
            $result[$date->format($date_format)] = 0;
            $date->add(new \DateInterval($date_add));
        }

        foreach ($data as $value) {
            if (!is_array($value)) {
                continue;
            }
            $str = $this->getDateObject($value)->format($date_format);
            if (isset($result[$str])) {
                $result[$str] += is_numeric($value['nb_pages'] ?? null) ? (int) $value['nb_pages'] : 0;
            }
        }

        return $result;
    }

    /** @param array<mixed> $row */
    private function getDateObject(array $row): \DateTime
    {
        $date_string = is_scalar($row['year'] ?? null) ? (string) $row['year'] : '2000';
        if (($row['month'] ?? null) !== null) {
            $date_string .= '-' . (is_string($row['month']) ? $row['month'] : '1');
            if (($row['day'] ?? null) !== null) {
                $date_string .= '-' . (is_string($row['day']) ? $row['day'] : '1');
                if (($row['hour'] ?? null) !== null) {
                    $date_string .= ' ' . (is_string($row['hour']) ? $row['hour'] : '0') . ':00';
                }
            }
        } else {
            $date_string .= '-1';
        }
        return new \DateTime($date_string);
    }

    /** @return list<mixed>|null */
    private function loadCacheSizes(): ?array
    {
        $item = $this->pool->getItem('piwigo.cache_sizes');
        if (!$item->isHit()) {
            return null;
        }
        $value = $item->get();
        return is_array($value) ? array_values($value) : null;
    }

    private function cacheSizesLastCalcLabel(): ?string
    {
        $cs = $this->loadCacheSizes();
        if ($cs === null) {
            return null;
        }
        $entry = $cs[3] ?? null;
        if (!is_array($entry)) {
            return null;
        }
        $value = $entry['value'] ?? null;
        return $this->dateService->timeSince(is_scalar($value) ? (string) $value : null, 'year');
    }
}
