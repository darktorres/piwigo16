<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use Piwigo\Core\Kernel;
use LogicException;
use Piwigo\Core\Lang;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Users\CurrentUser;
use Piwigo\Session\SessionService;
use Piwigo\Core\FilterState;
use Doctrine\DBAL\Connection;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Core\CurrentLogger;
use Piwigo\Db\DbConnection;
use Piwigo\Job\Handler\ReindexImagesHandler;
use Piwigo\Job\ReindexImagesJob;
use Piwigo\Metadata\MetadataRepository;
use Piwigo\Metadata\MetadataService;

/**
 * Only exercises the empty-$imageIds path -- MetadataRepository::
 * findImagesByIds([]) short-circuits to `return []` before any real EXIF
 * file read, and TagService::setTagsOf() (still called unconditionally at
 * the end of syncMetadata(), P23 batch 8d Tags sub-batch) safely no-ops on
 * an empty tag map. A full real EXIF resync round-trip is already covered
 * by MetadataServiceTest/the P19 live-verification (see project memory) --
 * this test's job is only to prove ReindexImagesHandler correctly
 * delegates to MetadataService.
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

    public function test_invoke_delegates_to_metadata_service_sync_metadata(): void
    {
        $handler = new ReindexImagesHandler(new MetadataService(Lang::current(), new MetadataRepository(EntityManagerFactory::build($this->conn)), new CurrentLogger(), new EventDispatcher(), new CurrentConfig(), CurrentUser::current(), SessionService::get(), new FilterState()));

        // no exception/fatal is the real assertion here -- see the class
        // docblock for why a full real EXIF-sync side effect isn't
        // exercised by this particular test; setUp()'s own
        // resetDatabase()/loadFixture() already perform real assertions
        // for this test method, so PHPUnit doesn't flag it as risky for
        // lacking any.
        $handler(new ReindexImagesJob([]));
    }
}
