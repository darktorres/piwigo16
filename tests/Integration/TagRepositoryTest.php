<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Db\DbConnection;
use Piwigo\Tag\TagRepository;

final class TagRepositoryTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private TagRepository $repo;

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

        $this->repo = new TagRepository(DbConnection::build());
    }

    public function test_find_all_returns_every_fixture_tag(): void
    {
        $names = array_column($this->repo->findAll(), 'name');
        sort($names);

        self::assertSame(['family', 'nature', 'travel'], $names);
    }

    public function test_find_by_ids_url_names_or_names_returns_empty_for_no_criteria(): void
    {
        self::assertSame([], $this->repo->findByIdsUrlNamesOrNames([], [], []));
    }

    public function test_find_by_ids_matches_by_id(): void
    {
        $rows = $this->repo->findByIdsUrlNamesOrNames([1], [], []);

        self::assertCount(1, $rows);
        self::assertSame('nature', $rows[0]->name);
    }

    public function test_find_by_ids_matches_by_url_name(): void
    {
        $rows = $this->repo->findByIdsUrlNamesOrNames([], ['travel'], []);

        self::assertCount(1, $rows);
        self::assertSame('travel', $rows[0]->name);
    }

    public function test_find_by_ids_matches_by_name(): void
    {
        $rows = $this->repo->findByIdsUrlNamesOrNames([], [], ['family']);

        self::assertCount(1, $rows);
        self::assertSame('family', $rows[0]->name);
    }

    public function test_find_by_ids_combines_criteria_with_or(): void
    {
        $rows = $this->repo->findByIdsUrlNamesOrNames([1], ['travel'], []);

        $names = array_column($rows, 'name');
        sort($names);
        self::assertSame(['nature', 'travel'], $names);
    }

    public function test_find_by_ids_accepts_numeric_string_ids(): void
    {
        $rows = $this->repo->findByIdsUrlNamesOrNames(['2'], [], []);

        self::assertCount(1, $rows);
        self::assertSame('travel', $rows[0]->name);
    }
}
