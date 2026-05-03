<?php

declare(strict_types=1);

global $template, $user, $page, $persistent_cache, $lang, $prefixeTable, $conf;

use Piwigo\Admin\Languages;
use Piwigo\Admin\Updates;
use Piwigo\Template\Template;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// right after the overwrite of previous version files by the unzip in the administration,
// PHP engine might still have old files in cache. We do not want to use the cache and
// force reload of all application files. Thus we disable opcache.
if (function_exists('ini_set')) {
    @ini_set('opcache.enable', 0);
}

define('PHPWG_ROOT_PATH', './');

$conf = [];

$localConfig = realpath(PHPWG_ROOT_PATH . 'local/config/config.inc.php');
if ($localConfig !== false) {
    include $localConfig;
}
defined('PWG_LOCAL_DIR') or define('PWG_LOCAL_DIR', 'local/');

// Legacy: existing installs may have local/config/database.inc.php with their
// DB credentials. New installs write .env instead. Either path works.
$config_file = realpath(PHPWG_ROOT_PATH . PWG_LOCAL_DIR . 'config/database.inc.php');
if ($config_file !== false) {
    include $config_file;
}

require_once PHPWG_ROOT_PATH . 'vendor/autoload.php';
\Piwigo\Config\ConfigLoader::applyDefaults($conf);
\Piwigo\Config\ConfigLoader::loadEnv(PHPWG_ROOT_PATH);
\Piwigo\Config\ConfigLoader::applyEnvOverrides($conf);

$prefixeTable ??= is_scalar($conf['db_prefix'] ?? null) ? (string) $conf['db_prefix'] : 'piwigo_';

if (!\Piwigo\Core\InstallSentinel::isInstalled()) {
    die('Piwigo is not installed yet — run install.php first.');
}

// $conf is not used for users tables - define cannot be re-defined
define('USERS_TABLE', $prefixeTable.'users');
include_once(PHPWG_ROOT_PATH.'include/constants.php');
define('PREFIX_TABLE', $prefixeTable);
define('UPGRADES_PATH', PHPWG_ROOT_PATH.'install/db');

include_once(PHPWG_ROOT_PATH.'include/functions.inc.php');
include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

// +-----------------------------------------------------------------------+
// |                              functions                                |
// +-----------------------------------------------------------------------+
/**
 * list all tables in an array
 */
/** @return string[] */
function get_tables(): array
{
    $tables = [];

    $query = '
SHOW TABLES
;';
    $result = pwg_query($query);

    while ($row = pwg_db_fetch_row($result)) {
        if (preg_match('/^'.PREFIX_TABLE.'/', (string) $row[0])) {
            $tables[] = (string)$row[0];
        }
    }

    return $tables;
}

/**
 * list all columns of each given table
 *
 * @return array of array
 */
/**
 * @param string[] $tables
 * @return array<string, string[]>
 */
function get_columns_of(array $tables): array
{
    $columns_of = [];

    foreach ($tables as $table) {
        $query = '
DESC `'.$table.'`
;';
        $result = pwg_query($query);

        $columns_of[$table] = [];

        while ($row = pwg_db_fetch_row($result)) {
            $columns_of[$table][] = (string)$row[0];
        }
    }

    return $columns_of;
}

/**
 */
