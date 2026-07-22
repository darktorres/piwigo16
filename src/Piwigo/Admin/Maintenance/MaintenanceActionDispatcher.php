<?php

declare(strict_types=1);

namespace Piwigo\Admin\Maintenance;

use Doctrine\DBAL\Connection;
use Piwigo\Activity\ActivityRepository;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\Integrity\CheckIntegrity;
use Piwigo\Auth\CookieService;
use Piwigo\Cache\UserCacheInvalidator;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Config\ConfigService;
use Piwigo\Core\ActivitySystem;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Group\GroupRepository;
use Piwigo\Http\HttpClientService;
use Piwigo\Image\DerivativeCacheService;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageService;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\Rate\RateRepository;
use Piwigo\Rate\RateService;
use Piwigo\Session\SessionService;
use Piwigo\Tag\TagRepository;
use Piwigo\Tag\TagService;
use Piwigo\Template\FileCombiner;

/**
 * Consolidates the ~18-case maintenance action switch that was previously
 * duplicated, byte-for-byte at first but since drifted, between
 * admin/maintenance_actions.php and admin/maintenance_env.php (P23 batch
 * 6h). Both tab templates only ever link to a subset of these actions
 * (confirmed via direct grep of both .tpl files) -- actions.tpl to the 16
 * real maintenance operations, env.tpl to just `phpinfo`/`check_upgrade` --
 * but each legacy file's own switch handled the full set regardless, so
 * both tabs remain reachable exactly as before via this single shared
 * dispatcher.
 *
 * Fixes 2 real behavioral drifts found by diffing the two original
 * switches rather than trusting DbMaintenanceRepository's own docblock
 * claim that they were "identical":
 *  - admin/maintenance_env.php's copy never called pwg_activity() for any
 *    action -- so performing a real mutation via a crafted env-tab URL
 *    went completely unlogged in the sys tab's own audit view. This
 *    dispatcher always logs, matching admin/maintenance_actions.php's own
 *    (correct) behavior.
 *  - admin/maintenance_env.php's copy was missing the `empty_lounge` case
 *    entirely (silently fell through to `default:`). Restored here.
 *
 * `check_upgrade` also differed in one more way: admin/maintenance_env.php
 * (the only tab whose own UI actually links to this action) rendered a
 * real "Update to Piwigo %s" link when a new version is available;
 * admin/maintenance_actions.php's copy instead appended a generic "action
 * successfully performed." tail message that never made semantic sense for
 * this action (a copy-paste artifact of sitting directly above the shared
 * `default:` fallthrough, never reachable via that tab's own UI). This
 * dispatcher keeps the real, UI-exercised "Update to Piwigo %s" link
 * behavior as canonical.
 */
final class MaintenanceActionDispatcher
{
    public function __construct(
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
        private readonly ConfigService $configService,
    ) {}

    /**
     * DRY extraction (Phase 1k DI-chain audit): the same PermissionService
     * recipe was repeated verbatim at 3 sites in this file.
     */
    private static function permissionService(Connection $conn): PermissionService
    {
        return new PermissionService(new PermissionRepository($conn), new GroupRepository($conn), new CategoryRepository($conn));
    }

    /**
     * DRY extraction (Phase 1k DI-chain audit): the same CategoryService
     * recipe was repeated verbatim at 2 sites in this file.
     */
    private static function categoryService(Connection $conn): CategoryService
    {
        return new CategoryService(new CategoryRepository($conn), self::permissionService($conn));
    }

