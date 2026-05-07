<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Doctrine\DBAL\Connection;
use Piwigo\Admin\AdminService;
use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Admin\History\HistoryAdminService;
use Piwigo\Admin\Image\ImageAdminService;
use Piwigo\Admin\Image\PwgImage;
use Piwigo\Admin\Integrity\CheckIntegrity;
use Piwigo\Admin\MaintenanceService;
use Piwigo\Admin\Metadata\MetadataAdminService;
use Piwigo\Admin\Tabsheet;
use Piwigo\Admin\Tag\TagAdminService;
use Piwigo\Admin\Users\UserAdminService;
use Piwigo\Auth\CookieService;
use Piwigo\Cache\PersistentCacheRegistry;
use Piwigo\Config\Config;
use Piwigo\Core\BoolUtil;
use Piwigo\Core\LoggerRegistry;
use Piwigo\Core\PageState;
use Piwigo\Core\ServiceLocator;
use Piwigo\Db\DbConnection;
use Piwigo\Db\DbInfo;
use Piwigo\Db\Dml;
use Piwigo\Db\SchemaHelper;
use Piwigo\Exception\ConfigException;
use Piwigo\Exception\NotFoundException;
use Piwigo\Exception\ValidationException;
use Piwigo\History\HistoryRepository;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageStdParams;
use Piwigo\Job\RegenerateAllDerivativesJob;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Search\SearchRepository;
use Piwigo\Session\SessionRepository;
use Piwigo\Site\LocalSiteReader;
use Piwigo\Site\SiteRepository;
use Piwigo\Template\FileCombiner;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlGenerator;
use Piwigo\Users\PermissionService;
use Piwigo\Users\UserRepository;
use Symfony\Component\Messenger\MessageBusInterface;

final class MaintenanceController
{
    /** @var list<string> */
    public const array PAGES = [
        'maintenance', 'maintenance_actions', 'maintenance_env', 'maintenance_sys',
        'history', 'stats', 'site_manager', 'site_reader_local', 'site_update',
    ];

