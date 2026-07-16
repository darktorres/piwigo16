<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Admin\languages;
use Piwigo\Admin\updates;
use Piwigo\Cache\PersistentFileCache;
use Piwigo\Config\Config;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Lang;
use Piwigo\Db\Tables;
use Piwigo\Template\Template;

// right after the overwrite of previous version files by the unzip in the administration,
// PHP engine might still have old files in cache. We do not want to use the cache and
// force reload of all application files. Thus we disable opcache.
if (function_exists('ini_set')) {
    @ini_set('opcache.enable', 0);
}

define('PHPWG_ROOT_PATH', './');

// Bootstrap globals, set by include/config_default.inc.php and $config_file.
/**
 * @var array<string, mixed> $conf
 * @var string $prefixeTable
 */
global $conf, $prefixeTable;

// load config file
include PHPWG_ROOT_PATH . 'include/config_default.inc.php';
@include PHPWG_ROOT_PATH . 'local/config/config.inc.php';
defined('PWG_LOCAL_DIR') or define('PWG_LOCAL_DIR', 'local/');

$config_file = PHPWG_ROOT_PATH . PWG_LOCAL_DIR . 'config/database.inc.php';
$config_file_contents = @file_get_contents($config_file);
if ($config_file_contents === false) {
    die('Cannot load ' . $config_file);
}
$php_end_tag = strrpos($config_file_contents, '?>');
if ($php_end_tag === false) {
    die('Cannot find php end tag in ' . $config_file);
}

include $config_file;

// Piwigo\Db\Tables::*() (used below and by admin/include/functions.php's
// own procedural functions once included) reads Config::dbPrefix() --
// this script never goes through Kernel::boot()/ConfigLoader (the DB
// isn't necessarily configured yet), so $prefixeTable (resolved above
// from database.inc.php, independent of $conf) must be seeded into
// Config's static state directly, or every Tables::*() call downstream
// silently falls back to the 'piwigo_' SCHEMA default instead of the
// real prefix.
Config::override('db_prefix', $prefixeTable);
define('PREFIX_TABLE', $prefixeTable);
define('UPGRADES_PATH', PHPWG_ROOT_PATH . 'install/db');

include_once PHPWG_ROOT_PATH . 'include/functions.inc.php';
include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

// +-----------------------------------------------------------------------+
// |                              functions                                |
// +-----------------------------------------------------------------------+

/**
 * list all tables in an array
 * @return array<int, string>
 */
function get_tables(): array
{
    $tables = [];

    $query = '
SHOW TABLES
;';
    $result = pwg_query($query);

    while ((bool) ($row = pwg_db_fetch_row($result))) {
        $table_name = $row[0];
        if (! is_string($table_name)) {
            continue;
        }
        if ((bool) preg_match('/^' . PREFIX_TABLE . '/', $table_name)) {
            $tables[] = $table_name;
        }
    }

    return $tables;
}

/**
 * list all columns of each given table
 *
 * @param array<int, string> $tables
 * @return array<string, array<int, string>>
 */
function get_columns_of($tables): array
{
    $columns_of = [];

    foreach ($tables as $table) {
        $query = '
DESC `' . $table . '`
;';
        $result = pwg_query($query);

        $columns_of[$table] = [];

        while ((bool) ($row = pwg_db_fetch_row($result))) {
            $column_name = $row[0];
            if (! is_string($column_name)) {
                continue;
            }
            $columns_of[$table][] = $column_name;
        }
    }

    return $columns_of;
}

function print_time(mixed $message): void
{
    global $last_time;

    $new_time = \Piwigo\Core\TimingHelper::getMoment();
    // $last_time is only ever assigned via get_moment() (this function, or
    // install/upgrade_1.4.0.php's top-level init, which runs in this same
    // include-shared scope but isn't visible to static analysis); if this is
    // the very first call before that init has run, treat elapsed time as 0.
    $start_time = is_float($last_time) ? $last_time : $new_time;
    $message_str = is_scalar($message) || $message instanceof Stringable
        ? (string) $message
        : print_r($message, true);

    echo '<pre>[' . \Piwigo\Core\TimingHelper::getElapsedTime($start_time, $new_time) . ']';
    echo ' ' . $message_str;
    echo '</pre>';
    flush();
    $last_time = $new_time;
}

