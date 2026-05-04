<?php

declare(strict_types=1);

use Piwigo\Admin\Plugins;
use Piwigo\Admin\Themes;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * @package functions\admin\install
 */


/**
 * Loads a SQL file and executes all queries.
 * Before executing a query, $replaced is... replaced by $replacing. This is
 * useful when the SQL file contains generic words. Drop table queries are
 * not executed.
 *
 * @param string $filepath
 * @param string $replaced
 * @param string $replacing
 */
function execute_sqlfile(string $filepath, string $replaced, string $replacing, string $dblayer): void
{
    if (\Piwigo\Core\ServiceLocator::has(\Doctrine\DBAL\Connection::class)) {
        $conn = \Piwigo\Core\ServiceLocator::get(\Doctrine\DBAL\Connection::class);
    } else {
        $conn = \Piwigo\Db\DbConnection::build();
    }

    $sql_lines = file($filepath) ?: [];
    $query = '';
    foreach ($sql_lines as $sql_line) {
        $sql_line = trim($sql_line);
        if (preg_match('/(^--|^$)/', $sql_line)) {
            continue;
        }
        $query .= ' '.$sql_line;
        // if we reached the end of query, we execute it and reinitialize the
        // variable "query"
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

/**
 * Automatically activate all core themes in the "themes" directory.
 */
function activate_core_themes(): void
{
    $themes = new Themes();
    foreach ($themes->fs_themes as $theme_id => $fs_theme) {
        if (in_array($theme_id, ['modus'])) {
            $themes->perform_action('activate', $theme_id);
        }
    }
}

/**
 * Automatically activate some core plugins
 */
function activate_core_plugins(): void
{
    $plugins = new Plugins();

    foreach ($plugins->fs_plugins as $plugin_id => $fs_plugin) {
        if (in_array($plugin_id, [])) {
            $plugins->perform_action('activate', $plugin_id);
        }
    }
}

/**
 * Connect to database during installation. Uses $_POST.
 *
 * @param array &$infos - populated with infos
 * @param array &$errors - populated with errors
 */
/**
 * @param array<mixed> $infos
 * @param string[] $errors
 */
function install_db_connect(array &$infos, array &$errors): void
{
    $host   = is_scalar($_POST['dbhost'] ?? null) ? (string) $_POST['dbhost'] : '';
    $user   = is_scalar($_POST['dbuser'] ?? null) ? (string) $_POST['dbuser'] : '';
    $pass   = is_scalar($_POST['dbpasswd'] ?? null) ? (string) $_POST['dbpasswd'] : '';
    $dbname = is_scalar($_POST['dbname'] ?? null) ? (string) $_POST['dbname'] : '';

    // Parse host/port/socket.
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

    // Drop and recreate the database so every install starts from a clean slate.
    // Connection probing legitimately fails on bad credentials — silence the
    // PHP warning so the catch block can surface a clean error message.
    try {
        set_error_handler(static fn (): bool => true);
        try {
            $tmp = new mysqli($h, $user, $pass, '', $port, $socket);
        } finally {
            restore_error_handler();
        }
        if ($tmp->connect_error) {
            throw new \Piwigo\Exception\DbException($tmp->connect_error);
        }
        $safe = $tmp->real_escape_string($dbname);
        $tmp->query("DROP DATABASE IF EXISTS `{$safe}`");
        $tmp->query("CREATE DATABASE `{$safe}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $tmp->close();
    } catch (Exception $e) {
        $errors[] = $e->getMessage();
        return;
    }

    // Prime Config so get_dbal_connection() uses the install credentials.
    \Piwigo\Config\Config::override('db_host', $host);
    \Piwigo\Config\Config::override('db_user', $user);
    \Piwigo\Config\Config::override('db_password', $pass);
    \Piwigo\Config\Config::override('db_base', $dbname);

    try {
        get_dbal_connection();
        $dbVersion = \Piwigo\Db\DbInfo::version();
        if (version_compare($dbVersion, REQUIRED_MYSQL_VERSION, '<')) {
            $errors[] = sprintf(
                'your MySQL version is too old, you have "%s" and you need at least "%s"',
                $dbVersion,
                REQUIRED_MYSQL_VERSION
            );
        }
    } catch (Exception $e) {
        $errors[] = l10n($e->getMessage());
    }
}
