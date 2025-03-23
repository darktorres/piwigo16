<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\admin\inc\check_integrity;
use Piwigo\admin\inc\functions_admin;
use Piwigo\admin\inc\pwg_image;
use Piwigo\inc\dblayer\functions_mysqli;
use Piwigo\inc\derivative_std_params;
use Piwigo\inc\FileCombiner;
use Piwigo\inc\functions;
use Piwigo\inc\functions_plugins;
use Piwigo\inc\functions_rate;
use Piwigo\inc\functions_session;
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;
use Piwigo\inc\ImageStdParams;

functions_admin::fs_quick_check();

// +-----------------------------------------------------------------------+
// |                                actions                                |
// +-----------------------------------------------------------------------+

$action = isset($_GET['action']) ? $_GET['action'] : '';
$register_activity = true;

switch ($action) {
    case 'phpinfo':
        phpinfo();
        exit();

    case 'lock_gallery':
        functions::conf_update_param('gallery_locked', 'true');
        functions::pwg_activity('system', ACTIVITY_SYSTEM_CORE, 'maintenance', [
            'maintenance_action' => $action,
        ]);
        functions::redirect(functions_url::get_root_url() . 'admin.php?page=maintenance');
        break;

    case 'unlock_gallery':
        functions::conf_update_param('gallery_locked', 'false');
        $_SESSION['page_infos'] = [functions::l10n('Gallery unlocked')];
        functions::pwg_activity('system', ACTIVITY_SYSTEM_CORE, 'maintenance', [
            'maintenance_action' => $action,
        ]);
        functions::redirect(functions_url::get_root_url() . 'admin.php?page=maintenance');
        break;

    case 'categories':
        functions_admin::images_integrity();
        functions_admin::categories_integrity();
        functions_admin::update_uppercats();
        functions_admin::update_category('all');
        functions_admin::update_global_rank();
        functions_admin::invalidate_user_cache(true);
        $page['infos'][] = sprintf('%s : %s', functions::l10n('Update albums information'), functions::l10n('action successfully performed.'));
        break;

    case 'images':
        functions_admin::images_integrity();
        functions_admin::update_path();
        functions_rate::update_rating_score();
        functions_admin::invalidate_user_cache();
        $page['infos'][] = sprintf('%s : %s', functions::l10n('Update photos information'), functions::l10n('action successfully performed.'));
        break;

    case 'delete_orphan_tags':
        functions_admin::delete_orphan_tags();
        $page['infos'][] = sprintf('%s : %s', functions::l10n('Delete orphan tags'), functions::l10n('action successfully performed.'));
        break;

    case 'user_cache':
        functions_admin::invalidate_user_cache();
        $page['infos'][] = sprintf('%s : %s', functions::l10n('Purge user cache'), functions::l10n('action successfully performed.'));
        break;

    case 'history_detail':
        $query = <<<SQL
            DELETE FROM history;
            SQL;
        functions_mysqli::pwg_query($query);
        $page['infos'][] = sprintf('%s : %s', functions::l10n('Purge history detail'), functions::l10n('action successfully performed.'));
        break;

    case 'history_summary':
        $query = <<<SQL
            DELETE FROM history_summary;
            SQL;
        functions_mysqli::pwg_query($query);
        $page['infos'][] = sprintf('%s : %s', functions::l10n('Purge history summary'), functions::l10n('action successfully performed.'));
        break;

    case 'sessions':
        functions_session::pwg_session_gc();

        // delete all sessions associated to invalid user ids (it should never happen)
        $query = <<<SQL
            SELECT id, data
            FROM sessions;
            SQL;
        $sessions = functions_mysqli::query2array($query);

        $query = <<<SQL
            SELECT {$conf['user_fields']['id']} AS id
            FROM users;
            SQL;
        $all_user_ids = functions_mysqli::query2array($query, 'id', null);

        $sessions_to_delete = [];

        foreach ($sessions as $session) {
            if (preg_match('/pwg_uid\|i:(\d+);/', $session['data'], $matches)) {
                if (! isset($all_user_ids[$matches[1]])) {
                    $sessions_to_delete[] = $session['id'];
                }
            }
        }

        if (count($sessions_to_delete) > 0) {
            $sessions_to_delete_str = implode("','", $sessions_to_delete);
            $query = <<<SQL
                DELETE FROM sessions
                WHERE id IN ('{$sessions_to_delete_str}');
                SQL;
            functions_mysqli::pwg_query($query);
        }

        $page['infos'][] = sprintf('%s : %s', functions::l10n('Purge sessions'), functions::l10n('action successfully performed.'));
        break;

    case 'feeds':
        $query = <<<SQL
            DELETE FROM user_feed
            WHERE last_check IS NULL;
            SQL;
        functions_mysqli::pwg_query($query);
        $page['infos'][] = sprintf('%s : %s', functions::l10n('Purge never used notification feeds'), functions::l10n('action successfully performed.'));
        break;

    case 'database':
        functions_mysqli::do_maintenance_all_tables();
        break;

    case 'c13y':
        $c13y = new check_integrity();
        $c13y->maintenance();
        $page['infos'][] = sprintf('%s : %s', functions::l10n('Reinitialize check integrity'), functions::l10n('action successfully performed.'));
        break;

    case 'search':
        $query = <<<SQL
            DELETE FROM search;
            SQL;
        functions_mysqli::pwg_query($query);
        sprintf('%s : %s', functions::l10n('Reinitialize check integrity'), functions::l10n('action successfully performed.'));
        break;

    case 'compiled-templates':
        $template->delete_compiled_templates();
        FileCombiner::clear_combined_files();
        $persistent_cache->purge(true);
        $page['infos'][] = sprintf('%s : %s', functions::l10n('Purge compiled templates'), functions::l10n('action successfully performed.'));
        break;

    case 'derivatives':
        $types_str = $_GET['type'];

        if ($types_str == 'all') {
            functions_admin::clear_derivative_cache($_GET['type']);
        } else {
            $types = explode('_', $types_str);
            foreach ($types as $type_to_clear) {
                functions_admin::clear_derivative_cache($type_to_clear);
            }
        }

        $page['infos'][] = functions::l10n('action successfully performed.');
        break;

    case 'check_upgrade':
        if (! functions_admin::fetchRemote(PHPWG_URL . '/download/latest_version', $result)) {
            $page['errors'][] = functions::l10n('Unable to check for upgrade.');
        } else {
            $versions = [
                'current' => PHPWG_VERSION,
            ];
            $lines = explode("\r\n", $result);

            // if the current version is a BSF (development branch) build, we check
            // the first line, for stable versions, we check the second line
            if (preg_match('/^BSF/', $versions['current'])) {
                $versions['latest'] = trim($lines[0]);

                // because integer are limited to 4,294,967,296 we need to split BSF
                // versions in date.time
                foreach ($versions as $key => $value) {
                    $versions[$key] = preg_replace('/BSF_(\d{8})(\d{4})/', '$1.$2', $value);
                }
            } else {
                $versions['latest'] = trim($lines[1]);
            }

            if ($versions['latest'] == '') {
                $page['errors'][] = functions::l10n('Check for upgrade failed for unknown reasons.');
            }
            // concatenation needed to avoid automatic transformation by release
            // script generator
            elseif ($versions['current'] == '%PWGVERSION%') {
                $page['infos'][] = functions::l10n('You are running on development sources, no check possible.');
            } elseif (version_compare($versions['current'], $versions['latest']) < 0) {
                $page['infos'][] = functions::l10n('A new version of Piwigo is available.');
            } else {
                $page['infos'][] = functions::l10n('You are running the latest version of Piwigo.');
            }
        }

        $page['infos'][] = functions::l10n('action successfully performed.');

        // no break

    default:
        $register_activity = false;
        break;

}

