<?php

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

define('PHPWG_ROOT_PATH', './');

require_once PHPWG_ROOT_PATH . 'vendor/autoload.php';

\Piwigo\Config\ConfigLoader::applyDefaults();

defined('PWG_LOCAL_DIR') or define('PWG_LOCAL_DIR', 'local/');

\Piwigo\Config\ConfigLoader::loadEnv(PHPWG_ROOT_PATH);
\Piwigo\Config\ConfigLoader::applyEnvOverrides();

$prefixeTable = \Piwigo\Config\Config::dbPrefix();

include(PHPWG_ROOT_PATH . 'include/dblayer/functions_mysqli.inc.php');

include_once(PHPWG_ROOT_PATH.'include/functions.inc.php');
include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');
include_once(PHPWG_ROOT_PATH.'admin/include/functions_upgrade.php');


// +-----------------------------------------------------------------------+
// | Check Access and exit when it is not ok                               |
// +-----------------------------------------------------------------------+

if (!\Piwigo\Config\Config::checkUpgradeFeed()) {
    die('upgrade feed is not active');
}

prepare_conf_upgrade();

define('PREFIX_TABLE', $prefixeTable);
define('UPGRADES_PATH', PHPWG_ROOT_PATH.'install/db');

// +-----------------------------------------------------------------------+
// |                         Database connection                           |
// +-----------------------------------------------------------------------+
// Connection is established lazily by get_dbal_connection() on first use.

// +-----------------------------------------------------------------------+
// |                              Upgrades                                 |
// +-----------------------------------------------------------------------+

// retrieve already applied upgrades
$query = '
SELECT id
  FROM '.PREFIX_TABLE.'upgrade
;';
$applied = array_from_query($query, 'id');

// retrieve existing upgrades
$existing = get_available_upgrade_ids();

// which upgrades need to be applied?
$to_apply = array_diff($existing, $applied);

echo '<pre>';
echo count($to_apply).' upgrades to apply';

foreach ($to_apply as $upgrade_id) {
    unset($upgrade_description);

    echo "\n\n";
    echo '=== upgrade '.$upgrade_id."\n";

    // include & execute upgrade script. Each upgrade script must contain
    // $upgrade_description variable which describe briefly what the upgrade
    // script does.
    include(UPGRADES_PATH.'/'.$upgrade_id.'-database.php');

    // notify upgrade
    single_insert(PREFIX_TABLE . 'upgrade', [
        'id'          => $upgrade_id,
        'applied'     => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        'description' => is_string($upgrade_description ?? null) ? $upgrade_description : '',
    ]);
}

echo '</pre>';