// +-----------------------------------------------------------------------+
// |                             playing zone                              |
// +-----------------------------------------------------------------------+

// echo implode('<br>', get_tables());
// echo '<pre>'; print_r(get_columns_of(get_tables())); echo '</pre>';

// foreach (get_available_upgrade_ids() as $upgrade_id)
// {
//   echo $upgrade_id, '<br>';
// }

// +-----------------------------------------------------------------------+
// |                             language                                  |
// +-----------------------------------------------------------------------+
$languages = new languages('utf-8');
if (isset($_GET['language'])) {
    $language = is_string($_GET['language']) ? strip_tags($_GET['language']) : '';

    if (! in_array($language, array_keys($languages->fs_languages))) {
        $language = AppInfo::DEFAULT_LANGUAGE;
    }
} else {
    $language = 'en_UK';
    // Try to get browser language
    $http_accept_language = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null;
    $http_accept_language = is_string($http_accept_language) ? $http_accept_language : '';
    foreach ($languages->fs_languages as $language_code => $fs_language) {
        if (substr($language_code, 0, 2) == substr($http_accept_language, 0, 2)) {
            $language = $language_code;
            break;
        }
    }
}

// See include/common.inc.php for why this fork never points PHPWG_DOMAIN at
// the real piwigo.org.
define('PHPWG_DOMAIN', 'upstream.example.invalid');
define('PHPWG_URL', 'https://' . PHPWG_DOMAIN);

Lang::load('common.lang', '', [
    'language' => $language,
    'no_fallback' => true,
]);
Lang::load('admin.lang', '', [
    'language' => $language,
    'no_fallback' => true,
]);
Lang::load('install.lang', '', [
    'language' => $language,
    'no_fallback' => true,
]);
Lang::load('upgrade.lang', '', [
    'language' => $language,
    'no_fallback' => true,
]);

// +-----------------------------------------------------------------------+
// |                          database connection                          |
// +-----------------------------------------------------------------------+
include_once PHPWG_ROOT_PATH . 'admin/include/functions_upgrade.php';
// config_default.inc.php/database.inc.php always set $conf['dblayer'] to a
// string ('mysqli'), but the value crosses an include() boundary invisible
// to static analysis, so we re-narrow at the point of use (same pattern as
// include/common.inc.php).
$dblayer = $conf['dblayer'];
if (! is_string($dblayer)) {
    die("Invalid \$conf['dblayer'] configuration: expected a string.");
}
include PHPWG_ROOT_PATH . 'include/dblayer/functions_' . $dblayer . '.inc.php';

upgrade_db_connect();
pwg_db_check_charset();

$row = pwg_db_fetch_row(pwg_query('SELECT NOW();'));
assert($row !== null);
[$dbnow] = $row;
define('CURRENT_DATE', $dbnow);

// +-----------------------------------------------------------------------+
// |                        template initialization                        |
// +-----------------------------------------------------------------------+

$template = new Template(PHPWG_ROOT_PATH . 'admin/themes', 'clear');
$template->set_filenames([
    'upgrade' => 'upgrade.tpl',
]);
$template->assign(
    [
        'RELEASE' => AppInfo::VERSION,
        'L_UPGRADE_HELP' => l10n('Need help ? Ask your question on <a href="%s">Piwigo message board</a>.', PHPWG_URL . '/forum'),
    ]
);

// +-----------------------------------------------------------------------+
// | Remote sites are not compatible with Piwigo 2.4+                      |
// +-----------------------------------------------------------------------+

$has_remote_site = false;

$query = 'SELECT galleries_url FROM ' . Tables::sites() . ';';
$result = pwg_query($query);
while ((bool) ($row = pwg_db_fetch_assoc($result))) {
    $galleries_url = $row['galleries_url'] ?? null;
    if (is_string($galleries_url) && url_is_remote($galleries_url)) {
        $has_remote_site = true;
    }
}