function print_time(string $message): void
{
    global $last_time;

    $new_time = get_moment();
    echo '<pre>['.get_elapsed_time($last_time, $new_time).']';
    echo ' '.$message;
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
$languages = new Languages('utf-8');
$get_language = input_string('language', null, $_GET);
if ($get_language !== null) {
    $language = strip_tags($get_language);

    if (!in_array($language, array_keys($languages->fs_languages))) {
        $language = PHPWG_DEFAULT_LANGUAGE;
    }
} else {
    $language = 'en_UK';
    // Try to get browser language
    foreach ($languages->fs_languages as $language_code => $fs_language) {
        $httpAccLang = is_scalar($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null) ? (string) $_SERVER['HTTP_ACCEPT_LANGUAGE'] : '';
        if (substr((string) $language_code, 0, 2) == @substr($httpAccLang, 0, 2)) {
            $language = $language_code;
            break;
        }
    }
}

if ('fr_FR' == $language) {
    define('PHPWG_DOMAIN', 'fr.piwigo.org');
} elseif ('it_IT' == $language) {
    define('PHPWG_DOMAIN', 'it.piwigo.org');
} elseif ('de_DE' == $language) {
    define('PHPWG_DOMAIN', 'de.piwigo.org');
} elseif ('es_ES' == $language) {
    define('PHPWG_DOMAIN', 'es.piwigo.org');
} elseif ('pl_PL' == $language) {
    define('PHPWG_DOMAIN', 'pl.piwigo.org');
} elseif ('zh_CN' == $language) {
    define('PHPWG_DOMAIN', 'cn.piwigo.org');
} elseif ('ru_RU' == $language) {
    define('PHPWG_DOMAIN', 'ru.piwigo.org');
} elseif ('nl_NL' == $language) {
    define('PHPWG_DOMAIN', 'nl.piwigo.org');
} elseif ('tr_TR' == $language) {
    define('PHPWG_DOMAIN', 'tr.piwigo.org');
} elseif ('da_DK' == $language) {
    define('PHPWG_DOMAIN', 'da.piwigo.org');
} elseif ('pt_BR' == $language) {
    define('PHPWG_DOMAIN', 'br.piwigo.org');
} else {
    define('PHPWG_DOMAIN', 'piwigo.org');
}
define('PHPWG_URL', 'https://'.PHPWG_DOMAIN);

load_language('common.lang', '', ['language' => $language, 'target_charset' => 'utf-8', 'no_fallback' => true]);
load_language('admin.lang', '', ['language' => $language, 'target_charset' => 'utf-8', 'no_fallback' => true]);
load_language('install.lang', '', ['language' => $language, 'target_charset' => 'utf-8', 'no_fallback' => true]);
load_language('upgrade.lang', '', ['language' => $language, 'target_charset' => 'utf-8', 'no_fallback' => true]);

// +-----------------------------------------------------------------------+
// |                          database connection                          |
// +-----------------------------------------------------------------------+
include_once(PHPWG_ROOT_PATH.'admin/include/functions_upgrade.php');
include(PHPWG_ROOT_PATH . 'include/dblayer/functions_mysqli.inc.php');

upgrade_db_connect();
pwg_db_check_charset();

[$dbnow] = pwg_db_fetch_row(pwg_query('SELECT NOW();')) ?? [null];
define('CURRENT_DATE', $dbnow);

// +-----------------------------------------------------------------------+
// |                        template initialization                        |
// +-----------------------------------------------------------------------+

$template = new Template(PHPWG_ROOT_PATH.'admin/themes', 'roma');
$template->set_filenames(['upgrade' => 'upgrade.tpl']);
$template->assign(
    [
  'RELEASE' => PHPWG_VERSION,
  'L_UPGRADE_HELP' => l10n('Need help ? Ask your question on <a href="%s">Piwigo message board</a>.', PHPWG_URL.'/forum'),
  ]
);

// +-----------------------------------------------------------------------+
// | Remote sites are not compatible with Piwigo 2.4+                      |
// +-----------------------------------------------------------------------+

$has_remote_site = false;

$query = 'SELECT galleries_url FROM '.SITES_TABLE.';';
$result = pwg_query($query);
while ($row = pwg_db_fetch_assoc($result)) {
    if (url_is_remote((string)$row['galleries_url'])) {
        $has_remote_site = true;
    }
}

if ($has_remote_site) {


    $page['errors'] = [];
    $step = 3;
    Updates::upgrade_to('2.3.4', $step, false);

    if (!empty($page['errors'])) {
        echo '<ul>';
        foreach ($page['errors'] as $error) {
            echo '<li>'.$error.'</li>';
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

// Piwigo 16.x-rewrite: refuse databases older than Piwigo 15.x.
// applied_upgrade id 181 marks the 15.0.0 boundary; any DB that does not
// have it cannot safely run this upgrade path.
$applied_upgrades = in_array(PREFIX_TABLE.'upgrade', $tables, true)
    ? query2array('SELECT id FROM '.PREFIX_TABLE.'upgrade', null, 'id')
    : [];

if (!in_array('181', $applied_upgrades, true)) {
    header('Content-Type: text/html; charset=UTF-8', true, 409);
    echo '<h1>Upgrade refused</h1>';
    echo '<p>This Piwigo build only upgrades from <strong>Piwigo 16.x</strong> sources. ';
    echo 'Your database appears to be older than Piwigo 15.0.0 ';
    echo '(applied_upgrades does not contain id 181). ';
    echo 'Please upgrade to Piwigo 16.x through the upstream project first, ';
    echo 'then run this upgrade.</p>';
    exit;
}

// Database is at 16.x level. No per-release upgrade scripts exist yet for this branch.
// When a future 16.x release introduces schema changes, add the version detection here
// and add the corresponding install/db/<N>-database.php file.
conf_update_param('piwigo_db_version', get_branch_from_version(PHPWG_VERSION));
header('Content-Type: text/html; charset='.get_pwg_charset());
echo 'No upgrade required, the database structure is up to date';
echo '<br><a href="index.php">← back to gallery</a>';
exit();
// NOTE: upgrade launch machinery (check_upgrade_access_rights, template render,
// upgrade_<release>.php include) removed in Phase 6 — no 16.x-specific upgrade
// scripts exist yet. When the first install/db/<N>-database.php for a 16.x release
// is authored, add 16.x version detection above and restore the upgrade launch
// section from git history (see commit before Phase 6 step 2).
