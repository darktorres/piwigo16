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

class functions_upgrade
{
    public static function check_upgrade()
    {
        if (defined('PHPWG_IN_UPGRADE')) {
            return PHPWG_IN_UPGRADE;
        }

        return false;
    }

    // Deactivate all non-standard plugins
    public static function deactivate_non_standard_plugins()
    {
        global $page;

        $standard_plugins = [
            'AdminTools',
            'TakeATour',
            'language_switch',
            'LocalFilesEditor',
        ];

        $implodedStandardPlugins = implode('\',\'', $standard_plugins);
        $query = <<<SQL
            SELECT id
            FROM plugins
            WHERE state = 'active'
                AND id NOT IN ('{$implodedStandardPlugins}');
            SQL;

        $result = functions_mysqli::pwg_query($query);
        $plugins = [];

        while ($row = functions_mysqli::pwg_db_fetch_assoc($result)) {
            $plugins[] = $row['id'];
        }

        if (! empty($plugins)) {
            $implodedPlugins = implode('\',\'', $plugins);
            $query = <<<SQL
                UPDATE plugins
                SET state = 'inactive'
                WHERE id IN ('{$implodedPlugins}');
                SQL;
            functions_mysqli::pwg_query($query);

            $page['infos'][] = functions::l10n('As a precaution, following plugins have been deactivated. You must check for plugins upgrade before reactivating them:')
                                . '<p><i>' . implode(', ', $plugins) . '</i></p>';
        }
    }

    // Deactivate all non-standard themes
    public static function deactivate_non_standard_themes()
    {
        global $page, $conf;

        $standard_themes = [
            'modus',
            'elegant',
            'smartpocket',
        ];

        $implodedStandardThemes = implode("','", $standard_themes);
        $query = <<<SQL
            SELECT id, name
            FROM themes
            WHERE id NOT IN ('{$implodedStandardThemes}');
            SQL;
        $result = functions_mysqli::pwg_query($query);
        $theme_ids = [];
        $theme_names = [];

        while ($row = functions_mysqli::pwg_db_fetch_assoc($result)) {
            $theme_ids[] = $row['id'];
            $theme_names[] = $row['name'];
        }

        if (! empty($theme_ids)) {
            $implodedThemeIds = implode("','", $theme_ids);
            $query = <<<SQL
                DELETE FROM themes
                WHERE id IN ('{$implodedThemeIds}');
                SQL;
            functions_mysqli::pwg_query($query);

            $page['infos'][] = functions::l10n('As a precaution, following themes have been deactivated. You must check for themes upgrade before reactivating them:')
                                . '<p><i>' . implode(', ', $theme_names) . '</i></p>';

            // what is the default theme?
            $query = <<<SQL
                SELECT theme
                FROM user_infos
                WHERE user_id = {$conf['default_user_id']};
                SQL;
            list($default_theme) = functions_mysqli::pwg_db_fetch_row(functions_mysqli::pwg_query($query));

            // if the default theme has just been deactivated, let's set another core theme as default
            if (in_array($default_theme, $theme_ids)) {
                // make sure default Piwigo theme is active
                $defaultTemplate = PHPWG_DEFAULT_TEMPLATE;
                $query = <<<SQL
                    SELECT COUNT(*)
                    FROM themes
                    WHERE id = '{$defaultTemplate}';
                    SQL;
                list($counter) = functions_mysqli::pwg_db_fetch_row(functions_mysqli::pwg_query($query));

                if ($counter < 1) {
                    // we need to activate theme first
                    $themes = new themes();
                    $themes->perform_action('activate', PHPWG_DEFAULT_TEMPLATE);
                }

                // then associate it to default user
                $defaultTemplate = PHPWG_DEFAULT_TEMPLATE;
                $query = <<<SQL
                    UPDATE user_infos
                    SET theme = '{$defaultTemplate}'
                    WHERE user_id = {$conf['default_user_id']};
                    SQL;
                functions_mysqli::pwg_query($query);
            }
        }
    }

