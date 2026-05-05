<?php

declare(strict_types=1);

use Piwigo\Admin\Themes;
use Piwigo\Config\Config;
use Piwigo\Core\PageState;
use Piwigo\Core\ServiceLocator;
use Piwigo\Plugin\PluginRepository;
use Piwigo\Theme\ThemeRepository;
use Piwigo\Users\UserRepository;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

function check_upgrade(): bool
{
    if (defined('PHPWG_IN_UPGRADE')) {
        return PHPWG_IN_UPGRADE;
    }
    return false;
}

// concerning upgrade, we use the default tables
function prepare_conf_upgrade(): void
{
    $prefixeTable = is_string($GLOBALS['prefixeTable'] ?? null) ? $GLOBALS['prefixeTable'] : 'piwigo_';

    // $conf is not used for users tables
    // define cannot be re-defined
    define('CATEGORIES_TABLE', $prefixeTable.'categories');
    define('COMMENTS_TABLE', $prefixeTable.'comments');
    define('CONFIG_TABLE', $prefixeTable.'config');
    define('FAVORITES_TABLE', $prefixeTable.'favorites');
    define('GROUP_ACCESS_TABLE', $prefixeTable.'group_access');
    define('GROUPS_TABLE', $prefixeTable.'groups');
    define('HISTORY_TABLE', $prefixeTable.'history');
    define('HISTORY_SUMMARY_TABLE', $prefixeTable.'history_summary');
    define('IMAGE_CATEGORY_TABLE', $prefixeTable.'image_category');
    define('IMAGES_TABLE', $prefixeTable.'images');
    define('SESSIONS_TABLE', $prefixeTable.'sessions');
    define('SITES_TABLE', $prefixeTable.'sites');
    define('USER_ACCESS_TABLE', $prefixeTable.'user_access');
    define('USER_GROUP_TABLE', $prefixeTable.'user_group');
    define('USERS_TABLE', $prefixeTable.'users');
    define('USER_INFOS_TABLE', $prefixeTable.'user_infos');
    define('USER_FEED_TABLE', $prefixeTable.'user_feed');
    define('RATE_TABLE', $prefixeTable.'rate');
    define('USER_CACHE_TABLE', $prefixeTable.'user_cache');
    define('USER_CACHE_CATEGORIES_TABLE', $prefixeTable.'user_cache_categories');
    define('CADDIE_TABLE', $prefixeTable.'caddie');
    define('UPGRADE_TABLE', $prefixeTable.'upgrade');
    define('SEARCH_TABLE', $prefixeTable.'search');
    define('USER_MAIL_NOTIFICATION_TABLE', $prefixeTable.'user_mail_notification');
    define('TAGS_TABLE', $prefixeTable.'tags');
    define('IMAGE_TAG_TABLE', $prefixeTable.'image_tag');
    define('PLUGINS_TABLE', $prefixeTable.'plugins');
    define('OLD_PERMALINKS_TABLE', $prefixeTable.'old_permalinks');
    define('THEMES_TABLE', $prefixeTable.'themes');
    define('LANGUAGES_TABLE', $prefixeTable.'languages');
}

// Deactivate all non-standard plugins
function deactivate_non_standard_plugins(): void
{
    $standard_plugins = [
      'AdminTools',
      'TakeATour',
      'language_switch',
      'LocalFilesEditor',
      ];

    $pluginRepo = ServiceLocator::get(PluginRepository::class);
    $allActive = $pluginRepo->findAll('active');
    $plugins = [];
    foreach ($allActive as $row) {
        $pluginId = is_scalar($row['id']) ? (string) $row['id'] : '';
        if ($pluginId !== '' && !in_array($pluginId, $standard_plugins)) {
            $plugins[] = $pluginId;
        }
    }

    if (!empty($plugins)) {
        foreach ($plugins as $pluginId) {
            $pluginRepo->updateState($pluginId, 'inactive');
        }

        PageState::current()->addInfo(l10n('As a precaution, following plugins have been deactivated. You must check for plugins upgrade before reactiving them:')
                            .'<p><i>'.implode(', ', $plugins).'</i></p>');
    }
}

