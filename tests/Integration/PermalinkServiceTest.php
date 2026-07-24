<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Core\PageState;
use Piwigo\Db\DbConnection;
use Piwigo\Permalink\PermalinkRepository;
use Piwigo\Permalink\PermalinkService;

final class PermalinkServiceTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private PermalinkService $service;

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

        CurrentConfig::reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();
        PageState::reset();
        PageState::attachGlobals();

        $this->repo = new PermalinkRepository(DbConnection::build());
        $this->service = new PermalinkService($this->repo);

        $this->repo->clearCategoryPermalink(1);
    }

    #[\Override]
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
        self::assertNotSame([], PageState::current()->errors);
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
        self::assertNotSame([], PageState::current()->errors);
    }
}
