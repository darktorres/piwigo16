<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// +-----------------------------------------------------------------------+
// |                          define and include                           |
// +-----------------------------------------------------------------------+

use Piwigo\Core\AccessLevel;
use Piwigo\Db\Tables;

define('PHPWG_ROOT_PATH', './');
include_once PHPWG_ROOT_PATH . 'include/common.inc.php';

// Bootstrap globals, set by include/common.inc.php.
/**
 * @var array<string, mixed> $conf
 * @var array<string, mixed> $user
 */
global $conf, $user;

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
\Piwigo\Auth\AccessControl::checkStatus(AccessLevel::Guest);

// +-----------------------------------------------------------------------+
// |                     generate random element list                      |
// +-----------------------------------------------------------------------+

// top_number/nb_image_page are DB-backed smallint columns (default 15);
// see include/config_default.inc.php and install/piwigo_structure-mysql.sql.
$top_number = is_numeric($conf['top_number'] ?? null) ? (int) $conf['top_number'] : 15;
$nb_image_page = is_numeric($user['nb_image_page'] ?? null) ? (int) $user['nb_image_page'] : 15;

$conn = \Piwigo\Db\DbConnection::build();

$query = '
SELECT id
  FROM ' . Tables::images() . '
    INNER JOIN ' . Tables::imageCategory() . ' AS ic ON id = ic.image_id
' . new \Piwigo\Permission\PermissionService(new \Piwigo\Permission\PermissionRepository($conn), new \Piwigo\Group\GroupRepository($conn))->getSqlConditionFandF([
    'forbidden_categories' => 'category_id',
    'visible_categories' => 'category_id',
    'visible_images' => 'id',
], 'WHERE') . '
  ORDER BY ' . \Piwigo\Db\SqlDialect::DB_RANDOM_FUNCTION . '()
  LIMIT ' . min(50, $top_number, $nb_image_page) . '
;';

// +-----------------------------------------------------------------------+
// |                                redirect                               |
// +-----------------------------------------------------------------------+

redirect(make_index_url([
    'list' => array_map(
        static fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
        $conn->fetchFirstColumn($query)
    ),
]));
