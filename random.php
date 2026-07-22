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

use Piwigo\Config\Config;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Paths;
use Piwigo\Db\Tables;
use Piwigo\Users\CurrentUser;

// Unlike this file's own former "never requires vendor/autoload.php,
// relies entirely on common.inc.php's own include/env.inc.php" shape:
// common.inc.php's RequestBootstrap::configure($paths) call (P24 8a, the
// boot-first fix) now needs a real Paths in scope before the include runs,
// and Paths::fromIndex() itself is a Piwigo\ class -- so the autoloader
// must be required explicitly first, matching every other real entry
// point (index.php, admin.php, ...). Requiring it twice is safe (PHP's
// own realpath-keyed include cache no-ops the second require via
// common.inc.php's own env.inc.php include), same precedent index.php's
// own docblock documents.
require __DIR__ . '/vendor/autoload.php';

$paths = Paths::fromIndex(__FILE__);
include_once $paths->root . 'include/common.inc.php';

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
\Piwigo\Auth\AccessControl::checkStatus(AccessLevel::Guest);

// +-----------------------------------------------------------------------+
// |                     generate random element list                      |
// +-----------------------------------------------------------------------+

// top_number/nb_image_page are DB-backed smallint columns (default 15);
// see include/config_default.inc.php and install/piwigo_structure-mysql.sql.
// nb_image_page has exactly this one reader repo-wide, under User's own
// documented promotion bar for a named property -- read via
// rawAttributes, same as every other low-frequency legacy $user key.
$top_number = Config::topNumber();
$rawNbImagePage = CurrentUser::get()->rawAttributes['nb_image_page'] ?? null;
$nb_image_page = is_numeric($rawNbImagePage) ? (int) $rawNbImagePage : 15;

$conn = \Piwigo\Db\DbConnection::build();

$query = '
SELECT id
  FROM ' . Tables::images() . '
    INNER JOIN ' . Tables::imageCategory() . ' AS ic ON id = ic.image_id
' . new \Piwigo\Permission\PermissionService(new \Piwigo\Permission\PermissionRepository($conn), new \Piwigo\Group\GroupRepository($conn), new \Piwigo\Category\CategoryRepository($conn))->getSqlConditionFandF([
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

new \Piwigo\Bootstrap\RedirectService()
    ->redirect(new \Piwigo\Url\UrlService(new \Piwigo\Html\HtmlService())->makeIndexUrl([
        'list' => array_map(
            static fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
            $conn->fetchFirstColumn($query)
        ),
    ]));
