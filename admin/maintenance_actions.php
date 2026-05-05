<?php

declare(strict_types=1);

use Piwigo\Admin\Image\PwgImage;
use Piwigo\Admin\Integrity\CheckIntegrity;
use Piwigo\Admin\MaintenanceService;
use Piwigo\Config\Config;
use Piwigo\Core\PageState;
use Piwigo\Core\ServiceLocator;
use Piwigo\Db\DbInfo;
use Piwigo\History\HistoryRepository;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageStdParams;
use Piwigo\Job\RegenerateAllDerivativesJob;
use Piwigo\Search\SearchRepository;
use Piwigo\Session\SessionRepository;
use Piwigo\Template\FileCombiner;
use Piwigo\Users\UserRepository;
use Symfony\Component\Messenger\MessageBusInterface;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

fs_quick_check();

// +-----------------------------------------------------------------------+
// |                                actions                                |
// +-----------------------------------------------------------------------+

global $template, $user, $page, $persistent_cache, $lang, $maint_actions;

$action = $_GET['action'] ?? '';
$register_activity = true;

switch ($action) {
    case 'phpinfo':
        {
            phpinfo();
            exit();
        }
    case 'lock_gallery':
        {
            conf_update_param('gallery_locked', 'true');
            pwg_activity('system', ACTIVITY_SYSTEM_CORE, 'maintenance', ['maintenance_action' => $action]);
            redirect(get_root_url().'admin.php?page=maintenance');
            break;
        }
    case 'unlock_gallery':
        {
            conf_update_param('gallery_locked', 'false');
            $_SESSION['page_infos'] = [l10n('Gallery unlocked')];
            pwg_activity('system', ACTIVITY_SYSTEM_CORE, 'maintenance', ['maintenance_action' => $action]);
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
            PageState::current()->addInfo(sprintf('%s : %s', l10n('Update albums informations'), l10n('action successfully performed.')));
            break;
        }
    case 'images':
        {
            images_integrity();
            update_path();
            update_rating_score();
            invalidate_user_cache();
            PageState::current()->addInfo(sprintf('%s : %s', l10n('Update photos information'), l10n('action successfully performed.')));
            break;
        }
    case 'delete_orphan_tags':
        {
            delete_orphan_tags();
            PageState::current()->addInfo(sprintf('%s : %s', l10n('Delete orphan tags'), l10n('action successfully performed.')));
            break;
        }
    case 'user_cache':
        {
            invalidate_user_cache();
            PageState::current()->addInfo(sprintf('%s : %s', l10n('Purge user cache'), l10n('action successfully performed.')));
            break;
        }
    case 'history_detail':
        {
            ServiceLocator::get(HistoryRepository::class)->deleteAll();
            PageState::current()->addInfo(sprintf('%s : %s', l10n('Purge history detail'), l10n('action successfully performed.')));
            break;
        }
    case 'history_summary':
        {
            ServiceLocator::get(HistoryRepository::class)->deleteAllSummary();
            PageState::current()->addInfo(sprintf('%s : %s', l10n('Purge history summary'), l10n('action successfully performed.')));
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
            PageState::current()->addInfo(sprintf('%s : %s', l10n('Purge sessions'), l10n('action successfully performed.')));
            break;
        }
    case 'feeds':
        {
            ServiceLocator::get(UserRepository::class)->deleteNeverUsedFeeds();
            PageState::current()->addInfo(sprintf('%s : %s', l10n('Purge never used notification feeds'), l10n('action successfully performed.')));
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
            PageState::current()->addInfo(sprintf('%s : %s', l10n('Reinitialize check integrity'), l10n('action successfully performed.')));
            break;
        }
    case 'empty_lounge':
        {
            $rows = empty_lounge();
            PageState::current()->addInfo(sprintf('%d photos were moved from the upload lounge to their albums', count($rows ?? [])));
            break;
        }
    case 'search':
        {
            ServiceLocator::get(SearchRepository::class)->deleteAll();
            sprintf('%s : %s', l10n('Reinitialize check integrity'), l10n('action successfully performed.'));
            break;
        }
    case 'compiled-templates':
        {
            $template->delete_compiled_templates();
            FileCombiner::clear_combined_files();
            $persistent_cache->purge(true);
            PageState::current()->addInfo(sprintf('%s : %s', l10n('Purge compiled templates'), l10n('action successfully performed.')));
            break;
        }
    case 'derivatives':
        {
            $types_str = is_string($_GET['type'] ?? null) ? $_GET['type'] : '';
            $types     = $types_str === 'all' ? ['all'] : explode('_', $types_str);

            foreach ($types as $type_to_clear) {
                clear_derivative_cache($type_to_clear);
            }

            ServiceLocator::get(MessageBusInterface::class)->dispatch(
                new RegenerateAllDerivativesJob($types)
            );

            PageState::current()->addInfo(l10n('action successfully performed.'));
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
                } else {
                    PageState::current()->addInfo(l10n('You are running the latest version of Piwigo.'));
                }
            }
            PageState::current()->addInfo(l10n('action successfully performed.'));
        }

    default:
        {
            $register_activity = false;
            break;
        }
}

