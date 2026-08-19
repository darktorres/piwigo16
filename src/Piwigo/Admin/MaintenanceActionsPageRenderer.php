<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\Event\GetAdminAdvancedFeaturesLinks;
use Piwigo\Admin\Maintenance\DbMaintenanceRepository;
use Piwigo\Admin\Maintenance\FilesystemIntegrityChecker;
use Piwigo\Admin\Maintenance\MaintenanceActionDispatcher;
use Piwigo\Admin\Maintenance\Request\MaintenanceActionRequest;
use Piwigo\Admin\Projection\MaintenanceActionsView;
use Piwigo\Auth\AccessControl;
use Piwigo\Cache\PersistentCache;
use Piwigo\Category\CategoryService;
use Piwigo\Config\CacheSizesSnapshot;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Admin\Projection\AdminContentPageContext;
use Piwigo\Core\DateHelper;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\Image\ImageStdParams;
use Piwigo\Lang\Translator;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Rate\RateService;
use Piwigo\Session\SessionService;
use Piwigo\Tag\TagService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Renderer;
use Piwigo\Validation\InputValidator;

/**
 * Ported from admin/maintenance_actions.php (the "actions" tab of the
 * "maintenance" page slug, dispatched by MaintenanceSubController) --
 * exposes the 16 real maintenance operations (lock/unlock gallery, purge
 * history/sessions/feeds/search, delete orphan tags, database repair,
 * derivative cache purge, etc), all reachable from this tab's own template.
 *
 * admin.php gates every page behind
 * check_status(AccessLevel::Administrator) before dispatch, and the shell
 * (admin/maintenance.php, folded into MaintenanceSubController) gates
 * every $_GET['action'] with check_pwg_token(); this class does not
 * duplicate either check.
 *
 * The action-dispatch switch itself lives in the shared
 * Piwigo\Admin\Maintenance\MaintenanceActionDispatcher.
 */
final readonly class MaintenanceActionsPageRenderer
{
    public function __construct(
        private AccessControl $accessControl,
        private RedirectServiceInterface $redirectService,
        private UrlServiceInterface $urlService,
        private ConfigService $configService,
        private FilesystemIntegrityChecker $filesystemIntegrityChecker,
        private SessionService $sessionService,
        private Translator $translator,
        private EventDispatcher $eventDispatcher,
        private ImageStdParams $imageStdParams,
        private PageState $pageState,
        private CurrentTemplate $currentTemplate,
        private DbMaintenanceRepository $dbMaintenanceRepository,
        private ActivityService $activityService,
        private RateService $rateService,
        private CategoryService $categoryService,
        private TagService $tagService,
        private HtmlRenderingInterface $htmlRenderer,
        private Lang $lang,
        private CurrentConfig $currentConfig,
        private CsrfService $csrfService,
        private InputValidator $inputValidator,
        private Paths $paths,
        private EntityManagerInterface $entityManager,
        private Renderer $renderer,
        private ?PersistentCache $persistentCache = null,
    ) {}

    /**
     * @param array<string, array{icon: string, label: string}> $maintActions
     */
    public function render(array $maintActions): void
    {
        $template = $this->currentTemplate->get();

        $this->filesystemIntegrityChecker->fsQuickCheck();

        $action = MaintenanceActionRequest::fromGlobals()->action;
        new MaintenanceActionDispatcher($this->redirectService, $this->urlService, $this->configService, $this->filesystemIntegrityChecker, $this->sessionService, $this->translator, $this->eventDispatcher, $this->pageState, $this->currentTemplate, $this->dbMaintenanceRepository, $this->activityService, $this->rateService, $this->categoryService, $this->tagService, $this->htmlRenderer, $this->lang, $this->currentConfig, $this->inputValidator, $this->paths, $this->entityManager, $this->persistentCache)
            ->dispatch($action);

        $pwg_token = $this->csrfService
            ->getToken();
        $url_format = $this->urlService->getRootUrl() . 'admin.php?page=maintenance&amp;action=%s&amp;pwg_token=' . $this->csrfService->getToken();

        if (! $this->accessControl->isWebmaster()) {
            $this->pageState->addWarning(str_replace('%s', $this->lang->t('user_status_webmaster'), $this->lang->t('%s status is required to edit parameters.')));
        }

        /** @var array<string, string> $purge_urls */
        $purge_urls = [];
        $purge_urls[$this->lang->t('All')] = 'all';
        foreach ($this->imageStdParams->getDefinedTypeMap() as $params) {
            $purge_urls[$this->lang->t($params->type)] = $params->type;
        }
        $purge_urls[$this->lang->t(ImageStdParams::CUSTOM)] = ImageStdParams::CUSTOM;

        // CurrentConfig::cacheSizes is a decoded CacheSizesSnapshot produced
        // by ws_getCacheSize() (cache_size, msizes, tsizes, last_date_calc);
        // lastDateCalc is the date string used for time_since().
        $cache_sizes = $this->currentConfig->cacheSizes;
        $time_elapsed_since_last_calc = $cache_sizes instanceof CacheSizesSnapshot
            ? DateHelper::timeSince($cache_sizes->lastDateCalc, 'year')
            : null;

        $maint_unlock_gallery = null;
        $maint_lock_gallery = null;
        if ($this->currentConfig->galleryLocked) {
            $maint_unlock_gallery = sprintf($url_format, 'unlock_gallery');
        } else {
            $maint_lock_gallery = sprintf($url_format, 'lock_gallery');
        }

        $db_maintenance = $this->dbMaintenanceRepository;
        $nb_lounge = $db_maintenance->countLoungeItems();

        $u_empty_lounge = null;
        $lounge_counter = null;
        if ($nb_lounge > 0) {
            $u_empty_lounge = sprintf($url_format, 'empty_lounge');
            $lounge_counter = $nb_lounge;
        }

        // $advanced_features is array of array composed of CAPTION & URL
        $advanced_features_event = $this->eventDispatcher->dispatch(new GetAdminAdvancedFeaturesLinks([]));

        $adminContent = $this->renderer->render(new MaintenanceActionsView(
            maintActions: $maintActions,
            maintCategories: sprintf($url_format, 'categories'),
            maintImages: sprintf($url_format, 'images'),
            maintOrphanTags: sprintf($url_format, 'delete_orphan_tags'),
            maintUserCache: sprintf($url_format, 'user_cache'),
            maintHistoryDetail: sprintf($url_format, 'history_detail'),
            maintHistorySummary: sprintf($url_format, 'history_summary'),
            maintSessions: sprintf($url_format, 'sessions'),
            maintFeeds: sprintf($url_format, 'feeds'),
            maintDatabase: sprintf($url_format, 'database'),
            maintC13y: sprintf($url_format, 'c13y'),
            maintSearch: sprintf($url_format, 'search'),
            maintCompiledTemplates: sprintf($url_format, 'compiled-templates'),
            purgeDerivatives: $purge_urls,
            pwgToken: $pwg_token,
            cacheSizes: $cache_sizes,
            timeElapsedSinceLastCalc: $time_elapsed_since_last_calc,
            maintUnlockGallery: $maint_unlock_gallery,
            maintLockGallery: $maint_lock_gallery,
            uEmptyLounge: $u_empty_lounge,
            loungeCounter: $lounge_counter,
            isWebmaster: ($this->accessControl->isWebmaster()) ? 1 : 0,
            advancedFeatures: $advanced_features_event->advancedFeatures,
        ));

        $template->assignContext(new AdminContentPageContext(
            adminContent: $adminContent,
            helpUrl: $this->urlService->getRootUrl() . 'admin/popuphelp.php?page=maintenance',
        ));
    }
}
