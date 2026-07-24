<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Menu\MenubarLayoutRepository;

final class MenubarLayoutRepositoryTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private MenubarLayoutRepository $repo;

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

        CurrentConfig::reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $this->conn = DbConnection::build();
        $this->repo = new MenubarLayoutRepository($this->conn);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->conn->executeStatement(
            "UPDATE " . Tables::config() . " SET value = '' WHERE param = 'blk_menubar'"
        );
        parent::tearDown();
    }

    public function test_save_layout_persists_serialized_positions(): void
    {
        $this->repo->saveLayout('menubar', ['mbCategories' => 50, 'mbTags' => -100]);

        $raw = $this->conn->createQueryBuilder()
            ->select('value')
            ->from(Tables::config())
            ->where("param = 'blk_menubar'")
            ->executeQuery()
            ->fetchOne();

        self::assertIsString($raw);
        self::assertSame(['mbCategories' => 50, 'mbTags' => -100], unserialize($raw));
    }

    public function test_save_layout_overwrites_a_previous_layout(): void
    {
        $this->repo->saveLayout('menubar', ['mbCategories' => 50]);
        $this->repo->saveLayout('menubar', ['mbCategories' => 200]);

        $raw = $this->conn->createQueryBuilder()
            ->select('value')
            ->from(Tables::config())
            ->where("param = 'blk_menubar'")
            ->executeQuery()
            ->fetchOne();

        self::assertIsString($raw);
        self::assertSame(['mbCategories' => 200], unserialize($raw));
    }

    public function test_save_layout_does_not_touch_other_config_rows(): void
    {
        $before = $this->conn->createQueryBuilder()
            ->select('value')
            ->from(Tables::config())
            ->where("param = 'gallery_title'")
            ->executeQuery()
            ->fetchOne();

        $this->repo->saveLayout('menubar', ['mbCategories' => 50]);

        $after = $this->conn->createQueryBuilder()
            ->select('value')
            ->from(Tables::config())
            ->where("param = 'gallery_title'")
            ->executeQuery()
            ->fetchOne();

        self::assertSame($before, $after);
    }
}
