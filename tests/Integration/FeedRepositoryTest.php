<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Db\DbConnection;
use Piwigo\Feed\FeedRepository;

final class FeedRepositoryTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private FeedRepository $repo;

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

        CurrentConfig::reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $this->repo = \Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Feed\FeedEntity::class);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $db = $this->newMysqli($this->dbName);
        $db->query(sprintf("DELETE FROM `%suser_feed` WHERE id LIKE 'p17-test-%%'", $this->dbPrefix));
        $db->close();

        parent::tearDown();
    }

    public function test_exists_by_id_returns_false_when_unused(): void
    {
        self::assertFalse($this->repo->existsById('p17-test-' . bin2hex(random_bytes(20))));
    }

    public function test_insert_then_exists_by_id_round_trips(): void
    {
        $id = 'p17-test-' . bin2hex(random_bytes(20));

        $this->repo->insert($id, 1);

        self::assertTrue($this->repo->existsById($id));
    }

    public function test_find_by_id_returns_the_user_id_with_a_null_last_check_right_after_insert(): void
    {
        $id = 'p17-test-' . bin2hex(random_bytes(20));
        $this->repo->insert($id, 1);

        $row = $this->repo->findById($id);

        self::assertNotNull($row);
        self::assertSame(1, $row['userId']);
        self::assertNull($row['lastCheck']);
    }

    public function test_find_by_id_returns_null_when_unused(): void
    {
        self::assertNull($this->repo->findById('p17-test-' . bin2hex(random_bytes(20))));
    }

    public function test_update_last_check_then_find_by_id_round_trips(): void
    {
        $id = 'p17-test-' . bin2hex(random_bytes(20));
        $this->repo->insert($id, 1);
        $lastCheck = new \DateTimeImmutable('2024-03-05 12:34:56');

        $this->repo->updateLastCheck($id, $lastCheck);

        $row = $this->repo->findById($id);
        self::assertNotNull($row);
        self::assertNotNull($row['lastCheck']);
        self::assertSame($lastCheck->format('Y-m-d H:i:s'), $row['lastCheck']->format('Y-m-d H:i:s'));
    }
}
