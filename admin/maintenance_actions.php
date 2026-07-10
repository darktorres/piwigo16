<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Admin\Image\pwg_image;
use Piwigo\Cache\PersistentFileCache;
use Piwigo\Image\ImageStdParams;

// Bootstrap globals. $maint_actions is set by admin/maintenance.php before
// dynamically including this tab panel; the rest by include/common.inc.php.
/**
 * @var array<string, mixed> $conf
 * @var PersistentFileCache $persistent_cache
 * @var \Template $template
 */
global $conf, $maint_actions, $persistent_cache, $template;

/** @var array<string, mixed> $page */
if (! is_array($page['infos'] ?? null)) {
    $page['infos'] = [];
}
if (! is_array($page['errors'] ?? null)) {
    $page['errors'] = [];
}
if (! is_array($page['warnings'] ?? null)) {
    $page['warnings'] = [];
}

fs_quick_check();

// +-----------------------------------------------------------------------+
// |                                actions                                |
// +-----------------------------------------------------------------------+

$action = $_GET['action'] ?? '';
$register_activity = true;

switch ($action) {
    case 'phpinfo':

        phpinfo();
        exit();

    case 'lock_gallery':

        conf_update_param('gallery_locked', 'true');
        pwg_activity('system', ACTIVITY_SYSTEM_CORE, 'maintenance', [
            'maintenance_action' => $action,
        ]);
        redirect(get_root_url() . 'admin.php?page=maintenance');

        // no break
    case 'unlock_gallery':

        conf_update_param('gallery_locked', 'false');
        $_SESSION['page_infos'] = [l10n('Gallery unlocked')];
        pwg_activity('system', ACTIVITY_SYSTEM_CORE, 'maintenance', [
            'maintenance_action' => $action,
        ]);
        redirect(get_root_url() . 'admin.php?page=maintenance');

        // no break
    case 'categories':

        images_integrity();
        categories_integrity();
        update_uppercats();
        update_category('all');
        update_global_rank();
        invalidate_user_cache(true);
        $page['infos'][] = sprintf('%s : %s', l10n('Update albums informations'), l10n('action successfully performed.'));
        break;

    case 'images':

        images_integrity();
        update_path();
        include_once PHPWG_ROOT_PATH . 'include/functions_rate.inc.php';
        update_rating_score();
        invalidate_user_cache();
        $page['infos'][] = sprintf('%s : %s', l10n('Update photos information'), l10n('action successfully performed.'));
        break;

    case 'delete_orphan_tags':

        delete_orphan_tags();
        $page['infos'][] = sprintf('%s : %s', l10n('Delete orphan tags'), l10n('action successfully performed.'));
        break;

    case 'user_cache':

        invalidate_user_cache();
        $page['infos'][] = sprintf('%s : %s', l10n('Purge user cache'), l10n('action successfully performed.'));
        break;

    case 'history_detail':

        $query = '
DELETE
  FROM ' . HISTORY_TABLE . '
;';
        pwg_query($query);
        $page['infos'][] = sprintf('%s : %s', l10n('Purge history detail'), l10n('action successfully performed.'));
        break;

    case 'history_summary':

        $query = '
DELETE
  FROM ' . HISTORY_SUMMARY_TABLE . '
;';
        pwg_query($query);
        $page['infos'][] = sprintf('%s : %s', l10n('Purge history summary'), l10n('action successfully performed.'));
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
        // names (see include/config_default.inc.php); its values are
        // configuration-supplied, not statically typed, hence the fallback.
        $user_fields = $conf['user_fields'] ?? null;
        $user_fields = is_array($user_fields) ? $user_fields : [];
        $id_field = is_string($user_fields['id'] ?? null) ? $user_fields['id'] : 'id';

        $query = '
SELECT
    ' . $id_field . ' AS id
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
        $page['infos'][] = sprintf('%s : %s', l10n('Purge sessions'), l10n('action successfully performed.'));
        break;

    case 'feeds':

        $query = '
DELETE
  FROM ' . USER_FEED_TABLE . '
  WHERE last_check IS NULL
;';
        pwg_query($query);
        $page['infos'][] = sprintf('%s : %s', l10n('Purge never used notification feeds'), l10n('action successfully performed.'));
        break;

    case 'database':

        do_maintenance_all_tables();
        break;

    case 'c13y':

        include_once PHPWG_ROOT_PATH . 'admin/include/check_integrity.class.php';
        $c13y = new check_integrity();
        $c13y->maintenance();
        $page['infos'][] = sprintf('%s : %s', l10n('Reinitialize check integrity'), l10n('action successfully performed.'));
        break;

    case 'empty_lounge':

        $rows = empty_lounge();
        $page['infos'][] = sprintf('%d photos were moved from the upload lounge to their albums', count($rows ?? []));
        break;

    case 'search':

        $query = '
DELETE
  FROM ' . SEARCH_TABLE . '
;';
        pwg_query($query);
        sprintf('%s : %s', l10n('Reinitialize check integrity'), l10n('action successfully performed.'));
        break;

    case 'compiled-templates':

        $template->delete_compiled_templates();
        FileCombiner::clear_combined_files();
        $persistent_cache->purge(true);
        $page['infos'][] = sprintf('%s : %s', l10n('Purge compiled templates'), l10n('action successfully performed.'));
        break;

    case 'derivatives':

        $types_str = $_GET['type'] ?? '';
        $types_str = is_string($types_str) ? $types_str : '';
        if ($types_str == 'all') {
            clear_derivative_cache($types_str);
        } else {
            $types = explode('_', $types_str);
            foreach ($types as $type_to_clear) {
                clear_derivative_cache($type_to_clear);
            }
        }
        $page['infos'][] = l10n('action successfully performed.');
        break;

    case 'check_upgrade':

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
            } else {
                $page['infos'][] = l10n('You are running the latest version of Piwigo.');
            }
        }
        $page['infos'][] = l10n('action successfully performed.');

        // no break
    default:

        $register_activity = false;
        break;

}