if ($register_activity) {
    pwg_activity('system', ACTIVITY_SYSTEM_CORE, 'maintenance', ['maintenance_action' => $action]);
}

// +-----------------------------------------------------------------------+
// |                             template init                             |
// +-----------------------------------------------------------------------+

$template->set_filenames(['maintenance' => 'maintenance_actions.tpl']);
$pwg_token = get_pwg_token();
$gallery_locked = Config::galleryLocked();
$template->assign('page_data_json', json_encode([
    'unit_MB'                     => l10n('%s MB'),
    'no_time_elapsed'             => l10n('right now'),
    'pwg_token'                   => $pwg_token,
    'gallery_locked'              => $gallery_locked,
    'str_lock_gallery_tip'        => l10n('A locked gallery is only visible to administrators'),
    'str_lock_gallery_confirm'    => l10n('Are you sure you want to lock the gallery?'),
    'str_unlock_gallery_confirm'  => l10n('Are you sure you want to unlock the gallery?'),
    'str_purge_history_detail'    => l10n('Purge history detail'),
    'str_purge_history_summary'   => l10n('Purge history summary'),
    'str_purge_search_history'    => l10n('Purge search history'),
    'str_delete_all_sizes_confirm' => l10n('Are you sure you want to delete all sizes?'),
], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));
$url_format = get_root_url().'admin.php?page=maintenance&amp;action=%s&amp;pwg_token='.get_pwg_token();

if (!is_webmaster()) {
    PageState::current()->addWarning(str_replace('%s', l10n('user_status_webmaster'), l10n('%s status is required to edit parameters.')));
}

$purge_urls[l10n('All')] = 'all';
foreach (ImageStdParams::get_defined_type_map() as $params) {
    $purge_urls[ l10n($params->type) ] = $params->type;
}
$purge_urls[ l10n(IMG_CUSTOM) ] = IMG_CUSTOM;

$php_current_timestamp = date('Y-m-d H:i:s');
$db_version = DbInfo::version();
$db_current_date = new \DateTimeImmutable()->format('Y-m-d H:i:s');

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
    'U_HELP' => get_root_url().'admin/popuphelp.php?page=maintenance',

    'PHPWG_URL' => PHPWG_URL,
    'PWG_VERSION' => PHPWG_VERSION,
    'U_CHECK_UPGRADE' => sprintf($url_format, 'check_upgrade'),
    'OS' => PHP_OS,
    'PHP_VERSION' => phpversion(),
    'DB_ENGINE' => 'MySQL',
    'DB_VERSION' => $db_version,
    'U_PHPINFO' => sprintf($url_format, 'phpinfo'),
    'PHP_DATATIME' => $php_current_timestamp,
    'DB_DATATIME' => $db_current_date,
    'pwg_token' => $pwg_token,
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
switch (PwgImage::get_library()) {
    case 'ext_imagick':
        $library = 'External ImageMagick';
        exec(Config::extImagickDir().PwgImage::get_ext_imagick_command().' -version', $returnarray);
        if (preg_match('/Version: ImageMagick (\d+\.\d+\.\d+-?\d*)/', $returnarray[0], $match)) {
            $library .= ' ' . $match[1];
        }
        $template->assign('GRAPHICS_LIBRARY', $library);
        break;

    case 'imagick':
        $library = 'ImageMagick';
        $img = new Imagick();
        $version = $img->getVersion();
        if (preg_match('/ImageMagick \d+\.\d+\.\d+-?\d*/', $version['versionString'], $match)) {
            $library = $match[0];
        }
        $template->assign('GRAPHICS_LIBRARY', $library);
        break;

    case 'gd':
        $gd_info = gd_info();
        $template->assign('GRAPHICS_LIBRARY', 'GD '.($gd_info['GD Version'] ?? ''));
        break;
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

$query = '
SELECT
    COUNT(*)
  FROM '.LOUNGE_TABLE.'
;';
$nb_lounge = ServiceLocator::get(ImageRepository::class)->countLoungeImages();

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
