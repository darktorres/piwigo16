<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Imagick;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\Image\PwgImage;
use Piwigo\Admin\Maintenance\DbMaintenanceRepository;
use Piwigo\Admin\Maintenance\FilesystemIntegrityChecker;
use Piwigo\Admin\Maintenance\MaintenanceActionDispatcher;
use Piwigo\Admin\Maintenance\Request\MaintenanceActionRequest;
use Piwigo\Admin\Projection\MaintenanceActionsPageContext;
use Piwigo\Auth\AccessControl;
use Piwigo\Cache\PersistentCache;
use Piwigo\Category\CategoryService;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\AppInfo;
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
final class MaintenanceActionsPageRenderer
{
    public function __construct(
        private readonly AccessControl $accessControl,
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
        private readonly CategoryService $categoryService,
        private readonly TagService $tagService,
        private readonly HtmlRenderingInterface $htmlRenderer,
        private readonly Lang $lang,
        private readonly CurrentConfig $currentConfig,
        private readonly InputValidator $inputValidator,
        private readonly Paths $paths,
        private readonly ?PersistentCache $persistentCache = null,
    ) {}

    /**
     * @param array<string, array{icon: string, label: string}> $maintActions
     */
    public function render(array $maintActions): void
    {
        $template = $this->currentTemplate->get();

        $this->filesystemIntegrityChecker->fsQuickCheck();

        $action = MaintenanceActionRequest::fromGlobals()->action;
        new MaintenanceActionDispatcher($this->redirectService, $this->urlService, $this->configService, $this->filesystemIntegrityChecker, $this->sessionService, $this->translator, $this->eventDispatcher, $this->pageState, $this->currentTemplate, $this->dbMaintenanceRepository, $this->activityService, $this->rateService, $this->categoryService, $this->tagService, $this->htmlRenderer, $this->lang, $this->currentConfig, $this->inputValidator, $this->paths, $this->persistentCache)
            ->dispatch($action);

        // +-------------------------------------------------------------------+
        // |                             template init                             |
        // +-------------------------------------------------------------------+

        $template->set_filenames([
            'maintenance' => 'maintenance_actions.tpl',
        ]);
        $pwg_token = new CsrfService($this->currentConfig)
            ->getToken();
        $url_format = $this->urlService->getRootUrl() . 'admin.php?page=maintenance&amp;action=%s&amp;pwg_token=' . new CsrfService($this->currentConfig)->getToken();

        if (! $this->accessControl->isWebmaster()) {
            $this->pageState->addWarning(str_replace('%s', $this->lang->t('user_status_webmaster'), $this->lang->t('%s status is required to edit parameters.')));
        }

        /** @var array<string, string> $purge_urls */
        $purge_urls = [];
        $purge_urls[$this->lang->t('All')] = 'all';
        foreach ($this->imageStdParams->get_defined_type_map() as $params) {
            $purge_urls[$this->lang->t($params->type)] = $params->type;
        }
        $purge_urls[$this->lang->t(ImageStdParams::CUSTOM)] = ImageStdParams::CUSTOM;

        $dbInfo = new DbInfo(DbConnection::build());
        $php_current_timestamp = date('Y-m-d H:i:s');
        $db_version = $dbInfo->version();
        $db_current_date = $dbInfo->currentDateTime();

        // \Piwigo\Config\CurrentConfig::cacheSizes() is a serialized 4-row [name, value] list produced by
        // ws_getCacheSize() (cache_size, msizes, tsizes, last_date_calc); row 3's
        // value is the last_date_calc date string used for time_since().
        // Real bug found via PHPStan: CurrentConfig::cacheSizes() already unserializes
        // internally and returns array|null, so the is_string()/unserialize()
        // dance that used to live here was permanently dead -- $cache_sizes was
        // always null, meaning $time_elapsed_since_last_calc below never
        // actually populated.
        $cache_sizes = $this->currentConfig->cacheSizes;
        $time_elapsed_since_last_calc = null;
        if ($cache_sizes !== null) {
            $last_calc_row = $cache_sizes[3] ?? null;
            if (is_array($last_calc_row)) {
                $last_calc_value = $last_calc_row['value'] ?? null;
                if (is_int($last_calc_value) || is_string($last_calc_value)) {
                    $time_elapsed_since_last_calc = DateHelper::timeSince($last_calc_value, 'year');
                }
            }
        }

        // graphics library
        $graphics_library = null;
        switch (PwgImage::get_library()) {
            case 'ext_imagick':
                $library = 'External ImageMagick';
                $ext_imagick_dir = $this->currentConfig->extImagickDir;
                $returnarray = [];
                exec($ext_imagick_dir . PwgImage::get_ext_imagick_command() . ' -version', $returnarray);
                $returnarray_line0 = $returnarray[0] ?? '';
                if ((bool) preg_match('/Version: ImageMagick (\d+\.\d+\.\d+-?\d*)/', $returnarray_line0, $match)) {
                    $library .= ' ' . $match[1];
                }
                $graphics_library = $library;
                break;

            case 'imagick':
                $library = 'ImageMagick';
                $version = Imagick::getVersion();
                if ((bool) preg_match('/ImageMagick \d+\.\d+\.\d+-?\d*/', $version['versionString'], $match)) {
                    $library = $match[0];
                }
                $graphics_library = $library;
                break;

            case 'gd':
                $gd_info = gd_info();
                $gd_version = $gd_info['GD Version'] ?? null;
                $gd_version = is_string($gd_version) ? $gd_version : '';
                $graphics_library = 'GD ' . $gd_version;
                break;
        }

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

        // +-------------------------------------------------------------------+
        // | Define advanced features                                              |
        // +-------------------------------------------------------------------+

        // $advanced_features is array of array composed of CAPTION & URL
        $advanced_features_event = $this->eventDispatcher->dispatchChange(new GetAdminAdvancedFeaturesLinks([]));

        $template->assignContext(new MaintenanceActionsPageContext(
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
            maintDerivatives: sprintf($url_format, 'derivatives'),
            purgeDerivatives: $purge_urls,
            helpUrl: $this->urlService->getRootUrl() . 'admin/popuphelp.php?page=maintenance',
            phpwgUrl: AppInfo::URL,
            pwgVersion: AppInfo::VERSION,
            checkUpgradeUrl: sprintf($url_format, 'check_upgrade'),
            os: PHP_OS,
            phpVersion: PHP_VERSION,
            dbEngine: 'MySQL',
            dbVersion: $db_version,
            phpinfoUrl: sprintf($url_format, 'phpinfo'),
            phpCurrentTimestamp: $php_current_timestamp,
            dbCurrentDate: $db_current_date,
            pwgToken: $pwg_token,
            cacheSizes: $cache_sizes,
            timeElapsedSinceLastCalc: $time_elapsed_since_last_calc,
            graphicsLibrary: $graphics_library,
            maintUnlockGallery: $maint_unlock_gallery,
            maintLockGallery: $maint_lock_gallery,
            uEmptyLounge: $u_empty_lounge,
            loungeCounter: $lounge_counter,
            isWebmaster: ($this->accessControl->isWebmaster()) ? 1 : 0,
            advancedFeatures: $advanced_features_event->advancedFeatures,
        ));

        // +-------------------------------------------------------------------+
        // |                           sending html code                           |
        // +-------------------------------------------------------------------+

        $template->assign_var_from_handle('ADMIN_CONTENT', 'maintenance');
    }
}
