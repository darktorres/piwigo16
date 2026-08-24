<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install;

use Doctrine\DBAL\Connection;
use Piwigo\Activity\ActivityEntity;
use Piwigo\Activity\ActivityRepository;
use Piwigo\Activity\ActivityService;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Auth\PasswordRepository;
use Piwigo\Auth\PasswordService;
use Piwigo\Bootstrap\PresentationAccessor;
use Piwigo\Cache\CacheFactory;
use Piwigo\Cache\TranslationsCachePool;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Core\FilterState;
use Piwigo\Core\InstallationFlag;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Core\ProcessCache;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\TypedRepository;
use Piwigo\Group\GroupEntity;
use Piwigo\Group\GroupRepository;
use Piwigo\Lang\Translator;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionRepository;
use Piwigo\Session\SessionService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;

/**
 * Builds throwaway UserService/PasswordService instances from an
 * already-resolved Connection, matching the same "private helper takes the
 * already-available Connection as a parameter" shape as
 * Bootstrap\RequestBootstrap::activityService() -- extracted out of
 * InstallWizard (formerly its own private userService()/passwordService()
 * methods) so InstallDataSeeder/InstallPostInstallSession can share it
 * without either repeating the same multi-dependency service construction
 * chain, which tests/Arch/StructuralTest.php's own
 * findDuplicateServiceConstructionChains() forbids verbatim within one
 * file.
 */
final class InstallServiceFactory
{
    public function __construct(
        private readonly Lang $lang,
        private readonly CurrentUser $currentUser,
        private readonly EventDispatcher $eventDispatcher,
        private readonly DeploymentPolicy $deploymentPolicy,
        private readonly CurrentConfig $currentConfig,
        private readonly Paths $paths,
    ) {}

    /**
     * $conn defaults to a fresh DbConnection::build() rather than requiring
     * a caller to always supply one: InstallWizard::analyzeForm()'s own
     * call site can legitimately run after InstallService::installDbConnect()
     * returned null (a failed connection attempt), unlike every
     * performInstall()/render() call site, which always has a real
     * connection by the time it runs.
     */
    public function userService(?Connection $conn = null): UserService
    {
        $conn ??= DbConnection::build();
        $accessLevelChecker = new AccessLevelChecker($this->currentUser, $this->currentConfig);
        $filterState = new FilterState();
        $permissionService = new PermissionService(new PermissionRepository(EntityManagerFactory::build($conn)), TypedRepository::narrow(EntityManagerFactory::build($conn)->getRepository(GroupEntity::class), GroupRepository::class), new CategoryRepository(EntityManagerFactory::build($conn), $this->currentConfig), $this->currentUser, $filterState, $accessLevelChecker);

        return new UserService($this->lang, new UserRepository(EntityManagerFactory::build($conn), $this->eventDispatcher, $this->currentConfig), TypedRepository::narrow(EntityManagerFactory::build($conn)->getRepository(GroupEntity::class), GroupRepository::class), new ActivityService(TypedRepository::narrow(EntityManagerFactory::build($conn)->getRepository(ActivityEntity::class), ActivityRepository::class)), PresentationAccessor::htmlService(), new SessionService(TypedRepository::narrow(EntityManagerFactory::build($conn)->getRepository(SessionEntity::class), SessionRepository::class), $this->currentConfig), $this->eventDispatcher, $this->deploymentPolicy, $this->currentUser, $this->currentConfig, new InstallationFlag(), new ProcessCache(), $this->paths, EntityManagerFactory::build($conn), $permissionService, new CategoryService($this->lang, new CategoryRepository(EntityManagerFactory::build($conn), $this->currentConfig), $permissionService, $this->currentConfig, $this->eventDispatcher, new Translator($this->currentConfig, new TranslationsCachePool(CacheFactory::create(namespace: 'piwigo.translations'))), $accessLevelChecker), $this->passwordService($conn));
    }

    public function passwordService(Connection $conn): PasswordService
    {
        return new PasswordService(new PasswordRepository(EntityManagerFactory::build($conn)), $this->deploymentPolicy);
    }
}