if ($register_activity) {
    functions::pwg_activity('system', ACTIVITY_SYSTEM_CORE, 'maintenance', [
        'maintenance_action' => $action,
    ]);
}

// +-----------------------------------------------------------------------+
// |                             template init                             |
// +-----------------------------------------------------------------------+

$template->set_filenames([
    'maintenance' => 'maintenance_actions.tpl',
]);
$pwg_token = functions::get_pwg_token();
$url_format = functions_url::get_root_url() . 'admin.php?page=maintenance&amp;action=%s&amp;pwg_token=' . functions::get_pwg_token();

if (! functions_user::is_webmaster()) {
    $page['warnings'][] = str_replace('%s', functions::l10n('user_status_webmaster'), functions::l10n('%s status is required to edit parameters.'));
}

$purge_urls[functions::l10n('All')] = 'all';

foreach (ImageStdParams::get_defined_type_map() as $params) {
    $purge_urls[functions::l10n($params->type)] = $params->type;
}

$purge_urls[functions::l10n(derivative_std_params::IMG_CUSTOM)] = derivative_std_params::IMG_CUSTOM;

$php_current_timestamp = date('Y-m-d H:i:s');
$db_version = functions_mysqli::pwg_get_db_version();
list($db_current_date) = functions_mysqli::pwg_db_fetch_row(functions_mysqli::pwg_query('SELECT NOW();'));

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
        'U_HELP' => functions_url::get_root_url() . 'admin/popuphelp.php?page=maintenance',

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
        'cache_sizes' => $conf['cache_sizes'] ?? null,
        'time_elapsed_since_last_calc' => (isset($conf['cache_sizes'])) ? functions::time_since($conf['cache_sizes'][3]['value'], 'year') : null,
    ]
);

// graphics library
switch (pwg_image::get_library()) {
    case 'imagick':
        $library = 'ImageMagick';
        $img = new Imagick();
        $version = $img->getVersion();

        if (preg_match('/ImageMagick \d+\.\d+\.\d+-?\d*/', $version['versionString'], $match)) {
            $library = $match[0];
        }

        $template->assign('GRAPHICS_LIBRARY', $library);
        break;

    case 'ext_imagick':
        $library = 'External ImageMagick';
        exec($conf['ext_imagick_dir'] . 'convert -version', $returnarray);

        if (preg_match('/Version: ImageMagick (\d+\.\d+\.\d+-?\d*)/', $returnarray[0], $match)) {
            $library .= ' ' . $match[1];
        }

        $template->assign('GRAPHICS_LIBRARY', $library);
        break;

    case 'gd':
        $gd_info = gd_info();
        $template->assign('GRAPHICS_LIBRARY', 'GD ' . $gd_info['GD Version']);
        break;

    case 'vips':
        $library = 'image_vips';
        $template->assign('GRAPHICS_LIBRARY', $library);
        break;
}

if ($conf['gallery_locked']) {
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

$template->assign('isWebmaster', (functions_user::is_webmaster()) ? 1 : 0);

// +-----------------------------------------------------------------------+
// | Define advanced features                                              |
// +-----------------------------------------------------------------------+

$advanced_features = [];

//$advanced_features is array of array composed of CAPTION & URL
$advanced_features = functions_plugins::trigger_change(
    'get_admin_advanced_features_links',
    $advanced_features
);

$template->assign('advanced_features', $advanced_features);

// +-----------------------------------------------------------------------+
// |                           sending html code                           |
// +-----------------------------------------------------------------------+

$template->assign_var_from_handle('ADMIN_CONTENT', 'maintenance');
