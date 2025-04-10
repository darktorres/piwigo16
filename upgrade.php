<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\admin\inc\functions_admin;
use Piwigo\admin\inc\functions_upgrade;
use Piwigo\admin\inc\languages;
use Piwigo\admin\inc\updates;
use Piwigo\inc\dblayer\functions_mysqli;
use Piwigo\inc\functions;
use Piwigo\inc\functions_url;
use Piwigo\inc\Template;

// right after the overwrite of previous version files by the unzip in the administration,
// PHP engine might still have old files in cache. We do not want to use the cache and
// force reload of all application files. Thus we disable opcache.
ini_set('opcache.enable', 0);

const PHPWG_ROOT_PATH = './';

// load config file
require __DIR__ . '/inc/config_default.php';

if (file_exists(__DIR__ . '/local/config/config.php')) {
    require __DIR__ . '/local/config/config.php';
}

if (! defined('PWG_LOCAL_DIR')) {
    define('PWG_LOCAL_DIR', 'local/');
}

$config_file = './local/config/database.php';
$config_file_contents = file_get_contents($config_file);

if ($config_file_contents === false) {
    exit('Cannot load ' . $config_file);
}

$php_end_tag = strrpos($config_file_contents, '?>');

if ($php_end_tag === false) {
    exit('Cannot find php end tag in ' . $config_file);
}

require $config_file;

require_once __DIR__ . '/inc/constants.php';
const UPGRADES_PATH = './install/db';

require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/Template.php';

// +-----------------------------------------------------------------------+
// |                             playing zone                              |
// +-----------------------------------------------------------------------+

// echo implode('<br>', \Piwigo\inc\functions::get_tables());
// echo '<pre>'; print_r(\Piwigo\inc\functions::get_columns_of(\Piwigo\inc\functions::get_tables())); echo '</pre>';

// foreach (\Piwigo\admin\inc\functions_upgrade::get_available_upgrade_ids() as $upgrade_id)
// {
//   echo $upgrade_id, '<br>';
// }

// +-----------------------------------------------------------------------+
// |                             language                                  |
// +-----------------------------------------------------------------------+
$languages = new languages('utf-8');

if (isset($_GET['language'])) {
    $language = strip_tags($_GET['language']);

    if (! in_array($language, array_keys($languages->fs_languages))) {
        $language = PHPWG_DEFAULT_LANGUAGE;
    }
} else {
    $language = 'en_UK';
    // Try to get browser language
    foreach (array_keys($languages->fs_languages) as $language_code) {
        if (substr($language_code, 0, 2) === substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2)) {
            $language = $language_code;
            break;
        }
    }
}

if ($language == 'fr_FR') {
    define('PHPWG_DOMAIN', 'fr.piwigo.org');
} elseif ($language == 'it_IT') {
    define('PHPWG_DOMAIN', 'it.piwigo.org');
} elseif ($language == 'de_DE') {
    define('PHPWG_DOMAIN', 'de.piwigo.org');
} elseif ($language == 'es_ES') {
    define('PHPWG_DOMAIN', 'es.piwigo.org');
} elseif ($language == 'pl_PL') {
    define('PHPWG_DOMAIN', 'pl.piwigo.org');
} elseif ($language == 'zh_CN') {
    define('PHPWG_DOMAIN', 'cn.piwigo.org');
} elseif ($language == 'ru_RU') {
    define('PHPWG_DOMAIN', 'ru.piwigo.org');
} elseif ($language == 'nl_NL') {
    define('PHPWG_DOMAIN', 'nl.piwigo.org');
} elseif ($language == 'tr_TR') {
    define('PHPWG_DOMAIN', 'tr.piwigo.org');
} elseif ($language == 'da_DK') {
    define('PHPWG_DOMAIN', 'da.piwigo.org');
} elseif ($language == 'pt_BR') {
    define('PHPWG_DOMAIN', 'br.piwigo.org');
} else {
    define('PHPWG_DOMAIN', 'piwigo.org');
}

const PHPWG_URL = 'https://' . PHPWG_DOMAIN;

functions::load_language('common.lang', '', [
    'language' => $language,
    'target_charset' => 'utf-8',
    'no_fallback' => true,
]);
functions::load_language('admin.lang', '', [
    'language' => $language,
    'target_charset' => 'utf-8',
    'no_fallback' => true,
]);
functions::load_language('install.lang', '', [
    'language' => $language,
    'target_charset' => 'utf-8',
    'no_fallback' => true,
]);
functions::load_language('upgrade.lang', '', [
    'language' => $language,
    'target_charset' => 'utf-8',
    'no_fallback' => true,
]);

// +-----------------------------------------------------------------------+
// |                          database connection                          |
// +-----------------------------------------------------------------------+

functions_upgrade::upgrade_db_connect();

