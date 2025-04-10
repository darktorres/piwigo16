<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\admin\inc;

use Exception;
use Piwigo\inc\dblayer\functions_mysqli;
use Piwigo\inc\functions;

final class functions_install
{
    /**
     * Loads a SQL file and executes all queries.
     * Before executing a query, $replaced is... replaced by $replacing. This is
     * useful when the SQL file contains generic words. Drop table queries are
     * not executed.
     */
    public static function execute_sqlfile(
        string $filepath
    ): void {
        $sql_lines = file($filepath);
        $query = '';

        foreach ($sql_lines as $sql_line) {
            $sql_line = trim($sql_line);

            if (preg_match('/(^--|^$)/', $sql_line)) {
                continue;
            }

            $query .= ' ' . $sql_line;

            // if we reached the end of query, we execute it and reinitialize the variable "query"
            if (str_ends_with($sql_line, ';')) {
                $query = trim($query);

                // we don't execute "DROP TABLE" queries
                if (! preg_match('/^DROP TABLE/i', $query)) {
                    functions_mysqli::pwg_query($query);
                }

                $query = '';
            }
        }
    }

    /**
     * Automatically activate all core themes in the "themes" directory.
     */
    public static function activate_core_themes(): void
    {
        $themes = new themes();

        foreach (array_keys($themes->fs_themes) as $theme_id) {
            if (in_array($theme_id, ['modus', 'smartpocket'])) {
                $themes->perform_action('activate', $theme_id);
            }
        }
    }

    /**
     * Automatically activate some core plugins
     */
    public static function activate_core_plugins(): void
    {
        $plugins = new plugins();

        foreach (array_keys($plugins->fs_plugins) as $plugin_id) {
            if (in_array($plugin_id, [])) {
                $plugins->perform_action('activate', $plugin_id);
            }
        }
    }

    /**
     * Connect to database during installation. Uses $_POST.
     *
     * @param array<string> $errors - populated with errors
     */
    public static function install_db_connect(
        array &$errors
    ): void {
        try {
            functions_mysqli::pwg_db_connect(
                $_POST['dbhost'],
                $_POST['dbuser'],
                $_POST['dbpasswd'],
                $_POST['dbname']
            );
            functions_mysqli::pwg_db_check_version();
            functions_mysqli::pwg_query("DROP DATABASE IF EXISTS {$_POST['dbname']};");
            functions_mysqli::pwg_query("CREATE DATABASE {$_POST['dbname']};");
            global $mysqli;
            $mysqli->select_db($_POST['dbname']);
        } catch (Exception $exception) {
            $errors[] = functions::l10n($exception->getMessage());
        }
    }
}
