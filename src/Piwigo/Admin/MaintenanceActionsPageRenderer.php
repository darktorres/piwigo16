<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Imagick;
use Piwigo\Admin\Image\pwg_image;
use Piwigo\Admin\Maintenance\DbMaintenanceRepository;
use Piwigo\Admin\Maintenance\FilesystemIntegrityChecker;
use Piwigo\Admin\Maintenance\MaintenanceActionDispatcher;
use Piwigo\Core\AppInfo;
use Piwigo\Db\DbConnection;
use Piwigo\Db\DbInfo;
use Piwigo\Image\ImageStdParams;
use Piwigo\Template\Template;

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
    public function render(): void
    {
        /**
         * @var array<string, mixed>
         */
        global $maint_actions;
        $template = \Piwigo\Template\CurrentTemplate::get();

        FilesystemIntegrityChecker::fsQuickCheck();

        $action = is_string($_GET['action'] ?? null) ? $_GET['action'] : '';
        new MaintenanceActionDispatcher()
            ->dispatch($action);

        // +-------------------------------------------------------------------+
        // |                             template init                             |
        // +-------------------------------------------------------------------+

        $template->set_filenames([
            'maintenance' => 'maintenance_actions.tpl',
        ]);
        $pwg_token = new \Piwigo\Csrf\CsrfService()
            ->getToken();
        $url_format = get_root_url() . 'admin.php?page=maintenance&amp;action=%s&amp;pwg_token=' . new \Piwigo\Csrf\CsrfService()->getToken();

        if (! \Piwigo\Auth\AccessControl::isWebmaster()) {
            \Piwigo\Core\PageState::current()->addWarning(str_replace('%s', l10n('user_status_webmaster'), l10n('%s status is required to edit parameters.')));
        }

        /** @var array<string, string> $purge_urls */
        $purge_urls = [];
        $purge_urls[l10n('All')] = 'all';
        foreach (ImageStdParams::get_defined_type_map() as $params) {
            $purge_urls[l10n($params->type)] = $params->type;
        }
        $purge_urls[l10n(ImageStdParams::CUSTOM)] = ImageStdParams::CUSTOM;

        $conn = DbConnection::build();
        $php_current_timestamp = date('Y-m-d H:i:s');
        $db_version = new DbInfo($conn)
            ->version();
        $row = $conn->fetchNumeric('SELECT now();');
        $db_current_date = $row !== false ? $row[0] : null;

        // \Piwigo\Config\Config::cacheSizes() is a serialized 4-row [name, value] list produced by
        // ws_getCacheSize() (cache_size, msizes, tsizes, last_date_calc); row 3's
        // value is the last_date_calc date string used for time_since().
        $cache_sizes_raw = \Piwigo\Config\Config::cacheSizes() ?? null;
        $cache_sizes = is_string($cache_sizes_raw) ? unserialize($cache_sizes_raw) : null;
        if (! is_array($cache_sizes)) {
            $cache_sizes = null;
        }
        $time_elapsed_since_last_calc = null;
        if ($cache_sizes !== null) {
            $last_calc_row = $cache_sizes[3] ?? null;
            if (is_array($last_calc_row)) {
                $last_calc_value = $last_calc_row['value'] ?? null;
                if (is_int($last_calc_value) || is_string($last_calc_value)) {
                    $time_elapsed_since_last_calc = \Piwigo\Core\DateHelper::timeSince($last_calc_value, 'year');
                }
            }
        }

        $template->assign(
            [
                'maint_actions' => $maint_actions,
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
                'U_HELP' => get_root_url() . 'admin/popuphelp.php?page=maintenance',

                'PHPWG_URL' => PHPWG_URL,
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
        switch (pwg_image::get_library()) {
            case 'ext_imagick':
                $library = 'External ImageMagick';
                $ext_imagick_dir = \Piwigo\Config\Config::extImagickDir();
                $returnarray = [];
                exec($ext_imagick_dir . pwg_image::get_ext_imagick_command() . ' -version', $returnarray);
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

        if (\Piwigo\Config\Config::galleryLocked()) {
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

        $db_maintenance = new DbMaintenanceRepository($conn);
        $nb_lounge = $db_maintenance->countLoungeItems();

        if ($nb_lounge > 0) {
            $template->assign(
                [
                    'U_EMPTY_LOUNGE' => sprintf($url_format, 'empty_lounge'),
                    'LOUNGE_COUNTER' => $nb_lounge,
                ]
            );
        }

        $template->assign('isWebmaster', (\Piwigo\Auth\AccessControl::isWebmaster()) ? 1 : 0);

        // +-------------------------------------------------------------------+
        // | Define advanced features                                              |
        // +-------------------------------------------------------------------+

        $advanced_features = [];

        // $advanced_features is array of array composed of CAPTION & URL
        $advanced_features = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange(
            'get_admin_advanced_features_links',
            $advanced_features
        );

        $template->assign('advanced_features', $advanced_features);

        // +-------------------------------------------------------------------+
        // |                           sending html code                           |
        // +-------------------------------------------------------------------+

        $template->assign_var_from_handle('ADMIN_CONTENT', 'maintenance');
    }
}