[$dbnow] = functions_mysqli::pwg_db_fetch_row(functions_mysqli::pwg_query('SELECT NOW();'));
define('CURRENT_DATE', $dbnow);

// +-----------------------------------------------------------------------+
// |                        template initialization                        |
// +-----------------------------------------------------------------------+

$template = new Template('./admin/themes', 'roma');
$template->set_filenames([
    'upgrade' => 'upgrade.tpl',
]);
$template->assign(
    [
        'RELEASE' => PHPWG_VERSION,
        'L_UPGRADE_HELP' => functions::l10n('Need help ? Ask your question on <a href="%s">Piwigo message board</a>.', PHPWG_URL . '/forum'),
    ]
);

// +-----------------------------------------------------------------------+
// | Remote sites are not compatible with Piwigo 2.4+                      |
// +-----------------------------------------------------------------------+

$has_remote_site = false;

$query = <<<SQL
    SELECT galleries_url FROM sites;
    SQL;
$result = functions_mysqli::pwg_query($query);

while ($row = functions_mysqli::pwg_db_fetch_assoc($result)) {
    if (functions_url::url_is_remote($row['galleries_url'])) {
        $has_remote_site = true;
    }
}

if ($has_remote_site) {
    $page['errors'] = [];
    $step = 3;
    updates::upgrade_to('2.3.4', $step, false);

    if (! empty($page['errors'])) {
        echo '<ul>';

        foreach ($page['errors'] as $error) {
            echo '<li>' . $error . '</li>';
        }

        echo '</ul>';
    }

    exit();
}

// +-----------------------------------------------------------------------+
// |                            upgrade choice                             |
// +-----------------------------------------------------------------------+

$tables = functions::get_tables();
$columns_of = functions::get_columns_of($tables);

// find the current release
if (! in_array('param', $columns_of['config'])) {
    // we're in branch 1.3, important upgrade, isn't it?
    $current_release = in_array('user_category', $tables) ? '1.3.1' : '1.3.0';
} elseif (! in_array('user_cache', $tables)) {
    $current_release = '1.4.0';
} elseif (! in_array('tags', $tables)) {
    $current_release = '1.5.0';
} elseif (! in_array('plugins', $tables)) {
    $current_release = in_array('auto_login_key', $columns_of['user_infos']) ? '1.6.2' : '1.6.0';
} elseif (! in_array('md5sum', $columns_of['images'])) {
    $current_release = '1.7.0';
} elseif (! in_array('themes', $tables)) {
    $current_release = '2.0.0';
} elseif (! in_array('added_by', $columns_of['images'])) {
    $current_release = '2.1.0';
} elseif (! in_array('rating_score', $columns_of['images'])) {
    $current_release = '2.2.0';
} elseif (! in_array('rotation', $columns_of['images'])) {
    $current_release = '2.3.0';
} elseif (! in_array('website_url', $columns_of['comments'])) {
    $current_release = '2.4.0';
} elseif (! in_array('nb_available_tags', $columns_of['user_cache'])) {
    $current_release = '2.5.0';
} elseif (! in_array('activation_key_expire', $columns_of['user_infos'])) {
    $current_release = '2.6.0';
} elseif (! in_array('auth_key_id', $columns_of['history'])) {
    $current_release = '2.7.0';
} elseif (! in_array('history_id_to', $columns_of['history_summary'])) {
    $current_release = '2.8.0';
} elseif (! in_array('activity', $tables)) {
    $current_release = '2.9.0';
} else {
    // retrieve already applied upgrades
    $query = <<<SQL
        SELECT id
        FROM upgrade;
        SQL;
    $applied_upgrades = functions_mysqli::query2array($query, null, 'id');

    if (! in_array(159, $applied_upgrades)) {
        $current_release = '2.10.0';
    } elseif (! in_array(162, $applied_upgrades)) {
        $current_release = '11.0.0';
    } elseif (! in_array(164, $applied_upgrades)) {
        $current_release = '12.0.0';
    } elseif (! in_array(170, $applied_upgrades)) {
        $current_release = '13.0.0';
    } else {
        // confirm that the database is in the same version as source code files
        functions::conf_update_param('piwigo_db_version', functions::get_branch_from_version(PHPWG_VERSION));

        header('Content-Type: text/html; charset=utf-8');
        echo 'No upgrade required, the database structure is up to date';
        echo '<br><a href="index.php">← back to gallery</a>';
        exit();
    }
}

// +-----------------------------------------------------------------------+
// |                            upgrade launch                             |
// +-----------------------------------------------------------------------+
$page['infos'] = [];
$page['errors'] = [];
$mysql_changes = [];

