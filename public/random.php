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

use Piwigo\Config\CurrentConfig;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Paths;
use Piwigo\Db\Tables;
use Piwigo\Users\CurrentUser;

// vendor/autoload.php must be required directly here -- Paths::fromRoot()
// below and RequestBootstrap::bootEntryPoint() are both Piwigo\ classes,
// so the autoloader must already be active before either is referenced,
// matching every other real entry point (index.php, admin.php, ...).
require __DIR__ . '/../vendor/autoload.php';

$paths = Paths::fromRoot(dirname(__DIR__));
\Piwigo\Bootstrap\RequestBootstrap::bootEntryPoint($paths);

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
\Piwigo\Auth\AccessControl::current()->checkStatus(AccessLevel::Guest);

// +-----------------------------------------------------------------------+
// |                     generate random element list                      |
// +-----------------------------------------------------------------------+

// top_number/nb_image_page are DB-backed smallint columns (default 15);
// see include/config_default.inc.php and install/piwigo_structure-mysql.sql.
// nb_image_page has exactly this one reader repo-wide, under User's own
// documented promotion bar for a named property -- read via
// rawAttributes, same as every other low-frequency legacy $user key.
$top_number = CurrentConfig::current()->topNumber();
$rawNbImagePage = CurrentUser::current()->get()->rawAttributes['nb_image_page'] ?? null;
$nb_image_page = is_numeric($rawNbImagePage) ? (int) $rawNbImagePage : 15;

$conn = \Piwigo\Db\DbConnection::build();

$condition = new \Piwigo\Permission\PermissionService(new \Piwigo\Permission\PermissionRepository(\Piwigo\Db\EntityManagerFactory::build($conn)), \Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Group\GroupEntity::class), \Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Category\CategoryEntity::class))->getSqlConditionFandFAsCondition([
    'forbidden_categories' => 'category_id',
    'visible_categories' => 'category_id',
    'visible_images' => 'id',
]);

$query = '
SELECT id
  FROM ' . Tables::images() . '
    INNER JOIN ' . Tables::imageCategory() . ' AS ic ON id = ic.image_id
' . ($condition->isEmpty() ? '' : 'WHERE ' . $condition->sql) . '
  ORDER BY ' . \Piwigo\Db\SqlDialect::DB_RANDOM_FUNCTION . '()
  LIMIT :limit
;';

$params = [
    ...$condition->parameters,
    'limit' => min(50, $top_number, $nb_image_page),
];
$types = [
    ...$condition->types,
    'limit' => \Doctrine\DBAL\ParameterType::INTEGER,
];

// +-----------------------------------------------------------------------+
// |                                redirect                               |
// +-----------------------------------------------------------------------+

// This file never calls RequestPipeline::handle() (unlike every P22
// controller's own root file) -- it's a raw top-level script, so none of
// Workstream C3's 3 pipeline/bootstrap catch points wrap this call. Found
// live (real 500, uncaught ResponseReadyException) while re-verifying
// Part I's own "every RedirectService/HtmlService caller is covered by one
// of the 3 catch points" claim against actual call sites rather than
// trusting it -- this file was never audited because it isn't reached from
// config/routes.php. A 4th, file-local catch point, same shape as
// AdminShell::run()'s own dispatch-context catch point.
try {
    new \Piwigo\Bootstrap\RedirectService(\Piwigo\Bootstrap\RequestBootstrap::lang(), \Piwigo\Bootstrap\RequestBootstrap::userService())
        ->redirect(\Piwigo\Bootstrap\RequestBootstrap::urlService()->makeIndexUrl([
            'list' => array_map(
                static fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
                $conn->fetchFirstColumn($query, $params, $types)
            ),
        ]));
} catch (\Piwigo\Http\ResponseReadyException $e) {
    new \Piwigo\Http\ResponseEmitter()
        ->emit($e->response());
}