    /** @var array<string, array<string, string>> */
    private array $maintActions = [];

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
        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];

        if (isset($_GET['action'])) {
            check_pwg_token();
        }

        $this->maintActions = [
            'derivatives'        => ['icon' => 'icon-trash-1',       'label' => l10n('Delete multiple size images')],
            'lock_gallery'       => ['icon' => 'icon-lock',           'label' => l10n('Lock gallery')],
            'unlock_gallery'     => ['icon' => 'icon-lock',           'label' => l10n('Unlock gallery')],
            'categories'         => ['icon' => 'icon-folder-open',    'label' => l10n('Update albums informations')],
            'images'             => ['icon' => 'icon-info-circled-1', 'label' => l10n('Update photos information')],
            'empty_lounge'       => ['icon' => 'icon-thumbs-up',      'label' => l10n('Empty lounge')],
            'delete_orphan_tags' => ['icon' => 'icon-tags',           'label' => l10n('Delete orphan tags')],
            'user_cache'         => ['icon' => 'icon-user-1',         'label' => l10n('Purge user cache')],
            'history_detail'     => ['icon' => 'icon-back-in-time',   'label' => l10n('Purge history detail')],
            'history_summary'    => ['icon' => 'icon-back-in-time',   'label' => l10n('Purge history summary')],
            'sessions'           => ['icon' => 'icon-th-list',        'label' => l10n('Purge sessions')],
            'feeds'              => ['icon' => 'icon-bell',           'label' => l10n('Purge never used notification feeds')],
            'database'           => ['icon' => 'icon-database',       'label' => l10n('Repair and optimize database')],
            'c13y'               => ['icon' => 'icon-ok',             'label' => l10n('Reinitialize check integrity')],
            'search'             => ['icon' => 'icon-search',         'label' => l10n('Purge search history')],
            'compiled-templates' => ['icon' => 'icon-file-code',      'label' => l10n('Purge compiled templates')],
        ];
        $GLOBALS['maint_actions'] = $this->maintActions;

        $GLOBALS['my_base_url'] = $my_base_url = ServiceLocator::get(UrlGenerator::class)->admin() . '&page=';

        if (isset($_GET['tab'])) {
            check_input_parameter('tab', $_GET, false, '/^(actions|env|sys)$/');
            $page['tab'] = is_string($_GET['tab']) ? $_GET['tab'] : 'actions';
        } else {
            $page['tab'] = 'actions';
        }

        $tabsheet = new Tabsheet();
        $tabsheet->setId('maintenance');
        $tabsheet->select($page['tab']);
        $tabsheet->assign();

        $tab = (string) $page['tab'];
        if ($tab === 'actions') {
            $this->maintenanceActions();
        } elseif ($tab === 'env') {
            $this->maintenanceEnv();
        } elseif ($tab === 'sys') {
            $this->maintenanceSys();
        }

        $tpl->assign(['ADMIN_PAGE_TITLE' => l10n('Maintenance')]);
    }

    // ── maintenance_actions ───────────────────────────────────────────────────

    private function maintenanceActions(): void
    {
        $tpl = TemplateRegistry::current();

        ServiceLocator::get(ImageAdminService::class)->fsQuickCheck();

        if (empty($this->maintActions)) {
            /** @var array<string, array<string, string>> $maint_actions_g */
            $maint_actions_g = is_array($GLOBALS['maint_actions'] ?? null) ? $GLOBALS['maint_actions'] : [];
            $this->maintActions = $maint_actions_g;
        }
        $maint_actions = $this->maintActions;

        $action = is_scalar($_GET['action'] ?? null) ? (string) $_GET['action'] : '';
        $register_activity = true;

        switch ($action) {
            case 'phpinfo':
                phpinfo();
                exit();
            case 'lock_gallery':
                conf_update_param('gallery_locked', 'true');
                pwg_activity('system', ACTIVITY_SYSTEM_CORE, 'maintenance', ['maintenance_action' => $action]);
                redirect(ServiceLocator::get(UrlGenerator::class)->admin('maintenance'));
                break;
            case 'unlock_gallery':
                conf_update_param('gallery_locked', 'false');
                $_SESSION['page_infos'] = [l10n('Gallery unlocked')];
                pwg_activity('system', ACTIVITY_SYSTEM_CORE, 'maintenance', ['maintenance_action' => $action]);
                redirect(ServiceLocator::get(UrlGenerator::class)->admin('maintenance'));
                break;
            case 'categories':
                ServiceLocator::get(CategoryAdminService::class)->imagesIntegrity();
                ServiceLocator::get(CategoryAdminService::class)->categoriesIntegrity();
                ServiceLocator::get(CategoryAdminService::class)->updateUppercats();
                ServiceLocator::get(CategoryAdminService::class)->updateCategory('all');
                ServiceLocator::get(CategoryAdminService::class)->updateGlobalRank();
                ServiceLocator::get(UserAdminService::class)->invalidateUserCache(true);
                PageState::current()->addInfo(sprintf('%s : %s', l10n('Update albums informations'), l10n('action successfully performed.')));
                break;
            case 'images':
                ServiceLocator::get(CategoryAdminService::class)->imagesIntegrity();
                ServiceLocator::get(CategoryAdminService::class)->updatePath();
                update_rating_score();
                ServiceLocator::get(UserAdminService::class)->invalidateUserCache();
                PageState::current()->addInfo(sprintf('%s : %s', l10n('Update photos information'), l10n('action successfully performed.')));
                break;
            case 'delete_orphan_tags':
                ServiceLocator::get(TagAdminService::class)->deleteOrphanTags();
                PageState::current()->addInfo(sprintf('%s : %s', l10n('Delete orphan tags'), l10n('action successfully performed.')));
                break;
            case 'user_cache':
                ServiceLocator::get(UserAdminService::class)->invalidateUserCache();
                PageState::current()->addInfo(sprintf('%s : %s', l10n('Purge user cache'), l10n('action successfully performed.')));
                break;
            case 'history_detail':
                ServiceLocator::get(HistoryRepository::class)->deleteAll();
                PageState::current()->addInfo(sprintf('%s : %s', l10n('Purge history detail'), l10n('action successfully performed.')));
                break;
            case 'history_summary':
                ServiceLocator::get(HistoryRepository::class)->deleteAllSummary();
                PageState::current()->addInfo(sprintf('%s : %s', l10n('Purge history summary'), l10n('action successfully performed.')));
                break;
            case 'sessions':
                pwg_session_gc();
                $userRepo    = ServiceLocator::get(UserRepository::class);
                $sessionRepo = ServiceLocator::get(SessionRepository::class);
                $sessions     = $userRepo->findAllSessions();
                $all_user_ids = $userRepo->findAllUserIdsAsSet(Config::userFields()['id'], USERS_TABLE);
                $sessions_to_delete = [];
                foreach ($sessions as $session) {
                    if (preg_match('/pwg_uid\|i:(\d+);/', is_scalar($session['data']) ? (string) $session['data'] : '', $matches)) {
                        if (!isset($all_user_ids[$matches[1]])) {
                            $sessions_to_delete[] = is_scalar($session['id']) ? (string) $session['id'] : '';
                        }
                    }
                }
                if (count($sessions_to_delete) > 0) {
                    $sessionRepo->deleteByIds($sessions_to_delete);
                }
                PageState::current()->addInfo(sprintf('%s : %s', l10n('Purge sessions'), l10n('action successfully performed.')));
                break;
            case 'feeds':
                ServiceLocator::get(UserRepository::class)->deleteNeverUsedFeeds();
                PageState::current()->addInfo(sprintf('%s : %s', l10n('Purge never used notification feeds'), l10n('action successfully performed.')));
                break;
            case 'database':
                MaintenanceService::repairAndOptimize();
                break;
            case 'c13y':
                $c13y = new CheckIntegrity();
                $c13y->maintenance();
                PageState::current()->addInfo(sprintf('%s : %s', l10n('Reinitialize check integrity'), l10n('action successfully performed.')));
                break;
            case 'empty_lounge':
                $rows = ServiceLocator::get(CategoryAdminService::class)->emptyLounge();
                PageState::current()->addInfo(sprintf('%d photos were moved from the upload lounge to their albums', count($rows ?? [])));
                break;
            case 'search':
                ServiceLocator::get(SearchRepository::class)->deleteAll();
                break;
            case 'compiled-templates':
                $tpl->deleteCompiledTemplates();
                FileCombiner::clearCombinedFiles();
                PersistentCacheRegistry::current()->purge(true);
                PageState::current()->addInfo(sprintf('%s : %s', l10n('Purge compiled templates'), l10n('action successfully performed.')));
                break;
            case 'derivatives':
                $types_str = is_string($_GET['type'] ?? null) ? $_GET['type'] : '';
                $types     = $types_str === 'all' ? ['all'] : explode('_', $types_str);
                foreach ($types as $type_to_clear) {
                    ServiceLocator::get(ImageAdminService::class)->clearDerivativeCache($type_to_clear);
                }
                ServiceLocator::get(MessageBusInterface::class)->dispatch(new RegenerateAllDerivativesJob($types));
                PageState::current()->addInfo(l10n('action successfully performed.'));
                break;
            case 'check_upgrade':
                if (!ServiceLocator::get(AdminService::class)->fetchRemote(PHPWG_URL . '/download/latest_version', $result)) {
                    PageState::current()->addError(l10n('Unable to check for upgrade.'));
                } else {
                    $versions = ['current' => PHPWG_VERSION];
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
                        PageState::current()->addError(l10n('Check for upgrade failed for unknown reasons.'));
                    } elseif (str_contains((string) ($versions['current'] ?? ''), '%')) {
                        PageState::current()->addInfo(l10n('You are running on development sources, no check possible.'));
                    } elseif (version_compare($versions['current'] ?? '', $versions['latest']) < 0) {
                        PageState::current()->addInfo(l10n('A new version of Piwigo is available.'));
                    } else {
                        PageState::current()->addInfo(l10n('You are running the latest version of Piwigo.'));
                    }
                }
                PageState::current()->addInfo(l10n('action successfully performed.'));
                // fall through to default
                // no break
            default:
                $register_activity = false;
                break;
        }

        if ($register_activity) {
            pwg_activity('system', ACTIVITY_SYSTEM_CORE, 'maintenance', ['maintenance_action' => $action]);
        }

        $tpl->setFilenames(['maintenance' => 'maintenance_actions.tpl']);
        $pwg_token    = get_pwg_token();
        $gallery_locked = Config::galleryLocked();
        $tpl->assign('page_data_json', json_encode([
            'unit_MB'                     => l10n('%s MB'),
            'no_time_elapsed'             => l10n('right now'),
            'pwg_token'                   => $pwg_token,
            'gallery_locked'              => $gallery_locked,
            'str_lock_gallery_tip'        => l10n('A locked gallery is only visible to administrators'),
            'str_lock_gallery_confirm'    => l10n('Are you sure you want to lock the gallery?'),
            'str_unlock_gallery_confirm'  => l10n('Are you sure you want to unlock the gallery?'),
            'str_purge_history_detail'    => l10n('Purge history detail'),
            'str_purge_history_summary'   => l10n('Purge history summary'),
            'str_purge_search_history'    => l10n('Purge search history'),
            'str_delete_all_sizes_confirm' => l10n('Are you sure you want to delete all sizes?'),
        ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));

        $url_format = ServiceLocator::get(UrlGenerator::class)->admin('maintenance') . '&amp;action=%s&amp;pwg_token=' . get_pwg_token();
        if (!PermissionService::get()->isWebmaster()) {
            PageState::current()->addWarning(str_replace('%s', l10n('user_status_webmaster'), l10n('%s status is required to edit parameters.')));
        }

        $purge_urls = [l10n('All') => 'all'];
        foreach (ImageStdParams::getDefinedTypeMap() as $params) {
            $purge_urls[l10n($params->type)] = $params->type;
        }
        $purge_urls[l10n(IMG_CUSTOM)] = IMG_CUSTOM;

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
            'U_HELP'                      => ServiceLocator::get(UrlGenerator::class)->adminPopupHelp('maintenance'),
            'PHPWG_URL'                   => PHPWG_URL,
            'PWG_VERSION'                 => PHPWG_VERSION,
            'U_CHECK_UPGRADE'             => sprintf($url_format, 'check_upgrade'),
            'OS'                          => PHP_OS,
            'PHP_VERSION'                 => phpversion(),
            'DB_ENGINE'                   => 'MySQL',
            'DB_VERSION'                  => $db_version,
            'U_PHPINFO'                   => sprintf($url_format, 'phpinfo'),
            'PHP_DATATIME'                => $php_current_timestamp,
            'DB_DATATIME'                 => $db_current_date,
            'pwg_token'                   => $pwg_token,
            'cache_sizes'                 => Config::has('cache_sizes') ? safe_unserialize((string) Config::cacheSizes()) : null,
            'time_elapsed_since_last_calc' => (function (): ?string {
                if (!Config::has('cache_sizes')) {
                    return null;
                }
                $cs    = safe_unserialize((string) Config::cacheSizes());
                $entry = is_array($cs[3] ?? null) ? $cs[3] : [];
                return time_since(is_scalar($entry['value'] ?? null) ? (string) $entry['value'] : null, 'year');
            })(),
        ]);

        switch (PwgImage::getLibrary()) {
            case 'ext_imagick':
                $library = 'External ImageMagick';
                exec(Config::extImagickDir() . PwgImage::getExtImagickCommand() . ' -version', $returnarray);
                if (preg_match('/Version: ImageMagick (\d+\.\d+\.\d+-?\d*)/', $returnarray[0], $match)) {
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
                $tpl->assign('GRAPHICS_LIBRARY', 'GD ' . ($gd_info['GD Version'] ?? ''));
                break;
        }

        if (Config::galleryLocked()) {
            $tpl->assign(['U_MAINT_UNLOCK_GALLERY' => sprintf($url_format, 'unlock_gallery')]);
        } else {
            $tpl->assign(['U_MAINT_LOCK_GALLERY' => sprintf($url_format, 'lock_gallery')]);
        }

        $nb_lounge = ServiceLocator::get(ImageRepository::class)->countLoungeImages();
        if ($nb_lounge > 0) {
            $tpl->assign(['U_EMPTY_LOUNGE' => sprintf($url_format, 'empty_lounge'), 'LOUNGE_COUNTER' => $nb_lounge]);
        }

        $tpl->assign('isWebmaster', PermissionService::get()->isWebmaster() ? 1 : 0);
        $advanced_features = trigger_change('get_admin_advanced_features_links', []);
        $tpl->assign('advanced_features', $advanced_features);
        $tpl->assignVarFromHandle('ADMIN_CONTENT', 'maintenance');
    }

    // ── maintenance_env ───────────────────────────────────────────────────────

    private function maintenanceEnv(): void
    {
        $tpl = TemplateRegistry::current();

        if (isset($_GET['action'])) {
            check_pwg_token();
        }

        $action = is_scalar($_GET['action'] ?? null) ? (string) $_GET['action'] : '';
        switch ($action) {
            case 'phpinfo':  phpinfo();
                exit();
            case 'lock_gallery':    conf_update_param('gallery_locked', 'true');
                redirect(ServiceLocator::get(UrlGenerator::class)->admin('maintenance'));
                break;
            case 'unlock_gallery':  conf_update_param('gallery_locked', 'false');
                $_SESSION['page_infos'] = [l10n('Gallery unlocked')];
                redirect(ServiceLocator::get(UrlGenerator::class)->admin('maintenance'));
                break;
            case 'categories':      ServiceLocator::get(CategoryAdminService::class)->imagesIntegrity();
                ServiceLocator::get(CategoryAdminService::class)->categoriesIntegrity();
                ServiceLocator::get(CategoryAdminService::class)->updateUppercats();
                ServiceLocator::get(CategoryAdminService::class)->updateCategory('all');
                ServiceLocator::get(CategoryAdminService::class)->updateGlobalRank();
                ServiceLocator::get(UserAdminService::class)->invalidateUserCache(true);
                break;
            case 'images':          ServiceLocator::get(CategoryAdminService::class)->imagesIntegrity();
                ServiceLocator::get(CategoryAdminService::class)->updatePath();
                update_rating_score();
                ServiceLocator::get(UserAdminService::class)->invalidateUserCache();
                break;
            case 'delete_orphan_tags': ServiceLocator::get(TagAdminService::class)->deleteOrphanTags();
                break;
            case 'user_cache':      ServiceLocator::get(UserAdminService::class)->invalidateUserCache();
                break;
            case 'history_detail':  ServiceLocator::get(HistoryRepository::class)->deleteAll();
                break;
            case 'history_summary': ServiceLocator::get(HistoryRepository::class)->deleteAllSummary();
                break;
            case 'sessions':
                pwg_session_gc();
                $userRepo    = ServiceLocator::get(UserRepository::class);
                $sessionRepo = ServiceLocator::get(SessionRepository::class);
                $sessions     = $userRepo->findAllSessions();
                $all_user_ids = $userRepo->findAllUserIdsAsSet(Config::userFields()['id'], USERS_TABLE);
                $sessions_to_delete = [];
                foreach ($sessions as $session) {
                    if (preg_match('/pwg_uid\|i:(\d+);/', is_scalar($session['data']) ? (string) $session['data'] : '', $matches)) {
                        if (!isset($all_user_ids[$matches[1]])) {
                            $sessions_to_delete[] = is_scalar($session['id']) ? (string) $session['id'] : '';
                        }
                    }
                }
                if (count($sessions_to_delete) > 0) {
                    $sessionRepo->deleteByIds($sessions_to_delete);
                }
                break;
            case 'feeds':           ServiceLocator::get(UserRepository::class)->deleteNeverUsedFeeds();
                break;
            case 'database':        MaintenanceService::repairAndOptimize();
                break;
            case 'c13y':            $c13y = new CheckIntegrity();
                $c13y->maintenance();
                break;
            case 'search':          ServiceLocator::get(SearchRepository::class)->deleteAll();
                break;
            case 'compiled-templates':
                $tpl->deleteCompiledTemplates();
                FileCombiner::clearCombinedFiles();
                PersistentCacheRegistry::current()->purge(true);
                break;
            case 'derivatives':
                $dtype = is_string($_GET['type'] ?? null) ? $_GET['type'] : '';
                ServiceLocator::get(ImageAdminService::class)->clearDerivativeCache($dtype);
                break;
            case 'check_upgrade':
                if (!ServiceLocator::get(AdminService::class)->fetchRemote(PHPWG_URL . '/download/latest_version', $result)) {
                    PageState::current()->addError(l10n('Unable to check for upgrade.'));
                } else {
                    $versions = ['current' => PHPWG_VERSION];
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
                        PageState::current()->addError(l10n('Check for upgrade failed for unknown reasons.'));
                    } elseif (str_contains((string) ($versions['current'] ?? ''), '%')) {
                        PageState::current()->addInfo(l10n('You are running on development sources, no check possible.'));
                    } elseif (version_compare($versions['current'] ?? '', $versions['latest']) < 0) {
                        PageState::current()->addInfo(l10n('A new version of Piwigo is available.'));
                        $update_url = ServiceLocator::get(UrlGenerator::class)->admin('updates');
                        PageState::current()->addInfo('<a href="' . $update_url . '">' . l10n('Update to Piwigo %s', $versions['latest']) . '</a>');
                    } else {
                        PageState::current()->addInfo(l10n('You are running the latest version of Piwigo.'));
                    }
                }
                break;
        }

        $tpl->setFilenames(['maintenance' => 'maintenance_env.tpl']);
        $tpl->assign('page_data_json', json_encode(['unit_MB' => l10n('%s MB'), 'no_time_elapsed' => l10n('right now'), 'no_active_plugin' => l10n('No plugin activated'), 'error_occured' => l10n('an error happened')], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));

        $url_format = ServiceLocator::get(UrlGenerator::class)->admin('maintenance') . '&amp;action=%s&amp;pwg_token=' . get_pwg_token();

        $purge_urls = [];
        $purge_urls[l10n('All')] = sprintf($url_format, 'derivatives') . '&amp;type=all';
        foreach (ImageStdParams::getDefinedTypeMap() as $params) {
            $purge_urls[l10n($params->type)] = sprintf($url_format, 'derivatives') . '&amp;type=' . $params->type;
        }
        $purge_urls[l10n(IMG_CUSTOM)] = sprintf($url_format, 'derivatives') . '&amp;type=' . IMG_CUSTOM;

        $php_current_timestamp = date('Y-m-d H:i:s');
        $db_version            = DbInfo::version();
        $db_current_date       = new \DateTimeImmutable()->format('Y-m-d H:i:s');
        [$container_name, $container_version] = get_container_info();
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
            'U_HELP'                      => ServiceLocator::get(UrlGenerator::class)->adminPopupHelp('maintenance'),
            'PHPWG_URL'                   => PHPWG_URL,
            'PWG_VERSION'                 => PHPWG_VERSION,
            'U_CHECK_UPGRADE'             => sprintf($url_format, 'check_upgrade'),
            'OS'                          => PHP_OS,
            'CONTAINER_INFO'              => $container_name . (!empty($container_version) ? ' ' . $container_version : ''),
            'PHP_VERSION'                 => phpversion(),
            'DB_ENGINE'                   => 'MySQL',
            'DB_VERSION'                  => $db_version,
            'U_PHPINFO'                   => sprintf($url_format, 'phpinfo'),
            'PHP_DATATIME'                => $php_current_timestamp,
            'DB_DATATIME'                 => $db_current_date,
            'cache_sizes'                 => Config::has('cache_sizes') ? safe_unserialize((string) Config::cacheSizes()) : null,
            'time_elapsed_since_last_calc' => (function (): ?string {
                if (!Config::has('cache_sizes')) {
                    return null;
                }
                $cs    = safe_unserialize((string) Config::cacheSizes());
                $entry = is_array($cs[3] ?? null) ? $cs[3] : [];
                return time_since(is_scalar($entry['value'] ?? null) ? (string) $entry['value'] : null, 'year');
            })(),
        ]);

        $graphics_library = ServiceLocator::get(AdminService::class)->getGraphicsLibraryLabel();
        if (!empty($graphics_library)) {
            $tpl->assign('GRAPHICS_LIBRARY', $graphics_library);
        }

        if (Config::galleryLocked()) {
            $tpl->assign(['U_MAINT_UNLOCK_GALLERY' => sprintf($url_format, 'unlock_gallery')]);
        } else {
            $tpl->assign(['U_MAINT_LOCK_GALLERY' => sprintf($url_format, 'lock_gallery')]);
        }

        $installed_on = ServiceLocator::get(AdminService::class)->getInstallationDate();
        if (!empty($installed_on)) {
            $tpl->assign(['INSTALLED_ON' => format_date($installed_on, ['day', 'month', 'year']), 'INSTALLED_SINCE' => time_since($installed_on, 'day')]);
        }

        $advanced_features = trigger_change('get_admin_advanced_features_links', []);
        $tpl->assign('advanced_features', $advanced_features);
        $tpl->assignVarFromHandle('ADMIN_CONTENT', 'maintenance');
    }

    // ── maintenance_sys ───────────────────────────────────────────────────────

    private function maintenanceSys(): void
    {
        $tpl = TemplateRegistry::current();

        if (empty($this->maintActions)) {
            /** @var array<string, array<string, string>> $maint_actions_g */
            $maint_actions_g = is_array($GLOBALS['maint_actions'] ?? null) ? $GLOBALS['maint_actions'] : [];
            $this->maintActions = $maint_actions_g;
        }
        $maint_actions = $this->maintActions;

        if (PermissionService::get()->isWebmaster()) {
            if (isset($_GET['method']) && 'pwg.activity_sys.getList' == $_GET['method']) {
                $response = [];
                $data     = [];
                $usernameField = Config::userFields()['username'];
                $idField       = Config::userFields()['id'];
                $activityRows  = ServiceLocator::get(Connection::class)
                    ->executeQuery("SELECT activity_id, object, object_id, action, performed_by, occured_on, details, IF(performed_by = 0, 'System', $usernameField) AS username FROM " . ACTIVITY_TABLE . ' LEFT JOIN ' . USERS_TABLE . " ON performed_by = $idField WHERE object = 'system' ORDER BY activity_id DESC")
                    ->fetchAllAssociative();

                foreach ($activityRows as $rows) {
                    $major_infos = false;
                    $object = $object_icon = $action_icon = $action_color = '';
                    $action = $rows['action'];
                    $date = $hour = '';
                    $detailsRaw = unserialize(is_scalar($rows['details']) ? (string) $rows['details'] : '');
                    $details    = is_array($detailsRaw) ? $detailsRaw : [];
                    $detail     = ['type' => 'empty'];

                    switch ($rows['object_id']) {
                        case ACTIVITY_SYSTEM_CORE:
                            $object_icon = 'icon-piwigo';
                            $object = l10n('Core');
                            switch ($rows['action']) {
                                case 'install':   $action_icon = 'icon-download';
                                    $action_color = 'icon-green';
                                    $action = l10n('Install');
                                    break;
                                case 'config':
                                    $action_icon = 'icon-cog-alt';
                                    $action_color = 'icon-yellow';
                                    $action = l10n('Configuration');
                                    if (isset($details['config_section'])) {
                                        $c_icon = $c_text = '';
                                        switch ($details['config_section']) {
                                            case 'main':      $c_icon = 'icon-cog';
                                                $c_text = l10n('General');
                                                break;
                                            case 'watermark': $c_icon = 'icon-file-image';
                                                $c_text = l10n('Watermark');
                                                break;
                                            case 'sizes':
                                                $c_icon = 'icon-zoom-square';
                                                $c_text = l10n('Photo sizes');
                                                if (isset($details['config_action']) && 'restore_settings' == $details['config_action']) {
                                                    $detail[] = ['icon' => 'icon-back-in-time', 'text' => l10n('Set as default')];
                                                }
                                                break;
                                            case 'comments':  $c_icon = 'icon-chat';
                                                $c_text = l10n('Comments');
                                                break;
                                            case 'display':   $c_icon = 'icon-television';
                                                $c_text = l10n('Display');
                                                break;
                                            default:          $c_icon = 'icon-cog-alt';
                                                $c_text = is_scalar($details['config_section']) ? (string) $details['config_section'] : '';
                                                break;
                                        }
                                        $detail['type'] = 'config_section';
                                        $detail[]       = ['icon' => $c_icon, 'text' => $c_text];
                                    }
                                    break;
                                case 'maintenance':
                                    $action_icon = 'icon-cone';
                                    $action_color = 'icon-yellow';
                                    $action = l10n('Maintenance');
                                    if (isset($details['maintenance_action'])) {
                                        $action_detail = is_scalar($details['maintenance_action']) ? (string) $details['maintenance_action'] : '';
                                        $detail = ['type' => 'maintenance_action', 'icon' => $maint_actions[$action_detail]['icon'] ?? 'icon-cone', 'text' => $maint_actions[$action_detail]['label'] ?? $action_detail];
                                    }
                                    break;
                                case 'update':     $action_icon = 'icon-arrows-cw';
                                    $action_color = 'icon-blue';
                                    $action = l10n('Update');
                                    $major_infos = true;
                                    break;
                                case 'autoupdate': $action_icon = 'icon-arrows-cw';
                                    $action_color = 'icon-blue';
                                    $action = l10n('Auto-update');
                                    $major_infos = true;
                                    break;
                                default:           $action_icon = 'icon-download';
                                    $action_color = 'icon-yellow';
                                    break;
                            }
                            break;

                        case ACTIVITY_SYSTEM_PLUGIN:
                            $object_icon = 'icon-puzzle';
                            $object = 'plugin';
                            if (isset($details['plugin_id'])) {
                                $object = str_replace(['_', '-'], ' ', is_scalar($details['plugin_id']) ? (string) $details['plugin_id'] : '');
                            }
                            switch ($rows['action']) {
                                case 'install':    $action_icon = 'icon-download';
                                    $action_color = 'icon-green';
                                    $action = l10n('Install');
                                    break;
                                case 'update':     $action_icon = 'icon-arrows-cw';
                                    $action_color = 'icon-blue';
                                    $action = l10n('Update');
                                    break;
                                case 'activate':   $action_icon = 'icon-check';
                                    $action_color = 'icon-green';
                                    $action = l10n('Activate');
                                    break;
                                case 'deactivate': $action_icon = 'icon-block';
                                    $action_color = 'icon-purple';
                                    $action = l10n('Deactivate');
                                    break;
                                case 'uninstall':  $action_icon = 'icon-trash-1';
                                    $action_color = 'icon-red';
                                    $action = l10n('Uninstall');
                                    break;
                                case 'restore':    $action_icon = 'icon-back-in-time';
                                    $action_color = 'icon-blue';
                                    $action = l10n('Restore');
                                    break;
                                case 'delete':
                                    $action_icon = 'icon-trash-1';
                                    $action_color = 'icon-red';
                                    $action = l10n('Delete');
                                    if (isset($details['db_version'])) {
                                        $detail['type'] = 'db_fs_version';
                                        $detail[] = ['icon' => 'icon-flow-branch', 'text' => 'database : ' . (is_scalar($details['db_version']) ? (string) $details['db_version'] : '')];
                                    }
                                    if (isset($details['fs_version'])) {
                                        $detail['type'] = 'db_fs_version';
                                        $detail[] = ['icon' => 'icon-flow-branch', 'text' => 'filesystem : ' . (is_scalar($details['fs_version']) ? (string) $details['fs_version'] : '')];
                                    }
                                    break;
                                case 'autoupdate': $action_icon = 'icon-arrows-cw';
                                    $action_color = 'icon-blue';
                                    $action = l10n('Auto-update');
                                    break;
                                default:           $action_icon = 'icon-puzzle';
                                    $action_color = 'icon-yellow';
                                    break;
                            }
                            break;

                        case ACTIVITY_SYSTEM_THEME:
                            $object_icon = 'icon-brush';
                            $object = 'theme';
                            if (isset($details['theme_id'])) {
                                $object = str_replace(['_', '-'], ' ', is_scalar($details['theme_id']) ? (string) $details['theme_id'] : '');
                            }
                            switch ($rows['action']) {
                                case 'install':    $action_icon = 'icon-download';
                                    $action_color = 'icon-green';
                                    $action = l10n('Install');
                                    break;
                                case 'activate':   $action_icon = 'icon-check';
                                    $action_color = 'icon-green';
                                    $action = l10n('Activate');
                                    break;
                                case 'deactivate': $action_icon = 'icon-block';
                                    $action_color = 'icon-purple';
                                    $action = l10n('Deactivate');
                                    break;
                                case 'delete':     $action_icon = 'icon-trash-1';
                                    $action_color = 'icon-red';
                                    $action = l10n('Delete');
                                    break;
                                case 'set_default': $action_icon = 'icon-star';
                                    $action_color = 'icon-yellow';
                                    $action = l10n('Set as default');
                                    break;
                                case 'update':     $action_icon = 'icon-arrows-cw';
                                    $action_color = 'icon-blue';
                                    $action = l10n('Update');
                                    break;
                                default:           $action_icon = 'icon-brush';
                                    $action_color = 'icon-yellow';
                                    break;
                            }
                            break;
                    }

                    if (isset($details['from_version'])) {
                        $detail = ['type' => 'from_to', ['icon' => 'icon-flow-branch', 'text' => is_scalar($details['from_version']) ? (string) $details['from_version'] : ''], ['icon' => isset($details['to_version']) ? 'icon-flow-branch' : 'icon-block', 'text' => is_scalar($details['to_version'] ?? null) ? (string) $details['to_version'] : (is_scalar($details['result'] ?? null) ? (string) $details['result'] : '')]];
                    } elseif (isset($details['version'])) {
                        $detail = ['type' => 'version', 'icon' => 'icon-flow-branch', 'text' => is_scalar($details['version']) ? (string) $details['version'] : ''];
                    } elseif (isset($details['result'])) {
                        $detail = ['type' => 'error', 'icon' => 'icon-block', 'text' => is_scalar($details['result']) ? (string) $details['result'] : ''];
                    }

                    [$date, $hour] = explode(' ', is_scalar($rows['occured_on']) ? (string) $rows['occured_on'] : '');
                    $data[] = ['major_infos' => $major_infos, 'id' => $rows['activity_id'], 'object_icon' => $object_icon, 'object' => ucwords($object), 'action_icon' => $action_icon, 'action_color' => $action_color, 'action' => $action, 'user_id' => $rows['performed_by'], 'username' => $rows['username'], 'date' => format_date($date), 'hour' => $hour, 'detail' => $detail];
                }

                $response = ['data' => $data];
                echo json_encode($response);
                exit;
            }
        } else {
            PageState::current()->addWarning(str_replace('%s', l10n('user_status_webmaster'), l10n('%s status is required to edit parameters.')));
        }

        $tpl->assign('isWebmaster', PermissionService::get()->isWebmaster() ? 1 : 0);
        $tpl->setFilenames(['maintenance' => 'maintenance_sys.tpl']);
        $tpl->assignVarFromHandle('ADMIN_CONTENT', 'maintenance');
    }

    // ── history ───────────────────────────────────────────────────────────────

    private function history(): void
    {
        $tpl = TemplateRegistry::current();
        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];

        $types = array_merge(['none'], SchemaHelper::getEnums(HISTORY_TABLE, 'image_type'));

        $display_thumbnails = [
            'no_display_thumbnail'    => l10n('No display'),
            'display_thumbnail_classic' => l10n('Classic display'),
            'display_thumbnail_hoverbox' => l10n('Hoverbox display'),
        ];

        check_input_parameter('filter_ip', $_GET, false, '/^[0-9.]+$/');
        check_input_parameter('filter_image_id', $_GET, false, '/^\d+$/');
        check_input_parameter('filter_user_id', $_GET, false, '/^\d+$/');

        $tpl->setFilename('history', 'history.tpl');
        ServiceLocator::get(HistoryAdminService::class)->historyTabsheet();
        $tpl->assign(['F_ACTION' => ServiceLocator::get(UrlGenerator::class)->admin('history'), 'API_METHOD' => ServiceLocator::get(UrlGenerator::class)->ws(['format' => 'json', 'method' => 'pwg.history.search'])]);

        if (isset($page['search_id'])) {
            $navbar = create_navigation_bar(ServiceLocator::get(UrlGenerator::class)->admin() . get_query_string_diff(['start']), is_int($page['nb_lines']) ? $page['nb_lines'] : 0, is_int($page['start']) ? $page['start'] : 0, Config::nbLogsPage());
            $tpl->assign('navbar', $navbar);
        }

        $form = ['ip' => null, 'image_id' => null, 'filename' => null, 'start' => '', 'end' => '', 'types' => [], 'display_thumbnail' => ''];
        if (isset($page['search'])) {
            $searchArr    = is_array($page['search']) ? $page['search'] : [];
            $searchFields = is_array($searchArr['fields'] ?? null) ? $searchArr['fields'] : [];
            if (isset($searchFields['date-after'])) {
                $form['start'] = $searchFields['date-after'];
            }
            if (isset($searchFields['date-before'])) {
                $form['end']   = $searchFields['date-before'];
            }
        } else {
            $form['start'] = $form['end'] = date('Y-m-d');
            $form['types'] = $types;
            $form['display_thumbnail'] = ServiceLocator::get(CookieService::class)->getCookieVar('display_thumbnail', 'no_display_thumbnail');
        }

        $form_param = [];
        $form_param['ip']       = is_scalar($_GET['filter_ip'] ?? null) ? (string) $_GET['filter_ip'] : $form['ip'];
        $form_param['image_id'] = is_scalar($_GET['filter_image_id'] ?? null) ? (string) $_GET['filter_image_id'] : $form['image_id'];
        $form_param['user_id']  = is_scalar($_GET['filter_user_id'] ?? null) ? (string) $_GET['filter_user_id'] : '-1';

        if (isset($_GET['filter_ip']) || isset($_GET['filter_image_id']) || isset($_GET['filter_user_id'])) {
            $form['start'] = '';
        }

        if ($form_param['user_id'] != '-1') {
            $userFields = Config::userFields();
            $foundUsername = ServiceLocator::get(UserRepository::class)->findUsernameById($userFields['id'], $userFields['username'], USERS_TABLE, (int) $form_param['user_id']);
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
            'START'     => $form['start'] ?? null,
            'END'       => $form['end'] ?? null,
        ]);

        $tpl->assign('display_thumbnails', $display_thumbnails);
        $tpl->assign('display_thumbnail_selected', $form['display_thumbnail']);
        $tpl->assign('guest_id', Config::guestId());
        $tpl->assign('ADMIN_PAGE_TITLE', l10n('History'));
        $tpl->assign('page_data_json', json_encode([
            'API_METHOD'                  => ServiceLocator::get(UrlGenerator::class)->ws(['format' => 'json', 'method' => 'pwg.history.search']),
            'filter_user_name'            => $form_param['user_name'] ?? null,
            'guest_id'                    => Config::guestId(),
            'today'                       => date('Y-m-d'),
            'initial_user_id'             => $form_param['user_id'],
            'initial_image_id'            => $form_param['image_id'] ?? '',
            'initial_ip'                  => $form_param['ip'] ?? '',
            'new_user_item'               => null,
            'str_dwld'                    => l10n('Downloaded'),
            'str_most_visited'            => l10n('Most visited'),
            'str_best_rated'              => l10n('Best rated'),
            'str_list'                    => l10n('Random photo'),
            'str_favorites'               => l10n('Your favorites'),
            'str_recent_cats'             => l10n('Recent albums'),
            'str_recent_pics'             => l10n('Recent photos'),
            'str_memories'                => l10n('Memories'),
            'str_no_longer_exist_photo'   => l10n('This photo no longer exists'),
            'str_guest'                   => l10n('guest'),
            'str_contact_form'            => l10n('Contact Form'),
            'str_edit_img'                => l10n('Edit photo'),
            'unit_MB'                     => l10n('%s MB'),
            'str_and_more'                => l10n('and %d more'),
            'str_search_details'          => ['allwords' => l10n('Search for words'), 'date_posted' => l10n('Post date'), 'tags' => l10n('Tags'), 'cat' => l10n('Album'), 'author' => l10n('Author'), 'added_by' => l10n('Added by'), 'filetypes' => l10n('File type')],
        ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));

        $tpl->assignVarFromHandle('ADMIN_CONTENT', 'history');
    }

    // ── stats ─────────────────────────────────────────────────────────────────

    private function stats(): void
    {
        $tpl = TemplateRegistry::current();
        /** @var array<string, mixed> $user */
        $user = $GLOBALS['user'];

        ServiceLocator::get(HistoryAdminService::class)->historySummarize();

        $tpl->setFilename('stats', 'stats.tpl');
        ServiceLocator::get(HistoryAdminService::class)->historyTabsheet();
        $tpl->assign(['U_HELP' => ServiceLocator::get(UrlGenerator::class)->adminPopupHelp('history'), 'F_ACTION' => ServiceLocator::get(UrlGenerator::class)->admin('history')]);

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

        /** @var array<string, mixed> $lang */
        $lang     = is_array($GLOBALS['lang']) ? $GLOBALS['lang'] : [];
        $langMonth = is_array($lang['month'] ?? null) ? $lang['month'] : [];
        ksort($langMonth);

        $tpl->assign([
            'compareYears' => $this->getMonthOfLastYears(Config::statCompareYearDisplayed()),
            'monthStats'   => $this->getMonthStats(),
            'lastHours'    => $last_hours,
            'lastDays'     => $last_days,
            'lastMonths'   => $last_months,
            'lastYears'    => $last_years,
            'ADMIN_PAGE_TITLE' => l10n('History'),
            'page_data_json' => json_encode([
                'str_avg'                => l10n('Average last 12 months'),
                'str_number_page_visited' => l10n('Page Visited'),
                'str_months'             => array_values($langMonth),
                'lang_code'              => is_scalar($user['language'] ?? null) ? (string) $user['language'] : '',
            ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE),
        ]);

        $tpl->assignVarFromHandle('ADMIN_CONTENT', 'stats');
    }

    // ── site_manager ──────────────────────────────────────────────────────────

    private function siteManager(): void
    {
        $tpl = TemplateRegistry::current();
        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];

        if (!Config::enableSynchronization()) {
            throw new ConfigException('synchronization is disabled');
        }

        if (!empty($_POST) || isset($_GET['action'])) {
            check_pwg_token();
        }

        $tpl->setFilenames(['site_manager' => 'site_manager.tpl']);

        $GLOBALS['my_base_url'] = $my_base_url = ServiceLocator::get(UrlGenerator::class)->admin() . '&page=';

        $tabsheet = new Tabsheet();
        $tabsheet->setId('site_update');
        $tabsheet->select('site_maager');
        $tabsheet->assign();

        if (isset($_POST['submit']) && !empty($_POST['galleries_url'])) {
            $galleries_url = is_scalar($_POST['galleries_url']) ? (string) $_POST['galleries_url'] : '';
            $is_remote = url_is_remote($galleries_url);
            if ($is_remote) {
                fatal_error('remote sites not supported');
            }
            $url = preg_replace('/[\/]*$/', '', $galleries_url) . '/';
            if (!str_starts_with($url, '.')) {
                $url = './' . $url;
            }

            $siteRepo = ServiceLocator::get(SiteRepository::class);
            if ($siteRepo->countByUrl($url) > 0) {
                PageState::current()->addError(l10n('This site already exists') . ' [' . $url . ']');
            }

            $pageErrors = is_array($page['errors'] ?? null) ? $page['errors'] : [];
            if (count($pageErrors) == 0) {
                if (!file_exists($url)) {
                    PageState::current()->addError(l10n('Directory does not exist') . ' [' . $url . ']');
                }
            }
            $pageErrors2 = is_array($page['errors'] ?? null) ? $page['errors'] : [];
            if (count($pageErrors2) == 0) {
                $siteRepo->insert($url);
                PageState::current()->addInfo($url . ' ' . l10n('created'));
            }
        }

        $siteIdSm = null;
        if (isset($_GET['site']) && is_numeric($_GET['site'])) {
            $page['site'] = $_GET['site'];
            $siteIdSm = (int) $_GET['site'];
        }
        if (isset($_GET['action']) && $siteIdSm !== null) {
            $galleries_url = ServiceLocator::get(SiteRepository::class)->findGalleriesUrlById($siteIdSm);
            switch ($_GET['action']) {
                case 'delete': ServiceLocator::get(CategoryAdminService::class)->deleteSite($page['site']);
                    PageState::current()->addInfo($galleries_url . ' ' . l10n('deleted'));
                    break;
            }
        }

        $tpl->assign([
            'F_ACTION'   => ServiceLocator::get(UrlGenerator::class)->admin() . get_query_string_diff(['action', 'site', 'pwg_token']),
            'PWG_TOKEN'  => get_pwg_token(),
            'ADMIN_PAGE_TITLE' => l10n('Synchronize'),
            'page_data_json' => json_encode(['str_delete_site_confirm' => l10n('Are you sure you want to delete this site?')], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE),
        ]);

        $sites_detail = array_column(DbConnection::get()->executeQuery('SELECT c.site_id, COUNT(DISTINCT c.id) AS nb_categories, COUNT(i.id) AS nb_images FROM ' . CATEGORIES_TABLE . ' AS c LEFT JOIN ' . IMAGES_TABLE . ' AS i ON c.id=i.storage_category_id WHERE c.site_id IS NOT NULL GROUP BY c.site_id;')->fetchAllAssociative(), null, 'site_id');

        foreach (ServiceLocator::get(SiteRepository::class)->findAll() as $row) {
            $row_id_str = is_scalar($row['id']) ? (string) $row['id'] : '';
            $is_remote  = url_is_remote(is_scalar($row['galleries_url']) ? (string) $row['galleries_url'] : '');
            $base_url   = ServiceLocator::get(UrlGenerator::class)->admin('site_manager') . '&amp;site=' . $row_id_str . '&amp;pwg_token=' . get_pwg_token() . '&amp;action=';
            $update_url = ServiceLocator::get(UrlGenerator::class)->admin('site_update') . '&amp;site=' . $row_id_str;
            $site_id    = is_numeric($row['id']) ? (int) $row['id'] : 0;

            $tpl_var = [
                'NAME'       => $row['galleries_url'],
                'TYPE'       => l10n($is_remote ? 'Remote' : 'Local'),
                'CATEGORIES' => is_numeric($sites_detail[(string) $site_id]['nb_categories'] ?? null) ? (int) $sites_detail[(string) $site_id]['nb_categories'] : 0,
                'IMAGES'     => is_numeric($sites_detail[(string) $site_id]['nb_images'] ?? null) ? (int) $sites_detail[(string) $site_id]['nb_images'] : 0,
                'U_SYNCHRONIZE' => $update_url,
            ];

            if ($row['id'] != 1) {
                $tpl_var['U_DELETE'] = $base_url . 'delete';
            }
            $tpl_var['plugin_links'] = trigger_change('get_admins_site_links', [], $row['id'], $is_remote);
            $tpl->append('sites', $tpl_var);
        }

        $tpl->assignVarFromHandle('ADMIN_CONTENT', 'site_manager');
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
        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];
        /** @var array<string, mixed> $user */
        $user = $GLOBALS['user'];

        if (!Config::enableSynchronization()) {
            throw new ConfigException('synchronization is disabled');
        }

        if (!is_numeric($_GET['site'])) {
            throw new ValidationException('site param missing or invalid');
        }
        $site_id = $_GET['site'];

        $site_url = ServiceLocator::get(SiteRepository::class)->findGalleriesUrlById((int) $site_id);
        if (!isset($site_url)) {
            throw new NotFoundException('site ' . $site_id . ' does not exist');
        }
        $site_url_str  = (string) $site_url;
        $site_is_remote = url_is_remote($site_url_str);

        defined('CURRENT_DATE') or define('CURRENT_DATE', new \DateTimeImmutable()->format('Y-m-d H:i:s'));

        $error_labels = [
            'PWG-UPDATE-1'    => [l10n('wrong filename'), l10n('The name of directories and files must be composed of letters, numbers, "-", "_" or "."')],
            'PWG-ERROR-NO-FS' => [l10n('File/directory read error'), l10n('The file or directory cannot be accessed (either it does not exist or the access is denied)')],
        ];
        $errors = [];
        $infos  = [];

        if ($site_is_remote) {
            fatal_error('remote sites not supported');
        } else {
            $site_reader = new LocalSiteReader($site_url_str);
        }

        if (isset($page['no_md5sum_number'])) {
            $tpl->assign(['save_error' => '<a href="' . ServiceLocator::get(UrlGenerator::class)->admin('batch_manager') . '&amp;filter=prefilter-no_sync_md5sum">' . l10n('Some checksums are missing.') . '<i class="icon-right"></i></a>']);
        }

        $GLOBALS['my_base_url'] = $my_base_url = ServiceLocator::get(UrlGenerator::class)->admin() . '&page=';

        $tabsheet = new Tabsheet();
        $tabsheet->setId('site_update');
        $tabsheet->select('synchronization');
        $tabsheet->assign();

        if (isset($_GET['quick_sync'])) {
            check_pwg_token();
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
            $start = get_moment();
            $query = 'SELECT id, uppercats, global_rank, status, visible FROM ' . CATEGORIES_TABLE . ' WHERE dir IS NOT NULL AND site_id = ' . $site_id;
            if (isset($_POST['cat']) && is_numeric($_POST['cat'])) {
                if (isset($_POST['subcats-included']) && $_POST['subcats-included'] == 1) {
                    $query .= ' AND uppercats ' . Dml::REGEX_OPERATOR . " '(^|,)" . $_POST['cat'] . "(,|$)'";
                } else {
                    $query .= ' AND id = ' . $_POST['cat'];
                }
            }
            $db_categories = array_column(DbConnection::get()->executeQuery($query)->fetchAllAssociative(), null, 'id');
            $db_fulldirs   = ServiceLocator::get(CategoryAdminService::class)->getFulldirs(array_map(intval(...), array_keys($db_categories)));

            if (isset($_POST['cat']) && is_numeric($_POST['cat'])) {
                $basedir = (string) ($db_fulldirs[(int) $_POST['cat']] ?? '');
            } else {
                $basedir = (string) preg_replace('#/*$#', '', $site_url_str);
            }

            $db_fulldirs = array_flip($db_fulldirs);
            $next_rank   = ['NULL' => 1];
            $conn        = ServiceLocator::get(Connection::class);
            foreach ($conn->executeQuery('SELECT id FROM ' . CATEGORIES_TABLE)->fetchAllAssociative() as $row) {
                $rowIdKey = is_scalar($row['id']) ? (string) $row['id'] : '';
                if ($rowIdKey !== '') {
                    $next_rank[$rowIdKey] = 1;
                }
            }
            foreach ($conn->executeQuery('SELECT id_uppercat, MAX(`rank`)+1 AS next_rank FROM ' . CATEGORIES_TABLE . ' GROUP BY id_uppercat')->fetchAllAssociative() as $row) {
                if (!isset($row['id_uppercat']) || $row['id_uppercat'] == '') {
                    $row['id_uppercat'] = 'NULL';
                }
                $ruk = is_scalar($row['id_uppercat']) ? (string) $row['id_uppercat'] : 'NULL';
                $next_rank[$ruk] = $row['next_rank'];
            }

            $next_id_raw = DbConnection::get()->executeQuery('SELECT IF(MAX(id)+1 IS NULL, 1, MAX(id)+1) FROM `' . CATEGORIES_TABLE . '`')->fetchOne();
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
                $dir = basename((string) $fulldir);
                if (preg_match(Config::syncCharsRegex(), $dir)) {
                    $insert = ['id' => $next_id++, 'dir' => $dir, 'name' => str_replace('_', ' ', $dir), 'site_id' => $site_id, 'commentable' => BoolUtil::toString(Config::newcatDefaultCommentable()), 'status' => Config::newcatDefaultStatus(), 'visible' => BoolUtil::toString(Config::newcatDefaultVisible())];
                    if (isset($db_fulldirs[dirname((string) $fulldir)])) {
                        $parent    = $db_fulldirs[dirname((string) $fulldir)];
                        $parentKey = (string) $parent;
                        $parentRow = is_array($db_categories[$parent] ?? null) ? $db_categories[$parent] : [];
                        $insert['id_uppercat'] = $parent;
                        $insert['uppercats']   = (is_scalar($parentRow['uppercats'] ?? null) ? (string) $parentRow['uppercats'] : '') . ',' . $insert['id'];
                        $nextRankParent        = is_int($next_rank[$parentKey] ?? null) ? $next_rank[$parentKey] : 0;
                        $insert['rank']        = $nextRankParent;
                        $next_rank[$parentKey] = $nextRankParent + 1;
                        $insert['global_rank'] = (is_scalar($parentRow['global_rank'] ?? null) ? (string) $parentRow['global_rank'] : '') . '.' . $insert['rank'];
                        if ('private' == ($parentRow['status'] ?? '')) {
                            $insert['status'] = 'private';
                        }
                        if ('false' == ($parentRow['visible'] ?? '')) {
                            $insert['visible'] = 'false';
                        }
                    } else {
                        $insert['uppercats']   = $insert['id'];
                        $nextRankNull          = is_int($next_rank['NULL'] ?? null) ? $next_rank['NULL'] : 0;
                        $insert['rank']        = $nextRankNull;
                        $next_rank['NULL']     = $nextRankNull + 1;
                        $insert['global_rank'] = $insert['rank'];
                    }
                    $inserts[] = $insert;
                    $infos[]   = ['path' => $fulldir, 'info' => l10n('added')];
                    $db_categories[$insert['id']] = ['id' => $insert['id'], 'parent' => $parent ?? null, 'status' => $insert['status'], 'visible' => $insert['visible'], 'uppercats' => $insert['uppercats'], 'global_rank' => $insert['global_rank']];
                    $db_fulldirs[$fulldir] = $insert['id'];
                    $next_rank[$insert['id']] = 1;
                } else {
                    $errors[] = ['path' => $fulldir, 'type' => 'PWG-UPDATE-1'];
                }
            }

            if (count($inserts) > 0 && !$simulate) {
                Dml::massInserts(CATEGORIES_TABLE, ['id', 'dir', 'name', 'site_id', 'id_uppercat', 'uppercats', 'commentable', 'visible', 'status', 'rank', 'global_rank'], $inserts);
                $category_ids = $category_up = [];
                foreach ($inserts as $category) {
                    $category_ids[] = $category['id'];
                    if (!empty($category['id_uppercat'])) {
                        $category_up[] = $category['id_uppercat'];
                    }
                }
                pwg_activity('album', $category_ids, 'add', ['sync' => true]);
                $category_up_str = implode(',', array_unique($category_up));
                if (Config::inheritanceByDefault() && !empty($category_up_str)) {
                    $permRepoSync    = ServiceLocator::get(PermissionRepository::class);
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
                    Dml::massInserts(GROUP_ACCESS_TABLE, ['group_id', 'cat_id'], $insert_granted_grps);
                    Dml::massInserts(USER_ACCESS_TABLE, ['user_id', 'cat_id'], array_unique($insert_granted_users, SORT_REGULAR));
                } else {
                    ServiceLocator::get(CategoryAdminService::class)->addPermissionOnCategory($category_ids, ServiceLocator::get(UserAdminService::class)->getAdmins());
                }
            }
            $counts['new_categories'] = count($inserts);

            $to_delete = $to_delete_derivative_dirs = [];
            foreach (array_diff(array_keys($db_fulldirs), $fs_fulldirs) as $fulldir) {
                $to_delete[] = $db_fulldirs[$fulldir];
                unset($db_fulldirs[$fulldir]);
                $infos[] = ['path' => $fulldir, 'info' => l10n('deleted')];
                if (substr_compare((string) $fulldir, '../', 0, 3) == 0) {
                    $fulldir = substr((string) $fulldir, 3);
                }
                $to_delete_derivative_dirs[] = PHPWG_ROOT_PATH . PWG_DERIVATIVE_DIR . $fulldir;
            }
            if (count($to_delete) > 0) {
                if (!$simulate) {
                    ServiceLocator::get(CategoryAdminService::class)->deleteCategories($to_delete);
                    foreach ($to_delete_derivative_dirs as $to_delete_dir) {
                        if (is_dir($to_delete_dir)) {
                            ServiceLocator::get(ImageAdminService::class)->clearDerivativeCacheRec($to_delete_dir, '#.+#');
                        }
                    }
                }
                $counts['del_categories'] = count($to_delete);
            }
            $tpl->append('footer_elements', '<!-- scanning dirs : ' . get_elapsed_time($start, get_moment()) . ' -->');
        }

        // ── files / elements ──────────────────────────────────────────────────

        if (isset($_POST['submit']) && $_POST['sync'] == 'files' && !$general_failure) {
            $start_files = $start = get_moment();
            $fs = $site_reader->getElements($basedir);
            $tpl->append('footer_elements', '<!-- get_elements: ' . get_elapsed_time($start, get_moment()) . ' -->');

            $cat_ids    = array_diff(array_keys($db_categories), $to_delete);
            $db_elements = [];
            if (count($cat_ids) > 0) {
                $query       = 'SELECT id, path FROM ' . IMAGES_TABLE . ' WHERE storage_category_id IN (' . wordwrap(implode(', ', $cat_ids), 160, "\n") . ')';
                $db_elements = array_column(DbConnection::get()->executeQuery($query)->fetchAllAssociative(), 'path', 'id');
            }

            $next_element_id_raw = DbConnection::get()->executeQuery('SELECT IF(MAX(id)+1 IS NULL, 1, MAX(id)+1) FROM `' . IMAGES_TABLE . '`')->fetchOne();
            $next_element_id     = is_numeric($next_element_id_raw) ? (int) $next_element_id_raw : 1;

            $start             = get_moment();
            $inserts           = $insert_links = $insert_formats = $formats_to_delete = [];

            foreach (array_diff(array_keys($fs), array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $db_elements)) as $path) {
                $dirname  = dirname((string) $path);
                if (!isset($db_fulldirs[$dirname])) {
                    continue;
                }
                $filename = basename((string) $path);
                if (!preg_match(Config::syncCharsRegex(), $filename)) {
                    $errors[] = ['path' => $path, 'type' => 'PWG-UPDATE-1'];
                    continue;
                }
                $insert = ['id' => $next_element_id++, 'file' => $filename, 'name' => get_name_from_file($filename), 'date_available' => CURRENT_DATE, 'path' => $path, 'representative_ext' => is_array($fs[$path]) ? ($fs[$path]['representative_ext'] ?? null) : null, 'storage_category_id' => $db_fulldirs[$dirname], 'added_by' => $user['id']];
                if ($_POST['privacy_level'] != 0) {
                    $insert['level'] = $_POST['privacy_level'];
                }
                $inserts[]      = $insert;
                $insert_links[] = ['image_id' => $insert['id'], 'category_id' => $insert['storage_category_id']];
                $infos[]        = ['path' => $insert['path'], 'info' => l10n('added')];
                if (Config::isFormatsEnabled()) {
                    $fs_path_formats = is_array($fs[$path]) && is_array($fs[$path]['formats'] ?? null) ? $fs[$path]['formats'] : [];
                    foreach ($fs_path_formats as $ext => $filesize) {
                        $insert_formats[] = ['image_id' => $insert['id'], 'ext' => $ext, 'filesize' => $filesize];
                        $infos[] = ['path' => $insert['path'], 'info' => l10n('format %s added', $ext)];
                    }
                }
                $caddiables[] = $insert['id'];
            }

            if (Config::isFormatsEnabled()) {
                $db_elements_str  = array_map(fn ($v): string => is_scalar($v) ? (string) $v : '', $db_elements);
                $db_elements_flip = array_flip($db_elements_str);
                $existing_ids     = [];
                foreach (array_intersect_key($fs, $db_elements_flip) as $path => $existing) {
                    $existing_ids[] = $db_elements_flip[$path];
                }
                $logger->debug('existing_ids', $existing_ids);

                if (count($existing_ids) > 0) {
                    $db_formats = [];
                    foreach (ServiceLocator::get(ImageRepository::class)->findFormatsByImageIds(array_map(intval(...), $existing_ids)) as $row) {
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
                            $infos[] = ['path' => $db_elem_path, 'info' => l10n('format %s removed', $ext)];
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
                            $infos[] = ['path' => $path, 'info' => l10n('format %s added', $ext)];
                        }
                    }
                }
            }

            if (!$simulate) {
                if (count($inserts) > 0) {
                    Dml::massInserts(IMAGES_TABLE, array_keys($inserts[0]), $inserts);
                    Dml::massInserts(IMAGE_CATEGORY_TABLE, array_keys($insert_links[0]), $insert_links);
                    pwg_activity('photo', $caddiables, 'add', ['sync' => true]);
                    if (isset($_POST['add_to_caddie']) && $_POST['add_to_caddie'] == 1) {
                        fill_caddie($caddiables);
                    }
                }
                if (count($insert_formats) > 0) {
                    Dml::massInserts(IMAGE_FORMAT_TABLE, array_keys($insert_formats[0]), $insert_formats);
                }
                if (count($formats_to_delete) > 0) {
                    ServiceLocator::get(ImageRepository::class)->deleteFormatsByFormatIds(array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $formats_to_delete));
                }
            }
            $counts['new_elements'] = count($inserts);

            $to_delete_elements = [];
            foreach (array_diff(array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $db_elements), array_keys($fs)) as $path) {
                $found = array_search($path, $db_elements);
                if ($found !== false) {
                    $to_delete_elements[] = (int) $found;
                }
                $infos[] = ['path' => $path, 'info' => l10n('deleted')];
            }
            if (count($to_delete_elements) > 0) {
                if (!$simulate) {
                    ServiceLocator::get(ImageAdminService::class)->deleteElements($to_delete_elements);
                } $counts['del_elements'] = count($to_delete_elements);
            }

            $tpl->append('footer_elements', '<!-- scanning files : ' . get_elapsed_time($start_files, get_moment()) . ' -->');
        }

        // ── sync categories & files ───────────────────────────────────────────

        if (isset($_POST['submit']) && ($_POST['sync'] == 'dirs' || $_POST['sync'] == 'files') && !$general_failure) {
            if (!$simulate) {
                $start = get_moment();
                ServiceLocator::get(CategoryAdminService::class)->updateCategory('all');
                $tpl->append('footer_elements', '<!-- ServiceLocator::get(CategoryAdminService::class)->updateCategory(all) : ' . get_elapsed_time($start, get_moment()) . ' -->');
                $start = get_moment();
                ServiceLocator::get(CategoryAdminService::class)->updateGlobalRank();
                $tpl->append('footer_elements', '<!-- ordering categories : ' . get_elapsed_time($start, get_moment()) . ' -->');
            }
            if ($_POST['sync'] == 'files') {
                $start = get_moment();
                $opts  = ['category_id' => '', 'recursive' => true];
                if (isset($_POST['cat'])) {
                    $opts['category_id'] = $_POST['cat'];
                    if (!isset($_POST['subcats-included']) || $_POST['subcats-included'] != 1) {
                        $opts['recursive'] = false;
                    }
                }
                $catIdOpt = is_int($opts['category_id']) || is_string($opts['category_id']) ? $opts['category_id'] : '';
                $files = ServiceLocator::get(MetadataAdminService::class)->getFilelist($catIdOpt, (int) $site_id, (bool) $opts['recursive'], false);
                $tpl->append('footer_elements', '<!-- get_filelist : ' . get_elapsed_time($start, get_moment()) . ' -->');
                $start = get_moment();
                $datas = [];
                foreach ($files as $id => $file) {
                    $file_path = is_array($file) && is_scalar($file['path'] ?? null) ? (string) $file['path'] : '';
                    $data      = $site_reader->getElementUpdateAttributes($file_path);
                    $data['id'] = $id;
                    $datas[]   = $data;
                }
                $counts['upd_elements'] = count($datas);
                if (!$simulate && count($datas) > 0) {
                    Dml::massUpdates(IMAGES_TABLE, ['primary' => ['id'], 'update' => array_map(fn ($v): string => is_scalar($v) ? (string) $v : '', $site_reader->getUpdateAttributes())], $datas);
                }
                $tpl->append('footer_elements', '<!-- update files : ' . get_elapsed_time($start, get_moment()) . ' -->');
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
            $start       = get_moment();
            $catIdMeta   = is_int($opts['category_id']) || is_string($opts['category_id']) ? $opts['category_id'] : '';
            $files = ServiceLocator::get(MetadataAdminService::class)->getFilelist($catIdMeta, (int) $site_id, (bool) $opts['recursive'], (bool) $opts['only_new']);
            $tpl->append('footer_elements', '<!-- get_filelist : ' . get_elapsed_time($start, get_moment()) . ' -->');
            $start = get_moment();
            $datas = $tags_of = [];
            foreach ($files as $id => $element_infos) {
                $element_infos_arr = is_array($element_infos) ? $element_infos : [];
                $data = $site_reader->getElementMetadata($element_infos_arr);
                if (is_array($data)) {
                    $data['date_metadata_update'] = CURRENT_DATE;
                    $data['id'] = $id;
                    $datas[]    = $data;
                    foreach (['keywords', 'tags'] as $key) {
                        if (isset($data[$key])) {
                            if (!isset($tags_of[$id])) {
                                $tags_of[$id] = [];
                            }
                            foreach (explode(',', is_scalar($data[$key]) ? (string) $data[$key] : '') as $tag_name) {
                                $tags_of[$id][] = ServiceLocator::get(TagAdminService::class)->tagIdFromTagName($tag_name);
                            }
                        }
                    }
                } else {
                    $errors[] = ['path' => $element_infos_arr['path'] ?? '', 'type' => 'PWG-ERROR-NO-FS'];
                }
            }
            if (!$simulate) {
                if (count($datas) > 0) {
                    Dml::massUpdates(IMAGES_TABLE, ['primary' => ['id'], 'update' => array_unique(array_merge(array_values(array_diff(array_map(fn ($v): string => is_scalar($v) ? (string) $v : '', $site_reader->getMetadataAttributes()), ['keywords', 'tags'])), ['date_metadata_update']))], $datas, isset($_POST['meta_empty_overrides']) ? 0 : Dml::SKIP_EMPTY);
                }
                ServiceLocator::get(TagAdminService::class)->setTagsOf($tags_of);
            }
            $tpl->append('footer_elements', '<!-- metadata update : ' . get_elapsed_time($start, get_moment()) . ' -->');
            $tpl->assign('metadata_result', ['NB_ELEMENTS_DONE' => count($datas), 'NB_ELEMENTS_CANDIDATES' => count($files), 'NB_ERRORS' => count($errors)]);
        }

        // ── template ─────────────────────────────────────────────────────────

        $tpl->setFilenames(['update' => 'site_update.tpl']);
        $result_title  = $simulate ? '[' . l10n('Simulation') . '] ' : '';
        $used_metadata = implode(', ', array_map(fn ($v): string => is_scalar($v) ? (string) $v : '', $site_reader->getMetadataAttributes()));

        $tpl->assign([
            'SITE_URL'       => $site_url_str,
            'U_SITE_MANAGER' => ServiceLocator::get(UrlGenerator::class)->admin('site_manager'),
            'L_RESULT_UPDATE' => $result_title . l10n('Search for new images in the directories'),
            'L_RESULT_METADATA' => $result_title . l10n('Metadata synchronization results'),
            'METADATA_LIST'  => $used_metadata,
            'U_HELP'         => ServiceLocator::get(UrlGenerator::class)->adminPopupHelp('synchronize'),
            'ADMIN_PAGE_TITLE' => l10n('Synchronize'),
        ]);

        if (isset($_POST['submit'])) {
            $tpl_introduction = [
                'sync'                  => $_POST['sync'],
                'sync_meta'             => isset($_POST['sync_meta']),
                'display_info'          => isset($_POST['display_info']) && $_POST['display_info'] == 1,
                'add_to_caddie'         => isset($_POST['add_to_caddie']) && $_POST['add_to_caddie'] == 1,
                'subcats_included'      => isset($_POST['subcats-included']) && $_POST['subcats-included'] == 1,
                'privacy_level_selected' => is_numeric($_POST['privacy_level'] ?? null) ? (int) $_POST['privacy_level'] : 0,
                'meta_all'              => isset($_POST['meta_all']),
                'meta_empty_overrides'  => isset($_POST['meta_empty_overrides']),
            ];
            $cat_selected = (isset($_POST['cat']) && is_numeric($_POST['cat'])) ? [(int) $_POST['cat']] : [];
        } else {
            $tpl_introduction = ['sync' => 'dirs', 'sync_meta' => true, 'display_info' => false, 'add_to_caddie' => false, 'subcats_included' => true, 'privacy_level_selected' => 0, 'meta_all' => false, 'meta_empty_overrides' => false];
            $cat_selected = [];
            if (isset($_GET['cat_id'])) {
                check_input_parameter('cat_id', $_GET, false, PATTERN_ID);
                $cat_selected = [is_numeric($_GET['cat_id']) ? (int) $_GET['cat_id'] : 0];
                $tpl_introduction['sync'] = 'files';
            }
        }

        $tpl_introduction['privacy_level_options'] = get_privacy_level_options();
        $tpl->assign('introduction', $tpl_introduction);

        display_select_cat_wrapper('SELECT id,name,uppercats,global_rank FROM ' . CATEGORIES_TABLE . ' WHERE site_id = ' . $site_id, $cat_selected, 'category_options', false);

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

        $tpl->assignVarFromHandle('ADMIN_CONTENT', 'update');
    }

    // ── stats helper methods (from stats.php) ─────────────────────────────────

    /** @return array<mixed> */
    private function getLast(int $last_number = 60, string $type = 'year'): array
    {
        return ServiceLocator::get(HistoryRepository::class)->findSummaryByType($type, $last_number);
    }

    /** @return array<mixed> */
    private function getMonthOfLastYears(string|int $last = 'all'): array
    {
        $query = 'SELECT year, month, day, hour, nb_pages FROM ' . HISTORY_SUMMARY_TABLE . ' WHERE month IS NOT NULL AND day IS NULL ORDER BY year DESC, month DESC';
        if ($last !== 'all') {
            $date  = new \DateTime();
            $limit = ((int) $last - 1) * 12 + (int) $date->format('n') - 1;
            $query .= ' LIMIT ' . $limit;
            $result   = DbConnection::get()->executeQuery($query . ';')->fetchAllAssociative();
            $lastDate = $date->sub(new \DateInterval('P' . ((int) $last - 1) . 'Y' . ((int) $date->format('n') - 1) . 'M'));
            return $this->setMissingValues('month', $result, $lastDate, new \DateTime());
        }
        if (count(DbConnection::get()->executeQuery($query . ';')->fetchAllAssociative()) > 1) {
            return $this->setMissingValues('month', DbConnection::get()->executeQuery($query . ';')->fetchAllAssociative());
        } else {
            $last_year_date = new \DateTime();
            return $this->setMissingValues('month', DbConnection::get()->executeQuery($query . ';')->fetchAllAssociative(), $last_year_date->sub(new \DateInterval('P1Y')), new \DateTime());
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

        $query = 'SELECT year, month, day, hour, nb_pages FROM ' . HISTORY_SUMMARY_TABLE .
            ' WHERE ((year = ' . $date->format('Y') . ' AND month = ' . $date->format('n') . ') OR (year = ' . $date_last_month->format('Y') . ' AND month = ' . $date_last_month->format('n') . ') OR (year = ' . $date_last_year->format('Y') . ' AND month = ' . $date_last_year->format('n') . ')) AND day IS NOT NULL AND hour IS NULL ORDER BY year DESC, month DESC;';

        $months = [];
        foreach (DbConnection::get()->executeQuery($query)->fetchAllAssociative() as $value) {
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

        $result['avg'] = ServiceLocator::get(HistoryRepository::class)->findCurrentPeriodDailyAvg((int) $date->format('Y'), (int) $date->format('n'));
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
            $date_string .= '-' . (is_scalar($row['month']) ? (string) $row['month'] : '1');
            if (($row['day'] ?? null) !== null) {
                $date_string .= '-' . (is_scalar($row['day']) ? (string) $row['day'] : '1');
                if (($row['hour'] ?? null) !== null) {
                    $date_string .= ' ' . (is_scalar($row['hour']) ? (string) $row['hour'] : '0') . ':00';
                }
            }
        } else {
            $date_string .= '-1';
        }
        return new \DateTime($date_string);
    }
}