    public function dispatch(string $action): void
    {
        $persistent_cache = \Piwigo\Cache\CurrentPersistentCache::get();

        $register_activity = true;
        $conn = DbConnection::build();
        $db_maintenance = new DbMaintenanceRepository($conn);

        switch ($action) {
            case 'phpinfo':

                // SEC-22: curated info, not a raw phpinfo() dump -- see
                // ServerInfoService's own docblock.
                echo new ServerInfoService()
                    ->renderHtml();
                exit();

            case 'lock_gallery':

                $this->configService->confUpdateParam('gallery_locked', 'true');
                new ActivityService(new ActivityRepository($conn))
                    ->record('system', ActivitySystem::Core, 'maintenance', [
                        'maintenance_action' => $action,
                    ]);
                $this->redirectService->redirect($this->urlService->getRootUrl() . 'admin.php?page=maintenance');

                // no break
            case 'unlock_gallery':

                $this->configService->confUpdateParam('gallery_locked', 'false');
                $_SESSION['page_infos'] = [Lang::t('Gallery unlocked')];
                new ActivityService(new ActivityRepository($conn))
                    ->record('system', ActivitySystem::Core, 'maintenance', [
                        'maintenance_action' => $action,
                    ]);
                $this->redirectService->redirect($this->urlService->getRootUrl() . 'admin.php?page=maintenance');

                // no break
            case 'categories':

                FilesystemIntegrityChecker::imagesIntegrity();
                $categoriesService = self::categoryService($conn);
                $categoriesService->checkCategoriesIntegrity();
                $categoriesService->updateUppercats();
                $categoriesService->updateCategory('all');
                $categoriesService->updateGlobalRank();
                UserCacheInvalidator::invalidate(true);
                \Piwigo\Core\PageState::current()->addInfo(sprintf('%s : %s', Lang::t('Update albums informations'), Lang::t('action successfully performed.')));
                break;

            case 'images':

                FilesystemIntegrityChecker::imagesIntegrity();
                self::categoryService($conn)
                    ->updatePath();
                new RateService(new RateRepository($conn), new CookieService())
                    ->updateRatingScore();
                UserCacheInvalidator::invalidate();
                \Piwigo\Core\PageState::current()->addInfo(sprintf('%s : %s', Lang::t('Update photos information'), Lang::t('action successfully performed.')));
                break;

            case 'delete_orphan_tags':

                new TagService(new TagRepository($conn), self::permissionService($conn), new ActivityService(new ActivityRepository($conn)))
                    ->deleteOrphanTags();
                \Piwigo\Core\PageState::current()->addInfo(sprintf('%s : %s', Lang::t('Delete orphan tags'), Lang::t('action successfully performed.')));
                break;

            case 'user_cache':

                UserCacheInvalidator::invalidate();
                \Piwigo\Core\PageState::current()->addInfo(sprintf('%s : %s', Lang::t('Purge user cache'), Lang::t('action successfully performed.')));
                break;

            case 'history_detail':

                $db_maintenance->purgeHistoryDetail();
                \Piwigo\Core\PageState::current()->addInfo(sprintf('%s : %s', Lang::t('Purge history detail'), Lang::t('action successfully performed.')));
                break;

            case 'history_summary':

                $db_maintenance->purgeHistorySummary();
                \Piwigo\Core\PageState::current()->addInfo(sprintf('%s : %s', Lang::t('Purge history summary'), Lang::t('action successfully performed.')));
                break;

            case 'sessions':

                SessionService::get()->sessionGc();

                // \Piwigo\Config\Config::userFields() maps generic field names to actual DB column
                // names (see include/config_default.inc.php); its values are
                // configuration-supplied, not statically typed, hence the fallback.
                $user_fields = \Piwigo\Config\Config::userFields();
                $id_field = is_string($user_fields['id'] ?? null) ? $user_fields['id'] : 'id';

                $db_maintenance->purgeSessionsForDeletedUsers($id_field);
                \Piwigo\Core\PageState::current()->addInfo(sprintf('%s : %s', Lang::t('Purge sessions'), Lang::t('action successfully performed.')));
                break;

            case 'feeds':

                $db_maintenance->purgeUnusedFeeds();
                \Piwigo\Core\PageState::current()->addInfo(sprintf('%s : %s', Lang::t('Purge never used notification feeds'), Lang::t('action successfully performed.')));
                break;

            case 'database':

                $db_maintenance->repairOptimizeAllTables();
                \Piwigo\Core\PageState::current()->addInfo(Lang::t('All optimizations have been successfully completed.'));
                break;

            case 'c13y':

                $c13y = new CheckIntegrity();
                $c13y->maintenance();
                \Piwigo\Core\PageState::current()->addInfo(sprintf('%s : %s', Lang::t('Reinitialize check integrity'), Lang::t('action successfully performed.')));
                break;

            case 'empty_lounge':

                $rows = new ImageService(new ImageRepository($conn), new ActivityService(new ActivityRepository($conn)))
                    ->emptyLounge();
                \Piwigo\Core\PageState::current()->addInfo(sprintf('%d photos were moved from the upload lounge to their albums', count($rows ?? [])));
                break;

            case 'search':

                $db_maintenance->purgeSearchHistory();
                \Piwigo\Core\PageState::current()->addInfo(sprintf('%s : %s', Lang::t('Purge search history'), Lang::t('action successfully performed.')));
                break;

            case 'compiled-templates':

                \Piwigo\Template\CurrentTemplate::get()->delete_compiled_templates();
                FileCombiner::clear_combined_files();
                if (! $persistent_cache instanceof \Piwigo\Cache\PersistentCache) {
                    new \Piwigo\Html\HtmlService()
                        ->fatalError('persistent cache not initialized');
                }
                $persistent_cache->purge(true);
                \Piwigo\Core\PageState::current()->addInfo(sprintf('%s : %s', Lang::t('Purge compiled templates'), Lang::t('action successfully performed.')));
                break;

            case 'derivatives':

                $types_str = $_GET['type'] ?? '';
                $types_str = is_string($types_str) ? $types_str : '';
                if ($types_str === 'all') {
                    new DerivativeCacheService()
                        ->clearDerivativeCache($types_str);
                } else {
                    $types = explode('_', $types_str);
                    foreach ($types as $type_to_clear) {
                        new DerivativeCacheService()
                            ->clearDerivativeCache($type_to_clear);
                    }
                }
                \Piwigo\Core\PageState::current()->addInfo(Lang::t('action successfully performed.'));
                break;

            case 'check_upgrade':

                $result = HttpClientService::fetch(AppInfo::URL . '/download/latest_version');
                if ($result === false) {
                    \Piwigo\Core\PageState::current()->addError(Lang::t('Unable to check for upgrade.'));
                } else {
                    $versions = [
                        'current' => AppInfo::VERSION,
                    ];
                    $lines = @explode("\r\n", $result);

                    // if the current version is a BSF (development branch) build, we check
                    // the first line, for stable versions, we check the second line
                    if ((bool) preg_match('/^BSF/', $versions['current'])) {
                        $versions['latest'] = trim($lines[0]);

                        // because integer are limited to 4,294,967,296 we need to split BSF
                        // versions in date.time
                        foreach ($versions as $key => $value) {
                            $replaced = preg_replace('/BSF_(\d{8})(\d{4})/', '$1.$2', $value);
                            assert($replaced !== null);
                            $versions[$key] = $replaced;
                        }
                    } else {
                        $versions['latest'] = trim($lines[1]);
                    }

                    if ($versions['latest'] === '') {
                        \Piwigo\Core\PageState::current()->addError(Lang::t('Check for upgrade failed for unknown reasons.'));
                    } elseif (version_compare($versions['current'], $versions['latest']) < 0) {
                        \Piwigo\Core\PageState::current()->addInfo(Lang::t('A new version of Piwigo is available.'));

                        $update_url = $this->urlService->getRootUrl() . 'admin.php?page=updates';
                        \Piwigo\Core\PageState::current()->addInfo('<a href="' . $update_url . '">' . Lang::t('Update to Piwigo %s', $versions['latest']) . '</a>');
                    } else {
                        \Piwigo\Core\PageState::current()->addInfo(Lang::t('You are running the latest version of Piwigo.'));
                    }
                }

                // no break
            default:

                $register_activity = false;
                break;

        }

        if ($register_activity) {
            new ActivityService(new ActivityRepository($conn))
                ->record('system', ActivitySystem::Core, 'maintenance', [
                    'maintenance_action' => $action,
                ]);
        }
    }
}
