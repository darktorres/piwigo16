<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// check php version
if (version_compare(PHP_VERSION, '8.5.0', '<')) {
    die('Piwigo requires PHP 8.5 or above.');
}

define('PHPWG_ROOT_PATH', './');

// Bootstrap globals, set by include/config_default.inc.php and database.inc.php.
/**
 * @var array<string, mixed> $conf
 * @var string $prefixeTable
 */
global $conf, $prefixeTable;

include PHPWG_ROOT_PATH . 'include/config_default.inc.php';
@include PHPWG_ROOT_PATH . 'local/config/config.inc.php';
defined('PWG_LOCAL_DIR') or define('PWG_LOCAL_DIR', 'local/');

include PHPWG_ROOT_PATH . PWG_LOCAL_DIR . 'config/database.inc.php';

// $conf['dblayer'] is set by config_default.inc.php/database.inc.php as a
// string, but the generic array<string, mixed> type of $conf erases that
// specific type; re-narrow at the point of use (same pattern as
// include/common.inc.php and upgrade.php).
$dblayer = $conf['dblayer'];
if (! is_string($dblayer)) {
    die("Invalid \$conf['dblayer'] configuration: expected a string.");
}
include PHPWG_ROOT_PATH . 'include/dblayer/functions_' . $dblayer . '.inc.php';

include_once PHPWG_ROOT_PATH . 'include/functions.inc.php';
include_once PHPWG_ROOT_PATH . 'admin/include/functions_upgrade.php';

// +-----------------------------------------------------------------------+
// | Check Access and exit when it is not ok                               |
// +-----------------------------------------------------------------------+

if (! (bool) $conf['check_upgrade_feed']) {
    die('upgrade feed is not active');
}

prepare_conf_upgrade();

define('PREFIX_TABLE', $prefixeTable);
define('UPGRADES_PATH', PHPWG_ROOT_PATH . 'install/db');

// +-----------------------------------------------------------------------+
// |                         Database connection                           |
// +-----------------------------------------------------------------------+
try {
    $db_host = $conf['db_host'];
    $db_user = $conf['db_user'];
    $db_password = $conf['db_password'];
    $db_base = $conf['db_base'];
    if (! is_string($db_host) || ! is_string($db_user) || ! is_string($db_password) || ! is_string($db_base)) {
        throw new Exception("Invalid database configuration: \$conf['db_host'], 'db_user', 'db_password' and 'db_base' must be strings.");
    }
    \Piwigo\Db\MysqliDb::connect(
        $db_host,
        $db_user,
        $db_password,
        $db_base
    );
} catch (Exception $e) {
    \Piwigo\Db\MysqliDb::myError(l10n($e->getMessage(), true));
}

\Piwigo\Db\MysqliDb::checkCharset();

// +-----------------------------------------------------------------------+
// |                              Upgrades                                 |
// +-----------------------------------------------------------------------+

// retrieve already applied upgrades
$query = '
SELECT id
  FROM ' . PREFIX_TABLE . 'upgrade
;';
$applied = array_filter(\Piwigo\Db\MysqliDb::query2Array($query, null, 'id'), is_string(...));

// retrieve existing upgrades
$existing = get_available_upgrade_ids();

// which upgrades need to be applied?
$to_apply = array_diff($existing, $applied);

echo '<pre>';
echo count($to_apply) . ' upgrades to apply';

foreach ($to_apply as $upgrade_id) {
    $upgrade_description = '';

    echo "\n\n";
    echo '=== upgrade ' . $upgrade_id . "\n";

    // include & execute upgrade script. Each upgrade script must contain
    // $upgrade_description variable which describe briefly what the upgrade
    // script does.
    include UPGRADES_PATH . '/' . $upgrade_id . '-database.php';

    // notify upgrade
    $query = '
INSERT INTO ' . PREFIX_TABLE . 'upgrade
  (id, applied, description)
  VALUES
  (\'' . $upgrade_id . '\', NOW(), \'' . $upgrade_description . '\')
;';
    \Piwigo\Db\MysqliDb::query($query);
}

echo '</pre>';
