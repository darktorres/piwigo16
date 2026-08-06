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
 * admin.php itself already gates every page behind
 * check_status(AccessLevel::Administrator) before dispatch; the shell
 * (admin/maintenance.php, folded into MaintenanceSubController) already
 * gates every $_GET['action'] with check_pwg_token() -- this file never had
 * its own copy of either check, so nothing to drop here (unlike
 * maintenance_env.php, see MaintenanceEnvPageRenderer's own docblock).
 *
 * The action-dispatch switch itself moved to the shared
 * Piwigo\Admin\Maintenance\MaintenanceActionDispatcher (P23 batch 6h) --
 * see that class's own docblock for the 2 real drift bugs its
 * consolidation fixed relative to maintenance_env.php's own copy.
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
        $cache_sizes = $this->currentConfig->cacheSizes();
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

        $template->assign(
            [
                'maint_actions' => $maintActions,
                'U_MAINT_CATEGORIES' => sprintf($url_format, 'categories'),
                'U_MAINT_IMAGES' => sprintf($url_format, 'images'),
                'U_MAINT_ORPHAN_TAGS' => sprintf($url_format, 'delete_orphan_tags'),
                'U_MAINT_USER_CACHE' => sprintf($url_format, 'user_cache'),
                'U_MAINT_HISTORY_DETAIL' => sprintf($url_format, 'history_detail'),
                'U_MAINT_HISTORY_SUMMARY' => sprintf($url_format, 'history_summary'),
                'U_MAINT_SESSIONS' => sprintf($url_format, 'sessions'),
                'U_MAINT_FEEDS' => sprintf($url_format, 'feeds'),
                'U_MAINT_DATABASE' => sprintf($url_format, 'database'),
                'U_MAINT_C13Y' => sprintf($url_format, 'c13y'),
                'U_MAINT_SEARCH' => sprintf($url_format, 'search'),
                'U_MAINT_COMPILED_TEMPLATES' => sprintf($url_format, 'compiled-templates'),
                'U_MAINT_DERIVATIVES' => sprintf($url_format, 'derivatives'),
                'purge_derivatives' => $purge_urls,
                'U_HELP' => $this->urlService->getRootUrl() . 'admin/popuphelp.php?page=maintenance',

                'PHPWG_URL' => AppInfo::URL,
                'PWG_VERSION' => AppInfo::VERSION,
                'U_CHECK_UPGRADE' => sprintf($url_format, 'check_upgrade'),
                'OS' => PHP_OS,
                'PHP_VERSION' => PHP_VERSION,
                'DB_ENGINE' => 'MySQL',
                'DB_VERSION' => $db_version,
                'U_PHPINFO' => sprintf($url_format, 'phpinfo'),
                'PHP_DATATIME' => $php_current_timestamp,
                'DB_DATATIME' => $db_current_date,
                'pwg_token' => $pwg_token,
                'cache_sizes' => $cache_sizes,
                'time_elapsed_since_last_calc' => $time_elapsed_since_last_calc,
            ]
        );

        // graphics library
        switch (PwgImage::get_library()) {
            case 'ext_imagick':
                $library = 'External ImageMagick';
                $ext_imagick_dir = $this->currentConfig->extImagickDir();
                $returnarray = [];
                exec($ext_imagick_dir . PwgImage::get_ext_imagick_command() . ' -version', $returnarray);
                $returnarray_line0 = $returnarray[0] ?? '';
                if ((bool) preg_match('/Version: ImageMagick (\d+\.\d+\.\d+-?\d*)/', $returnarray_line0, $match)) {
                    $library .= ' ' . $match[1];
                }
                $template->assign('GRAPHICS_LIBRARY', $library);
                break;

            case 'imagick':
                $library = 'ImageMagick';
                $version = Imagick::getVersion();
                if ((bool) preg_match('/ImageMagick \d+\.\d+\.\d+-?\d*/', $version['versionString'], $match)) {
                    $library = $match[0];
                }
                $template->assign('GRAPHICS_LIBRARY', $library);
                break;

            case 'gd':
                $gd_info = gd_info();
                $gd_version = $gd_info['GD Version'] ?? null;
                $gd_version = is_string($gd_version) ? $gd_version : '';
                $template->assign('GRAPHICS_LIBRARY', 'GD ' . $gd_version);
                break;
        }

        if ($this->currentConfig->galleryLocked()) {
            $template->assign(
                [
                    'U_MAINT_UNLOCK_GALLERY' => sprintf($url_format, 'unlock_gallery'),
                ]
            );
        } else {
            $template->assign(
                [
                    'U_MAINT_LOCK_GALLERY' => sprintf($url_format, 'lock_gallery'),
                ]
            );
        }

        $db_maintenance = $this->dbMaintenanceRepository;
        $nb_lounge = $db_maintenance->countLoungeItems();

        if ($nb_lounge > 0) {
            $template->assign(
                [
                    'U_EMPTY_LOUNGE' => sprintf($url_format, 'empty_lounge'),
                    'LOUNGE_COUNTER' => $nb_lounge,
                ]
            );
        }

        $template->assign('isWebmaster', ($this->accessControl->isWebmaster()) ? 1 : 0);

        // +-------------------------------------------------------------------+
        // | Define advanced features                                              |
        // +-------------------------------------------------------------------+

        // $advanced_features is array of array composed of CAPTION & URL
        $advanced_features_event = $this->eventDispatcher->dispatchChange(new GetAdminAdvancedFeaturesLinks([]));

        $template->assign('advanced_features', $advanced_features_event->advancedFeatures);

        // +-------------------------------------------------------------------+
        // |                           sending html code                           |
        // +-------------------------------------------------------------------+

        $template->assign_var_from_handle('ADMIN_CONTENT', 'maintenance');
    }
}