if ($register_activity) {
    pwg_activity('system', ACTIVITY_SYSTEM_CORE, 'maintenance', [
        'maintenance_action' => $action,
    ]);
}

// +-----------------------------------------------------------------------+
// |                             template init                             |
// +-----------------------------------------------------------------------+

$template->set_filenames([
    'maintenance' => 'maintenance_actions.tpl',
]);
$pwg_token = get_pwg_token();
$url_format = get_root_url() . 'admin.php?page=maintenance&amp;action=%s&amp;pwg_token=' . get_pwg_token();

if (! is_webmaster()) {
    $page['warnings'][] = str_replace('%s', l10n('user_status_webmaster'), l10n('%s status is required to edit parameters.'));
}

/** @var array<string, string> $purge_urls */
$purge_urls = [];
$purge_urls[l10n('All')] = 'all';
foreach (ImageStdParams::get_defined_type_map() as $params) {
    $purge_urls[l10n($params->type)] = $params->type;
}
$purge_urls[l10n(IMG_CUSTOM)] = IMG_CUSTOM;

$php_current_timestamp = date('Y-m-d H:i:s');
$db_version = pwg_get_db_version();
$row = pwg_db_fetch_row(pwg_query('SELECT now();'));
assert($row !== null);
[$db_current_date] = $row;

// $conf['cache_sizes'] is a serialized 4-row [name, value] list produced by
// ws_getCacheSize() (cache_size, msizes, tsizes, last_date_calc); row 3's
// value is the last_date_calc date string used for time_since().
$cache_sizes_raw = $conf['cache_sizes'] ?? null;
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
            $time_elapsed_since_last_calc = time_since($last_calc_value, 'year');
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
        'PWG_VERSION' => PHPWG_VERSION,
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
        $ext_imagick_dir = $conf['ext_imagick_dir'] ?? null;
        $ext_imagick_dir = is_string($ext_imagick_dir) ? $ext_imagick_dir : '';
        exec($ext_imagick_dir . pwg_image::get_ext_imagick_command() . ' -version', $returnarray);
        if ((bool) preg_match('/Version: ImageMagick (\d+\.\d+\.\d+-?\d*)/', $returnarray[0], $match)) {
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

$query = '
SELECT
    COUNT(*)
  FROM ' . LOUNGE_TABLE . '
;';
$row = pwg_db_fetch_row(pwg_query($query));
assert($row !== null);
[$nb_lounge] = $row;

if ($nb_lounge > 0) {
    $template->assign(
        [
            'U_EMPTY_LOUNGE' => sprintf($url_format, 'empty_lounge'),
            'LOUNGE_COUNTER' => $nb_lounge,
        ]
    );
}

$template->assign('isWebmaster', (is_webmaster()) ? 1 : 0);

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
