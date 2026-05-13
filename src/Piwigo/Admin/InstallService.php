<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Doctrine\DBAL\Connection;
use Piwigo\Config\Config;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
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

        $sql_lines_raw = file($filepath);
        $sql_lines = $sql_lines_raw !== false ? $sql_lines_raw : [];
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
        $themes = Kernel::service(Themes::class);
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
        $rawHost   = $_POST['dbhost']   ?? null;
        $rawUser   = $_POST['dbuser']   ?? null;
        $rawPass   = $_POST['dbpasswd'] ?? null;
        $rawDbname = $_POST['dbname']   ?? null;
        $host   = is_string($rawHost) ? $rawHost : '';
        $user   = is_string($rawUser) ? $rawUser : '';
        $pass   = is_string($rawPass) ? $rawPass : '';
        $dbname = is_string($rawDbname) ? $rawDbname : '';

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
            if ($tmp->connect_error !== null && $tmp->connect_error !== '') {
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
            $errors[] = Lang::t($e->getMessage());
        }
    }
}
