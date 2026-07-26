<?php

declare(strict_types=1);

// getAllTags() calls the real Piwigo\PluginConfig\EventDispatcher::get()->
// triggerChange() directly, a pure passthrough with no handlers
// registered, so no local stub is needed.
namespace {
    // Also stubbed: the real tag_alpha_compare() (functions_html.inc.php)
    // delegates to Piwigo\Html\HtmlService::tagAlphaCompare(), which needs
    // pwg_transliterate() (Lang domain) -- plain alphabetical comparison
    // is a faithful-enough stand-in for these tests, which only assert on
    // ordering between plain ASCII fixture tag names.
    if (! function_exists('tag_alpha_compare')) {
        /**
         * @param array<string, mixed> $a
         * @param array<string, mixed> $b
         */
        function tag_alpha_compare(array $a, array $b): int
        {
            $name_a = is_string($a['name'] ?? null) ? $a['name'] : '';
            $name_b = is_string($b['name'] ?? null) ? $b['name'] : '';

            return strcmp($name_a, $name_b);
        }
    }
}

namespace Piwigo\Tests\Integration {

    use Doctrine\DBAL\Connection;
    use Piwigo\Activity\ActivityRepository;
    use Piwigo\Activity\ActivityService;
    use Piwigo\Cache\CachePools;
    use Piwigo\Category\CategoryRepository;
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
            $this->service = new TagService(new TagRepository($this->conn), new PermissionService(new PermissionRepository($this->conn), \Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\Group\GroupEntity::class), new CategoryRepository($this->conn)), new ActivityService(new ActivityRepository($this->conn)));
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
                id: 2,
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
    }
}
