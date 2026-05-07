<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Config\Config;
use Piwigo\Config\ConfigService;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\ServiceLocator;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Plugin\PluginRepository;
use Piwigo\Theme\ThemeRepository;
use Piwigo\Users\UserRepository;

final class UpgradeService
{
    public static function checkUpgrade(): bool
    {
        if (defined('PHPWG_IN_UPGRADE')) {
            return (bool) PHPWG_IN_UPGRADE;
        }
        return false;
    }

    public static function prepareConfUpgrade(): void
    {
        $prefixeTable = is_string($GLOBALS['prefixeTable'] ?? null) ? $GLOBALS['prefixeTable'] : 'piwigo_';

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

    public static function deactivateNonStandardPlugins(): void
    {
        $standard_plugins = ['AdminTools', 'TakeATour', 'language_switch', 'LocalFilesEditor'];

        $pluginRepo = ServiceLocator::get(PluginRepository::class);
        $allActive  = $pluginRepo->findAll('active');
        $plugins    = [];
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
            PageState::current()->addInfo(
                Lang::t('As a precaution, following plugins have been deactivated. You must check for plugins upgrade before reactiving them:')
                . '<p><i>' . implode(', ', $plugins) . '</i></p>'
            );
        }
    }

    public static function deactivateNonStandardThemes(): void
    {
        $standard_themes = ['modus', 'elegant', 'smartpocket'];

        $themeRepo   = ServiceLocator::get(ThemeRepository::class);
        $allThemes   = $themeRepo->findAll();
        $theme_ids   = [];
        $theme_names = [];
        foreach ($allThemes as $row) {
            $tid = is_scalar($row['id']) ? (string) $row['id'] : '';
            if ($tid !== '' && !in_array($tid, $standard_themes)) {
                $theme_ids[]   = $tid;
                $theme_names[] = is_scalar($row['name']) ? (string) $row['name'] : '';
            }
        }

        if (!empty($theme_ids)) {
            foreach ($theme_ids as $tid) {
                $themeRepo->deactivate($tid);
            }
            PageState::current()->addInfo(
                Lang::t('As a precaution, following themes have been deactivated. You must check for themes upgrade before reactiving them:')
                . '<p><i>' . implode(', ', $theme_names) . '</i></p>'
            );

            $defaultUserInfo = ServiceLocator::get(UserRepository::class)
                ->getDefaultUserInfo(Config::defaultUserId());
            $default_theme = is_scalar($defaultUserInfo['theme'] ?? null) ? (string) $defaultUserInfo['theme'] : '';

            if (in_array($default_theme, $theme_ids)) {
                if (!$themeRepo->existsById(AppInfo::DEFAULT_TEMPLATE)) {
                    $themes = new Themes();
                    $themes->performAction('activate', AppInfo::DEFAULT_TEMPLATE);
                }
                $themeRepo->setThemeForUsers([Config::defaultUserId()], AppInfo::DEFAULT_TEMPLATE);
            }
        }
    }

    public static function deactivateTemplates(): void
    {
        ServiceLocator::get(ConfigService::class)->confUpdateParam('extents_for_templates', []);
    }

    public static function checkUpgradeAccessRights(): void
    {
        $current_release = is_string($GLOBALS['current_release'] ?? null) ? $GLOBALS['current_release'] : '';
        if (version_compare($current_release, '2.0', '>=') and isset($_COOKIE[session_name()])) {
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
            $query = 'SELECT password, status FROM '.Tables::users().' WHERE username = ?';
        } else {
            $query = 'SELECT u.password, ui.status FROM '.Tables::users().' AS u'
                .' INNER JOIN '.Tables::userInfos().' AS ui ON u.'.Config::userFields()['id'].'=ui.user_id'
                .' WHERE '.Config::userFields()['username'].' = ?';
        }
        $row = DbConnection::get()->executeQuery($query, [$username])->fetchAssociative() ?: null;

        if ($row === null || !password_verify($password, is_string($row['password']) ? $row['password'] : '')) {
            PageState::current()->addError(Lang::t('Invalid password!'));
        } elseif ($row['status'] != 'admin' and $row['status'] != 'webmaster') {
            PageState::current()->addError(Lang::t('You do not have access rights to run upgrade'));
        } else {
            define('PHPWG_IN_UPGRADE', true);
        }
    }

    /** @return string[] */
    public static function getAvailableUpgradeIds(): array
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

    public static function checkUpgradeFeed(): bool
    {
        $query   = 'SELECT id FROM '.Tables::upgrade().';';
        $applied = array_map(
            fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
            array_column(DbConnection::get()->executeQuery($query)->fetchAllAssociative(), 'id')
        );
        return count(array_diff(self::getAvailableUpgradeIds(), $applied)) > 0;
    }

    public static function upgradeDbConnect(): void
    {
        DbConnection::get();
    }
}
