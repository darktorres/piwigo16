<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration {

    use Override;
    use Piwigo\Auth\AccessLevelChecker;
    use Piwigo\Common\ValueObject\Username;
    use Piwigo\Core\Kernel;
    use LogicException;
    use Piwigo\Core\CurrentLogger;
    use Piwigo\Core\FilterState;
    use Piwigo\Tests\Support\LangTestFactory;
    use Piwigo\Db\EntityManagerFactory;
    use Piwigo\Tag\TagEntity;
    use Piwigo\Group\GroupEntity;
    use Piwigo\Activity\ActivityEntity;
    use Piwigo\Session\SessionService;
    use Piwigo\Session\SessionEntity;
    use Piwigo\Tests\Support\HtmlServiceTestFactory;
    use Piwigo\Common\ValueObject\ImageId;
    use Piwigo\Common\ValueObject\LangCode;
    use Piwigo\Common\ValueObject\UserId;
    use Piwigo\Tag\Projection\TagBrief;
    use Error;
    use Doctrine\DBAL\Connection;
    use Piwigo\Activity\ActivityService;
    use Piwigo\Cache\CachePools;
    use Piwigo\Category\CategoryRepository;
    use Piwigo\Common\ValueObject\TagId;
    use Piwigo\Config\CurrentConfig;
    use Piwigo\Tests\Support\CurrentConfigTestFactory;
    use Piwigo\Config\ConfigLoader;
    use Piwigo\Db\DbConnection;
    use Piwigo\Db\Tables;
    use Piwigo\Event\Tag\GetTagAltNames;
    use Piwigo\Event\Tag\GetTagNameLikeWhere;
    use Piwigo\Event\Tag\RenderTagUrl;
    use Piwigo\Permission\PermissionRepository;
    use Piwigo\Permission\PermissionService;
    use Piwigo\Tests\Support\EventDispatcherTestFactory;
    use Piwigo\Tag\TagService;
    use Piwigo\Tests\Support\CurrentUserTestFactory;
    use Piwigo\Users\User;
    use Piwigo\Users\UserStatus;

    final class TagServiceTest extends IntegrationTestCase
    {
        private static bool $fixtureReady = false;

        private TagService $service;

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

            $currentConfig->setTagsLevels(5);

            $currentLogger = Kernel::container()->get(CurrentLogger::class);
            if (! $currentLogger instanceof CurrentLogger) {
                throw new LogicException('Container returned an unexpected type for ' . CurrentLogger::class);
            }

            $filterState = Kernel::container()->get(FilterState::class);
            if (! $filterState instanceof FilterState) {
                throw new LogicException('Container returned an unexpected type for ' . FilterState::class);
            }

            $this->conn = DbConnection::build();
            $this->service = new TagService(LangTestFactory::get(), EntityManagerFactory::build($this->conn)->getRepository(TagEntity::class), new PermissionService(new PermissionRepository(EntityManagerFactory::build($this->conn)), EntityManagerFactory::build($this->conn)->getRepository(GroupEntity::class), new CategoryRepository(EntityManagerFactory::build($this->conn), $currentConfig), CurrentUserTestFactory::get(), $filterState, new AccessLevelChecker(CurrentUserTestFactory::get(), $currentConfig)), new ActivityService(EntityManagerFactory::build($this->conn)->getRepository(ActivityEntity::class)), EventDispatcherTestFactory::get(), CurrentUserTestFactory::get(), CurrentConfigTestFactory::get(), $currentLogger, new SessionService(EntityManagerFactory::build($this->conn)->getRepository(SessionEntity::class), CurrentConfigTestFactory::get()));
        }

        #[Override]
        protected function tearDown(): void
        {
            CachePools::tagCloud()->clear();
            CurrentUserTestFactory::get()->reset();
            parent::tearDown();
        }

        public function test_get_all_tags_returns_every_fixture_tag_alphabetically(): void
        {
            $names = array_column($this->service->getAllTags(HtmlServiceTestFactory::build()), 'name');

            self::assertSame(['family', 'nature', 'travel'], $names);
        }

        public function test_get_all_tags_sets_name_raw(): void
        {
            $tags = $this->service->getAllTags(HtmlServiceTestFactory::build());

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
            CurrentUserTestFactory::get()->set(new User(
                id: UserId::from(2),
                username: Username::from('fixture_guest'),
                email: null,
                language: LangCode::from('en_UK'),
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
                $orphanIds = array_map(static fn (TagBrief $tag): int => $tag->id->value, $orphans);

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

        // --- getAvailableTags() with an explicit filter ---------------------

        public function test_get_available_tags_returns_empty_when_the_filter_matches_no_images(): void
        {
            self::assertSame([], $this->service->getAvailableTags([999999]));
        }

        /**
         * getAvailableTags()'s own `if (! isset($tagCounters[$tag->id->value])) { continue; }`
         * is unreachable below TagRepository::findByIdsOrAll()'s own 1000-id
         * threshold: with fewer than 1000 ids, that method's own `WHERE
         * t.id IN (:ids)` filters to *exactly* the id set $tagCounters was
         * just built from, so every returned tag is always a hit. Past
         * 1000 ids it intentionally (per its own docblock: "matches the
         * original's own 'IN() clause too large' avoidance") returns EVERY
         * tag instead, "letting the caller filter down by its own id set"
         * -- this is that filter-down, and this is the only way to
         * genuinely reach it: 1000 disposable tags all linked to the same
         * real, visible fixture image (giving $tagCounters exactly 1000+
         * keys), plus one more disposable tag with zero image links at all
         * (present in the tags table, absent from $tagCounters).
         */
        public function test_get_available_tags_skips_a_tag_absent_from_the_counters_once_past_the_1000_id_threshold(): void
        {
            CurrentUserTestFactory::get()->set(new User(
                id: UserId::from(2),
                username: Username::from('fixture_guest'),
                email: null,
                language: LangCode::from('en_UK'),
                theme: '',
                status: UserStatus::Guest,
                enabledHigh: false,
            ));

            $tagsTable = Tables::tags();
            $imageTagTable = Tables::imageTag();
            $suffix = bin2hex(random_bytes(4));

            $tagValues = [];
            for ($i = 0; $i < 1000; $i++) {
                $name = "p18-test-bulk-{$suffix}-{$i}";
                $tagValues[] = "('{$name}', '{$name}', NOW())";
            }
            $this->conn->executeStatement("INSERT INTO {$tagsTable} (name, url_name, lastmodified) VALUES " . implode(',', $tagValues));
            $bulkIds = array_map(
                static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
                $this->conn->fetchFirstColumn("SELECT id FROM {$tagsTable} WHERE name LIKE 'p18-test-bulk-{$suffix}-%'")
            );
            self::assertCount(1000, $bulkIds);

            // Fixture image 1 is already in a public/visible category
            // (category 1) -- reused rather than uploading a disposable
            // image of this test's own, since only its FandF-visibility
            // matters here, not its content.
            $imageTagValues = [];
            foreach ($bulkIds as $tagId) {
                $imageTagValues[] = "(1, {$tagId})";
            }
            $this->conn->executeStatement("INSERT INTO {$imageTagTable} (image_id, tag_id) VALUES " . implode(',', $imageTagValues));

            $extraName = "p18-test-bulk-extra-{$suffix}";
            $this->conn->executeStatement("INSERT INTO {$tagsTable} (name, url_name, lastmodified) VALUES ('{$extraName}', '{$extraName}', NOW())");
            $extraId = (int) $this->conn->lastInsertId();

            try {
                $result = $this->service->getAvailableTags();

                $ids = array_column($result, 'id');
                self::assertNotContains($extraId, $ids);
                self::assertContains($bulkIds[0], $ids);
                self::assertContains($bulkIds[999], $ids);
            } finally {
                $this->conn->executeStatement("DELETE FROM {$imageTagTable} WHERE tag_id IN (" . implode(',', $bulkIds) . ')');
                $this->conn->executeStatement("DELETE FROM {$tagsTable} WHERE id IN (" . implode(',', $bulkIds) . ", {$extraId})");
            }
        }

        // --- getImageIdsForTags() -------------------------------------------

        public function test_get_image_ids_for_tags_returns_empty_for_no_tag_ids(): void
        {
            self::assertSame([], $this->service->getImageIdsForTags([]));
        }

        /**
         * fixture image_tag rows: image 1 has tags 1+2+3, image 2 and 3
         * each have only tag 1 -- AND-mode with tags [1, 2] must only
         * match image 1 (the one image with BOTH), while OR-mode with the
         * same 2 tags matches every image that has either one.
         */
        public function test_get_image_ids_for_tags_and_mode_requires_every_tag(): void
        {
            self::assertSame([1], $this->service->getImageIdsForTags([TagId::from(1), TagId::from(2)], 'AND'));
        }

        public function test_get_image_ids_for_tags_or_mode_matches_any_tag(): void
        {
            $ids = $this->service->getImageIdsForTags([TagId::from(1), TagId::from(2)], 'OR');
            sort($ids);

            self::assertSame([1, 2, 3], $ids);
        }

        // --- getCommonTags() -------------------------------------------------

        public function test_get_common_tags_returns_empty_for_no_items(): void
        {
            self::assertSame([], $this->service->getCommonTags([], 10, HtmlServiceTestFactory::build()));
        }

        // --- addTags() ---------------------------------------------------------

        public function test_add_tags_is_a_no_op_for_empty_tags_or_images(): void
        {
            $this->service->addTags([], [5]);
            $this->service->addTags([TagId::from(1)], []);

            self::assertSame([], $this->service->getImageTagIds([5])[5]);
        }

        // --- tagIdFromTagName() edge cases -----------------------------------

        /**
         * A 2nd call for the same name must return the identical id from
         * the in-process TagIdCache without touching the DB at all -- proven
         * by deleting the underlying row directly in between: if the cache
         * hit didn't short-circuit, the 2nd call would fall through to
         * findIdByName() (returns null, row is gone) and create a brand
         * new tag with a different id instead.
         */
        public function test_tag_id_from_tag_name_returns_the_cached_id_without_touching_the_db(): void
        {
            $name = 'cache-hit-tag-' . uniqid();

            $firstId = $this->service->tagIdFromTagName($name);
            $this->conn->executeStatement('DELETE FROM ' . Tables::tags() . ' WHERE id = ?', [$firstId->value]);

            try {
                $secondId = $this->service->tagIdFromTagName($name);

                self::assertEquals($firstId, $secondId);
                $tagCount = $this->conn->createQueryBuilder()->select('COUNT(*)')->from(Tables::tags())->where('name = :name')->setParameter('name', $name)->executeQuery()->fetchOne();
                self::assertSame(
                    0,
                    is_numeric($tagCount) ? (int) $tagCount : -1,
                    'the cache hit must never re-insert the deleted row'
                );
            } finally {
                $this->conn->executeStatement('DELETE FROM ' . Tables::tags() . ' WHERE name = ?', [$name]);
            }
        }

        public function test_tag_id_from_tag_name_throws_when_the_render_tag_url_handler_returns_something_other_than_a_render_tag_url_instance(): void
        {
            // addEventHandler(), not addTypedHandler() -- a real plugin
            // handler is untyped from PHPStan's perspective, and this test
            // exercises dispatchChange()'s own runtime enforcement, not a
            // static one.
            $name = 'weird url name ' . uniqid();
            EventDispatcherTestFactory::get()->addEventHandler(RenderTagUrl::class, static fn (): int => 42);

            $this->expectException(Error::class);
            $this->expectExceptionMessageIsOrContains('must return an instance of');

            try {
                $this->service->tagIdFromTagName($name);
            } finally {
                EventDispatcherTestFactory::get()->reset();
                $this->conn->executeStatement('DELETE FROM ' . Tables::tags() . ' WHERE name = ?', [$name]);
            }
        }

        /**
         * A plugin's `get_tag_name_like_where` handler (extended-description
         * sub-name matching) can resolve to an EXISTING tag even when the
         * exact name and url name both miss -- no new tag gets created in
         * that case.
         *
         * SQL-modernization audit / [SEC-19]: the handler now returns LIKE
         * pattern VALUES (bound as parameters), not raw SQL fragments --
         * see TagRepository::findIdByNameLikeAnyPattern()'s own docblock
         * for why (a real injection in the ExtendedDescription plugin's
         * actual handler, which built raw SQL from an unescaped tag name).
         */
        public function test_tag_id_from_tag_name_matches_via_a_plugin_supplied_like_pattern(): void
        {
            EventDispatcherTestFactory::get()->addTypedHandler(
                GetTagNameLikeWhere::class,
                static fn (GetTagNameLikeWhere $event): GetTagNameLikeWhere => new GetTagNameLikeWhere(['nature'], $event->tagName)
            );

            try {
                self::assertEquals(TagId::from(1), $this->service->tagIdFromTagName('totally-unrelated-name-' . uniqid()));
            } finally {
                EventDispatcherTestFactory::get()->reset();
            }
        }

        /**
         * [SEC-19] regression: a plugin handler returning SQL-shaped text
         * (exactly the un-escaped shape the real ExtendedDescription
         * plugin's own handler used to produce) is now always bound as a
         * literal LIKE value -- it matches nothing and a brand-new tag
         * gets created, instead of injecting a tautology that would have
         * resolved to an arbitrary existing tag.
         */
        public function test_tag_id_from_tag_name_treats_a_plugin_supplied_sql_injection_attempt_as_a_literal_value(): void
        {
            EventDispatcherTestFactory::get()->addTypedHandler(
                GetTagNameLikeWhere::class,
                static fn (GetTagNameLikeWhere $event): GetTagNameLikeWhere => new GetTagNameLikeWhere(["' OR '1'='1"], $event->tagName)
            );

            $tagName = 'p18-test-sec19-' . bin2hex(random_bytes(4));

            try {
                $id = $this->service->tagIdFromTagName($tagName);

                self::assertNotEquals(TagId::from(1), $id);
                self::assertSame(
                    $tagName,
                    $this->conn->createQueryBuilder()
                        ->select('name')
                        ->from(Tables::tags())
                        ->where('id = :id')
                        ->setParameter('id', $id->value)
                        ->executeQuery()
                        ->fetchOne()
                );
            } finally {
                EventDispatcherTestFactory::get()->reset();
                $this->conn->executeStatement('DELETE FROM ' . Tables::tags() . ' WHERE name = ?', [$tagName]);
            }
        }

        // --- getImageTagIds() --------------------------------------------------

        public function test_get_image_tag_ids_returns_empty_for_no_image_ids(): void
        {
            self::assertSame([], $this->service->getImageTagIds([]));
        }

        // --- createTag() ---------------------------------------------------------

        public function test_create_tag_returns_an_error_for_an_existing_name(): void
        {
            self::assertSame('Tag "nature" already exists', $this->service->createTag('nature')->error);
        }

        // --- getTagListForImage() --------------------------------------------------

        /**
         * Fixture image_tag: image 1 has tags 1 (nature), 2 (travel), 3
         * (family) -- each rendered as a '~~id~~'-marked, alphabetically
         * sorted entry (see getTagIds()'s own docblock for the marker format).
         */
        public function test_get_tag_list_for_image_returns_the_images_tags_sorted_alphabetically(): void
        {
            $result = $this->service->getTagListForImage(ImageId::from(1), HtmlServiceTestFactory::build());

            self::assertSame(
                ['~~3~~' => 'family', '~~1~~' => 'nature', '~~2~~' => 'travel'],
                array_combine(array_column($result, 'id'), array_column($result, 'name'))
            );
        }

        public function test_get_tag_list_for_image_returns_empty_for_an_image_with_no_tags(): void
        {
            self::assertSame([], $this->service->getTagListForImage(ImageId::from(999_999), HtmlServiceTestFactory::build()));
        }

        // --- getTagListByIds() -----------------------------------------------------

        public function test_get_tag_list_by_ids_returns_the_matching_tags_sorted_alphabetically(): void
        {
            $result = $this->service->getTagListByIds([1, 2], HtmlServiceTestFactory::build());

            self::assertSame(
                ['~~1~~' => 'nature', '~~2~~' => 'travel'],
                array_combine(array_column($result, 'id'), array_column($result, 'name'))
            );
        }

        /**
         * onlyUserLanguage=false additionally surfaces every alt name a
         * `get_tag_alt_names` plugin handler returns, except any alt name
         * identical to the tag's own already-rendered name (array_diff
         * against $nameForDiff) -- both the original and surviving alt
         * entries share the same '~~id~~' marker.
         */
        public function test_get_tag_list_by_ids_includes_surviving_alt_names_when_not_restricted_to_user_language(): void
        {
            EventDispatcherTestFactory::get()->addTypedHandler(
                GetTagAltNames::class,
                static fn (GetTagAltNames $event): GetTagAltNames => new GetTagAltNames($event->rawName === 'nature' ? ['nature', 'Nature (alt)'] : [], $event->rawName)
            );

            try {
                $result = $this->service->getTagListByIds([1], HtmlServiceTestFactory::build(), false);

                $names = array_column($result, 'name');
                sort($names);
                self::assertSame(['Nature (alt)', 'nature'], $names);

                foreach ($result as $row) {
                    self::assertSame('~~1~~', $row['id']);
                }
            } finally {
                EventDispatcherTestFactory::get()->reset();
            }
        }

        // --- getTagIds() ---------------------------------------------------------

        public function test_get_tag_ids_parses_existing_tag_id_markers(): void
        {
            self::assertEquals(
                [TagId::from(1), TagId::from(2)],
                $this->service->getTagIds('~~1~~,~~2~~')
            );
        }

        public function test_get_tag_ids_creates_a_new_tag_for_a_plain_name_when_allowed(): void
        {
            $name = 'freeform-tag-' . uniqid();

            try {
                $ids = $this->service->getTagIds([$name]);

                self::assertCount(1, $ids);
                self::assertSame(
                    $name,
                    $this->conn->createQueryBuilder()->select('name')->from(Tables::tags())->where('id = :id')->setParameter('id', $ids[0]->value)->executeQuery()->fetchOne()
                );
            } finally {
                $this->conn->executeStatement('DELETE FROM ' . Tables::tags() . ' WHERE name = ?', [$name]);
            }
        }

        public function test_get_tag_ids_skips_a_plain_name_when_creation_is_disallowed(): void
        {
            self::assertSame([], $this->service->getTagIds(['brand-new-name-' . uniqid()], false));
        }
    }
}
