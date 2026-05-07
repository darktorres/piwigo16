<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Doctrine\DBAL\Connection;
use Piwigo\Config\Config;
use Piwigo\Core\ServiceLocator;
use Piwigo\Db\DbConnection;
use Piwigo\Db\DbInfo;
use Piwigo\Db\Dml;
use Piwigo\Exception\DbException;

final class InstallService
{
    public static function executeSqlFile(string $filepath, string $replaced, string $replacing, string $dblayer): void
    {
        if (ServiceLocator::has(Connection::class)) {
            $conn = ServiceLocator::get(Connection::class);
        } else {
            $conn = DbConnection::build();
        }

        $sql_lines = file($filepath) ?: [];
        $query     = '';
        foreach ($sql_lines as $sql_line) {
            $sql_line = trim($sql_line);
            if (preg_match('/(^--|^$)/', $sql_line)) {
                continue;
            }
            $query .= ' '.$sql_line;
            if (preg_match('/;$/', $sql_line)) {
                $query = trim($query);
                $query = str_replace($replaced, $replacing, $query);
                if ('mysql' == $dblayer) {
                    if (preg_match('/^(CREATE TABLE .*)[\s]*;[\s]*/im', $query, $matches)) {
                        $query = $matches[1].' DEFAULT CHARACTER SET utf8'.';';
                    }
                }
                $conn->executeStatement($query);
                $query = '';
            }
        }
    }

    public static function activateCoreThemes(): void
    {
        $themes = new Themes();
        foreach ($themes->fs_themes as $theme_id => $fs_theme) {
            if (in_array($theme_id, ['modus'])) {
                $themes->performAction('activate', $theme_id);
            }
        }
    }

    public static function activateCorePlugins(): void
    {
        $plugins = new Plugins();
        foreach ($plugins->fs_plugins as $plugin_id => $fs_plugin) {
            if (in_array($plugin_id, [])) {
                $plugins->performAction('activate', $plugin_id);
            }
        }
    }

    /**
     * @param array<mixed> $infos
     * @param string[]     $errors
     */
    public static function installDbConnect(array &$infos, array &$errors): void
    {
        $host   = is_scalar($_POST['dbhost'] ?? null) ? (string) $_POST['dbhost'] : '';
        $user   = is_scalar($_POST['dbuser'] ?? null) ? (string) $_POST['dbuser'] : '';
        $pass   = is_scalar($_POST['dbpasswd'] ?? null) ? (string) $_POST['dbpasswd'] : '';
        $dbname = is_scalar($_POST['dbname'] ?? null) ? (string) $_POST['dbname'] : '';

        $port   = null;
        $socket = null;
        $h      = $host;
        if (str_starts_with($host, '/')) {
            $socket = $host;
            $h      = null;
        } elseif (str_contains($host, ':')) {
            [$h, $port_str] = explode(':', $host);
            $port = (int) $port_str;
        }

        try {
            set_error_handler(static fn (): bool => true);
            try {
                $tmp = new \mysqli($h, $user, $pass, '', $port, $socket);
            } finally {
                restore_error_handler();
            }
            if ($tmp->connect_error) {
                throw new DbException($tmp->connect_error);
            }
            $safe = $tmp->real_escape_string($dbname);
            $tmp->query("DROP DATABASE IF EXISTS `{$safe}`");
            $tmp->query("CREATE DATABASE `{$safe}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $tmp->close();
        } catch (\Exception $e) {
            $errors[] = $e->getMessage();
            return;
        }

        Config::override('db_host', $host);
        Config::override('db_user', $user);
        Config::override('db_password', $pass);
        Config::override('db_base', $dbname);

        try {
            DbConnection::get();
            $dbVersion = DbInfo::version();
            if (version_compare($dbVersion, Dml::REQUIRED_MYSQL_VERSION, '<')) {
                $errors[] = sprintf(
                    'your MySQL version is too old, you have "%s" and you need at least "%s"',
                    $dbVersion,
                    Dml::REQUIRED_MYSQL_VERSION
                );
            }
        } catch (\Exception $e) {
            $errors[] = l10n($e->getMessage());
        }
    }
}
