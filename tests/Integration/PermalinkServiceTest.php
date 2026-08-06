<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use Piwigo\Core\Kernel;
use LogicException;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Core\Lang;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Core\PageState;
use Piwigo\Tests\Support\PageStateTestFactory;
use Piwigo\Core\ProcessCache;
use Piwigo\Db\DbConnection;
use Piwigo\Permalink\PermalinkRepository;
use Piwigo\Permalink\PermalinkService;

final class PermalinkServiceTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private PermalinkService $service;

    private PermalinkRepository $repo;

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
        PageStateTestFactory::get()->reset();

        $this->repo = new PermalinkRepository(EntityManagerFactory::build(DbConnection::build()));
        $this->service = new PermalinkService(LangTestFactory::get(), $this->repo, new ProcessCache(), PageStateTestFactory::get());

        $this->repo->clearCategoryPermalink(1);
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->repo->clearCategoryPermalink(1);
        parent::tearDown();
    }

    public function test_set_cat_permalink_then_delete_round_trips(): void
    {
        $slug = 'p17-service-test-' . bin2hex(random_bytes(4));

        self::assertTrue($this->service->setCatPermalink(1, $slug, false));
        self::assertSame($slug, $this->repo->findPermalinkByCategoryId(1));

        self::assertTrue($this->service->deleteCatPermalink(1, false));
        self::assertNull($this->repo->findPermalinkByCategoryId(1));
    }

    public function test_set_cat_permalink_rejects_a_numeric_permalink(): void
    {
        $result = $this->service->setCatPermalink(1, '12345', false);

        self::assertFalse($result);
        self::assertNotSame([], PageStateTestFactory::get()->errors);
    }

    public function test_set_cat_permalink_rejects_disallowed_characters(): void
    {
        $result = $this->service->setCatPermalink(1, 'not valid!', false);

        self::assertFalse($result);
    }

    public function test_set_cat_permalink_rejects_an_already_used_permalink(): void
    {
        $slug = 'p17-service-test-' . bin2hex(random_bytes(4));
        self::assertTrue($this->service->setCatPermalink(1, $slug, false));

        $result = $this->service->setCatPermalink(2, $slug, false);

        self::assertFalse($result);

        $this->repo->clearCategoryPermalink(2);
    }

    public function test_set_cat_permalink_is_a_noop_success_when_unchanged(): void
    {
        $slug = 'p17-service-test-' . bin2hex(random_bytes(4));
        self::assertTrue($this->service->setCatPermalink(1, $slug, false));

        self::assertTrue($this->service->setCatPermalink(1, $slug, false));
    }

    public function test_delete_cat_permalink_with_no_permalink_set_succeeds_as_a_noop(): void
    {
        self::assertTrue($this->service->deleteCatPermalink(1, false));
    }

    public function test_set_then_delete_with_save_records_and_blocks_reuse(): void
    {
        $slug = 'p17-service-test-' . bin2hex(random_bytes(4));
        self::assertTrue($this->service->setCatPermalink(1, $slug, true));
        self::assertTrue($this->service->deleteCatPermalink(1, true));

        // Now historically used -- setting it on a DIFFERENT category must
        // be rejected until the history entry is removed.
        $result = $this->service->setCatPermalink(2, $slug, false);
        self::assertFalse($result);

        $this->repo->deleteOldPermalink(1, $slug);
    }

    public function test_delete_old_permalink_by_value_removes_a_recorded_history_entry(): void
    {
        $slug = 'p17-service-test-' . bin2hex(random_bytes(4));
        $this->repo->insertOldPermalinkDeleted(1, $slug);

        self::assertTrue($this->service->deleteOldPermalinkByValue($slug));
        self::assertNull($this->repo->findOldCategoryId($slug));
    }

    public function test_delete_old_permalink_by_value_returns_false_and_records_an_error_when_unmatched(): void
    {
        $result = $this->service->deleteOldPermalinkByValue('never-used-' . bin2hex(random_bytes(4)));

        self::assertFalse($result);
        self::assertNotSame([], PageStateTestFactory::get()->errors);
    }

    public function test_delete_cat_permalink_with_save_rejects_when_the_live_permalink_was_historically_owned_by_a_different_category(): void
    {
        $slug = 'p17-service-test-' . bin2hex(random_bytes(4));

        // deleteCatPermalink()'s own internal "previously used by a
        // different album" guard is distinct from setCatPermalink()'s
        // *caller-side* check of the same shape (already covered by
        // test_set_then_delete_with_save_records_and_blocks_reuse). Normal
        // setCatPermalink() usage can never actually put a category into
        // this state (its own guard blocks setting a permalink that
        // history says belongs to someone else) -- constructing it
        // directly via the repository, bypassing that guard, is the only
        // way to reach this specific branch in isolation.
        $this->repo->setCategoryPermalink(1, $slug);
        $this->repo->insertOldPermalinkDeleted(2, $slug);

        try {
            $result = $this->service->deleteCatPermalink(1, true);

            self::assertFalse($result);
            self::assertNotSame([], PageStateTestFactory::get()->errors);
            self::assertSame($slug, $this->repo->findPermalinkByCategoryId(1), 'a rejected delete must leave the live permalink untouched');
        } finally {
            $this->repo->deleteOldPermalink(2, $slug);
        }
    }

    public function test_delete_cat_permalink_with_save_marks_an_already_recorded_history_row_deleted_again(): void
    {
        $slug = 'p17-service-test-' . bin2hex(random_bytes(4));

        // A history row already exists for THIS SAME category/permalink
        // pair (as opposed to insertOldPermalinkDeleted()'s "no history
        // row yet" branch, exercised by test_set_then_delete_with_save_records_and_blocks_reuse)
        // -- deleteCatPermalink() must UPDATE that existing row
        // (markOldPermalinkDeleted) rather than inserting a duplicate,
        // which would violate old_permalinks' own permalink-as-primary-key
        // constraint.
        $this->repo->setCategoryPermalink(1, $slug);
        $this->repo->insertOldPermalinkDeleted(1, $slug);

        $result = $this->service->deleteCatPermalink(1, true);

        self::assertTrue($result);
        self::assertNull($this->repo->findPermalinkByCategoryId(1));
        self::assertSame(1, $this->repo->findOldCategoryId($slug), 'the existing history row must still exist (updated, not duplicated)');

        $this->repo->deleteOldPermalink(1, $slug);
    }

    public function test_set_cat_permalink_clears_the_reclaimed_permalinks_own_stale_history_row(): void
    {
        $slug = 'p17-service-test-' . bin2hex(random_bytes(4));

        self::assertTrue($this->service->setCatPermalink(1, $slug, true));
        self::assertTrue($this->service->deleteCatPermalink(1, true));
        self::assertSame(1, $this->repo->findOldCategoryId($slug), 'precondition: a history row for (cat 1, slug) must exist');

        // Re-claiming the SAME permalink back onto the SAME category it
        // was historically deleted from must succeed AND clear that now-
        // stale history row -- distinct from the cross-category rejection
        // covered by test_set_then_delete_with_save_records_and_blocks_reuse.
        self::assertTrue($this->service->setCatPermalink(1, $slug, true));

        self::assertNull($this->repo->findOldCategoryId($slug));
        self::assertSame($slug, $this->repo->findPermalinkByCategoryId(1));
    }

    public function test_set_cat_permalink_fails_when_its_own_internal_delete_of_the_old_permalink_is_rejected(): void
    {
        $oldSlug = 'p17-service-test-old-' . bin2hex(random_bytes(4));
        $newSlug = 'p17-service-test-new-' . bin2hex(random_bytes(4));

        // Same inconsistent-state construction as
        // test_delete_cat_permalink_with_save_rejects_when_the_live_permalink_was_historically_owned_by_a_different_category,
        // but reached through setCatPermalink() as the caller this time:
        // category 1's CURRENT live permalink ($oldSlug) is one history
        // says belongs to category 2, so setCatPermalink()'s own internal
        // "clear the old one first" step fails, and that failure must
        // propagate out of setCatPermalink() itself as false.
        $this->repo->setCategoryPermalink(1, $oldSlug);
        $this->repo->insertOldPermalinkDeleted(2, $oldSlug);

        try {
            $result = $this->service->setCatPermalink(1, $newSlug, true);

            self::assertFalse($result);
            self::assertSame($oldSlug, $this->repo->findPermalinkByCategoryId(1), 'the failed set must leave the previous live permalink untouched');
        } finally {
            $this->repo->deleteOldPermalink(2, $oldSlug);
        }
    }
}
