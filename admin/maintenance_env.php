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
use Piwigo\inc\derivative_std_params;
use Piwigo\inc\FileCombiner;
use Piwigo\inc\functions;
use Piwigo\inc\functions_plugins;
use Piwigo\inc\functions_rate;
use Piwigo\inc\functions_session;
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;
use Piwigo\inc\ImageStdParams;

if (! defined('PHPWG_ROOT_PATH')) {
    exit('Hacking attempt!');
}

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+

functions_user::check_status(ACCESS_ADMINISTRATOR);

if (isset($_GET['action'])) {
    functions::check_pwg_token();
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
        functions::conf_update_param('gallery_locked', 'true');
        functions::redirect(functions_url::get_root_url() . 'admin.php?page=maintenance');
        break;

    case 'unlock_gallery':
        functions::conf_update_param('gallery_locked', 'false');
        $_SESSION['page_infos'] = [functions::l10n('Gallery unlocked')];
        functions::redirect(functions_url::get_root_url() . 'admin.php?page=maintenance');
        break;

    case 'categories':
        functions_admin::images_integrity();
        functions_admin::categories_integrity();
        functions_admin::update_uppercats();
        functions_admin::update_category();
        functions_admin::update_global_rank();
        functions_admin::invalidate_user_cache();
        break;

    case 'images':
        functions_admin::images_integrity();
        functions_admin::update_path();
        functions_rate::update_rating_score();
        functions_admin::invalidate_user_cache();
        break;

    case 'delete_orphan_tags':
        functions_admin::delete_orphan_tags();
        break;

    case 'user_cache':
        functions_admin::invalidate_user_cache();
        break;

    case 'history_detail':
        $query = <<<SQL
            DELETE FROM history;
            SQL;
        $conf->sql_backend::pwg_query($query);
        break;

    case 'history_summary':
        $query = <<<SQL
            DELETE FROM history_summary;
            SQL;
        $conf->sql_backend::pwg_query($query);
        break;

    case 'sessions':
        functions_session::pwg_session_gc();

        // delete all sessions associated to invalid user ids (it should never happen)
        $query = <<<SQL
            SELECT id, data
            FROM sessions;
            SQL;
        $sessions = $conf->sql_backend::query2array($query);

        $query = <<<SQL
            SELECT {$conf->user_fields['id']} AS id
            FROM users;
            SQL;
        $all_user_ids = $conf->sql_backend::query2array($query, 'id');

        $sessions_to_delete = [];

        foreach ($sessions as $session) {
            if (preg_match('/pwg_uid\|i:(\d+);/', $session['data'], $matches) && ! isset($all_user_ids[$matches[1]])) {
                $sessions_to_delete[] = $session['id'];
            }
        }

        if ($sessions_to_delete !== []) {
            $sessions_to_delete_imploded = implode("','", $sessions_to_delete);
            $query = <<<SQL
                DELETE FROM sessions
                WHERE id IN ('{$sessions_to_delete_imploded}');
                SQL;
            $conf->sql_backend::pwg_query($query);
        }

        break;

    case 'feeds':
        $query = <<<SQL
            DELETE FROM user_feed
            WHERE last_check IS NULL;
            SQL;
        $conf->sql_backend::pwg_query($query);
        break;

    case 'database':
        $conf->sql_backend::do_maintenance_all_tables();
        break;

    case 'c13y':
        $c13y = new check_integrity();
        $c13y->maintenance();
        break;

    case 'search':
        $query = <<<SQL
            DELETE FROM search;
            SQL;
        $conf->sql_backend::pwg_query($query);
        break;

    case 'compiled-templates':
        $template->delete_compiled_templates();
        FileCombiner::clear_combined_files();
        $persistent_cache->purge(true);
        break;

    case 'derivatives':
        functions_admin::clear_derivative_cache($_GET['type']);
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
            if (str_starts_with($versions['current'], 'BSF')) {
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

                $update_url = './admin.php?page=updates';
                $page['infos'][] = '<a href="' . $update_url . '">' . functions::l10n('Update to Piwigo %s', $versions['latest']) . '</a>';
            } else {
                $page['infos'][] = functions::l10n('You are running the latest version of Piwigo.');
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

$url_format = functions_url::get_root_url() . 'admin.php?page=maintenance&amp;action=%s&amp;pwg_token=' . functions::get_pwg_token();

$purge_urls[functions::l10n('All')] = sprintf($url_format, 'derivatives') . '&amp;type=all';

foreach (ImageStdParams::get_defined_type_map() as $params) {
    $purge_urls[functions::l10n($params->type)] = sprintf($url_format, 'derivatives') . '&amp;type=' . $params->type;
}

$purge_urls[functions::l10n(derivative_std_params::IMG_CUSTOM)] = sprintf($url_format, 'derivatives') . '&amp;type=' . derivative_std_params::IMG_CUSTOM;

$php_current_timestamp = date('Y-m-d H:i:s');
$db_version = $conf->sql_backend::pwg_get_db_version();
[$db_current_date] = $conf->sql_backend::pwg_db_fetch_row($conf->sql_backend::pwg_query('SELECT NOW();'));

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
        'DB_ENGINE' => $conf->sql_backend::DB_ENGINE,
        'DB_VERSION' => $db_version,
        'U_PHPINFO' => sprintf($url_format, 'phpinfo'),
        'PHP_DATATIME' => $php_current_timestamp,
        'DB_DATATIME' => $db_current_date,
        'cache_sizes' => $conf->cache_sizes ?? null,
        'time_elapsed_since_last_calc' => (isset($conf->cache_sizes)) ? functions::time_since($conf->cache_sizes[3]['value'], 'year') : null,
    ]
);

// graphics library
switch (pwg_image::get_library()) {
    case 'imagick':
        $library = 'ImageMagick';
        $img = new Imagick();
        $version = Imagick::getVersion();

        if (preg_match('/ImageMagick \d+\.\d+\.\d+-?\d*/', $version['versionString'], $match)) {
            $library = $match[0];
        }

        $template->assign('GRAPHICS_LIBRARY', $library);
        break;

    case 'ext_imagick':
        $library = 'External ImageMagick';
        exec($conf->ext_imagick_dir . 'convert -version', $returnarray);

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

if ($conf->gallery_locked) {
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

$query = <<<SQL
    SELECT registration_date
    FROM user_infos
    WHERE user_id = 2;
    SQL;
$users = $conf->sql_backend::query2array($query);

if ($users !== []) {
    $installed_on = $users[0]['registration_date'];

    if (! empty($installed_on)) {
        $template->assign(
            [
                'INSTALLED_ON' => functions::format_date($installed_on, ['day', 'month', 'year']),
                'INSTALLED_SINCE' => functions::time_since($installed_on, 'day'),
            ]
        );
    }
}

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

// Assign Vite module filenames for dynamic loading
require_once __DIR__ . '/../inc/vite_helper.php';
\Piwigo\Vite\vite_assign_modules($template, ['maintenance', 'maintenance_env']);

$template->assign_var_from_handle('ADMIN_CONTENT', 'maintenance');
