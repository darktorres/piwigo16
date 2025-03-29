<?php

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

//check php version
use Piwigo\admin\inc\functions_upgrade;
use Piwigo\inc\dblayer\functions_mysqli;
use Piwigo\inc\functions;

if (version_compare(PHP_VERSION, REQUIRED_PHP_VERSION, '<')) {
    die(functions::l10n('PHP version %s required (you are running on PHP %s)', REQUIRED_PHP_VERSION, PHP_VERSION));
}

define('PHPWG_ROOT_PATH', './');

include(PHPWG_ROOT_PATH . 'inc/config_default.php');

if (file_exists(PHPWG_ROOT_PATH . 'local/config/config.php')) {
    include(PHPWG_ROOT_PATH . 'local/config/config.php');
}

if (! defined('PWG_LOCAL_DIR')) {
    define('PWG_LOCAL_DIR', 'local/');
}

include(PHPWG_ROOT_PATH . PWG_LOCAL_DIR . 'config/database.php');
include_once(PHPWG_ROOT_PATH . 'inc/functions.php');

// +-----------------------------------------------------------------------+
// | Check Access and exit when it is not ok                               |
// +-----------------------------------------------------------------------+

if (! $conf['check_upgrade_feed']) {
    die('upgrade feed is not active');
}

define('UPGRADES_PATH', PHPWG_ROOT_PATH . 'install/db');

// +-----------------------------------------------------------------------+
// |                         Database connection                           |
// +-----------------------------------------------------------------------+
try {
    functions_mysqli::pwg_db_connect(
        $conf['db_host'],
        $conf['db_user'],
        $conf['db_password'],
        $conf['db_base']
    );
} catch (Exception $e) {
    functions_mysqli::my_error(functions::l10n($e->getMessage()), true);
}

functions_mysqli::pwg_db_check_charset();

// +-----------------------------------------------------------------------+
// |                              Upgrades                                 |
// +-----------------------------------------------------------------------+

// retrieve already applied upgrades
$query = <<<SQL
    SELECT id
    FROM upgrade;
    SQL;
$applied = functions::array_from_query($query, 'id');

// retrieve existing upgrades
$existing = functions_upgrade::get_available_upgrade_ids();

// which upgrades need to be applied?
$to_apply = array_diff($existing, $applied);

echo '<pre>';
echo count($to_apply) . ' upgrades to apply';

foreach ($to_apply as $upgrade_id) {
    unset($upgrade_description);

    echo "\n\n";
    echo '=== upgrade ' . $upgrade_id . "\n";

    // include & execute upgrade script. Each upgrade script must contain
    // $upgrade_description variable which describe briefly what the upgrade
    // script does.
    include(UPGRADES_PATH . '/' . $upgrade_id . '-database.php');

    // notify upgrade
    $query = <<<SQL
        INSERT INTO upgrade
            (id, applied, description)
        VALUES
            ('{$upgrade_id}', NOW(), '{$upgrade_description}');
        SQL;
    functions_mysqli::pwg_query($query);
}

echo '</pre>';
