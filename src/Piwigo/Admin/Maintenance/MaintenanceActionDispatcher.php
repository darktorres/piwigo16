<?php

declare(strict_types=1);

namespace Piwigo\Admin\Maintenance;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\Integrity\CheckIntegrity;
use Piwigo\Admin\Integrity\IntegrityIgnoredAnomalyEntity;
use Piwigo\Admin\Maintenance\Request\DerivativesTypeRequest;
use Piwigo\Cache\PermissionCacheInvalidator;
use Piwigo\Cache\PersistentCache;
use Piwigo\Category\CategoryService;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\ActivitySystem;
use Piwigo\Core\AppInfo;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\LayoutState;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Http\HttpClientService;
use Piwigo\Image\DerivativeCacheService;
use Piwigo\Image\ImageEntity;
use Piwigo\Image\ImageService;
use Piwigo\Lang\Translator;
use Piwigo\Permalink\PermalinkRepository;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Rate\RateService;
use Piwigo\Session\SessionService;
use Piwigo\Site\SiteEntity;
use Piwigo\Tag\TagService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Validation\InputValidator;

/**
 * Handles the maintenance action switch shared by the "actions" and
 * "env" admin tabs. maintenance_actions.latte links to the 16 real
 * maintenance operations, each of which is logged via activity
 * recording; maintenance_env.latte links to just
 * `phpinfo`/`check_upgrade`, neither of which is logged.
 * `check_upgrade` renders an "Update to Piwigo %s" link when a new
 * version is available.
 */
