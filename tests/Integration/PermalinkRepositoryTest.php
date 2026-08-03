<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Db\DbConnection;
use Piwigo\Permalink\OldPermalinkSortField;
use Piwigo\Permalink\PermalinkRepository;

final class PermalinkRepositoryTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private PermalinkRepository $repo;

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

        $currentConfig = \Piwigo\Core\Kernel::container()->get(\Piwigo\Config\CurrentConfig::class);
        if (! $currentConfig instanceof \Piwigo\Config\CurrentConfig) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Config\CurrentConfig::class);
        }
        $currentConfig->reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $this->repo = new PermalinkRepository(\Piwigo\Db\EntityManagerFactory::build(DbConnection::build()));
    }

    public function test_set_then_find_category_id_by_permalink_round_trips(): void
    {
        $slug = 'p17-test-' . bin2hex(random_bytes(4));

        $this->repo->setCategoryPermalink(1, $slug);

        self::assertSame(1, $this->repo->findCategoryIdByPermalink($slug));
        self::assertSame($slug, $this->repo->findPermalinkByCategoryId(1));

        $this->repo->clearCategoryPermalink(1);
    }

    public function test_find_category_id_by_permalink_returns_null_when_unused(): void
    {
        self::assertNull($this->repo->findCategoryIdByPermalink('does-not-exist-' . bin2hex(random_bytes(4))));
    }

    public function test_find_permalink_by_category_id_returns_null_when_unset(): void
    {
        $this->repo->clearCategoryPermalink(1);

        self::assertNull($this->repo->findPermalinkByCategoryId(1));
    }

    public function test_clear_category_permalink_removes_it(): void
    {
        $slug = 'p17-test-' . bin2hex(random_bytes(4));
        $this->repo->setCategoryPermalink(1, $slug);

        $this->repo->clearCategoryPermalink(1);

        self::assertNull($this->repo->findPermalinkByCategoryId(1));
        self::assertNull($this->repo->findCategoryIdByPermalink($slug));
    }

    public function test_insert_old_permalink_deleted_then_find_old_category_id_round_trips(): void
    {
        $slug = 'p17-old-test-' . bin2hex(random_bytes(4));

        $this->repo->insertOldPermalinkDeleted(1, $slug);

        self::assertSame(1, $this->repo->findOldCategoryId($slug));

        $this->repo->deleteOldPermalink(1, $slug);
    }

    public function test_find_old_category_id_returns_null_when_never_used(): void
    {
        self::assertNull($this->repo->findOldCategoryId('never-used-' . bin2hex(random_bytes(4))));
    }

    public function test_mark_old_permalink_deleted_updates_an_existing_row(): void
    {
        $slug = 'p17-old-test-' . bin2hex(random_bytes(4));
        $this->repo->insertOldPermalinkDeleted(1, $slug);

        // Should not throw / should not insert a duplicate row -- updates
        // the existing (cat_id, permalink) row's date_deleted instead.
        $this->repo->markOldPermalinkDeleted(1, $slug);

        self::assertSame(1, $this->repo->findOldCategoryId($slug));

        $this->repo->deleteOldPermalink(1, $slug);
    }

    public function test_delete_old_permalink_removes_the_row(): void
    {
        $slug = 'p17-old-test-' . bin2hex(random_bytes(4));
        $this->repo->insertOldPermalinkDeleted(1, $slug);

        $this->repo->deleteOldPermalink(1, $slug);

        self::assertNull($this->repo->findOldCategoryId($slug));
    }

    public function test_delete_old_permalink_by_value_removes_the_row_and_returns_true(): void
    {
        $slug = 'p17-old-test-' . bin2hex(random_bytes(4));
        $this->repo->insertOldPermalinkDeleted(1, $slug);

        self::assertTrue($this->repo->deleteOldPermalinkByValue($slug));
        self::assertNull($this->repo->findOldCategoryId($slug));
    }

    public function test_delete_old_permalink_by_value_returns_false_when_nothing_matches(): void
    {
        self::assertFalse($this->repo->deleteOldPermalinkByValue('never-used-' . bin2hex(random_bytes(4))));
    }

    public function test_clear_category_permalink_on_an_unknown_category_is_a_silent_noop(): void
    {
        // Should neither throw nor affect any real category -- em->find()
        // returns null for a nonexistent id, so this exercises the early
        // `return;` guard directly.
        $this->expectNotToPerformAssertions();

        $this->repo->clearCategoryPermalink(999999);
    }

    public function test_set_category_permalink_on_an_unknown_category_is_a_silent_noop(): void
    {
        $this->repo->setCategoryPermalink(999999, 'p17-test-' . bin2hex(random_bytes(4)));

        self::assertNull($this->repo->findCategoryIdByPermalink('p17-test-does-not-matter'));
    }

    public function test_find_all_ordered_by_applies_the_given_order_column(): void
    {
        $lowSlug = 'aaa-order-test-' . bin2hex(random_bytes(4));
        $highSlug = 'zzz-order-test-' . bin2hex(random_bytes(4));
        $this->repo->insertOldPermalinkDeleted(1, $lowSlug);
        $this->repo->insertOldPermalinkDeleted(1, $highSlug);

        try {
            $rows = $this->repo->findAllOrderedBy(OldPermalinkSortField::Permalink);
            $permalinks = array_map(static fn ($row) => $row->permalink, $rows);

            $lowIndex = array_search($lowSlug, $permalinks, true);
            $highIndex = array_search($highSlug, $permalinks, true);

            self::assertIsInt($lowIndex);
            self::assertIsInt($highIndex);
            self::assertLessThan($highIndex, $lowIndex, 'ascending order by permalink puts the lexicographically-earlier slug first');
        } finally {
            $this->repo->deleteOldPermalink(1, $lowSlug);
            $this->repo->deleteOldPermalink(1, $highSlug);
        }
    }
}
