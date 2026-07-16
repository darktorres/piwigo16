<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Admin\Maintenance\MaintenanceActionDispatcher;
use Piwigo\Core\AppInfo;
use Piwigo\Image\ImageStdParams;
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
    public function render(): void
    {
        /**
         * @var array<string, mixed> $conf
         * @var Template $template
         */
        global $conf, $template;

        include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

        $action = is_string($_GET['action'] ?? null) ? $_GET['action'] : '';
        new MaintenanceActionDispatcher()
            ->dispatch($action);

        // +-------------------------------------------------------------------+
        // |                             template init                             |
        // +-------------------------------------------------------------------+

        $template->set_filenames([
            'maintenance' => 'maintenance_env.tpl',
        ]);

        $url_format = get_root_url() . 'admin.php?page=maintenance&amp;action=%s&amp;pwg_token=' . (new \Piwigo\Csrf\CsrfService())->getToken();

        /** @var array<string, string> $purge_urls */
        $purge_urls = [];
        $purge_urls[l10n('All')] = sprintf($url_format, 'derivatives') . '&amp;type=all';
        foreach (ImageStdParams::get_defined_type_map() as $params) {
            $purge_urls[l10n($params->type)] = sprintf($url_format, 'derivatives') . '&amp;type=' . $params->type;
        }
        $purge_urls[l10n(IMG_CUSTOM)] = sprintf($url_format, 'derivatives') . '&amp;type=' . IMG_CUSTOM;

        $php_current_timestamp = date('Y-m-d H:i:s');
        $db_version = pwg_get_db_version();
        $row = pwg_db_fetch_row(pwg_query('SELECT now();'));
        assert($row !== null);
        [$db_current_date] = $row;

        [$container_name, $container_version] = get_container_info();

        if (! in_array($container_name, ['Official', 'none'], true)) {
            $container_name = '(unofficial) ' . $container_name;
        }

        // $conf['cache_sizes'] is normally the serialized string as loaded from the
        // config table, but conf_update_param(..., true) can also leave the raw
        // array in place within the same request.
        $cache_sizes = null;
        if (isset($conf['cache_sizes'])) {
            if (is_string($conf['cache_sizes'])) {
                $unserialized_cache_sizes = unserialize($conf['cache_sizes']);
                if (is_array($unserialized_cache_sizes)) {
                    $cache_sizes = $unserialized_cache_sizes;
                }
            } elseif (is_array($conf['cache_sizes'])) {
                $cache_sizes = $conf['cache_sizes'];
            }
        }

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
                'U_HELP' => get_root_url() . 'admin/popuphelp.php?page=maintenance',

                'PHPWG_URL' => PHPWG_URL,
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
        $graphics_library = get_graphics_library_label();
        if ($graphics_library !== '') {
            $template->assign('GRAPHICS_LIBRARY', $graphics_library);
        }

        if ((bool) $conf['gallery_locked']) {
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

        $installed_on = get_installation_date();
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

        $advanced_features = [];

        // $advanced_features is array of array composed of CAPTION & URL
        $advanced_features = trigger_change(
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
