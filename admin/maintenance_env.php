<?php

declare(strict_types=1);

use Piwigo\Exception\AuthException;
use Piwigo\Core\ServiceLocator;
use Piwigo\History\HistoryRepository;
use Piwigo\Users\UserRepository;
use Piwigo\Session\SessionRepository;
use Piwigo\Config\Config;
use Piwigo\Admin\MaintenanceService;
use Piwigo\Search\SearchRepository;
use Piwigo\Core\PageState;
use Piwigo\Db\DbInfo;
use Piwigo\Admin\Integrity\CheckIntegrity;
use Piwigo\Image\ImageStdParams;
use Piwigo\Template\FileCombiner;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

if (!defined('PHPWG_ROOT_PATH')) {
    throw new AuthException('Hacking attempt!');
}

global $template, $user, $page, $persistent_cache, $lang;


require_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

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
        {
            phpinfo();
            exit();
        }
    case 'lock_gallery':
        {
            conf_update_param('gallery_locked', 'true');
            redirect(get_root_url().'admin.php?page=maintenance');
            break;
        }
    case 'unlock_gallery':
        {
            conf_update_param('gallery_locked', 'false');
            $_SESSION['page_infos'] = [l10n('Gallery unlocked')];
            redirect(get_root_url().'admin.php?page=maintenance');
            break;
        }
    case 'categories':
        {
            images_integrity();
            categories_integrity();
            update_uppercats();
            update_category('all');
            update_global_rank();
            invalidate_user_cache(true);
            break;
        }
    case 'images':
        {
            images_integrity();
            update_path();
            update_rating_score();
            invalidate_user_cache();
            break;
        }
    case 'delete_orphan_tags':
        {
            delete_orphan_tags();
            break;
        }
    case 'user_cache':
        {
            invalidate_user_cache();
            break;
        }
    case 'history_detail':
        {
            ServiceLocator::get(HistoryRepository::class)->deleteAll();
            break;
        }
    case 'history_summary':
        {
            ServiceLocator::get(HistoryRepository::class)->deleteAllSummary();
            break;
        }
    case 'sessions':
        {
            pwg_session_gc();

            // delete all sessions associated to invalid user ids (it should never happen)
            $userRepo    = ServiceLocator::get(UserRepository::class);
            $sessionRepo = ServiceLocator::get(SessionRepository::class);

            $sessions     = $userRepo->findAllSessions();
            $all_user_ids = $userRepo->findAllUserIdsAsSet(
                Config::userFields()['id'],
                USERS_TABLE
            );

            $sessions_to_delete = [];
            foreach ($sessions as $session) {
                if (preg_match('/pwg_uid\|i:(\d+);/', is_scalar($session['data']) ? (string) $session['data'] : '', $matches)) {
                    if (!isset($all_user_ids[ $matches[1] ])) {
                        $sessions_to_delete[] = is_scalar($session['id']) ? (string) $session['id'] : '';
                    }
                }
            }

            if (count($sessions_to_delete) > 0) {
                $sessionRepo->deleteByIds($sessions_to_delete);
            }

            break;
        }
    case 'feeds':
        {
            ServiceLocator::get(UserRepository::class)->deleteNeverUsedFeeds();
            break;
        }
    case 'database':
        {
            MaintenanceService::repairAndOptimize();
            break;
        }
    case 'c13y':
        {
            $c13y = new CheckIntegrity();
            $c13y->maintenance();
            break;
        }
    case 'search':
        {
            ServiceLocator::get(SearchRepository::class)->deleteAll();
            break;
        }
    case 'compiled-templates':
        {
            $template->delete_compiled_templates();
            FileCombiner::clear_combined_files();
            $persistent_cache->purge(true);
            break;
        }
    case 'derivatives':
        {
            $dtype = is_string($_GET['type'] ?? null) ? $_GET['type'] : '';
            clear_derivative_cache($dtype);
            break;
        }

    case 'check_upgrade':
        {
            if (!fetchRemote(PHPWG_URL.'/download/latest_version', $result)) {
                PageState::current()->addError(l10n('Unable to check for upgrade.'));
            } else {
                $versions = ['current' => PHPWG_VERSION];
                $lines = explode("\r\n", $result);

                // if the current version is a BSF (development branch) build, we check
                // the first line, for stable versions, we check the second line
                if (preg_match('/^BSF/', $versions['current'])) {
                    $versions['latest'] = trim($lines[0]);

                    // because integer are limited to 4,294,967,296 we need to split BSF
                    // versions in date.time
                    foreach ($versions as $key => $value) {
                        $versions[$key] =
                          preg_replace('/BSF_(\d{8})(\d{4})/', '$1.$2', $value);
                    }
                } else {
                    $versions['latest'] = trim($lines[1]);
                }

                if ('' == $versions['latest']) {
                    PageState::current()->addError(l10n('Check for upgrade failed for unknown reasons.'));
                }
                // concatenation needed to avoid automatic transformation by release
                // script generator
                elseif (str_contains((string) ($versions['current'] ?? ''), '%')) {
                    PageState::current()->addInfo(l10n('You are running on development sources, no check possible.'));
                } elseif (version_compare($versions['current'] ?? '', $versions['latest']) < 0) {
                    PageState::current()->addInfo(l10n('A new version of Piwigo is available.'));

                    $update_url = PHPWG_ROOT_PATH.'admin.php?page=updates';
                    PageState::current()->addInfo('<a href="'. $update_url . '">' . l10n('Update to Piwigo %s', $versions['latest']) . '</a>');
                } else {
                    PageState::current()->addInfo(l10n('You are running the latest version of Piwigo.'));
                }
            }
        }

    default:
        {
            break;
        }
}


