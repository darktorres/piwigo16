<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+
use Doctrine\DBAL\ParameterType;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Bootstrap\RedirectService;
use Piwigo\Bootstrap\RequestBootstrap;
use Piwigo\Bootstrap\RequestPipeline;
use Piwigo\Category\CategoryRepository;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Paths;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\SqlDialect;
use Piwigo\Group\GroupEntity;
use Piwigo\Http\RequestFactory;
use Piwigo\Http\ResponseEmitter;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\Permission\SqlCondition;
use Psr\Http\Message\ResponseInterface;

// vendor/autoload.php must be required directly here -- Paths::fromRoot()
// below and RequestBootstrap::bootEntryPoint() are both Piwigo\ classes,
// so the autoloader must already be active before either is referenced,
// matching every other real entry point (index.php, admin.php, ...).
require __DIR__ . '/../vendor/autoload.php';

$paths = Paths::fromRoot(dirname(__DIR__));
RequestBootstrap::bootEntryPoint($paths);

// This file never calls RequestPipeline::handle() -- same "raw top-level
// script" shape as admin.php, which explains why it alone (of every other
// real entry point) needs this explicit call: every other entry point
// reaches CurrentUser/CurrentConfig/the plugin registry/a loaded Lang only
// because RequestPipeline::handle()'s own BOOTSTRAP_MIDDLEWARE already ran
// first -- see BOOTSTRAP_MIDDLEWARE's own docblock and admin.php's
// identical call.
$bootstrapPhaseResponse = RequestPipeline::runBootstrapPhase(RequestFactory::fromGlobals());
if ($bootstrapPhaseResponse instanceof ResponseInterface) {
    new ResponseEmitter()
        ->emit($bootstrapPhaseResponse);
    exit;
}

RequestBootstrap::accessControl()->checkStatus(AccessLevel::Guest);

// top_number/nb_image_page are DB-backed smallint columns (default 15);
// see include/config_default.inc.php and install/piwigo_structure-mysql.sql.
// nb_image_page has exactly this one reader repo-wide, under User's own
// documented promotion bar for a named property -- read via
// rawAttributes, same as every other low-frequency legacy $user key.
$top_number = RequestBootstrap::currentConfig()->topNumber;
$rawNbImagePage = RequestBootstrap::currentUser()->get()->rawAttributes['nb_image_page'] ?? null;
$nb_image_page = is_numeric($rawNbImagePage) ? (int) $rawNbImagePage : 15;

$conn = DbConnection::build();

$permissionCriteria = new PermissionService(new PermissionRepository(EntityManagerFactory::build($conn)), EntityManagerFactory::build($conn)->getRepository(GroupEntity::class), new CategoryRepository(EntityManagerFactory::build($conn), RequestBootstrap::currentConfig()), RequestBootstrap::currentUser(), RequestBootstrap::filterState(), new AccessLevelChecker(RequestBootstrap::currentUser(), RequestBootstrap::currentConfig()))->getPermissionCriteria();
$condition = SqlCondition::combine(
    'AND',
    $permissionCriteria->forbiddenCategoriesCondition('category_id'),
    $permissionCriteria->visibleCategoriesCondition('category_id'),
    $permissionCriteria->visibleImagesCondition('id'),
    $permissionCriteria->maxLevelCondition('level'),
);

$query = '
SELECT id
  FROM images
    INNER JOIN image_category AS ic ON id = ic.image_id
' . ($condition->isEmpty() ? '' : 'WHERE ' . $condition->sql) . '
  ORDER BY ' . SqlDialect::randomFunction() . '
  LIMIT :limit
;';

$params = [
    ...$condition->parameters,
    'limit' => min(50, $top_number, $nb_image_page),
];
$types = [
    ...$condition->types,
    'limit' => ParameterType::INTEGER,
];

// This file never calls RequestPipeline::handle() -- it's a raw
// top-level script, so nothing else catches ResponseReadyException here.
// This try/catch is this file's own catch point, same shape as
// AdminShell::run()'s own dispatch-context catch point.
try {
    new RedirectService(RequestBootstrap::lang(), RequestBootstrap::userService(), RequestBootstrap::eventDispatcher(), RequestBootstrap::pageState())
        ->redirect(RequestBootstrap::urlService()->makeIndexUrl([
            'list' => array_map(
                static fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
                $conn->fetchFirstColumn($query, $params, $types)
            ),
        ]));
} catch (ResponseReadyException $e) {
    new ResponseEmitter()
        ->emit($e->response());
}
