<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Cache\PersistentFileCache;

if (! defined('PHPWG_ROOT_PATH')) {
    die('Hacking attempt!');
}

// Bootstrap globals. Set by admin/maintenance.php (which dynamically
// includes this tab panel) or include/common.inc.php.
/**
 * @var array<string, mixed> $conf
 * @var array<string, mixed> $page
 * @var PersistentFileCache $persistent_cache
 * @var \Template $template
 */
global $conf, $page, $persistent_cache, $template;

include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
include_once PHPWG_ROOT_PATH . 'admin/include/image.class.php';

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+

check_status(ACCESS_ADMINISTRATOR);

if (isset($_GET['action'])) {
    check_pwg_token();
}

// +-----------------------------------------------------------------------+
// |                                actions                                |
// +-----------------------------------------------------------------------+

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'phpinfo':

        phpinfo();
        exit();

    case 'lock_gallery':

        conf_update_param('gallery_locked', 'true');
        redirect(get_root_url() . 'admin.php?page=maintenance');

        // no break
    case 'unlock_gallery':

        conf_update_param('gallery_locked', 'false');
        $_SESSION['page_infos'] = [l10n('Gallery unlocked')];
        redirect(get_root_url() . 'admin.php?page=maintenance');

        // no break
    case 'categories':

        images_integrity();
        categories_integrity();
        update_uppercats();
        update_category('all');
        update_global_rank();
        invalidate_user_cache(true);
        break;

    case 'images':

        images_integrity();
        update_path();
        include_once PHPWG_ROOT_PATH . 'include/functions_rate.inc.php';
        update_rating_score();
        invalidate_user_cache();
        break;

    case 'delete_orphan_tags':

        delete_orphan_tags();
        break;

    case 'user_cache':

        invalidate_user_cache();
        break;

    case 'history_detail':

        $query = '
DELETE
  FROM ' . HISTORY_TABLE . '
;';
        pwg_query($query);
        break;

    case 'history_summary':

        $query = '
DELETE
  FROM ' . HISTORY_SUMMARY_TABLE . '
;';
        pwg_query($query);
        break;

    case 'sessions':

        pwg_session_gc();

        // delete all sessions associated to invalid user ids (it should never happen)
        $query = '
SELECT
    id,
    data
  FROM ' . SESSIONS_TABLE . '
;';
        $sessions = query2array($query);

        // $conf['user_fields'] maps generic field names to actual DB column
        // names (see config_default.inc.php).
        /** @var array<string, string> $user_fields */
        $user_fields = $conf['user_fields'];

        $query = '
SELECT
    ' . $user_fields['id'] . ' AS id
  FROM ' . USERS_TABLE . '
;';
        $all_user_ids = query2array($query, 'id', null);

        $sessions_to_delete = [];

        foreach ($sessions as $session) {
            if ((bool) preg_match('/pwg_uid\|i:(\d+);/', (string) $session['data'], $matches)) {
                if (! isset($all_user_ids[$matches[1]])) {
                    $sessions_to_delete[] = $session['id'];
                }
            }
        }

        if (count($sessions_to_delete) > 0) {
            $query = '
DELETE
  FROM ' . SESSIONS_TABLE . '
  WHERE id IN (\'' . implode("','", $sessions_to_delete) . '\')
;';
            pwg_query($query);
        }

        break;

    case 'feeds':

        $query = '
DELETE
  FROM ' . USER_FEED_TABLE . '
  WHERE last_check IS NULL
;';
        pwg_query($query);
        break;

    case 'database':

        do_maintenance_all_tables();
        break;

    case 'c13y':

        include_once PHPWG_ROOT_PATH . 'admin/include/check_integrity.class.php';
        $c13y = new check_integrity();
        $c13y->maintenance();
        break;

    case 'search':

        $query = '
DELETE
  FROM ' . SEARCH_TABLE . '
;';
        pwg_query($query);
        break;

    case 'compiled-templates':

        $template->delete_compiled_templates();
        FileCombiner::clear_combined_files();
        $persistent_cache->purge(true);
        break;

    case 'derivatives':

        $derivative_type = $_GET['type'] ?? 'all';
        if (is_array($derivative_type)) {
            $derivative_type = array_values(array_filter($derivative_type, is_string(...)));
        } elseif (! is_string($derivative_type)) {
            $derivative_type = 'all';
        }
        clear_derivative_cache($derivative_type);
        break;

    case 'check_upgrade':

        // $page itself is only known as array<string, mixed>, so
        // $page['errors']/['infos'] need their own guard before the
        // nested pushes below.
        if (! is_array($page['errors'] ?? null)) {
            $page['errors'] = [];
        }
        if (! is_array($page['infos'] ?? null)) {
            $page['infos'] = [];
        }

        $result = '';
        if (! fetchRemote(PHPWG_URL . '/download/latest_version', $result)) {
            $page['errors'][] = l10n('Unable to check for upgrade.');
        } else {
            $versions = [
                'current' => PHPWG_VERSION,
            ];
            $lines = @explode("\r\n", (string) $result);

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

            if ($versions['latest'] == '') {
                $page['errors'][] = l10n('Check for upgrade failed for unknown reasons.');
            } elseif (version_compare($versions['current'], $versions['latest']) < 0) {
                $page['infos'][] = l10n('A new version of Piwigo is available.');

                $update_url = PHPWG_ROOT_PATH . 'admin.php?page=updates';
                $page['infos'][] = '<a href="' . $update_url . '">' . l10n('Update to Piwigo %s', $versions['latest']) . '</a>';
            } else {
                $page['infos'][] = l10n('You are running the latest version of Piwigo.');
            }
        }

        // no break
    default:

        break;

}

// +-----------------------------------------------------------------------+
// |                             template init                             |
// +-----------------------------------------------------------------------+

$template->set_filenames([
    'maintenance' => 'maintenance_env.tpl',
]);

$url_format = get_root_url() . 'admin.php?page=maintenance&amp;action=%s&amp;pwg_token=' . get_pwg_token();

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

if (! in_array($container_name, ['Official', 'none'])) {
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
    $time_elapsed_since_last_calc = time_since($cache_sizes[3]['value'], 'year');
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
        'PWG_VERSION' => PHPWG_VERSION,
        'U_CHECK_UPGRADE' => sprintf($url_format, 'check_upgrade'),
        'OS' => PHP_OS,
        'CONTAINER_INFO' => $container_name . (! empty($container_version) ? ' ' . $container_version : ''),
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
if (! empty($graphics_library)) {
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
            'INSTALLED_ON' => format_date($installed_on, ['day', 'month', 'year']),
            'INSTALLED_SINCE' => time_since($installed_on, 'day'),
        ]
    );
}

// +-----------------------------------------------------------------------+
// | Define advanced features                                              |
// +-----------------------------------------------------------------------+

$advanced_features = [];

// $advanced_features is array of array composed of CAPTION & URL
$advanced_features = trigger_change(
    'get_admin_advanced_features_links',
    $advanced_features
);

$template->assign('advanced_features', $advanced_features);

// +-----------------------------------------------------------------------+
// |                           sending html code                           |
// +-----------------------------------------------------------------------+

$template->assign_var_from_handle('ADMIN_CONTENT', 'maintenance');