// +-----------------------------------------------------------------------+
// |                             template init                             |
// +-----------------------------------------------------------------------+

$template->set_filenames(['maintenance' => 'maintenance_env.tpl']);
$template->assign('page_data_json', json_encode([
    'unit_MB'         => l10n('%s MB'),
    'no_time_elapsed'  => l10n('right now'),
    'no_active_plugin' => l10n('No plugin activated'),
    'error_occured'    => l10n('an error happened'),
], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));

$url_format = get_root_url().'admin.php?page=maintenance&amp;action=%s&amp;pwg_token='.get_pwg_token();

$purge_urls[l10n('All')] = sprintf($url_format, 'derivatives').'&amp;type=all';
foreach (ImageStdParams::get_defined_type_map() as $params) {
    $purge_urls[ l10n($params->type) ] = sprintf($url_format, 'derivatives').'&amp;type='.$params->type;
}
$purge_urls[ l10n(IMG_CUSTOM) ] = sprintf($url_format, 'derivatives').'&amp;type='.IMG_CUSTOM;

$php_current_timestamp = date('Y-m-d H:i:s');
$db_version = DbInfo::version();
$db_current_date = new \DateTimeImmutable()->format('Y-m-d H:i:s');

[$container_name, $container_version] = get_container_info();

if (!in_array($container_name, ['Official','none'])) {
    $container_name = '(unofficial) '.$container_name;
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
    'U_HELP' => get_root_url().'admin/popuphelp.php?page=maintenance',

    'PHPWG_URL' => PHPWG_URL,
    'PWG_VERSION' => PHPWG_VERSION,
    'U_CHECK_UPGRADE' => sprintf($url_format, 'check_upgrade'),
    'OS' => PHP_OS,
    'CONTAINER_INFO' => $container_name.(!empty($container_version) ? ' '.$container_version : ''),
    'PHP_VERSION' => phpversion(),
    'DB_ENGINE' => 'MySQL',
    'DB_VERSION' => $db_version,
    'U_PHPINFO' => sprintf($url_format, 'phpinfo'),
    'PHP_DATATIME' => $php_current_timestamp,
    'DB_DATATIME' => $db_current_date,
    'cache_sizes' => (Config::has('cache_sizes')) ? safe_unserialize((string)Config::cacheSizes()) : null,
    'time_elapsed_since_last_calc' => (function (): ?string {
        if (!Config::has('cache_sizes')) {
            return null;
        }
        $cs = safe_unserialize((string)Config::cacheSizes());
        $entry = is_array($cs[3] ?? null) ? $cs[3] : [];
        return time_since(is_scalar($entry['value'] ?? null) ? (string)$entry['value'] : null, 'year');
    })(),
    ]
);

// graphics library
$graphics_library = get_graphics_library_label();
if (!empty($graphics_library)) {
    $template->assign('GRAPHICS_LIBRARY', $graphics_library);
}

if (Config::galleryLocked()) {
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
if (!empty($installed_on)) {
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

//$advanced_features is array of array composed of CAPTION & URL
$advanced_features = trigger_change(
    'get_admin_advanced_features_links',
    $advanced_features
);

$template->assign('advanced_features', $advanced_features);

// +-----------------------------------------------------------------------+
// |                           sending html code                           |
// +-----------------------------------------------------------------------+

$template->assign_var_from_handle('ADMIN_CONTENT', 'maintenance');
