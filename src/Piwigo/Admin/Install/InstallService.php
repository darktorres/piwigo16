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
use Piwigo\Admin\plugins;
use Piwigo\Admin\themes;
use Piwigo\Core\Lang;
use Piwigo\Db\DbConnection;
use RuntimeException;

/**
 * Installation helpers, ported verbatim from the former
 * admin/include/functions_install.inc.php free functions (P23 sub-batch
 * 8f-6). Static methods on purpose: this class runs on the
 * pre-installation entry path (install.php) where no DI container exists,
 * matching the Env/FilesystemHelper/MysqliDb precedent.
 *
 * The frozen install/db/86-database.php script still calls the bare
 * activate_core_themes() delegate kept in
 * admin/include/functions_install.inc.php (a file it include_once's
 * itself), which forwards to activateCoreThemes() here.
 */
class InstallService
{
    /**
     * Loads a SQL file and executes all queries.
     * Before executing a query, $replaced is... replaced by $replacing. This is
     * useful when the SQL file contains generic words. Drop table queries are
     * not executed.
     */
    public static function executeSqlfile(Connection $conn, string $filepath, string $replaced, string $replacing, string $dblayer): void
    {
        $sql_lines = file($filepath);
        if ($sql_lines === false) {
            throw new RuntimeException('Unable to read SQL file: ' . $filepath);
        }
        $query = '';
        foreach ($sql_lines as $sql_line) {
            $sql_line = trim($sql_line);
            if ((bool) preg_match('/(^--|^$)/', $sql_line)) {
                continue;
            }
            $query .= ' ' . $sql_line;
            // if we reached the end of query, we execute it and reinitialize the
            // variable "query"
            if ((bool) preg_match('/;$/', $sql_line)) {
                $query = trim($query);
                $query = str_replace($replaced, $replacing, $query);
                // we don't execute "DROP TABLE" queries
                if (! (bool) preg_match('/^DROP TABLE/i', $query)) {
                    if ($dblayer == 'mysql') {
                        if ((bool) preg_match('/^(CREATE TABLE .*)[\s]*;[\s]*/im', $query, $matches)) {
                            $query = $matches[1] . ' DEFAULT CHARACTER SET utf8;';
                        }
                    }
                    $conn->executeStatement($query);
                }
                $query = '';
            }
        }
    }

    /**
     * Automatically activate all core themes in the "themes" directory.
     */
    public static function activateCoreThemes(): void
    {
        $themes = new themes();
        foreach ($themes->fs_themes as $theme_id => $fs_theme) {
            if (in_array($theme_id, ['modus'])) {
                $themes->perform_action('activate', $theme_id);
            }
        }
    }

    /**
     * Automatically activate some core plugins
     */
    public static function activateCorePlugins(): void
    {
        $plugins = new plugins();

        foreach ($plugins->fs_plugins as $plugin_id => $fs_plugin) {
            if (in_array($plugin_id, [])) {
                $plugins->perform_action('activate', $plugin_id);
            }
        }
    }

    /**
     * Connect to database during installation. Uses $_POST indirectly --
     * InstallWizard::boot() already calls Config::override('db_host', ...)
     * etc. with the submitted credentials before this runs, so
     * DbConnection::build() (which reads Config::db*()) resolves to them
     * directly; no local $_POST parsing needed here.
     *
     * @param array<int, string> $infos - populated with infos
     * @param array<int, string> $errors - populated with errors
     */
    public static function installDbConnect(array &$infos, array &$errors): ?Connection
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
            $errors[] = Lang::t($e->getMessage());

            return null;
        }
    }

    /**
     * PHP5 configuration directives for known shared-hosting providers,
     * folded verbatim from the deleted install/hosting.php (P23 sub-batch
     * 8f-6). Historical data: nothing in the current codebase reads it any
     * more (the last includer of install/hosting.php was removed years
     * before the 17.x rewrite); preserved as a typed constant per the 8f-6
     * migration decision rather than silently dropping the data file.
     *
     * @var array<string, string>
     */
    public const PHP5_HOSTING_HTACCESS = [
        // 1and1
        'kundenserver.de' => 'AddType x-mapp-php5 .php',

        // Apinc
        'apinc.org' => 'AddHandler x-httpd-php5 .php',

        // Free
        'free.fr' => 'php 1',

        // Lost Oasis
        'lost-oasis.net' => 'PHP_Version 5.0',
        'lo-data.net' => 'PHP_Version 5.0',

        // MediaTemple
        'gridserver.com' => 'AddHandler php5-script .php',

        // Online
        'online.net' => 'AddType application/x-httpd-php5 .php',

        // Ouvaton
        'web.ocsa-data.net' => 'AddHandler application/x-suexec-php5 .php',

        // OVH
        'ovh.net' => 'SetEnv PHP_VER 5',

        // Strato
        'rzone.de' => 'AddType application/x-httpd-php5 .php',

        // Web1.fr - NFrance
        'nfrance.com' => 'AddHandler php-fastcgi5 .php',
    ];
}
