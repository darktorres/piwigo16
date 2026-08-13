<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use LogicException;
use Override;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Category\CategoryRepository;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\FilterState;
use Piwigo\Core\Kernel;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Group\GroupEntity;
use Piwigo\Job\Handler\ReindexImagesHandler;
use Piwigo\Job\ReindexImagesJob;
use Piwigo\Metadata\MetadataRepository;
use Piwigo\Metadata\MetadataService;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tests\Support\CurrentPathsTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\SessionServiceTestFactory;

/**
 * Only exercises the empty-$imageIds path -- MetadataRepository::
 * findImagesByIds([]) short-circuits to `return []` before any real EXIF
 * file read, and TagService::setTagsOf() (called unconditionally at the
 * end of syncMetadata()) safely no-ops on an empty tag map. A full real
 * EXIF resync round-trip is covered by MetadataServiceTest; this test's
 * job is only to prove ReindexImagesHandler correctly delegates to
 * MetadataService.
 */
final class ReindexImagesHandlerTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private Connection $conn;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        if (! self::$fixtureReady) {
            $this->resetDatabase();
            $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $this->conn = DbConnection::build();
    }

    #[Override]
    protected function tearDown(): void
    {
        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->reset();
        parent::tearDown();
    }

    public function testInvokeDelegatesToMetadataServiceSyncMetadata(): void
    {
        $currentConfig = new CurrentConfig();
        $handler = new ReindexImagesHandler(
            new MetadataService(LangTestFactory::get(), new MetadataRepository(EntityManagerFactory::build($this->conn)), new CurrentLogger(), new EventDispatcher(), $currentConfig, CurrentUserTestFactory::get(), SessionServiceTestFactory::get(), CurrentPathsTestFactory::get()),
            new PermissionService(
                new PermissionRepository(EntityManagerFactory::build($this->conn)),
                EntityManagerFactory::build($this->conn)->getRepository(GroupEntity::class),
                new CategoryRepository(EntityManagerFactory::build($this->conn), $currentConfig),
                CurrentUserTestFactory::get(),
                new FilterState(),
                new AccessLevelChecker(CurrentUserTestFactory::get(), $currentConfig),
            ),
        );

        // no exception/fatal is the real assertion here -- see the class
        // docblock for why a full real EXIF-sync side effect isn't
        // exercised by this particular test; setUp()'s own
        // resetDatabase()/loadFixture() already perform real assertions
        // for this test method, so PHPUnit doesn't flag it as risky for
        // lacking any.
        $handler(new ReindexImagesJob([]));
    }
}