final readonly class MaintenanceActionDispatcher
{
    public function __construct(
        private RedirectServiceInterface $redirectService,
        private UrlServiceInterface $urlService,
        private ConfigService $configService,
        private FilesystemIntegrityChecker $filesystemIntegrityChecker,
        private SessionService $sessionService,
        private Translator $translator,
        private EventDispatcher $eventDispatcher,
        private PageState $pageState,
        private LayoutState $layoutState,
        private CurrentTemplate $currentTemplate,
        private DbMaintenanceRepository $dbMaintenanceRepository,
        private ActivityService $activityService,
        private RateService $rateService,
        private CategoryService $categoryService,
        private TagService $tagService,
        private HtmlRenderingInterface $htmlRenderer,
        private Lang $lang,
        private CurrentConfig $currentConfig,
        private InputValidator $inputValidator,
        private Paths $paths,
        private EntityManagerInterface $entityManager,
        private ?PersistentCache $persistentCache = null,
    ) {}

    public function dispatch(string $action): void
    {
        $register_activity = true;
        $db_maintenance = $this->dbMaintenanceRepository;

        switch ($action) {
            case 'phpinfo':

                // SEC-22: curated info, not a raw phpinfo() dump -- see
                // ServerInfoService's own docblock.
                echo new ServerInfoService()
                    ->renderHtml();
                exit();

            case 'lock_gallery':

                $this->configService->confUpdateParam('gallery_locked', true);
                $this->activityService
                    ->record('system', ActivitySystem::Core, 'maintenance', [
                        'maintenance_action' => $action,
                    ]);
                $this->redirectService->redirect($this->urlService->getRootUrl() . 'admin.php?page=maintenance');

                // no break
            case 'unlock_gallery':

                $this->configService->confUpdateParam('gallery_locked', false);
                $_SESSION['page_infos'] = [$this->lang->t('Gallery unlocked')];
                $this->activityService
                    ->record('system', ActivitySystem::Core, 'maintenance', [
                        'maintenance_action' => $action,
                    ]);
                $this->redirectService->redirect($this->urlService->getRootUrl() . 'admin.php?page=maintenance');

                // no break
            case 'categories':

                $this->filesystemIntegrityChecker->imagesIntegrity();
                $categoriesService = $this->categoryService;
                $categoriesService->checkCategoriesIntegrity(new PermalinkRepository($this->entityManager));
                $categoriesService->updateUppercats();
                $categoriesService->updateCategory('all');
                $categoriesService->updateGlobalRank();
                PermissionCacheInvalidator::invalidate();
                $this->pageState->addInfo(sprintf('%s : %s', $this->lang->t('Update albums informations'), $this->lang->t('action successfully performed.')));
                break;

            case 'images':

                $this->filesystemIntegrityChecker->imagesIntegrity();
                /**
                 * @psalm-suppress InvalidArgument getRepository() always
                 *   really returns SiteEntity's own custom repositoryClass
                 *   at runtime (SiteRepository), which implements
                 *   SiteGalleriesUrlLookupInterface; Psalm's Doctrine
                 *   plugin doesn't narrow getRepository()'s return type
                 *   per-entity's own repositoryClass binding, only the
                 *   generic EntityRepository<T>.
                 */
                $this->categoryService
                    ->updatePath($this->entityManager->getRepository(SiteEntity::class));
                $this->rateService
                    ->updateRatingScore($this->entityManager);
                PermissionCacheInvalidator::invalidate();
                $this->pageState->addInfo(sprintf('%s : %s', $this->lang->t('Update photos information'), $this->lang->t('action successfully performed.')));
                break;

            case 'delete_orphan_tags':

                $this->tagService
                    ->deleteOrphanTags($this->entityManager, new ImageService($this->entityManager->getRepository(ImageEntity::class), $this->activityService, $this->eventDispatcher, $this->currentConfig, $this->paths, $this->categoryService));
                $this->pageState->addInfo(sprintf('%s : %s', $this->lang->t('Delete orphan tags'), $this->lang->t('action successfully performed.')));
                break;

            case 'user_cache':

                PermissionCacheInvalidator::invalidate();
                $this->pageState->addInfo(sprintf('%s : %s', $this->lang->t('Purge user cache'), $this->lang->t('action successfully performed.')));
                break;

            case 'history_detail':

                $db_maintenance->purgeHistoryDetail();
                $this->pageState->addInfo(sprintf('%s : %s', $this->lang->t('Purge history detail'), $this->lang->t('action successfully performed.')));
                break;

            case 'history_summary':

                $db_maintenance->purgeHistorySummary();
                $this->pageState->addInfo(sprintf('%s : %s', $this->lang->t('Purge history summary'), $this->lang->t('action successfully performed.')));
                break;

            case 'sessions':

                $this->sessionService->sessionGc();

                $db_maintenance->purgeSessionsForDeletedUsers();
                $this->pageState->addInfo(sprintf('%s : %s', $this->lang->t('Purge sessions'), $this->lang->t('action successfully performed.')));
                break;

            case 'feeds':

                $db_maintenance->purgeUnusedFeeds();
                $this->pageState->addInfo(sprintf('%s : %s', $this->lang->t('Purge never used notification feeds'), $this->lang->t('action successfully performed.')));
                break;

            case 'database':

                $db_maintenance->repairOptimizeAllTables();
                $this->pageState->addInfo($this->lang->t('All optimizations have been successfully completed.'));
                break;

            case 'c13y':

                $integrityRepo = $this->entityManager->getRepository(IntegrityIgnoredAnomalyEntity::class);
                $c13y = new CheckIntegrity($this->lang, $integrityRepo, $this->translator, $this->eventDispatcher, $this->pageState, $this->layoutState);
                $c13y->maintenance();
                $this->pageState->addInfo(sprintf('%s : %s', $this->lang->t('Reinitialize check integrity'), $this->lang->t('action successfully performed.')));
                break;

            case 'empty_lounge':

                $rows = new ImageService($this->entityManager->getRepository(ImageEntity::class), $this->activityService, $this->eventDispatcher, $this->currentConfig, $this->paths, $this->categoryService)
                    ->emptyLounge($this->sessionService);
                $this->pageState->addInfo(sprintf('%d photos were moved from the upload lounge to their albums', count($rows ?? [])));
                break;

            case 'search':

                $db_maintenance->purgeSearchHistory();
                $this->pageState->addInfo(sprintf('%s : %s', $this->lang->t('Purge search history'), $this->lang->t('action successfully performed.')));
                break;

            case 'compiled-templates':

                $this->currentTemplate->get()
                    ->deleteCompiledTemplates();
                if (! $this->persistentCache instanceof PersistentCache) {
                    $this->htmlRenderer
                        ->fatalError('persistent cache not initialized');
                }
                $this->persistentCache->purge(true);
                $this->pageState->addInfo(sprintf('%s : %s', $this->lang->t('Purge compiled templates'), $this->lang->t('action successfully performed.')));
                break;

            case 'derivatives':

                $types_str = DerivativesTypeRequest::fromGlobals($this->inputValidator)->typesStr;
                if ($types_str === 'all') {
                    new DerivativeCacheService($this->currentConfig, $this->paths)
                        ->clearDerivativeCache($types_str);
                } else {
                    $types = explode('_', $types_str);
                    foreach ($types as $type_to_clear) {
                        new DerivativeCacheService($this->currentConfig, $this->paths)
                            ->clearDerivativeCache($type_to_clear);
                    }
                }
                $this->pageState->addInfo($this->lang->t('action successfully performed.'));
                break;

            case 'check_upgrade':

                $result = HttpClientService::fetch(AppInfo::URL . '/download/latest_version', $this->currentConfig);
                if ($result === false) {
                    $this->pageState->addError($this->lang->t('Unable to check for upgrade.'));
                } else {
                    $versions = [
                        'current' => AppInfo::VERSION,
                    ];
                    $lines = explode("\r\n", $result);

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
                        $this->pageState->addError($this->lang->t('Check for upgrade failed for unknown reasons.'));
                    } elseif (version_compare($versions['current'], $versions['latest']) < 0) {
                        $this->pageState->addInfo($this->lang->t('A new version of Piwigo is available.'));

                        $update_url = $this->urlService->getRootUrl() . 'admin.php?page=updates';
                        $this->pageState->addInfo('<a href="' . $update_url . '">' . $this->lang->t('Update to Piwigo %s', $versions['latest']) . '</a>');
                    } else {
                        $this->pageState->addInfo($this->lang->t('You are running the latest version of Piwigo.'));
                    }
                }

                // no break
            default:

                $register_activity = false;
                break;

        }

        if ($register_activity) {
            $this->activityService
                ->record('system', ActivitySystem::Core, 'maintenance', [
                    'maintenance_action' => $action,
                ]);
        }
    }
}
