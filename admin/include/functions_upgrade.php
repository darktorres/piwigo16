<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Admin\themes;
use Piwigo\Core\AppInfo;
use Piwigo\Db\Tables;

function check_upgrade(): bool
{
    if (defined('PHPWG_IN_UPGRADE')) {
        // PHPWG_IN_UPGRADE is only ever define()'d with a bool literal `true`
        // (see check_upgrade_access_rights() below); is_bool() reflects that
        // real invariant without resorting to an unsafe cast on a mixed constant.
        $in_upgrade = PHPWG_IN_UPGRADE;
        return is_bool($in_upgrade) && $in_upgrade;
    }
    return false;
}

// concerning upgrade, we use the default tables
function prepare_conf_upgrade(): void
{
    /** @var string $prefixeTable */
    global $prefixeTable;

    // $conf is not used for users tables
    // define cannot be re-defined
    define('Tables::categories()', $prefixeTable . 'categories');
    define('Tables::comments()', $prefixeTable . 'comments');
    define('Tables::config()', $prefixeTable . 'config');
    define('Tables::favorites()', $prefixeTable . 'favorites');
    define('Tables::groupAccess()', $prefixeTable . 'group_access');
    define('Tables::groups()', $prefixeTable . 'groups');
    define('Tables::history()', $prefixeTable . 'history');
    define('Tables::historySummary()', $prefixeTable . 'history_summary');
    define('Tables::imageCategory()', $prefixeTable . 'image_category');
    define('Tables::images()', $prefixeTable . 'images');
    define('Tables::sessions()', $prefixeTable . 'sessions');
    define('Tables::sites()', $prefixeTable . 'sites');
    define('Tables::userAccess()', $prefixeTable . 'user_access');
    define('Tables::userGroup()', $prefixeTable . 'user_group');
    define('Tables::users()', $prefixeTable . 'users');
    define('Tables::userInfos()', $prefixeTable . 'user_infos');
    define('Tables::userFeed()', $prefixeTable . 'user_feed');
    define('Tables::rate()', $prefixeTable . 'rate');
    define('Tables::userCache()', $prefixeTable . 'user_cache');
    define('Tables::userCacheCategories()', $prefixeTable . 'user_cache_categories');
    define('Tables::caddie()', $prefixeTable . 'caddie');
    define('Tables::upgrade()', $prefixeTable . 'upgrade');
    define('Tables::search()', $prefixeTable . 'search');
    define('Tables::userMailNotification()', $prefixeTable . 'user_mail_notification');
    define('Tables::tags()', $prefixeTable . 'tags');
    define('Tables::imageTag()', $prefixeTable . 'image_tag');
    define('Tables::plugins()', $prefixeTable . 'plugins');
    define('Tables::oldPermalinks()', $prefixeTable . 'old_permalinks');
    define('Tables::themes()', $prefixeTable . 'themes');
    define('Tables::languages()', $prefixeTable . 'languages');
}

