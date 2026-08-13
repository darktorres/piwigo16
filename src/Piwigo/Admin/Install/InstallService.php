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
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Exception;
use Piwigo\Admin\Extensions\ExtensionLifecycle;
use Piwigo\Admin\Extensions\ExtensionRepository;
use Piwigo\Admin\Extensions\ExtensionScanner;
use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Admin\Extensions\PemCatalog;
use Piwigo\Admin\Extensions\ZipExtractor;
use Piwigo\Bootstrap\CoreDomainAccessor;
use Piwigo\Bootstrap\ExtendedDomainAccessor;
use Piwigo\Bootstrap\InstallBootstrap;
use Piwigo\Bootstrap\PresentationAccessor;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Db\DbConnection;
use Piwigo\Db\DbInfo;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\SqlDialect;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\PluginConfig\PluginMigrationEntity;
use Piwigo\Users\CurrentUser;
use RuntimeException;

/**
 * Installation helpers used on the pre-installation entry path
 * (install.php), where no DI container exists. Methods are static to
 * match the Env/FilesystemHelper/MysqliDb precedent.
 */
final class InstallService
{
    /**
     * Loads a SQL file and executes all queries. Drop table queries are
     * not executed.
     */
    public static function executeSqlfile(Connection $conn, string $filepath): void
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
    public static function activateCoreThemes(Lang $lang, CurrentUser $currentUser, CurrentConfigService $currentConfigService, CurrentConfig $currentConfig, Paths $paths, EventDispatcher $eventDispatcher): void
    {
        $urlService = PresentationAccessor::urlService();
        $conn = DbConnection::build();
        $lifecycle = new ExtensionLifecycle(
            $lang,
            new ExtensionRepository(EntityManagerFactory::build($conn)),
            new PemCatalog(new ZipExtractor(), InstallBootstrap::currentLogger(), $currentUser, $paths, $currentConfig),
            $urlService,
            $currentConfigService->get(),
            EntityManagerFactory::build($conn)->getRepository(PluginMigrationEntity::class),
            ExtendedDomainAccessor::activityService(),
            CoreDomainAccessor::userService(),
            PresentationAccessor::htmlService(),
            $currentConfig,
            $paths,
            $currentUser,
            $eventDispatcher,
            PresentationAccessor::pluginRegistry(),
            PresentationAccessor::themeRegistry(),
        );
        $fs_themes = new ExtensionScanner()
            ->scan(ExtensionType::Theme, $urlService, $lang, $paths, $currentUser, $eventDispatcher, $currentConfig);
        foreach ($fs_themes as $theme_id => $fs_theme) {
            if (in_array($theme_id, [AppInfo::DEFAULT_TEMPLATE], true)) {
                $lifecycle->performAction(ExtensionType::Theme, 'activate', $theme_id, $fs_theme);
            }
        }
    }

    /**
     * Automatically activate some core plugins
     */
    public static function activateCorePlugins(Lang $lang, Paths $paths, CurrentUser $currentUser, EventDispatcher $eventDispatcher, CurrentConfig $currentConfig): void
    {
        // No core plugins are auto-activated at install time (empty list,
        // matching the original's own empty in_array() haystack).
        new ExtensionScanner()
            ->scan(ExtensionType::Plugin, PresentationAccessor::urlService(), $lang, $paths, $currentUser, $eventDispatcher, $currentConfig);
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
    public static function installDbConnect(array &$infos, array &$errors, Lang $lang): ?Connection
    {
        try {
            $conn = DbConnection::build();
            $conn->getNativeConnection();

            $version = new DbInfo($conn)
                ->version();

            // Unconditionally comparing against the MySQL floor would fail
            // for a genuine PostgreSQL server with "your MySQL version is
            // too old, you have "PostgreSQL 18.4 (...)..." (version_compare()
            // can't parse Postgres's own full descriptive version() string
            // against a bare MySQL-shaped floor at all). PostgreSQL's
            // version() output isn't a bare X.Y.Z the way MySQL's is
            // either, so its own check needs the leading numeric version
            // extracted first.
            if ($conn->getDatabasePlatform() instanceof PostgreSQLPlatform) {
                if (preg_match('/PostgreSQL (\d+(?:\.\d+)*)/', $version, $matches) !== 1) {
                    throw new Exception('could not determine your PostgreSQL version from "' . $version . '"');
                }

                if (version_compare($matches[1], SqlDialect::REQUIRED_POSTGRES_VERSION, '<')) {
                    throw new Exception(sprintf('your PostgreSQL version is too old, you have "%s" and you need at least "%s"', $matches[1], SqlDialect::REQUIRED_POSTGRES_VERSION));
                }
            } elseif (version_compare($version, SqlDialect::REQUIRED_MYSQL_VERSION, '<')) {
                throw new Exception(sprintf('your MySQL version is too old, you have "%s" and you need at least "%s"', $version, SqlDialect::REQUIRED_MYSQL_VERSION));
            }

            return $conn;
        } catch (Exception $e) {
            $errors[] = $lang->t($e->getMessage());

            return null;
        }
    }

    /**
     * PHP5 configuration directives for known shared-hosting providers.
     * Not read anywhere in the current codebase.
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
