<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration {

    use Doctrine\DBAL\Connection;
    use Piwigo\Activity\ActivityRepository;
    use Piwigo\Activity\ActivityService;
    use Piwigo\Cache\CachePools;
    use Piwigo\Category\CategoryRepository;
    use Piwigo\Common\ValueObject\TagId;
    use Piwigo\Config\CurrentConfig;
    use Piwigo\Config\ConfigLoader;
    use Piwigo\Db\DbConnection;
    use Piwigo\Db\Tables;
    use Piwigo\Group\GroupRepository;
    use Piwigo\Html\HtmlService;
    use Piwigo\Permission\PermissionRepository;
    use Piwigo\Permission\PermissionService;
    use Piwigo\Tag\TagRepository;
    use Piwigo\Tag\TagService;
    use Piwigo\Users\CurrentUser;
    use Piwigo\Users\User;
    use Piwigo\Users\UserStatus;

    final class TagServiceTest extends IntegrationTestCase
    {
        private static bool $fixtureReady = false;

        private TagService $service;

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

            CurrentConfig::setTagsLevels(5);

            $this->conn = DbConnection::build();
            $this->service = new TagService(\Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\Tag\TagEntity::class), new PermissionService(new PermissionRepository(\Piwigo\Db\EntityManagerFactory::build($this->conn)), \Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\Group\GroupEntity::class), \Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\Category\CategoryEntity::class)), new ActivityService(\Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\Activity\ActivityEntity::class)));
        }

        #[\Override]
        protected function tearDown(): void
        {
            CachePools::tagCloud()->clear();
            CurrentUser::reset();
            parent::tearDown();
        }

        public function test_get_all_tags_returns_every_fixture_tag_alphabetically(): void
        {
            $names = array_column($this->service->getAllTags(new HtmlService()), 'name');

            self::assertSame(['family', 'nature', 'travel'], $names);
        }

        public function test_get_all_tags_sets_name_raw(): void
        {
            $tags = $this->service->getAllTags(new HtmlService());

            self::assertSame($tags[0]['name'], $tags[0]['name_raw']);
        }

        public function test_find_tags_delegates_to_the_repository(): void
        {
            $tags = $this->service->findTags([1]);

            self::assertCount(1, $tags);
            self::assertSame('nature', $tags[0]['name']);
        }

        public function test_add_level_to_tags_returns_empty_for_empty_input(): void
        {
            self::assertSame([], $this->service->addLevelToTags([]));
        }

        public function test_add_level_to_tags_assigns_higher_level_to_higher_counter(): void
        {
            $tags = [
                ['id' => 1, 'counter' => 1],
                ['id' => 2, 'counter' => 100],
            ];

            $withLevels = $this->service->addLevelToTags($tags);

            self::assertGreaterThan($withLevels[0]['level'], $withLevels[1]['level']);
        }

        public function test_add_level_to_tags_gives_the_middle_level_to_the_average(): void
        {
            $tags = [
                ['id' => 1, 'counter' => 10],
                ['id' => 2, 'counter' => 10],
                ['id' => 3, 'counter' => 10],
            ];

            $withLevels = $this->service->addLevelToTags($tags);

            foreach ($withLevels as $tag) {
                self::assertSame(3, $tag['level']);
            }
        }

        public function test_tags_id_compare_orders_by_id_ascending(): void
        {
            self::assertSame(-1, $this->service->tagsIdCompare(['id' => 1], ['id' => 2]));
            self::assertSame(1, $this->service->tagsIdCompare(['id' => 2], ['id' => 1]));
        }

        public function test_tags_counter_compare_orders_by_counter_descending(): void
        {
            self::assertSame(1, $this->service->tagsCounterCompare(['id' => 1, 'counter' => 1], ['id' => 2, 'counter' => 5]));
            self::assertSame(-1, $this->service->tagsCounterCompare(['id' => 1, 'counter' => 5], ['id' => 2, 'counter' => 1]));
        }

        public function test_tags_counter_compare_breaks_ties_by_id(): void
        {
            self::assertSame(
                -1,
                $this->service->tagsCounterCompare(['id' => 1, 'counter' => 5], ['id' => 2, 'counter' => 5])
            );
        }

        /**
         * CachePools::tagCloud() (P23 Stage 1d) replaces the older
         * CurrentPersistentCache mechanism for getAvailableTags()'s
         * no-tag-id-filter branch -- proven the same way
         * ForbiddenCategoriesCacheTest/CategoryTreeCacheTest prove their
         * own pool wiring: mutate the underlying data after the first
         * (caching) call, then show a 2nd no-filter call still returns the
         * stale result while an explicitly-filtered call (which always
         * bypasses this cache) reflects the change.
         */
        public function test_get_available_tags_with_no_filter_caches_the_result_via_cache_pools_tag_cloud(): void
        {
            CurrentUser::set(new User(
                id: \Piwigo\Common\ValueObject\UserId::from(2),
                username: 'fixture_guest',
                email: '',
                language: '',
                theme: '',
                status: UserStatus::Guest,
                enabledHigh: false,
            ));

            // Tag 1 ("nature") + an image already in a visible category,
            // not yet tagged "nature" -- found live rather than hardcoded,
            // so this test doesn't depend on the fixture's exact
            // image/tag association rows staying the same.
            $imageId = $this->conn->createQueryBuilder()
                ->select('ic.image_id')
                ->from(Tables::imageCategory(), 'ic')
                ->where('ic.image_id NOT IN (SELECT image_id FROM ' . Tables::imageTag() . ' WHERE tag_id = 1)')
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne();
            self::assertIsInt($imageId, 'fixture must have at least one image not already tagged "nature"');

            $before = array_column($this->service->getAvailableTags(), 'id');

            $this->conn->executeStatement(
                'INSERT INTO ' . Tables::imageTag() . ' (image_id, tag_id) VALUES (?, 1)',
                [$imageId]
            );

            try {
                $cachedAfterMutation = array_column($this->service->getAvailableTags(), 'id');
                self::assertSame($before, $cachedAfterMutation, 'a cache hit must not re-query the DB');

                $bypassed = array_column($this->service->getAvailableTags([1]), 'id');
                self::assertContains(1, $bypassed, 'an explicit tag_id filter always bypasses this cache');
            } finally {
                $this->conn->executeStatement(
                    'DELETE FROM ' . Tables::imageTag() . ' WHERE image_id = ? AND tag_id = 1',
                    [$imageId]
                );
            }
        }

        // --- tagIdFromTagName() -------------------------------------------

        public function test_tag_id_from_tag_name_returns_the_existing_id_for_a_known_name(): void
        {
            self::assertEquals(TagId::from(1), $this->service->tagIdFromTagName('nature'));
        }

        public function test_tag_id_from_tag_name_creates_a_new_tag_for_an_unknown_name(): void
        {
            $name = 'brand-new-tag-' . uniqid();

            try {
                $id = $this->service->tagIdFromTagName($name);

                self::assertSame(
                    $name,
                    $this->conn->createQueryBuilder()
                        ->select('name')
                        ->from(Tables::tags())
                        ->where('id = :id')
                        ->setParameter('id', $id->value)
                        ->executeQuery()
                        ->fetchOne()
                );
            } finally {
                $this->conn->executeStatement('DELETE FROM ' . Tables::tags() . ' WHERE name = ?', [$name]);
            }
        }

        // --- setTagsOf()/getImageTagIds() ----------------------------------

        public function test_set_tags_of_creates_then_overwrites_image_tag_associations(): void
        {
            // fixture image 4 has no image_tag rows at all (see fixture's
            // own comment above this class), so it's safe to freely
            // set/overwrite here without disturbing any other test.
            try {
                $this->service->setTagsOf([4 => [TagId::from(1), TagId::from(2)]]);
                self::assertEqualsCanonicalizing([TagId::from(1), TagId::from(2)], $this->service->getImageTagIds([4])[4]);

                // Overwrites, not appends -- tag 3 replaces 1+2 entirely.
                $this->service->setTagsOf([4 => [TagId::from(3)]]);
                self::assertEqualsCanonicalizing([TagId::from(3)], $this->service->getImageTagIds([4])[4]);
            } finally {
                $this->conn->executeStatement('DELETE FROM ' . Tables::imageTag() . ' WHERE image_id = 4');
            }
        }

        /**
         * Regression test: compareImageTagLists() used to compare TagId
         * lists with `!==`, which for objects checks identity, not value --
         * two separately-constructed TagId(1) instances (one from the
         * "before" read, one from the "after" read) are never `!==`-equal,
         * so this would have wrongly reported every image as changed on
         * every call, even when the tag list genuinely didn't change.
         */
        public function test_compare_image_tag_lists_reports_no_change_when_tags_are_set_to_the_same_values(): void
        {
            try {
                $this->service->setTagsOf([4 => [TagId::from(1), TagId::from(2)]]);
                $before = $this->service->getImageTagIds([4]);

                // Re-set the exact same tags -- a genuine no-op from the
                // caller's perspective.
                $this->service->setTagsOf([4 => [TagId::from(1), TagId::from(2)]]);
                $after = $this->service->getImageTagIds([4]);

                self::assertSame([], $this->service->compareImageTagLists($before, $after));
            } finally {
                $this->conn->executeStatement('DELETE FROM ' . Tables::imageTag() . ' WHERE image_id = 4');
            }
        }

        public function test_compare_image_tag_lists_reports_the_image_when_tags_genuinely_change(): void
        {
            $before = [4 => [TagId::from(1)]];
            $after = [4 => [TagId::from(1), TagId::from(2)]];

            self::assertSame([4], $this->service->compareImageTagLists($before, $after));
        }

        // --- getOrphanTags()/deleteOrphanTags() -----------------------------

        public function test_get_orphan_tags_finds_a_tag_with_no_images_past_the_grace_period(): void
        {
            $name = 'orphan-tag-' . uniqid();
            $this->conn->insert(Tables::tags(), [
                'name' => $name,
                'url_name' => $name,
                // past the 1-day grace period findOrphanTags() applies.
                'lastmodified' => '2020-01-01 00:00:00',
            ]);
            $id = (int) $this->conn->lastInsertId();

            try {
                $orphans = $this->service->getOrphanTags();
                $orphanIds = array_map(static fn (\Piwigo\Tag\Projection\TagBrief $tag): int => $tag->id->value, $orphans);

                self::assertContains($id, $orphanIds);
            } finally {
                $this->conn->executeStatement('DELETE FROM ' . Tables::tags() . ' WHERE id = ?', [$id]);
            }
        }

        public function test_delete_orphan_tags_removes_a_genuinely_orphaned_tag(): void
        {
            $name = 'orphan-tag-' . uniqid();
            $this->conn->insert(Tables::tags(), [
                'name' => $name,
                'url_name' => $name,
                'lastmodified' => '2020-01-01 00:00:00',
            ]);
            $id = (int) $this->conn->lastInsertId();

            $this->service->deleteOrphanTags();

            $remaining = $this->conn->createQueryBuilder()
                ->select('id')
                ->from(Tables::tags())
                ->where('id = :id')
                ->setParameter('id', $id)
                ->executeQuery()
                ->fetchOne();

            self::assertFalse($remaining, 'the orphaned tag must have been deleted');
        }
    }
}