if ($has_remote_site) {
    /** @var array<string, mixed> $page */
    $page['errors'] = [];
    $step = 3;
    updates::upgrade_to('2.3.4', $step, false);

    // updates::upgrade_to() mutates $page['errors'] from its own function
    // scope via global $page -- static analysis can't trace that, so
    // re-narrow here rather than trust the pre-call [] assignment.
    $upgrade_errors = is_array($page['errors']) ? array_filter($page['errors'], is_string(...)) : [];
    if (! empty($upgrade_errors)) {
        echo '<ul>';
        foreach ($upgrade_errors as $error) {
            echo '<li>' . $error . '</li>';
        }
        echo '</ul>';
    }

    exit();
}

// +-----------------------------------------------------------------------+
// |                            upgrade choice                             |
// +-----------------------------------------------------------------------+

$tables = get_tables();
$columns_of = get_columns_of($tables);

// find the current release
if (! in_array('param', $columns_of[PREFIX_TABLE . 'config'])) {
    // we're in branch 1.3, important upgrade, isn't it?
    if (in_array(PREFIX_TABLE . 'user_category', $tables)) {
        $current_release = '1.3.1';
    } else {
        $current_release = '1.3.0';
    }
} elseif (! in_array(PREFIX_TABLE . 'user_cache', $tables)) {
    $current_release = '1.4.0';
} elseif (! in_array(PREFIX_TABLE . 'tags', $tables)) {
    $current_release = '1.5.0';
} elseif (! in_array(PREFIX_TABLE . 'plugins', $tables)) {
    if (! in_array('auto_login_key', $columns_of[PREFIX_TABLE . 'user_infos'])) {
        $current_release = '1.6.0';
    } else {
        $current_release = '1.6.2';
    }
} elseif (! in_array('md5sum', $columns_of[PREFIX_TABLE . 'images'])) {
    $current_release = '1.7.0';
} elseif (! in_array(PREFIX_TABLE . 'themes', $tables)) {
    $current_release = '2.0.0';
} elseif (! in_array('added_by', $columns_of[PREFIX_TABLE . 'images'])) {
    $current_release = '2.1.0';
} elseif (! in_array('rating_score', $columns_of[PREFIX_TABLE . 'images'])) {
    $current_release = '2.2.0';
} elseif (! in_array('rotation', $columns_of[PREFIX_TABLE . 'images'])) {
    $current_release = '2.3.0';
} elseif (! in_array('website_url', $columns_of[PREFIX_TABLE . 'comments'])) {
    $current_release = '2.4.0';
} elseif (! in_array('nb_available_tags', $columns_of[PREFIX_TABLE . 'user_cache'])) {
    $current_release = '2.5.0';
} elseif (! in_array('activation_key_expire', $columns_of[PREFIX_TABLE . 'user_infos'])) {
    $current_release = '2.6.0';
} elseif (! in_array('auth_key_id', $columns_of[PREFIX_TABLE . 'history'])) {
    $current_release = '2.7.0';
} elseif (! in_array('history_id_to', $columns_of[PREFIX_TABLE . 'history_summary'])) {
    $current_release = '2.8.0';
} elseif (! in_array(PREFIX_TABLE . 'activity', $tables)) {
    $current_release = '2.9.0';
} else {
    // retrieve already applied upgrades
    $query = '
SELECT id
  FROM ' . PREFIX_TABLE . 'upgrade
;';
    $applied_upgrades = query2array($query, null, 'id');

    if (! in_array(159, $applied_upgrades)) {
        $current_release = '2.10.0';
    } elseif (! in_array(162, $applied_upgrades)) {
        $current_release = '11.0.0';
    } elseif (! in_array(164, $applied_upgrades)) {
        $current_release = '12.0.0';
    } elseif (! in_array(170, $applied_upgrades)) {
        $current_release = '13.0.0';
    } elseif (! in_array(174, $applied_upgrades)) {
        $current_release = '14.0.0';
    } elseif (! in_array(181, $applied_upgrades)) {
        $current_release = '15.0.0';
    } else {
        // confirm that the database is in the same version as source code files
        conf_update_param('piwigo_db_version', \Piwigo\Core\VersionHelper::getBranchFromVersion(AppInfo::VERSION));

        header('Content-Type: text/html; charset=' . \Piwigo\Core\CharsetHelper::getPwgCharset());
        echo 'No upgrade required, the database structure is up to date';
        echo '<br><a href="index.php">← back to gallery</a>';
        exit();
    }
}

