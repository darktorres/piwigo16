<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Piwigo\Common\ValueObject\TagId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\Tables;
use Piwigo\Tag\TagEntity;
use Piwigo\Tag\TagRepository;

final class TagRepositoryTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private TagRepository $repo;

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
        $this->repo = EntityManagerFactory::build($this->conn)->getRepository(TagEntity::class);
    }

    public function test_find_all_returns_every_fixture_tag(): void
    {
        $names = array_column($this->repo->findAllTags(), 'name');
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

    public function test_find_tag_ids_by_image_ids_returns_empty_for_no_ids(): void
    {
        self::assertSame([], $this->repo->findTagIdsByImageIds([]));
    }

    /**
     * fixture image_tag rows (tests/Fixtures/piwigo-17.0.sql): image 1 has
     * tags 1 (nature), 2 (travel), 3 (family); image 2 has only tag 1.
     */
    public function test_find_tag_ids_by_image_ids_matches_the_fixture(): void
    {
        $rows = $this->repo->findTagIdsByImageIds([1, 2]);

        $pairs = array_map(
            static fn (array $row): string => $row['image_id'] . ':' . $row['tag_id']->value,
            $rows
        );
        sort($pairs);

        self::assertSame(['1:1', '1:2', '1:3', '2:1'], $pairs);
    }

    public function test_find_image_ids_for_tag_ids_returns_empty_for_no_ids(): void
    {
        self::assertSame([], $this->repo->findImageIdsForTagIds([]));
    }

    public function test_find_image_ids_for_tag_ids_matches_the_fixture(): void
    {
        $ids = $this->repo->findImageIdsForTagIds([TagId::from(1)]);
        sort($ids);

        self::assertSame([1, 2, 3], $ids);
    }

    public function test_delete_image_tag_by_tag_ids_is_a_no_op_for_no_ids(): void
    {
        $this->conn->insert(Tables::imageTag(), ['image_id' => 4, 'tag_id' => 3]);

        try {
            $this->repo->deleteImageTagByTagIds([]);

            // tag 3 (family) is also linked to image 1 in the fixture --
            // both links survive this no-op call.
            self::assertSame([1, 4], $this->repo->findImageIdsForTagIds([TagId::from(3)]));
        } finally {
            $this->conn->delete(Tables::imageTag(), ['image_id' => 4, 'tag_id' => 3]);
        }
    }

    public function test_delete_image_tag_by_tag_ids_removes_every_link_to_that_tag(): void
    {
        $this->conn->insert(Tables::imageTag(), ['image_id' => 4, 'tag_id' => 3]);
        $this->conn->insert(Tables::imageTag(), ['image_id' => 5, 'tag_id' => 3]);

        try {
            $this->repo->deleteImageTagByTagIds([TagId::from(3)]);

            // family (tag 3) was also linked to image 1 in the fixture --
            // that link must be gone too, not just the 2 disposable ones
            // just added.
            self::assertSame([], $this->repo->findImageIdsForTagIds([TagId::from(3)]));
        } finally {
            // restore the fixture's own image1<->tag3 link for later tests/classes
            $this->conn->insert(Tables::imageTag(), ['image_id' => 1, 'tag_id' => 3]);
        }
    }

    public function test_delete_image_tag_by_image_ids_is_a_no_op_for_no_ids(): void
    {
        $this->conn->insert(Tables::imageTag(), ['image_id' => 5, 'tag_id' => 2]);

        try {
            $this->repo->deleteImageTagByImageIds([]);

            // tag 2 is also linked to image 1 in the fixture -- both links
            // survive this no-op call.
            self::assertSame([1, 5], $this->repo->findImageIdsForTagIds([TagId::from(2)]));
        } finally {
            $this->conn->delete(Tables::imageTag(), ['image_id' => 5, 'tag_id' => 2]);
        }
    }

    public function test_delete_image_tag_by_image_ids_removes_every_link_from_that_image(): void
    {
        $this->conn->insert(Tables::imageTag(), ['image_id' => 5, 'tag_id' => 2]);
        $this->conn->insert(Tables::imageTag(), ['image_id' => 5, 'tag_id' => 3]);

        $this->repo->deleteImageTagByImageIds([5]);

        self::assertSame([], $this->repo->findTagIdsByImageIds([5]));
    }

    public function test_delete_image_tag_by_image_and_tag_ids_is_a_no_op_for_empty_image_ids(): void
    {
        $this->conn->insert(Tables::imageTag(), ['image_id' => 4, 'tag_id' => 3]);

        try {
            $this->repo->deleteImageTagByImageAndTagIds([], [TagId::from(3)]);

            // tag 3 (family) is also linked to image 1 in the fixture --
            // both links survive this no-op call.
            self::assertSame([1, 4], $this->repo->findImageIdsForTagIds([TagId::from(3)]));
        } finally {
            $this->conn->delete(Tables::imageTag(), ['image_id' => 4, 'tag_id' => 3]);
        }
    }

    public function test_delete_image_tag_by_image_and_tag_ids_is_a_no_op_for_empty_tag_ids(): void
    {
        $this->conn->insert(Tables::imageTag(), ['image_id' => 4, 'tag_id' => 3]);

        try {
            $this->repo->deleteImageTagByImageAndTagIds([4], []);

            // tag 3 (family) is also linked to image 1 in the fixture --
            // both links survive this no-op call.
            self::assertSame([1, 4], $this->repo->findImageIdsForTagIds([TagId::from(3)]));
        } finally {
            $this->conn->delete(Tables::imageTag(), ['image_id' => 4, 'tag_id' => 3]);
        }
    }

    public function test_delete_image_tag_by_image_and_tag_ids_removes_only_the_intersection(): void
    {
        // image 4 linked to both tag 2 and tag 3, but only (image 4, tag 3)
        // (the requested image/tag intersection) should be removed -- the
        // (image 4, tag 2) link must survive untouched.
        $this->conn->insert(Tables::imageTag(), ['image_id' => 4, 'tag_id' => 2]);
        $this->conn->insert(Tables::imageTag(), ['image_id' => 4, 'tag_id' => 3]);

        try {
            $this->repo->deleteImageTagByImageAndTagIds([4], [TagId::from(3)]);

            // tag 2 is also linked to image 1 in the fixture -- that link
            // survives alongside the (image 4, tag 2) one this test added.
            self::assertSame([1, 4], $this->repo->findImageIdsForTagIds([TagId::from(2)]));

            $remaining = $this->repo->findTagIdsByImageIds([4]);
            self::assertCount(1, $remaining);
            self::assertSame(2, $remaining[0]['tag_id']->value);
        } finally {
            $this->conn->delete(Tables::imageTag(), ['image_id' => 4, 'tag_id' => 2]);
        }
    }

    public function test_delete_by_ids_is_a_no_op_for_no_ids(): void
    {
        $this->repo->deleteByIds([]);

        self::assertNotNull($this->repo->findIdByName('nature'));
    }

    public function test_delete_by_ids_removes_the_disposable_tag(): void
    {
        $id = $this->repo->insert('disposable-tag', 'disposable-tag');

        $this->repo->deleteByIds([$id]);

        self::assertNull($this->repo->findIdByName('disposable-tag'));
    }

    public function test_find_id_by_where_fragment_matches_a_raw_sql_condition(): void
    {
        $id = $this->repo->findIdByWhereFragment("name = 'nature'");

        self::assertNotNull($id);
        self::assertSame(1, $id->value);
    }

    public function test_find_id_by_where_fragment_returns_null_for_no_match(): void
    {
        self::assertNull($this->repo->findIdByWhereFragment("name = 'no-such-tag'"));
    }
}