// Deactivate all non-standard plugins
function deactivate_non_standard_plugins(): void
{
    /** @var array<string, mixed> $page */
    global $page;

    if (! is_array($page['infos'] ?? null)) {
        $page['infos'] = [];
    }

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

    $result = pwg_query($query);
    $plugins = [];
    while ((bool) ($row = pwg_db_fetch_assoc($result))) {
        $plugins[] = $row['id'];
    }

    if (! empty($plugins)) {
        $query = '
UPDATE ' . PREFIX_TABLE . 'plugins
SET state=\'inactive\'
WHERE id IN (\'' . implode('\',\'', $plugins) . '\')
;';
        pwg_query($query);

        $page['infos'][] = l10n('As a precaution, following plugins have been deactivated. You must check for plugins upgrade before reactiving them:')
                            . '<p><i>' . implode(', ', $plugins) . '</i></p>';
    }
}

// Deactivate all non-standard themes
function deactivate_non_standard_themes(): void
{
    /**
     * @var array<string, mixed> $page
     * @var array<string, mixed> $conf
     */
    global $page, $conf;

    if (! is_array($page['infos'] ?? null)) {
        $page['infos'] = [];
    }

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
    $result = pwg_query($query);
    $theme_ids = [];
    $theme_names = [];
    while ((bool) ($row = pwg_db_fetch_assoc($result))) {
        $theme_ids[] = $row['id'];
        $theme_names[] = $row['name'];
    }

    if (! empty($theme_ids)) {
        $query = '
DELETE
  FROM ' . PREFIX_TABLE . 'themes
  WHERE id IN (\'' . implode("','", $theme_ids) . '\')
;';
        pwg_query($query);

        $page['infos'][] = l10n('As a precaution, following themes have been deactivated. You must check for themes upgrade before reactiving them:')
                            . '<p><i>' . implode(', ', $theme_names) . '</i></p>';

        // what is the default theme?
        // $conf['default_user_id'] is always an int (see include/config_default.inc.php)
        $default_user_id = is_numeric($conf['default_user_id']) ? (int) $conf['default_user_id'] : 0;
        $query = '
SELECT theme
  FROM ' . PREFIX_TABLE . 'user_infos
  WHERE user_id = ' . $default_user_id . '
;';
        $row = pwg_db_fetch_row(pwg_query($query));
        assert($row !== null);
        [$default_theme] = $row;

        // if the default theme has just been deactivated, let's set another core theme as default
        if (in_array($default_theme, $theme_ids)) {
            // make sure default Piwigo theme is active
            $query = '
SELECT
    COUNT(*)
  FROM ' . PREFIX_TABLE . 'themes
  WHERE id = \'' . AppInfo::DEFAULT_TEMPLATE . '\'
;';
            $row = pwg_db_fetch_row(pwg_query($query));
            assert($row !== null);
            [$counter] = $row;
            if ($counter < 1) {
                // we need to activate theme first
                $themes = new themes();
                $themes->perform_action('activate', AppInfo::DEFAULT_TEMPLATE);
            }

            // then associate it to default user
            $query = '
UPDATE ' . PREFIX_TABLE . 'user_infos
  SET theme = \'' . AppInfo::DEFAULT_TEMPLATE . '\'
  WHERE user_id = ' . $default_user_id . '
;';
            pwg_query($query);
        }
    }
}

// Deactivate all templates
function deactivate_templates(): void
{
    conf_update_param('extents_for_templates', []);
}

// Check access rights
function check_upgrade_access_rights(): void
{
    /**
     * @var array<string, mixed> $conf
     * @var array<string, mixed> $page
     * @var string $current_release
     */
    global $conf, $page, $current_release;

    if (! is_array($page['errors'] ?? null)) {
        $page['errors'] = [];
    }

    if (version_compare($current_release, '2.0', '>=') and isset($_COOKIE[session_name()])) {
        // Check if user is already connected as webmaster
        session_start();
        $pwg_uid = $_SESSION['pwg_uid'] ?? null;
        if (! empty($pwg_uid) and (is_int($pwg_uid) or (is_string($pwg_uid) and is_numeric($pwg_uid)))) {
            $query = '
SELECT status
  FROM ' . Tables::userInfos() . '
  WHERE user_id = ' . (int) $pwg_uid . '
;';
            pwg_query($query);

            $row = pwg_db_fetch_assoc(pwg_query($query));
            if (isset($row['status']) and $row['status'] == 'webmaster') {
                define('PHPWG_IN_UPGRADE', true);
                return;
            }
        }
    }

    if (! isset($_POST['username']) or ! isset($_POST['password'])) {
        return;
    }

    $username = is_string($_POST['username']) ? $_POST['username'] : null;
    $password = is_string($_POST['password']) ? $_POST['password'] : null;

    if ($username === null or $password === null) {
        return;
    }

    if (function_exists('get_magic_quotes_gpc') && ! @get_magic_quotes_gpc()) {
        $username = pwg_db_real_escape_string($username) ?? $username;
    }

    if (version_compare($current_release, '2.0', '<')) {
        // mb_convert_encoding() always returns a string given a string input.
        $username = mb_convert_encoding($username, 'ISO-8859-1', 'UTF-8');
        $password = mb_convert_encoding($password, 'ISO-8859-1', 'UTF-8');
    }

    if (version_compare($current_release, '1.5', '<')) {
        $query = '
SELECT password, status
FROM ' . Tables::users() . '
WHERE username = \'' . $username . '\'
;';
    } else {
        // $conf['user_fields'] maps generic field names to table specific
        // field names and is always array<string, string> (see
        // include/config_default.inc.php).
        $user_fields = is_array($conf['user_fields']) ? $conf['user_fields'] : [];
        $id_field = isset($user_fields['id']) && is_string($user_fields['id']) ? $user_fields['id'] : 'id';
        $username_field = isset($user_fields['username']) && is_string($user_fields['username']) ? $user_fields['username'] : 'username';
        $query = '
SELECT u.password, ui.status
FROM ' . Tables::users() . ' AS u
INNER JOIN ' . Tables::userInfos() . ' AS ui
ON u.' . $id_field . '=ui.user_id
WHERE ' . $username_field . '=\'' . $username . '\'
;';
    }
    $row = pwg_db_fetch_assoc(pwg_query($query));

    if (! is_array($row) or ! isset($row['password'])) {
        $page['errors'][] = l10n('Invalid password!');
    } elseif (! pwg_password_verify($password, $row['password'])) {
        $page['errors'][] = l10n('Invalid password!');
    } elseif ($row['status'] != 'admin' and $row['status'] != 'webmaster') {
        $page['errors'][] = l10n('You do not have access rights to run upgrade');
    } else {
        define('PHPWG_IN_UPGRADE', true);
    }
}

/**
 * which upgrades are available ?
 * @return array<int, string>
 */
function get_available_upgrade_ids(): array
{
    $upgrades_path = PHPWG_ROOT_PATH . 'install/db';

    $available_upgrade_ids = [];

    if ((bool) ($contents = opendir($upgrades_path))) {
        while (($node = readdir($contents)) !== false) {
            if (is_file($upgrades_path . '/' . $node)
                and (bool) preg_match('/^(.*?)-database\.php$/', $node, $match)) {
                $available_upgrade_ids[] = $match[1];
            }
        }
    }
    natcasesort($available_upgrade_ids);

    return $available_upgrade_ids;
}

/**
 * returns true if there are available upgrade files
 */
function check_upgrade_feed(): bool
{
    // retrieve already applied upgrades
    $query = '
SELECT id
  FROM ' . Tables::upgrade() . '
;';
    $applied = array_filter(array_from_query($query, 'id'), is_string(...));

    // retrieve existing upgrades
    $existing = get_available_upgrade_ids();

    // which upgrades need to be applied?
    return count(array_diff($existing, $applied)) > 0;
}

function upgrade_db_connect(): void
{
    /** @var array<string, mixed> $conf */
    global $conf;

    try {
        $db_host = $conf['db_host'];
        $db_user = $conf['db_user'];
        $db_password = $conf['db_password'];
        $db_base = $conf['db_base'];
        if (! is_string($db_host) || ! is_string($db_user) || ! is_string($db_password) || ! is_string($db_base)) {
            throw new Exception("Invalid database configuration: \$conf['db_host'], 'db_user', 'db_password' and 'db_base' must be strings.");
        }
        pwg_db_connect(
            $db_host,
            $db_user,
            $db_password,
            $db_base
        );
        pwg_db_check_version();
    } catch (Exception $e) {
        my_error(l10n($e->getMessage()), true);
    }
}