// +-----------------------------------------------------------------------+
// |                            upgrade launch                             |
// +-----------------------------------------------------------------------+
// The if($has_remote_site){...} block above always exits() before falling
// through, so PHPStan's flow analysis merges only the "false" branch here —
// $page needs a fresh @var since that earlier block's narrowing does not
// reach this point.
/** @var array<string, mixed> $page */
$page['infos'] = [];
$page['errors'] = [];
$mysql_changes = [];

// check php version
if (version_compare(PHP_VERSION, AppInfo::REQUIRED_PHP_VERSION, '<')) {
    $page['errors'][] = l10n('PHP version %s required (you are running on PHP %s)', AppInfo::REQUIRED_PHP_VERSION, PHP_VERSION);
}

check_upgrade_access_rights();

if ((isset($_POST['submit']) or isset($_GET['now']))
  and check_upgrade()) {
    $upgrade_file = PHPWG_ROOT_PATH . 'install/upgrade_' . $current_release . '.php';
    if (is_file($upgrade_file)) {
        // reset SQL counters
        $page['queries_time'] = 0;
        $page['count_queries'] = 0;

        $page['upgrade_start'] = \Piwigo\Core\TimingHelper::getMoment();
        $conf['die_on_sql_error'] = false;
        include $upgrade_file;
        // install/upgrade_*.php scripts (e.g. upgrade_1.3.1.php) can
        // array_push() onto $mysql_changes from this same top-level scope —
        // get_defined_vars() (rather than reading $mysql_changes directly)
        // keeps its real, post-include shape visible here instead of
        // appearing to still be the empty array set above.
        $included_vars = get_defined_vars();
        // array_push($mysql_changes, '...') calls in install/upgrade_*.php
        // (e.g. upgrade_1.3.1.php) only ever push PHP source-code strings,
        // but get_defined_vars() itself returns array<string, mixed>.
        $mysql_changes_raw = $included_vars['mysql_changes'] ?? null;
        $mysql_changes = is_array($mysql_changes_raw) ? array_filter($mysql_changes_raw, is_string(...)) : [];

        conf_update_param('piwigo_db_version', \Piwigo\Core\VersionHelper::getBranchFromVersion(AppInfo::VERSION));

        // Conf delete param on last major update for whats new popin to be displayed when changing major version
        conf_delete_param('last_major_update');

        // Something to add in database.inc.php? (install/upgrade_*.php
        // scripts may push onto $mysql_changes)
        if (count($mysql_changes) > 0) {
            $config_file_contents =
              substr($config_file_contents, 0, $php_end_tag) . "\r\n"
              . implode("\r\n", $mysql_changes) . "\r\n"
              . substr($config_file_contents, $php_end_tag);

            if (! (bool) @file_put_contents($config_file, $config_file_contents)) {
                // various by-ref function calls above (global $page inside
                // their own scope) mutate $page in ways static analysis
                // can't trace, so re-narrow before appending.
                if (! is_array($page['infos'] ?? null)) {
                    $page['infos'] = [];
                }
                $page['infos'][] = l10n(
                    'In <i>%s</i>, before <b>?></b>, insert:',
                    PWG_LOCAL_DIR . 'config/database.inc.php'
                )
                . '<p><textarea rows="4" cols="40">'
                . implode("\r\n", $mysql_changes) . '</textarea></p>';
            }
        }

        // Deactivate non standard extensions
        deactivate_non_standard_plugins();
        deactivate_non_standard_themes();
        deactivate_templates();

        $page['upgrade_end'] = \Piwigo\Core\TimingHelper::getMoment();

        // $page['upgrade_start']/'upgrade_end'/'queries_time'] are only ever
        // set within this same scope: get_moment() (native float return)
        // above and at reset time, and 'queries_time' as a numeric
        // accumulator, never touched elsewhere.
        $upgrade_start = $page['upgrade_start'];
        $upgrade_end = $page['upgrade_end'];
        $queries_time = $page['queries_time'];

        $template->assign(
            'upgrade',
            [
                'VERSION' => $current_release,
                'TOTAL_TIME' => \Piwigo\Core\TimingHelper::getElapsedTime($upgrade_start, $upgrade_end),
                'SQL_TIME' => number_format(
                    $queries_time,
                    3,
                    '.',
                    ' '
                ) . ' s',
                'NB_QUERIES' => $page['count_queries'],
            ]
        );

        if (! is_array($page['infos'] ?? null)) {
            $page['infos'] = [];
        }
        $page['infos'][] = l10n('Perform a maintenance check in [Administration>Tools>Maintenance] if you encounter any problem.');

        // Save $page['infos'] in order to restore after maintenance actions
        $page['infos_sav'] = $page['infos'];
        $page['infos'] = [];

        $template->assign(
            [
                'button_label' => l10n('Home'),
                'button_link' => 'index.php',
            ]
        );

        // if the webmaster has a session, let's give a link to discover new features
        if (! empty($_SESSION['pwg_uid'])) {
            $version_ = str_replace('.', '_', \Piwigo\Core\VersionHelper::getBranchFromVersion(AppInfo::VERSION) . '.0');

            if (file_exists(PHPWG_PLUGINS_PATH . 'TakeATour/tours/' . $version_ . '/config.inc.php')) {
                $query = '
REPLACE INTO ' . Tables::plugins() . '
  (id, state)
  VALUES (\'TakeATour\', \'active\')
;';
                pwg_query($query);

                // we need the secret key for get_pwg_token()
                load_conf_from_db();

                $template->assign(
                    [
                        'button_label' => l10n('Discover what\'s new in Piwigo %s', \Piwigo\Core\VersionHelper::getBranchFromVersion(AppInfo::VERSION)),
                        'button_link' => 'admin.php?submited_tour_path=tours/' . $version_ . '&amp;pwg_token=' . (new \Piwigo\Csrf\CsrfService())->getToken(),
                    ]
                );
            }
        }

        if (! isset($_SESSION['connected_with'])) {
            $_SESSION['connected_with'] = 'pwg_ui';
        }

        // Delete cache data
        // invalidate_user_cache will purge persistent_cache so it needs to be instantiated first
        $persistent_cache = new PersistentFileCache();

        invalidate_user_cache(true);
        $template->delete_compiled_templates();

        // Restore $page['infos'] in order to hide informations messages from functions calles
        // errors messages are not hide
        $page['infos'] = $page['infos_sav'];

    }
}

// +-----------------------------------------------------------------------+
// |                          start template output                        |
// +-----------------------------------------------------------------------+
else {
    if (! defined('PWG_CHARSET')) {
        define('PWG_CHARSET', 'utf-8');
    }

    $languages = new languages();

    $languages_options = [];
    foreach ($languages->fs_languages as $language_code => $fs_language) {
        if ($language == $language_code) {
            $template->assign('language_selection', $language_code);
        }
        $languages_options[$language_code] = $fs_language['name'];
    }
    $template->assign('language_options', $languages_options);

    $template->assign('introduction', [
        'CURRENT_RELEASE' => $current_release,
        'F_ACTION' => 'upgrade.php?language=' . $language,
    ]);

    if (! check_upgrade()) {
        $template->assign('login', true);
    }
}

// $page['errors']/'infos' are always arrays: initialized to [] in the
// "upgrade launch" block above and only ever appended to via []= throughout
// this script.
$page_errors = $page['errors'];
if (count($page_errors) != 0) {
    $template->assign('errors', $page_errors);
}

$page_infos = $page['infos'];
$page_infos = is_array($page_infos) ? $page_infos : [];
if (count($page_infos) != 0) {
    $template->assign('infos', $page_infos);
}

// +-----------------------------------------------------------------------+
// |                          sending html code                            |
// +-----------------------------------------------------------------------+

$template->pparse('upgrade');