// Deactivate all non-standard themes
function deactivate_non_standard_themes(): void
{
    $standard_themes = [
      'modus',
      'elegant',
      'smartpocket',
      ];

    $themeRepo = ServiceLocator::get(ThemeRepository::class);
    $allThemes = $themeRepo->findAll();
    $theme_ids = [];
    $theme_names = [];
    foreach ($allThemes as $row) {
        $tid = is_scalar($row['id']) ? (string) $row['id'] : '';
        if ($tid !== '' && !in_array($tid, $standard_themes)) {
            $theme_ids[] = $tid;
            $theme_names[] = is_scalar($row['name']) ? (string) $row['name'] : '';
        }
    }

    if (!empty($theme_ids)) {
        foreach ($theme_ids as $tid) {
            $themeRepo->deactivate($tid);
        }

        PageState::current()->addInfo(l10n('As a precaution, following themes have been deactivated. You must check for themes upgrade before reactiving them:')
                            .'<p><i>'.implode(', ', $theme_names).'</i></p>');

        // what is the default theme?
        $defaultUserInfo = ServiceLocator::get(UserRepository::class)
            ->getDefaultUserInfo(Config::defaultUserId());
        $default_theme = is_scalar($defaultUserInfo['theme'] ?? null) ? (string) $defaultUserInfo['theme'] : '';

        // if the default theme has just been deactivated, let's set another core theme as default
        if (in_array($default_theme, $theme_ids)) {
            // make sure default Piwigo theme is active
            if (!$themeRepo->existsById(PHPWG_DEFAULT_TEMPLATE)) {
                // we need to activate theme first
                $themes = new Themes();
                $themes->perform_action('activate', PHPWG_DEFAULT_TEMPLATE);
            }

            // then associate it to default user
            $themeRepo->setThemeForUsers([Config::defaultUserId()], PHPWG_DEFAULT_TEMPLATE);
        }
    }
}

// Deactivate all templates
function deactivate_templates(): void
{
    conf_update_param('extents_for_templates', []);
}

// Check access rights
function check_upgrade_access_rights(): void
{
    $current_release = is_string($GLOBALS['current_release'] ?? null) ? $GLOBALS['current_release'] : '';
    if (version_compare($current_release, '2.0', '>=') and isset($_COOKIE[session_name()])) {
        // Check if user is already connected as webmaster
        session_start();
        $pwgUid = is_scalar($_SESSION['pwg_uid'] ?? null) ? (string) $_SESSION['pwg_uid'] : '';
        if (!empty($pwgUid)) {
            $statusValue = ServiceLocator::get(UserRepository::class)
                ->findStatusByUserId((int) $pwgUid);
            if ($statusValue === 'webmaster') {
                define('PHPWG_IN_UPGRADE', true);
                return;
            }
        }
    }

    if (!isset($_POST['username']) or !isset($_POST['password'])) {
        return;
    }

    $username = is_scalar($_POST['username']) ? (string) $_POST['username'] : '';
    $password = is_scalar($_POST['password']) ? (string) $_POST['password'] : '';

    if (version_compare($current_release, '2.0', '<')) {
        $username = mb_convert_encoding($username, 'ISO-8859-1', 'UTF-8');
        $password = mb_convert_encoding($password, 'ISO-8859-1', 'UTF-8');
    }

    if (version_compare($current_release, '1.5', '<')) {
        $query = 'SELECT password, status FROM '.USERS_TABLE.' WHERE username = ?';
    } else {
        $query = 'SELECT u.password, ui.status FROM '.USERS_TABLE.' AS u'
            .' INNER JOIN '.USER_INFOS_TABLE.' AS ui ON u.'.Config::userFields()['id'].'=ui.user_id'
            .' WHERE '.Config::userFields()['username'].' = ?';
    }
    $row = get_dbal_connection()->executeQuery($query, [$username])->fetchAssociative() ?: null;

    if ($row === null || !password_verify($password, is_string($row['password']) ? $row['password'] : '')) {
        PageState::current()->addError(l10n('Invalid password!'));
    } elseif ($row['status'] != 'admin' and $row['status'] != 'webmaster') {
        PageState::current()->addError(l10n('You do not have access rights to run upgrade'));
    } else {
        define('PHPWG_IN_UPGRADE', true);
    }
}

/**
 * which upgrades are available ?
 */
/** @return string[] */
function get_available_upgrade_ids(): array
{
    $upgrades_path = PHPWG_ROOT_PATH.'install/db';

    $available_upgrade_ids = [];

    if ($contents = opendir($upgrades_path)) {
        while (($node = readdir($contents)) !== false) {
            if (is_file($upgrades_path.'/'.$node)
                and preg_match('/^(.*?)-database\.php$/', $node, $match)) {
                $available_upgrade_ids[] = $match[1];
            }
        }
    }
    natcasesort($available_upgrade_ids);

    return $available_upgrade_ids;
}


/**
 * returns true if there are available upgrade files
 */
function check_upgrade_feed(): bool
{
    // retrieve already applied upgrades
    $query = '
SELECT id
  FROM '.UPGRADE_TABLE.'
;';
    $applied = array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '', array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'id'));

    // retrieve existing upgrades
    $existing = get_available_upgrade_ids();

    // which upgrades need to be applied?
    return (count(array_diff($existing, $applied)) > 0);
}

function upgrade_db_connect(): void
{
    // Force a connection early so errors surface before rendering begins.
    // get_dbal_connection() caches the connection for the lifetime of the request.
    get_dbal_connection();
}
