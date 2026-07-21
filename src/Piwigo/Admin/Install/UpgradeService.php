<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install;

use Doctrine\DBAL\Connection;
use Exception;
use Piwigo\Admin\themes;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Lang;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;

/**
 * Database-upgrade machinery, ported verbatim from the former
 * admin/include/functions_upgrade.php free functions plus upgrade.php's own
 * get_tables()/get_columns_of() (P23 sub-batch 8f-6).
 *
 * Static methods on purpose: this class runs on the pre-installation /
 * pre-upgrade entry paths (upgrade.php, upgrade_feed.php, install.php,
 * RequestBootstrap's check_upgrade_feed gate) where no DI container exists,
 * matching the Env/FilesystemHelper/MysqliDb precedent for
 * legacy-reachable stateless machinery.
 *
 * PHPWG_IN_UPGRADE note: the former check_upgrade_access_rights() used to
 * define() PHPWG_IN_UPGRADE itself; SEC-60 forbids define() inside
 * src/Piwigo, so checkUpgradeAccessRights() now RETURNS the authorization
 * verdict and the upgrade.php entry shell performs the define() (the frozen
 * install/upgrade_*.php scripts read that constant at include time, so it
 * must stay a real global constant). checkUpgrade() still reads it.
 *
 * PREFIX_TABLE/UPGRADES_PATH/CURRENT_DATE are define()'d by the
 * upgrade.php/upgrade_feed.php entry shells before any method here runs
 * (see tools/phpstan-bootstrap.php for the PREFIX_TABLE analysis stub).
 *
 * Legacy Coupling Retirement Phase 5: this same "no DI container exists"
 * fact is why its extents_for_templates write (Tier 3) stays on ConfigDb
 * rather than ConfigService.
 */
final class UpgradeService
{
    public static function checkUpgrade(): bool
    {
        if (defined('PHPWG_IN_UPGRADE')) {
            // PHPWG_IN_UPGRADE is only ever define()'d with a bool literal
            // `true` (see the upgrade.php entry shell, fed by
            // checkUpgradeAccessRights() below); is_bool() reflects that
            // real invariant without resorting to an unsafe cast on a mixed
            // constant.
            $in_upgrade = PHPWG_IN_UPGRADE;
            return is_bool($in_upgrade) && $in_upgrade;
        }
        return false;
    }

    /**
     * list all tables in an array
     *
     * @return array<int, string>
     */
    public static function getTables(Connection $conn): array
    {
        $tables = [];

        $query = '
SHOW TABLES
;';
        foreach ($conn->fetchFirstColumn($query) as $table_name) {
            if (! is_string($table_name)) {
                continue;
            }
            if ((bool) preg_match('/^' . PREFIX_TABLE . '/', $table_name)) {
                $tables[] = $table_name;
            }
        }

        return $tables;
    }

    /**
     * list all columns of each given table
     *
     * @param array<int, string> $tables
     * @return array<string, array<int, string>>
     */
    public static function getColumnsOf(Connection $conn, array $tables): array
    {
        $columns_of = [];

        foreach ($tables as $table) {
            $query = '
DESC `' . $table . '`
;';
            $columns_of[$table] = [];

            foreach ($conn->fetchFirstColumn($query) as $column_name) {
                if (! is_string($column_name)) {
                    continue;
                }
                $columns_of[$table][] = $column_name;
            }
        }

        return $columns_of;
    }

