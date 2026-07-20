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

    use Piwigo\Activity\ActivityRepository;
    use Piwigo\Activity\ActivityService;
    use Piwigo\Category\CategoryRepository;
    use Piwigo\Config\Config;
    use Piwigo\Config\ConfigLoader;
    use Piwigo\Db\DbConnection;
    use Piwigo\Group\GroupRepository;
    use Piwigo\Html\HtmlService;
    use Piwigo\Permission\PermissionRepository;
    use Piwigo\Permission\PermissionService;
    use Piwigo\Tag\TagRepository;
    use Piwigo\Tag\TagService;

    final class TagServiceTest extends IntegrationTestCase
    {
        private static bool $fixtureReady = false;

        private TagService $service;

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

            Config::override('tags_levels', 5);

            $conn = DbConnection::build();
            $this->service = new TagService(new TagRepository($conn), new PermissionService(new PermissionRepository($conn), new GroupRepository($conn), new CategoryRepository($conn)), new ActivityService(new ActivityRepository($conn)));
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
    }
}
