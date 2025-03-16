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

    // concerning upgrade, we use the default tables
    public static function prepare_conf_upgrade()
    {
        global $prefixTable;

        // $conf is not used for users tables
        // define cannot be re-defined
        define('CATEGORIES_TABLE', $prefixTable . 'categories');
        define('COMMENTS_TABLE', $prefixTable . 'comments');
        define('CONFIG_TABLE', $prefixTable . 'config');
        define('FAVORITES_TABLE', $prefixTable . 'favorites');
        define('GROUP_ACCESS_TABLE', $prefixTable . 'group_access');
        define('GROUPS_TABLE', $prefixTable . 'groups');
        define('HISTORY_TABLE', $prefixTable . 'history');
        define('HISTORY_SUMMARY_TABLE', $prefixTable . 'history_summary');
        define('IMAGE_CATEGORY_TABLE', $prefixTable . 'image_category');
        define('IMAGES_TABLE', $prefixTable . 'images');
        define('SESSIONS_TABLE', $prefixTable . 'sessions');
        define('SITES_TABLE', $prefixTable . 'sites');
        define('USER_ACCESS_TABLE', $prefixTable . 'user_access');
        define('USER_GROUP_TABLE', $prefixTable . 'user_group');
        define('USERS_TABLE', $prefixTable . 'users');
        define('USER_INFOS_TABLE', $prefixTable . 'user_infos');
        define('USER_FEED_TABLE', $prefixTable . 'user_feed');
        define('RATE_TABLE', $prefixTable . 'rate');
        define('USER_CACHE_TABLE', $prefixTable . 'user_cache');
        define('USER_CACHE_CATEGORIES_TABLE', $prefixTable . 'user_cache_categories');
        define('CADDIE_TABLE', $prefixTable . 'caddie');
        define('UPGRADE_TABLE', $prefixTable . 'upgrade');
        define('SEARCH_TABLE', $prefixTable . 'search');
        define('USER_MAIL_NOTIFICATION_TABLE', $prefixTable . 'user_mail_notification');
        define('TAGS_TABLE', $prefixTable . 'tags');
        define('IMAGE_TAG_TABLE', $prefixTable . 'image_tag');
        define('PLUGINS_TABLE', $prefixTable . 'plugins');
        define('OLD_PERMALINKS_TABLE', $prefixTable . 'old_permalinks');
        define('THEMES_TABLE', $prefixTable . 'themes');
        define('LANGUAGES_TABLE', $prefixTable . 'languages');
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

        $query = '
  SELECT id
  FROM ' . PREFIX_TABLE . 'plugins
  WHERE state = \'active\'
  AND id NOT IN (\'' . implode('\',\'', $standard_plugins) . '\')
  ;';

        $result = functions_mysqli::pwg_query($query);
        $plugins = [];
        while ($row = functions_mysqli::pwg_db_fetch_assoc($result)) {
            $plugins[] = $row['id'];
        }

        if (! empty($plugins)) {
            $query = '
  UPDATE ' . PREFIX_TABLE . 'plugins
  SET state=\'inactive\'
  WHERE id IN (\'' . implode('\',\'', $plugins) . '\')
  ;';
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

        $query = '
  SELECT
      id,
      name
    FROM ' . PREFIX_TABLE . 'themes
    WHERE id NOT IN (\'' . implode("','", $standard_themes) . '\')
  ;';
        $result = functions_mysqli::pwg_query($query);
        $theme_ids = [];
        $theme_names = [];
        while ($row = functions_mysqli::pwg_db_fetch_assoc($result)) {
            $theme_ids[] = $row['id'];
            $theme_names[] = $row['name'];
        }

        if (! empty($theme_ids)) {
            $query = '
  DELETE
    FROM ' . PREFIX_TABLE . 'themes
    WHERE id IN (\'' . implode("','", $theme_ids) . '\')
  ;';
            functions_mysqli::pwg_query($query);

            $page['infos'][] = functions::l10n('As a precaution, following themes have been deactivated. You must check for themes upgrade before reactivating them:')
                                . '<p><i>' . implode(', ', $theme_names) . '</i></p>';

            // what is the default theme?
            $query = '
  SELECT theme
    FROM ' . PREFIX_TABLE . 'user_infos
    WHERE user_id = ' . $conf['default_user_id'] . '
  ;';
            list($default_theme) = functions_mysqli::pwg_db_fetch_row(functions_mysqli::pwg_query($query));

            // if the default theme has just been deactivated, let's set another core theme as default
            if (in_array($default_theme, $theme_ids)) {
                // make sure default Piwigo theme is active
                $query = '
  SELECT
      COUNT(*)
    FROM ' . PREFIX_TABLE . 'themes
    WHERE id = \'' . PHPWG_DEFAULT_TEMPLATE . '\'
  ;';
                list($counter) = functions_mysqli::pwg_db_fetch_row(functions_mysqli::pwg_query($query));
                if ($counter < 1) {
                    // we need to activate theme first
                    $themes = new themes();
                    $themes->perform_action('activate', PHPWG_DEFAULT_TEMPLATE);
                }

                // then associate it to default user
                $query = '
  UPDATE ' . PREFIX_TABLE . 'user_infos
    SET theme = \'' . PHPWG_DEFAULT_TEMPLATE . '\'
    WHERE user_id = ' . $conf['default_user_id'] . '
  ;';
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

        if (version_compare($current_release, '2.0', '>=') and isset($_COOKIE[session_name()])) {
            // Check if user is already connected as webmaster
            session_start();
            if (! empty($_SESSION['pwg_uid'])) {
                $query = '
  SELECT status
    FROM ' . USER_INFOS_TABLE . '
    WHERE user_id = ' . $_SESSION['pwg_uid'] . '
  ;';
                functions_mysqli::pwg_query($query);

                $row = functions_mysqli::pwg_db_fetch_assoc(functions_mysqli::pwg_query($query));
                if (isset($row['status']) and $row['status'] == 'webmaster') {
                    define('PHPWG_IN_UPGRADE', true);
                    return;
                }
            }
        }

        if (! isset($_POST['username']) or ! isset($_POST['password'])) {
            return;
        }

        $username = $_POST['username'];
        $password = $_POST['password'];

        if (function_exists('get_magic_quotes_gpc') && ! @get_magic_quotes_gpc()) {
            $username = functions_mysqli::pwg_db_real_escape_string($username);
        }

        if (version_compare($current_release, '2.0', '<')) {
            $username = utf8_decode($username);
            $password = utf8_decode($password);
        }

        if (version_compare($current_release, '1.5', '<')) {
            $query = '
  SELECT password, status
  FROM ' . USERS_TABLE . '
  WHERE username = \'' . $username . '\'
  ;';
        } else {
            $query = '
  SELECT u.password, ui.status
  FROM ' . USERS_TABLE . ' AS u
  INNER JOIN ' . USER_INFOS_TABLE . ' AS ui
  ON u.' . $conf['user_fields']['id'] . '=ui.user_id
  WHERE ' . $conf['user_fields']['username'] . '=\'' . $username . '\'
  ;';
        }
        $row = functions_mysqli::pwg_db_fetch_assoc(functions_mysqli::pwg_query($query));

        if (! $conf['password_verify']($password, $row['password'])) {
            $page['errors'][] = functions::l10n('Invalid password!');
        } elseif ($row['status'] != 'admin' and $row['status'] != 'webmaster') {
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

        // if ($contents = opendir($upgrades_path))
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
        $query = '
  SELECT id
    FROM ' . UPGRADE_TABLE . '
  ;';
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
