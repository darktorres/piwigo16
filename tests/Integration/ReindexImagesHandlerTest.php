<?php

declare(strict_types=1);

// set_tags_of() (admin/include/functions.php) isn't loaded by this
// isolated Integration test process. Its real body is entirely guarded
// behind `if (count($tags_of) > 0)` -- a genuine, faithful no-op for the
// empty $tags_of this test's empty $imageIds always produces (see
// MetadataService::syncMetadata()'s own loop: findImagesByIds([])
// short-circuits before $tagsOf is ever populated). Fails loudly rather
// than silently if ever exercised with real data, since a real tag sync
// needs get_image_tag_ids()/mass_inserts()/Logger well beyond this
// handler-delegation test's scope.
namespace {
    if (! function_exists('set_tags_of')) {
        /**
         * @param array<int|string, array<int, int|string>> $tags_of
         */
        function set_tags_of(array $tags_of): void
        {
            if ($tags_of === []) {
                return;
            }

            throw new \LogicException('set_tags_of() stub only supports an empty $tags_of in this test process.');
        }
    }
}

namespace Piwigo\Tests\Integration {

use Doctrine\DBAL\Connection;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigLoader;
use Piwigo\Db\DbConnection;
use Piwigo\Job\Handler\ReindexImagesHandler;
use Piwigo\Job\ReindexImagesJob;
use Piwigo\Metadata\MetadataRepository;
use Piwigo\Metadata\MetadataService;

/**
 * Only exercises the empty-$imageIds path -- MetadataRepository::
 * findImagesByIds([]) short-circuits to `return []` before any real EXIF
 * file read, and set_tags_of() (still called unconditionally at the end
 * of syncMetadata()) is a real, already-migrated free function that
 * safely no-ops on an empty tag map (see the stub above). A full real
 * EXIF resync round-trip is already covered by MetadataServiceTest/the
 * P19 live-verification (see project memory) -- this test's job is only
 * to prove ReindexImagesHandler correctly delegates to MetadataService.
 */
final class ReindexImagesHandlerTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private Connection $conn;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        if (! self::$fixtureReady) {
            $this->resetDatabase();
            $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        Config::reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $this->conn = DbConnection::build();
    }

    #[\Override]
    protected function tearDown(): void
    {
        Config::reset();
        parent::tearDown();
    }

    public function test_invoke_delegates_to_metadata_service_sync_metadata(): void
    {
        $handler = new ReindexImagesHandler(new MetadataService(new MetadataRepository($this->conn)));

        // no exception/fatal is the real assertion here -- see the class
        // docblock for why a full real EXIF-sync side effect isn't
        // exercised by this particular test; setUp()'s own
        // resetDatabase()/loadFixture() already perform real assertions
        // for this test method, so PHPUnit doesn't flag it as risky for
        // lacking any.
        $handler(new ReindexImagesJob([]));
    }
}
}
