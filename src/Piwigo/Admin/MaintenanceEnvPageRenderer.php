<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Activity\ActivityService;
use Piwigo\Admin\Image\PwgImage;
use Piwigo\Admin\Maintenance\DbMaintenanceRepository;
use Piwigo\Admin\Maintenance\FilesystemIntegrityChecker;
use Piwigo\Admin\Maintenance\MaintenanceActionDispatcher;
use Piwigo\Admin\Maintenance\Request\MaintenanceActionRequest;
use Piwigo\Admin\Projection\MaintenanceEnvPageContext;
use Piwigo\Cache\PersistentCache;
use Piwigo\Category\CategoryService;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\AppInfo;
use Piwigo\Core\ContainerDetector;
use Piwigo\Core\DateHelper;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\DbConnection;
use Piwigo\Db\DbInfo;
use Piwigo\Event\Admin\GetAdminAdvancedFeaturesLinks;
use Piwigo\Image\ImageStdParams;
use Piwigo\Lang\Translator;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Rate\RateService;
use Piwigo\Session\SessionService;
use Piwigo\Tag\TagService;
use Piwigo\Template\CurrentTemplate;
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
final class MaintenanceEnvPageRenderer
{
    public function __construct(
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
        private readonly ConfigService $configService,
        private readonly FilesystemIntegrityChecker $filesystemIntegrityChecker,
        private readonly SessionService $sessionService,
        private readonly Translator $translator,
        private readonly EventDispatcher $eventDispatcher,
        private readonly ImageStdParams $imageStdParams,
        private readonly PageState $pageState,
        private readonly CurrentTemplate $currentTemplate,
        private readonly DbMaintenanceRepository $dbMaintenanceRepository,
        private readonly ActivityService $activityService,
        private readonly RateService $rateService,
        private readonly InstallationStats $installationStats,
        private readonly CategoryService $categoryService,
        private readonly TagService $tagService,
        private readonly HtmlRenderingInterface $htmlRenderer,
        private readonly Lang $lang,
        private readonly CurrentConfig $currentConfig,
        private readonly InputValidator $inputValidator,
        private readonly Paths $paths,
        private readonly ?PersistentCache $persistentCache = null,
    ) {}

    public function render(): void
    {
        $template = $this->currentTemplate->get();

        $action = MaintenanceActionRequest::fromGlobals()->action;
        new MaintenanceActionDispatcher($this->redirectService, $this->urlService, $this->configService, $this->filesystemIntegrityChecker, $this->sessionService, $this->translator, $this->eventDispatcher, $this->pageState, $this->currentTemplate, $this->dbMaintenanceRepository, $this->activityService, $this->rateService, $this->categoryService, $this->tagService, $this->htmlRenderer, $this->lang, $this->currentConfig, $this->inputValidator, $this->paths, $this->persistentCache)
            ->dispatch($action);

        // +-------------------------------------------------------------------+
        // |                             template init                             |
        // +-------------------------------------------------------------------+

        $template->set_filenames([
            'maintenance' => 'maintenance_env.tpl',
        ]);

        $url_format = $this->urlService->getRootUrl() . 'admin.php?page=maintenance&amp;action=%s&amp;pwg_token=' . new CsrfService($this->currentConfig)->getToken();

        /** @var array<string, string> $purge_urls */
        $purge_urls = [];
        $purge_urls[$this->lang->t('All')] = sprintf($url_format, 'derivatives') . '&amp;type=all';
        foreach ($this->imageStdParams->get_defined_type_map() as $params) {
            $purge_urls[$this->lang->t($params->type)] = sprintf($url_format, 'derivatives') . '&amp;type=' . $params->type;
        }
        $purge_urls[$this->lang->t(ImageStdParams::CUSTOM)] = sprintf($url_format, 'derivatives') . '&amp;type=' . ImageStdParams::CUSTOM;

        $dbInfo = new DbInfo(DbConnection::build());
        $php_current_timestamp = date('Y-m-d H:i:s');
        $db_version = $dbInfo->version();
        $db_current_date = $dbInfo->currentDateTime();

        $containerInfo = ContainerDetector::detect();
        $container_name = $containerInfo->type;
        $container_version = $containerInfo->version;

        if (! in_array($container_name, ['Official', 'none'], true)) {
            $container_name = '(unofficial) ' . $container_name;
        }

        $cache_sizes = $this->currentConfig->cacheSizes();

        $time_elapsed_since_last_calc = null;
        if ($cache_sizes !== null && is_array($cache_sizes[3] ?? null) && (is_string($cache_sizes[3]['value'] ?? null) || is_int($cache_sizes[3]['value'] ?? null))) {
            $time_elapsed_since_last_calc = DateHelper::timeSince($cache_sizes[3]['value'], 'year');
        }

        // graphics library
        $graphics_library = PwgImage::get_graphics_library_label();
        $graphics_library_value = $graphics_library !== '' ? $graphics_library : null;

        $maint_unlock_gallery = null;
        $maint_lock_gallery = null;
        if ($this->currentConfig->galleryLocked()) {
            $maint_unlock_gallery = sprintf($url_format, 'unlock_gallery');
        } else {
            $maint_lock_gallery = sprintf($url_format, 'lock_gallery');
        }

        $installed_on_value = null;
        $installed_since_value = null;
        $installed_on = $this->installationStats->getInstallationDate();
        if (is_string($installed_on) && $installed_on !== '') {
            $installed_on_value = DateHelper::formatDate($installed_on, ['day', 'month', 'year']);
            $installed_since_value = DateHelper::timeSince($installed_on, 'day');
        }

        // +-------------------------------------------------------------------+
        // | Define advanced features                                              |
        // +-------------------------------------------------------------------+

        // $advanced_features is array of array composed of CAPTION & URL
        $advanced_features_event = $this->eventDispatcher->dispatchChange(new GetAdminAdvancedFeaturesLinks([]));

        $template->assignContext(new MaintenanceEnvPageContext(
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
            maintDerivatives: sprintf($url_format, 'derivatives'),
            purgeDerivatives: $purge_urls,
            helpUrl: $this->urlService->getRootUrl() . 'admin/popuphelp.php?page=maintenance',
            phpwgUrl: AppInfo::URL,
            pwgVersion: AppInfo::VERSION,
            checkUpgradeUrl: sprintf($url_format, 'check_upgrade'),
            os: PHP_OS,
            containerInfo: $container_name . ($container_version !== null && $container_version !== '' ? ' ' . $container_version : ''),
            phpVersion: PHP_VERSION,
            dbEngine: 'MySQL',
            dbVersion: $db_version,
            phpinfoUrl: sprintf($url_format, 'phpinfo'),
            phpCurrentTimestamp: $php_current_timestamp,
            dbCurrentDate: $db_current_date,
            cacheSizes: $cache_sizes,
            timeElapsedSinceLastCalc: $time_elapsed_since_last_calc,
            graphicsLibrary: $graphics_library_value,
            maintUnlockGallery: $maint_unlock_gallery,
            maintLockGallery: $maint_lock_gallery,
            installedOn: $installed_on_value,
            installedSince: $installed_since_value,
            advancedFeatures: $advanced_features_event->advancedFeatures,
        ));

        // +-------------------------------------------------------------------+
        // |                           sending html code                           |
        // +-------------------------------------------------------------------+

        $template->assign_var_from_handle('ADMIN_CONTENT', 'maintenance');
    }
}
