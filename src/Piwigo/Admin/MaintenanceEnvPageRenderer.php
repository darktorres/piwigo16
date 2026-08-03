<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Admin\Image\PwgImage;
use Piwigo\Admin\Maintenance\FilesystemIntegrityChecker;
use Piwigo\Admin\Maintenance\MaintenanceActionDispatcher;
use Piwigo\Admin\Maintenance\Request\MaintenanceActionRequest;
use Piwigo\Config\ConfigService;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Db\DbInfo;
use Piwigo\Event\Admin\GetAdminAdvancedFeaturesLinks;
use Piwigo\Image\ImageStdParams;
use Piwigo\Session\SessionService;
use Piwigo\Template\Template;

/**
 * Ported from admin/maintenance_env.php (the "env" tab of the "maintenance"
 * page slug, dispatched by MaintenanceSubController) -- server/DB info and
 * cache/storage stats; the only 2 actions this tab's own template actually
 * links to are `phpinfo`/`check_upgrade`.
 *
 * admin.php itself already gates every page behind
 * check_status(AccessLevel::Administrator) before dispatch, and the shell
 * (admin/maintenance.php, folded into MaintenanceSubController) already
 * gates every $_GET['action'] with check_pwg_token() before the tab-specific
 * dispatch even runs -- the original file's own SECOND copy of both checks
 * (redundant twice over: once against admin.php's blanket gate, once
 * against the shell's own per-tab gate) is dropped here.
 *
 * The action-dispatch switch itself moved to the shared
 * Piwigo\Admin\Maintenance\MaintenanceActionDispatcher (P23 batch 6h),
 * shared with MaintenanceActionsPageRenderer -- see that class's own
 * docblock for the 2 real drift bugs its consolidation fixed relative to
 * this file's own original copy (missing pwg_activity() logging, missing
 * the empty_lounge case).
 */
final class MaintenanceEnvPageRenderer
{
    public function __construct(
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
        private readonly ConfigService $configService,
        private readonly FilesystemIntegrityChecker $filesystemIntegrityChecker,
        private readonly SessionService $sessionService,
        private readonly \Piwigo\Lang\Translator $translator,
        private readonly \Piwigo\PluginConfig\EventDispatcher $eventDispatcher,
        private readonly ?\Piwigo\Cache\PersistentCache $persistentCache = null,
    ) {}

    public function render(): void
    {
        $template = \Piwigo\Template\CurrentTemplate::get();

        $action = MaintenanceActionRequest::fromGlobals()->action;
        new MaintenanceActionDispatcher($this->redirectService, $this->urlService, $this->configService, $this->filesystemIntegrityChecker, $this->sessionService, $this->translator, $this->eventDispatcher, $this->persistentCache)
            ->dispatch($action);

        // +-------------------------------------------------------------------+
        // |                             template init                             |
        // +-------------------------------------------------------------------+

        $template->set_filenames([
            'maintenance' => 'maintenance_env.tpl',
        ]);

        $url_format = $this->urlService->getRootUrl() . 'admin.php?page=maintenance&amp;action=%s&amp;pwg_token=' . new \Piwigo\Csrf\CsrfService()->getToken();

        /** @var array<string, string> $purge_urls */
        $purge_urls = [];
        $purge_urls[Lang::t('All')] = sprintf($url_format, 'derivatives') . '&amp;type=all';
        foreach (ImageStdParams::get_defined_type_map() as $params) {
            $purge_urls[Lang::t($params->type)] = sprintf($url_format, 'derivatives') . '&amp;type=' . $params->type;
        }
        $purge_urls[Lang::t(ImageStdParams::CUSTOM)] = sprintf($url_format, 'derivatives') . '&amp;type=' . ImageStdParams::CUSTOM;

        $dbInfo = new DbInfo(DbConnection::build());
        $php_current_timestamp = date('Y-m-d H:i:s');
        $db_version = $dbInfo->version();
        $db_current_date = $dbInfo->currentDateTime();

        [$container_name, $container_version] = \Piwigo\Core\ContainerDetector::detect();

        if (! in_array($container_name, ['Official', 'none'], true)) {
            $container_name = '(unofficial) ' . $container_name;
        }

        $cache_sizes = \Piwigo\Config\CurrentConfig::cacheSizes();

        $time_elapsed_since_last_calc = null;
        if ($cache_sizes !== null && is_array($cache_sizes[3] ?? null) && (is_string($cache_sizes[3]['value'] ?? null) || is_int($cache_sizes[3]['value'] ?? null))) {
            $time_elapsed_since_last_calc = \Piwigo\Core\DateHelper::timeSince($cache_sizes[3]['value'], 'year');
        }

        $template->assign(
            [
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
                'CONTAINER_INFO' => $container_name . ($container_version !== null && $container_version !== '' ? ' ' . $container_version : ''),
                'PHP_VERSION' => PHP_VERSION,
                'DB_ENGINE' => 'MySQL',
                'DB_VERSION' => $db_version,
                'U_PHPINFO' => sprintf($url_format, 'phpinfo'),
                'PHP_DATATIME' => $php_current_timestamp,
                'DB_DATATIME' => $db_current_date,
                'cache_sizes' => $cache_sizes,
                'time_elapsed_since_last_calc' => $time_elapsed_since_last_calc,
            ]
        );

        // graphics library
        $graphics_library = PwgImage::get_graphics_library_label();
        if ($graphics_library !== '') {
            $template->assign('GRAPHICS_LIBRARY', $graphics_library);
        }

        if (\Piwigo\Config\CurrentConfig::galleryLocked()) {
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

        $installed_on = InstallationStats::getInstallationDate();
        if (is_string($installed_on) && $installed_on !== '') {
            $template->assign(
                [
                    'INSTALLED_ON' => \Piwigo\Core\DateHelper::formatDate($installed_on, ['day', 'month', 'year']),
                    'INSTALLED_SINCE' => \Piwigo\Core\DateHelper::timeSince($installed_on, 'day'),
                ]
            );
        }

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
