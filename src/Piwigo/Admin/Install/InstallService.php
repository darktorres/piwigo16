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
use Piwigo\Admin\Extensions\ExtensionLifecycle;
use Piwigo\Admin\Extensions\ExtensionRepository;
use Piwigo\Admin\Extensions\ExtensionScanner;
use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Admin\Extensions\PemCatalog;
use Piwigo\Admin\Extensions\ZipExtractor;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Core\AppInfo;
use Piwigo\Db\DbConnection;
use RuntimeException;

/**
 * Installation helpers, ported verbatim from the former
 * admin/include/functions_install.inc.php free functions (P23 sub-batch
 * 8f-6). Static methods on purpose: this class runs on the
 * pre-installation entry path (install.php) where no DI container exists,
 * matching the Env/FilesystemHelper/MysqliDb precedent.
 *
 * The former frozen install/db/86-database.php script and
 * admin/include/functions_install.inc.php's bare activate_core_themes()
 * delegate (which forwarded to activateCoreThemes() here) were both
 * deleted in P23 batch 8g-6; InstallWizard::performInstall() now calls
 * activateCoreThemes() here directly.
 */
final class InstallService
{
    /**
     * Loads a SQL file and executes all queries.
     * Before executing a query, $replaced is... replaced by $replacing. This is
     * useful when the SQL file contains generic words. Drop table queries are
     * not executed.
     */
    public static function executeSqlfile(Connection $conn, string $filepath, string $replaced, string $replacing): void
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
        $urlService = \Piwigo\Bootstrap\PresentationAccessor::urlService();
        $conn = DbConnection::build();
        $lifecycle = new ExtensionLifecycle(
            \Piwigo\Core\Lang::current(),
            new ExtensionRepository(\Piwigo\Db\EntityManagerFactory::build($conn)),
            new PemCatalog(new ZipExtractor(), \Piwigo\Bootstrap\InstallBootstrap::currentLogger()),
            $urlService,
            CurrentConfigService::current()->get(),
            \Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Admin\Extensions\PluginMigrationEntity::class),
            \Piwigo\Bootstrap\ExtendedDomainAccessor::activityService(),
            \Piwigo\Bootstrap\CoreDomainAccessor::userService(),
            \Piwigo\Bootstrap\PresentationAccessor::htmlService(),
            \Piwigo\Config\CurrentConfig::current(),
            \Piwigo\Bootstrap\InfrastructureAccessor::wsContext(),
            \Piwigo\Bootstrap\CoreDomainAccessor::accessControl(),
        );
        $fs_themes = new ExtensionScanner()
            ->scan(ExtensionType::Theme, $urlService, \Piwigo\Core\Lang::current());
        foreach ($fs_themes as $theme_id => $fs_theme) {
            if (in_array($theme_id, [AppInfo::DEFAULT_TEMPLATE], true)) {
                $lifecycle->performAction(ExtensionType::Theme, 'activate', $theme_id, $fs_theme);
            }
        }
    }

    /**
     * Automatically activate some core plugins
     */
    public static function activateCorePlugins(): void
    {
        // No core plugins are auto-activated at install time (empty list,
        // matching the original's own empty in_array() haystack).
        new ExtensionScanner()
            ->scan(ExtensionType::Plugin, \Piwigo\Bootstrap\PresentationAccessor::urlService(), \Piwigo\Core\Lang::current());
    }

    /**
     * Connect to database during installation. Uses $_POST indirectly --
     * InstallWizard::boot() already calls DbCredentials::seed(...) with the
     * submitted credentials before this runs, so DbConnection::build()
     * (which reads DbCredentials::current()) resolves to them directly; no
     * local $_POST parsing needed here.
     *
     * @param array<int, string> $infos - accepted by reference for
     *   signature symmetry with $errors, but never written to by this
     *   method
     * @param array<int, string> $errors - populated with errors
     */
    public static function installDbConnect(array &$infos, array &$errors): ?Connection
    {
        try {
            $conn = DbConnection::build();
            $conn->getNativeConnection();

            $version = new \Piwigo\Db\DbInfo($conn)
                ->version();

            // pgsql support pass: real bug found live -- this ran
            // unconditionally against every driver, so a genuine
            // PostgreSQL 18.4 server failed here with "your MySQL version
            // is too old, you have "PostgreSQL 18.4 (...)..." (version_compare()
            // can't parse Postgres's own full descriptive version() string
            // against a bare MySQL-shaped floor at all). PostgreSQL's
            // version() output isn't a bare X.Y.Z the way MySQL's is
            // either, so its own check needs the leading numeric version
            // extracted first.
            if ($conn->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform) {
                if (preg_match('/PostgreSQL (\d+(?:\.\d+)*)/', $version, $matches) !== 1) {
                    throw new Exception('could not determine your PostgreSQL version from "' . $version . '"');
                }

                if (version_compare($matches[1], \Piwigo\Db\SqlDialect::REQUIRED_POSTGRES_VERSION, '<')) {
                    throw new Exception(sprintf('your PostgreSQL version is too old, you have "%s" and you need at least "%s"', $matches[1], \Piwigo\Db\SqlDialect::REQUIRED_POSTGRES_VERSION));
                }
            } elseif (version_compare($version, \Piwigo\Db\SqlDialect::REQUIRED_MYSQL_VERSION, '<')) {
                throw new Exception(sprintf('your MySQL version is too old, you have "%s" and you need at least "%s"', $version, \Piwigo\Db\SqlDialect::REQUIRED_MYSQL_VERSION));
            }

            return $conn;
        } catch (Exception $e) {
            $errors[] = \Piwigo\Core\Lang::current()->t($e->getMessage());

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
    public const array PHP5_HOSTING_HTACCESS = [
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
