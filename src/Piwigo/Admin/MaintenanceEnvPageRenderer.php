<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\Extensions\PluginListBuilder;
use Piwigo\Admin\Image\ImageBackend;
use Piwigo\Admin\Maintenance\DbMaintenanceRepository;
use Piwigo\Admin\Maintenance\FilesystemIntegrityChecker;
use Piwigo\Admin\Maintenance\MaintenanceActionDispatcher;
use Piwigo\Admin\Maintenance\Request\MaintenanceActionRequest;
use Piwigo\Admin\Projection\MaintenanceEnvView;
use Piwigo\Cache\PersistentCache;
use Piwigo\Category\CategoryService;
use Piwigo\Config\CacheSizesSnapshot;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Admin\Projection\AdminPageResult;
use Piwigo\Core\AppInfo;
use Piwigo\Core\ContainerDetector;
use Piwigo\Core\DateHelper;
use Piwigo\Core\Env;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\LayoutState;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\DbConnection;
use Piwigo\Db\DbInfo;
use Piwigo\Lang\Translator;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Rate\RateService;
use Piwigo\Session\SessionService;
use Piwigo\Tag\TagService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Renderer;
use Piwigo\Validation\InputValidator;

/**
 * Renders the "env" tab of the "maintenance" admin page (dispatched by
 * MaintenanceSubController) -- server/DB info and cache/storage stats; the
 * only 2 actions this tab's own template links to are
 * `phpinfo`/`check_upgrade`.
 *
 * Access control is enforced by admin.php's dispatch gate, and the
 * check_pwg_token() gate for every $_GET['action'] is enforced by
 * MaintenanceSubController before the tab-specific dispatch runs, so
 * render() does not repeat either check.
 *
 * The action-dispatch switch itself lives in the shared
 * Piwigo\Admin\Maintenance\MaintenanceActionDispatcher, also used by
 * MaintenanceActionsPageRenderer.
 */
final readonly class MaintenanceEnvPageRenderer
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
        private InstallationStats $installationStats,
        private CategoryService $categoryService,
        private TagService $tagService,
        private HtmlRenderingInterface $htmlRenderer,
        private Lang $lang,
        private CurrentConfig $currentConfig,
        private CsrfService $csrfService,
        private InputValidator $inputValidator,
        private Paths $paths,
        private EntityManagerInterface $entityManager,
        private PluginListBuilder $pluginListBuilder,
        private Renderer $renderer,
        private ?PersistentCache $persistentCache = null,
    ) {}

    public function render(): AdminPageResult
    {
        $action = MaintenanceActionRequest::fromGlobals()->action;
        new MaintenanceActionDispatcher($this->redirectService, $this->urlService, $this->configService, $this->filesystemIntegrityChecker, $this->sessionService, $this->translator, $this->eventDispatcher, $this->pageState, $this->layoutState, $this->currentTemplate, $this->dbMaintenanceRepository, $this->activityService, $this->rateService, $this->categoryService, $this->tagService, $this->htmlRenderer, $this->lang, $this->currentConfig, $this->inputValidator, $this->paths, $this->entityManager, $this->persistentCache)
            ->dispatch($action);

        $url_format = $this->urlService->getRootUrl() . 'admin.php?page=maintenance&amp;action=%s&amp;pwg_token=' . $this->csrfService->getToken();

        $dbInfo = new DbInfo(DbConnection::build());
        // Env::now(), not date(): this is PHP's own clock as the page
        // reports it, and every other reading of that clock in the
        // codebase goes through Env::now() so a test run can freeze it.
        // The MySQL timestamp beside it deliberately stays a real
        // SELECT NOW() -- the pair exists to reveal PHP/DB clock skew,
        // which a frozen DB side could not show.
        $php_current_timestamp = Env::now()->format('Y-m-d H:i:s');
        $db_version = $dbInfo->version();
        $db_current_date = $dbInfo->currentDateTime();

        $containerInfo = ContainerDetector::detect();
        $container_name = $containerInfo->type;
        $container_version = $containerInfo->version;

        if (! in_array($container_name, ['Official', 'none'], true)) {
            $container_name = '(unofficial) ' . $container_name;
        }

        $cache_sizes = $this->currentConfig->cacheSizes;

        $time_elapsed_since_last_calc = $cache_sizes instanceof CacheSizesSnapshot
            ? DateHelper::timeSince($cache_sizes->lastDateCalc, 'year')
            : null;

        // graphics library
        $graphics_library = ImageBackend::getGraphicsLibraryLabel();
        $graphics_library_value = $graphics_library !== '' ? $graphics_library : null;

        $installed_on_value = null;
        $installed_since_value = null;
        $installed_on = $this->installationStats->getInstallationDate();
        if (is_string($installed_on) && $installed_on !== '') {
            $installed_on_value = DateHelper::formatDate($installed_on, ['day', 'month', 'year']);
            $installed_since_value = DateHelper::timeSince($installed_on, 'day');
        }

        $active_plugin_names = [];
        foreach ($this->pluginListBuilder->build() as $plugin) {
            if ($plugin['state'] === 'active') {
                $active_plugin_names[] = $plugin['name'];
            }
        }

        $adminContent = $this->renderer->render(new MaintenanceEnvView(
            phpwgUrl: AppInfo::URL,
            pwgVersion: AppInfo::VERSION,
            checkUpgradeUrl: sprintf($url_format, 'check_upgrade'),
            installedOn: $installed_on_value,
            installedSince: $installed_since_value,
            os: PHP_OS,
            containerInfo: $container_name . ($container_version !== null && $container_version !== '' ? ' ' . $container_version : ''),
            phpinfoUrl: sprintf($url_format, 'phpinfo'),
            phpVersion: PHP_VERSION,
            phpCurrentTimestamp: $php_current_timestamp,
            dbEngine: 'MySQL',
            dbVersion: $db_version,
            dbCurrentDate: $db_current_date,
            graphicsLibrary: $graphics_library_value,
            cacheSizes: $cache_sizes,
            timeElapsedSinceLastCalc: $time_elapsed_since_last_calc,
            activePluginNames: $active_plugin_names,
        ));

        return new AdminPageResult(
            content: $adminContent,
            helpUrl: $this->urlService->getRootUrl() . 'admin/popuphelp.php?page=maintenance',
        );
    }
}