// check php version
if (version_compare(PHP_VERSION, REQUIRED_PHP_VERSION, '<')) {
    $page['errors'][] = functions::l10n('PHP version %s required (you are running on PHP %s)', REQUIRED_PHP_VERSION, PHP_VERSION);
}

functions_upgrade::check_upgrade_access_rights();

if ((isset($_POST['submit']) || isset($_GET['now'])) &&
     functions_upgrade::check_upgrade()
) {
    $upgrade_file = './install/upgrade_' . $current_release . '.php';

    if (is_file($upgrade_file)) {
        // reset SQL counters
        $page['queries_time'] = 0;
        $page['count_queries'] = 0;

        $page['upgrade_start'] = functions::get_moment();
        $conf->die_on_sql_error = false;
        require $upgrade_file;
        functions::conf_update_param('piwigo_db_version', functions::get_branch_from_version(PHPWG_VERSION));

        // Something to add in database.php?
        if ($mysql_changes !== []) {
            $config_file_contents =
              substr($config_file_contents, 0, $php_end_tag) . "\r\n"
              . implode("\r\n", $mysql_changes) . "\r\n"
              . substr($config_file_contents, $php_end_tag);

            if (! file_put_contents($config_file, $config_file_contents)) {
                $page['infos'][] = functions::l10n(
                    'In <i>%s</i>, before <b>?></b>, insert:',
                    'local/config/database.php'
                )
                . '<p><textarea rows="4" cols="40">'
                . implode("\r\n", $mysql_changes) . '</textarea></p>';
            }
        }

        // Deactivate non standard extensions
        functions_upgrade::deactivate_non_standard_plugins();
        functions_upgrade::deactivate_non_standard_themes();
        functions_upgrade::deactivate_templates();

        $page['upgrade_end'] = functions::get_moment();

        $template->assign(
            'upgrade',
            [
                'VERSION' => $current_release,
                'TOTAL_TIME' => functions::get_elapsed_time(
                    $page['upgrade_start'],
                    $page['upgrade_end']
                ),
                'SQL_TIME' => number_format(
                    $page['queries_time'],
                    3,
                    '.',
                    ' '
                ) . ' s',
                'NB_QUERIES' => $page['count_queries'],
            ]
        );

        $page['infos'][] = functions::l10n('Perform a maintenance check in [Administration>Tools>Maintenance] if you encounter any problem.');

        // Save $page['infos'] in order to restore after maintenance actions
        $page['infos_sav'] = $page['infos'];
        $page['infos'] = [];

        $template->assign(
            [
                'button_label' => functions::l10n('Home'),
                'button_link' => 'index.php',
            ]
        );

        // if the webmaster has a session, let's give a link to discover new features
        if (! empty($_SESSION['pwg_uid'])) {
            $version_ = str_replace('.', '_', functions::get_branch_from_version(PHPWG_VERSION) . '.0');

            if (file_exists(PHPWG_PLUGINS_PATH . 'TakeATour/tours/' . $version_ . '/config.php')) {
                $query = <<<SQL
                    REPLACE INTO plugins
                        (id, state)
                    VALUES
                        ('TakeATour', 'active');
                    SQL;
                functions_mysqli::pwg_query($query);

                // we need the secret key for get_pwg_token()
                functions::load_conf_from_db();

                $template->assign(
                    [
                        'button_label' => functions::l10n("Discover what's new in Piwigo %s", functions::get_branch_from_version(PHPWG_VERSION)),
                        'button_link' => 'admin.php?submitted_tour_path=tours/' . $version_ . '&amp;pwg_token=' . functions::get_pwg_token(),
                    ]
                );
            }
        }

        // Delete cache data
        functions_admin::invalidate_user_cache();
        $template->delete_compiled_templates();

        // Restore $page['infos'] in order to hide information messages from function calls
        // error messages are not hidden
        $page['infos'] = $page['infos_sav'];
    }
}

// +-----------------------------------------------------------------------+
// |                          start template output                        |
// +-----------------------------------------------------------------------+
else {
    $languages = new languages();

    foreach ($languages->fs_languages as $language_code => $fs_language) {
        if ($language === $language_code) {
            $template->assign('language_selection', $language_code);
        }

        $languages_options[$language_code] = $fs_language['name'];
    }

    $template->assign('language_options', $languages_options);

    $template->assign('introduction', [
        'CURRENT_RELEASE' => $current_release,
        'F_ACTION' => 'upgrade.php?language=' . $language,
    ]);

    if (! functions_upgrade::check_upgrade()) {
        $template->assign('login', true);
    }
}

if (count($page['errors']) != 0) {
    $template->assign('errors', $page['errors']);
}

if (count($page['infos']) != 0) {
    $template->assign('infos', $page['infos']);
}

// +-----------------------------------------------------------------------+
// |                          sending html code                            |
// +-----------------------------------------------------------------------+

$template->pparse('upgrade');