    // Deactivate all templates
    public static function deactivate_templates()
    {
        functions::conf_update_param('extents_for_templates', []);
    }

    // Check access rights
    public static function check_upgrade_access_rights()
    {
        global $conf, $page, $current_release;

        if (version_compare($current_release, '2.0', '>=') and
            isset($_COOKIE[session_name()])
        ) {
            // Check if user is already connected as webmaster
            session_start();
            if (! empty($_SESSION['pwg_uid'])) {
                $query = <<<SQL
                    SELECT status
                    FROM user_infos
                    WHERE user_id = {$_SESSION['pwg_uid']};
                    SQL;
                functions_mysqli::pwg_query($query);

                $row = functions_mysqli::pwg_db_fetch_assoc(functions_mysqli::pwg_query($query));

                if (isset($row['status']) and
                    $row['status'] == 'webmaster'
                ) {
                    define('PHPWG_IN_UPGRADE', true);
                    return;
                }
            }
        }

        if (! isset($_POST['username']) or
            ! isset($_POST['password'])
        ) {
            return;
        }

        $username = $_POST['username'];
        $password = $_POST['password'];

        if (function_exists('get_magic_quotes_gpc') &&
            ! get_magic_quotes_gpc()
        ) {
            $username = functions_mysqli::pwg_db_real_escape_string($username);
        }

        if (version_compare($current_release, '2.0', '<')) {
            $username = utf8_decode($username);
            $password = utf8_decode($password);
        }

        if (version_compare($current_release, '1.5', '<')) {
            $query = <<<SQL
                SELECT password, status
                FROM users
                WHERE username = '{$username}';
                SQL;
        } else {
            $query = <<<SQL
                SELECT u.password, ui.status
                FROM users AS u
                INNER JOIN user_infos AS ui ON u.{$conf['user_fields']['id']} = ui.user_id
                WHERE {$conf['user_fields']['username']} = '{$username}';
                SQL;
        }

        $row = functions_mysqli::pwg_db_fetch_assoc(functions_mysqli::pwg_query($query));

        if (! $conf['password_verify']($password, $row['password'])) {
            $page['errors'][] = functions::l10n('Invalid password!');
        } elseif ($row['status'] != 'admin' and
                  $row['status'] != 'webmaster'
        ) {
            $page['errors'][] = functions::l10n('You do not have access rights to run upgrade');
        } else {
            define('PHPWG_IN_UPGRADE', true);
        }
    }

    /**
     * which upgrades are available ?
     *
     * @return array
     */
    public static function get_available_upgrade_ids()
    {
        // $upgrades_path = PHPWG_ROOT_PATH.'install/db';

        // $available_upgrade_ids = array();
        // $contents = opendir($upgrades_path);

        // if ($contents)
        // {
        //   while (($node = readdir($contents)) !== false)
        //   {
        //     if (is_file($upgrades_path.'/'.$node)
        //         and preg_match('/^(.*?)-database\.php$/', $node, $match))
        //     {
        //       $available_upgrade_ids[] = $match[1];
        //     }
        //   }
        // }
        // natcasesort($available_upgrade_ids);

        // return $available_upgrade_ids;
        return [];
    }

    /**
     * returns true if there are available upgrade files
     */
    public static function check_upgrade_feed()
    {
        // retrieve already applied upgrades
        $query = <<<SQL
            SELECT id
            FROM upgrade;
            SQL;
        $applied = functions::array_from_query($query, 'id');

        // retrieve existing upgrades
        $existing = self::get_available_upgrade_ids();

        // which upgrades need to be applied?
        return count(array_diff($existing, $applied)) > 0;
    }

    public static function upgrade_db_connect()
    {
        global $conf;

        try {
            functions_mysqli::pwg_db_connect(
                $conf['db_host'],
                $conf['db_user'],
                $conf['db_password'],
                $conf['db_base']
            );
            functions_mysqli::pwg_db_check_version();
        } catch (Exception $e) {
            functions_mysqli::my_error(functions::l10n($e->getMessage()), true);
        }
    }
}
