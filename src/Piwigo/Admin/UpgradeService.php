<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Doctrine\DBAL\Connection;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigService;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Db\Tables;
use Piwigo\Plugin\PluginRepository;
use Piwigo\Theme\ThemeRepository;
use Piwigo\Users\UserRepository;

final class UpgradeService
{
    /**
     * Set once the upgrade-wizard credentials check at {@see self::checkUpgradeAccessRights()}
     * has authorised the user. Replaces the legacy `PHPWG_IN_UPGRADE` PHP
     * constant (retired in Phase 4c) — same single-write, multi-read shape but
     * scoped to this class and typed.
     */
    private static bool $upgradeAuthorized = false;

    public static function checkUpgrade(): bool
    {
        return self::$upgradeAuthorized;
    }

    public static function deactivateNonStandardPlugins(): void
    {
        $standard_plugins = ['AdminTools', 'TakeATour', 'language_switch', 'LocalFilesEditor'];

        $pluginRepo = Kernel::service(PluginRepository::class);
        $allActive  = $pluginRepo->findAll('active');
        $plugins    = [];
        foreach ($allActive as $row) {
            $pluginId = is_string($row['id'] ?? null) ? $row['id'] : '';
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

        $themeRepo   = Kernel::service(ThemeRepository::class);
        $allThemes   = $themeRepo->findAll();
        $theme_ids   = [];
        $theme_names = [];
        foreach ($allThemes as $row) {
            $tid = is_string($row['id'] ?? null) ? $row['id'] : '';
            if ($tid !== '' && !in_array($tid, $standard_themes)) {
                $theme_ids[]   = $tid;
                $theme_names[] = is_string($row['name'] ?? null) ? $row['name'] : '';
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

            $defaultUserInfo = Kernel::service(UserRepository::class)
                ->getDefaultUserInfo(Config::defaultUserId());
            $defaultThemeRaw = $defaultUserInfo !== null ? ($defaultUserInfo['theme'] ?? null) : null;
            $default_theme = is_scalar($defaultThemeRaw) ? (string) $defaultThemeRaw : '';

            if (in_array($default_theme, $theme_ids)) {
                if (!$themeRepo->existsById(AppInfo::DEFAULT_TEMPLATE)) {
                    $themes = Kernel::service(Themes::class);
                    $themes->performAction('activate', AppInfo::DEFAULT_TEMPLATE);
                }
                $themeRepo->setThemeForUsers([Config::defaultUserId()], AppInfo::DEFAULT_TEMPLATE);
            }
        }
    }

    public static function deactivateTemplates(): void
    {
        Kernel::service(ConfigService::class)->confUpdateParam('extents_for_templates', []);
    }

    public static function checkUpgradeAccessRights(): void
    {
        $current_release = Config::piwigoInstalledVersion() ?? '';
        if (version_compare($current_release, '2.0', '>=') and isset($_COOKIE[session_name()])) {
            session_start();
            $pwgUid = is_scalar($_SESSION['pwg_uid'] ?? null) ? (string) $_SESSION['pwg_uid'] : '';
            if ($pwgUid !== '') {
                $statusValue = Kernel::service(UserRepository::class)
                    ->findStatusByUserId((int) $pwgUid);
                if ($statusValue === 'webmaster') {
                    self::$upgradeAuthorized = true;
                    return;
                }
            }
        }

        if (!isset($_POST['username']) or !isset($_POST['password'])) {
            return;
        }

        $rawUsername = $_POST['username'];
        $rawPassword = $_POST['password'];
        $username = is_string($rawUsername) ? $rawUsername : '';
        $password = is_string($rawPassword) ? $rawPassword : '';

        if (version_compare($current_release, '2.0', '<')) {
            $username = (string) mb_convert_encoding($username, 'ISO-8859-1', 'UTF-8');
            $password = (string) mb_convert_encoding($password, 'ISO-8859-1', 'UTF-8');
        }

        if (version_compare($current_release, '1.5', '<')) {
            $query = 'SELECT password, status FROM '.Tables::users().' WHERE username = ?';
        } else {
            $query = 'SELECT u.password, ui.status FROM '.Tables::users().' AS u'
                .' INNER JOIN '.Tables::userInfos().' AS ui ON u.'.Config::userFields()['id'].'=ui.user_id'
                .' WHERE '.Config::userFields()['username'].' = ?';
        }
        $rowResult = Kernel::service(Connection::class)->executeQuery($query, [$username])->fetchAssociative();
        $row = $rowResult !== false ? $rowResult : null;

        if ($row === null || !password_verify($password, is_string($row['password']) ? $row['password'] : '')) {
            PageState::current()->addError(Lang::t('Invalid password!'));
        } elseif ($row['status'] != 'admin' and $row['status'] != 'webmaster') {
            PageState::current()->addError(Lang::t('You do not have access rights to run upgrade'));
        } else {
            self::$upgradeAuthorized = true;
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
            fn (mixed $v): string => is_scalar($v) ? (string) $v : '0',
            array_column(Kernel::service(Connection::class)->executeQuery($query)->fetchAllAssociative(), 'id')
        );
        return count(array_diff(self::getAvailableUpgradeIds(), $applied)) > 0;
    }

    public static function upgradeDbConnect(): void
    {
        Kernel::service(Connection::class);
    }
}