    // Deactivate all non-standard plugins
    public static function deactivateNonStandardPlugins(Connection $conn): void
    {
        $standard_plugins = [
            'AdminTools',
            'TakeATour',
            'language_switch',
            'LocalFilesEditor',
        ];

        $query = '
SELECT id
FROM ' . PREFIX_TABLE . 'plugins
WHERE state = \'active\'
AND id NOT IN (\'' . implode('\',\'', $standard_plugins) . '\')
;';

        $plugins = array_map(
            static fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
            $conn->fetchFirstColumn($query)
        );

        if (! empty($plugins)) {
            $query = '
UPDATE ' . PREFIX_TABLE . 'plugins
SET state=\'inactive\'
WHERE id IN (\'' . implode('\',\'', $plugins) . '\')
;';
            $conn->executeStatement($query);

            \Piwigo\Core\PageState::current()->addInfo(Lang::t('As a precaution, following plugins have been deactivated. You must check for plugins upgrade before reactiving them:')
                                . '<p><i>' . implode(', ', $plugins) . '</i></p>');
        }
    }

    // Deactivate all non-standard themes
    public static function deactivateNonStandardThemes(Connection $conn): void
    {
        $standard_themes = [
            'modus',
            'elegant',
            'smartpocket',
        ];

        $query = '
SELECT
    id,
    name
  FROM ' . PREFIX_TABLE . 'themes
  WHERE id NOT IN (\'' . implode("','", $standard_themes) . '\')
;';
        $theme_ids = [];
        $theme_names = [];
        foreach ($conn->fetchAllAssociative($query) as $row) {
            $theme_ids[] = is_scalar($row['id']) ? (string) $row['id'] : '';
            $theme_names[] = is_scalar($row['name']) ? (string) $row['name'] : '';
        }

        if (! empty($theme_ids)) {
            $query = '
DELETE
  FROM ' . PREFIX_TABLE . 'themes
  WHERE id IN (\'' . implode("','", $theme_ids) . '\')
;';
            $conn->executeStatement($query);

            \Piwigo\Core\PageState::current()->addInfo(Lang::t('As a precaution, following themes have been deactivated. You must check for themes upgrade before reactiving them:')
                                . '<p><i>' . implode(', ', $theme_names) . '</i></p>');

            // what is the default theme?
            // \Piwigo\Config\Config::defaultUserId() is always an int (see include/config_default.inc.php)
            $default_user_id = is_numeric(\Piwigo\Config\Config::defaultUserId()) ? (int) \Piwigo\Config\Config::defaultUserId() : 0;
            $query = '
SELECT theme
  FROM ' . PREFIX_TABLE . 'user_infos
  WHERE user_id = ' . $default_user_id . '
;';
            $default_theme = $conn->fetchOne($query);
            $default_theme = is_scalar($default_theme) ? (string) $default_theme : '';

            // if the default theme has just been deactivated, let's set another core theme as default
            if (in_array($default_theme, $theme_ids)) {
                // make sure default Piwigo theme is active
                $query = '
SELECT
    COUNT(*)
  FROM ' . PREFIX_TABLE . 'themes
  WHERE id = \'' . AppInfo::DEFAULT_TEMPLATE . '\'
;';
                $counter = $conn->fetchOne($query);
                if (! is_int($counter) || $counter < 1) {
                    // we need to activate theme first
                    $themes = new themes();
                    $themes->perform_action('activate', AppInfo::DEFAULT_TEMPLATE);
                }

                // then associate it to default user
                $query = '
UPDATE ' . PREFIX_TABLE . 'user_infos
  SET theme = \'' . AppInfo::DEFAULT_TEMPLATE . '\'
  WHERE user_id = ' . $default_user_id . '
;';
                $conn->executeStatement($query);
            }
        }
    }

    // Deactivate all templates
    public static function deactivateTemplates(): void
    {
        \Piwigo\Config\CurrentConfigService::get()->confUpdateParam('extents_for_templates', []);
    }

    /**
     * Check access rights.
     *
     * Body ported verbatim from check_upgrade_access_rights() except for the
     * two historical `define('PHPWG_IN_UPGRADE', true);` sites: SEC-60
     * forbids define() in src/Piwigo, so those became `return true;` and the
     * upgrade.php entry shell performs the define() when this returns true
     * (nothing ran between the old define and the function's end, so the
     * timing is identical). $current_release used to arrive via
     * `global $current_release`; it is now an explicit parameter (single
     * caller, the upgrade.php shell).
     *
     * @return bool true when the requester is authorized to run the upgrade
     *              (webmaster session, or valid admin/webmaster credentials)
     */
    public static function checkUpgradeAccessRights(Connection $conn, string $current_release): bool
    {
        if (version_compare($current_release, '2.0', '>=') and isset($_COOKIE[session_name()])) {
            // Check if user is already connected as webmaster
            session_start();
            $pwg_uid = $_SESSION['pwg_uid'] ?? null;
            if (! empty($pwg_uid) and (is_int($pwg_uid) or (is_string($pwg_uid) and is_numeric($pwg_uid)))) {
                $query = '
SELECT status
  FROM ' . Tables::userInfos() . '
  WHERE user_id = ' . (int) $pwg_uid . '
;';
                $row = $conn->fetchAssociative($query);
                $status = $row !== false ? ($row['status'] ?? null) : null;
                if (is_string($status) && $status === 'webmaster') {
                    return true;
                }
            }
        }

        if (! isset($_POST['username']) or ! isset($_POST['password'])) {
            return false;
        }

        $username = is_string($_POST['username']) ? $_POST['username'] : null;
        $password = is_string($_POST['password']) ? $_POST['password'] : null;

        if ($username === null or $password === null) {
            return false;
        }

        if (version_compare($current_release, '2.0', '<')) {
            // mb_convert_encoding() always returns a string given a string input.
            $username = mb_convert_encoding($username, 'ISO-8859-1', 'UTF-8');
            $password = mb_convert_encoding($password, 'ISO-8859-1', 'UTF-8');
        }

        // Parameterized below (DBAL binds $username), so the former
        // MysqliDb::realEscapeString() call ahead of this block is gone --
        // dead once the query it protected is no longer string-spliced.
        if (version_compare($current_release, '1.5', '<')) {
            $query = '
SELECT password, status
FROM ' . Tables::users() . '
WHERE username = :username
;';
        } else {
            // \Piwigo\Config\Config::userFields() maps generic field names to table specific
            // field names and is always array<string, string> (see
            // include/config_default.inc.php).
            $user_fields = is_array(\Piwigo\Config\Config::userFields()) ? \Piwigo\Config\Config::userFields() : [];
            $id_field = isset($user_fields['id']) && is_string($user_fields['id']) ? $user_fields['id'] : 'id';
            $username_field = isset($user_fields['username']) && is_string($user_fields['username']) ? $user_fields['username'] : 'username';
            $query = '
SELECT u.password, ui.status
FROM ' . Tables::users() . ' AS u
INNER JOIN ' . Tables::userInfos() . ' AS ui
ON u.' . $id_field . '=ui.user_id
WHERE ' . $username_field . '=:username
;';
        }
        $row = $conn->fetchAssociative($query, [
            'username' => $username,
        ]);
        $stored_password = is_array($row) && isset($row['password']) && is_string($row['password']) ? $row['password'] : null;
        $stored_status = is_array($row) ? ($row['status'] ?? null) : null;
        $stored_status = is_string($stored_status) ? $stored_status : null;

        if ($stored_password === null) {
            \Piwigo\Core\PageState::current()->addError(Lang::t('Invalid password!'));
        } elseif (! new \Piwigo\Auth\PasswordService(new \Piwigo\Auth\PasswordRepository($conn))->verify($password, $stored_password)) {
            \Piwigo\Core\PageState::current()->addError(Lang::t('Invalid password!'));
        } elseif ($stored_status !== 'admin' and $stored_status !== 'webmaster') {
            \Piwigo\Core\PageState::current()->addError(Lang::t('You do not have access rights to run upgrade'));
        } else {
            return true;
        }

        return false;
    }

    /**
     * which upgrades are available ?
     *
     * Byte-identical opendir()/regex scan of install/db/ -- the single
     * coupling point to the frozen install/db/*.php scripts. The frozen
     * install/upgrade_*.php scripts keep calling the bare
     * get_available_upgrade_ids() delegate in
     * admin/include/functions_upgrade.php, which forwards here.
     *
     * @return array<int, string>
     */
    public static function getAvailableUpgradeIds(): array
    {
        // P23 sub-batch 8g-6: the DbPatchRegistry is the single source of
        // available one-shot patch ids (the historical opendir()/regex scan
        // of install/db/ died with the files; the registry preserves the
        // scan's natcasesort ordering by construction).
        return \Piwigo\Admin\Install\DbPatch\DbPatchRegistry::ids();
    }

    /**
     * returns true if there are available upgrade files
     */
    public static function checkUpgradeFeed(Connection $conn): bool
    {
        // retrieve already applied upgrades
        $query = '
SELECT id
  FROM ' . Tables::upgrade() . '
;';
        $applied = array_map(
            static fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
            $conn->fetchFirstColumn($query)
        );

        // retrieve existing upgrades
        $existing = self::getAvailableUpgradeIds();

        // which upgrades need to be applied?
        return count(array_diff($existing, $applied)) > 0;
    }

    /**
     * upgrade.php seeds Config's db_* overrides from the same
     * $config_file include this method itself would have read from, ahead
     * of this call, so DbConnection::build() (which reads Config::db*())
     * resolves to the real submitted credentials.
     */
    public static function upgradeDbConnect(): Connection
    {
        try {
            $conn = DbConnection::build();
            $conn->getNativeConnection();

            $version = new \Piwigo\Db\DbInfo($conn)
                ->version();
            if (version_compare($version, \Piwigo\Db\SqlDialect::REQUIRED_MYSQL_VERSION, '<')) {
                throw new Exception(sprintf('your MySQL version is too old, you have "%s" and you need at least "%s"', $version, \Piwigo\Db\SqlDialect::REQUIRED_MYSQL_VERSION));
            }

            return $conn;
        } catch (Exception $e) {
            // fatalError() is declared `: never` -- PHPStan proves this
            // catch block never falls through, no fallback statement
            // needed after it.
            new \Piwigo\Html\HtmlService()
                ->fatalError(Lang::t($e->getMessage()));
        }
    }
}
