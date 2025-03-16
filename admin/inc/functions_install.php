<?php

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

class functions_install
{
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
    public static function execute_sqlfile($filepath, $replaced, $replacing, $dblayer)
    {
        $sql_lines = file($filepath);
        $query = '';
        foreach ($sql_lines as $sql_line) {
            $sql_line = trim($sql_line);
            if (preg_match('/(^--|^$)/', $sql_line)) {
                continue;
            }
            $query .= ' ' . $sql_line;
            // if we reached the end of query, we execute it and reinitialize the
            // variable "query"
            if (preg_match('/;$/', $sql_line)) {
                $query = trim($query);
                $query = str_replace($replaced, $replacing, $query);
                // we don't execute "DROP TABLE" queries
                if (! preg_match('/^DROP TABLE/i', $query)) {
                    if ($dblayer == 'mysql') {
                        if (preg_match('/^(CREATE TABLE .*)[\s]*;[\s]*/im', $query, $matches)) {
                            $query = $matches[1] . ' DEFAULT CHARACTER SET utf8' . ';';
                        }
                    }
                    functions_mysqli::pwg_query($query);
                }
                $query = '';
            }
        }
    }

    /**
     * Automatically activate all core themes in the "themes" directory.
     */
    public static function activate_core_themes()
    {
        $themes = new themes();
        foreach ($themes->fs_themes as $theme_id => $fs_theme) {
            if (in_array($theme_id, ['modus', 'smartpocket'])) {
                $themes->perform_action('activate', $theme_id);
            }
        }
    }

    /**
     * Automatically activate some core plugins
     */
    public static function activate_core_plugins()
    {
        $plugins = new plugins();

        foreach ($plugins->fs_plugins as $plugin_id => $fs_plugin) {
            if (in_array($plugin_id, [])) {
                $plugins->perform_action('activate', $plugin_id);
            }
        }
    }

    /**
     * Connect to database during installation. Uses $_POST.
     *
     * @param array $infos - populated with infos
     * @param array $errors - populated with errors
     */
    public static function install_db_connect(&$infos, &$errors)
    {
        try {
            functions_mysqli::pwg_db_connect(
                $_POST['dbhost'],
                $_POST['dbuser'],
                $_POST['dbpasswd'],
                $_POST['dbname']
            );
            functions_mysqli::pwg_db_check_version();
            functions_mysqli::pwg_query('DROP DATABASE IF EXISTS ' . $_POST['dbname']);
            functions_mysqli::pwg_query('CREATE DATABASE ' . $_POST['dbname']);
            global $mysqli;
            $mysqli->select_db($_POST['dbname']);
        } catch (Exception $e) {
            $errors[] = functions::l10n($e->getMessage());
        }
    }
}
