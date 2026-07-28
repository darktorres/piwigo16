<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\FilterState;
use Piwigo\Core\PageState;
use Piwigo\Db\DbConnection;
use Piwigo\Filter\FilterService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;

/**
 * Piwigo\Filter\FilterService::initializeFromRequest() -- had zero
 * dedicated coverage (the Unit sibling only exercises
 * updateCatsWithFilteredData(), see its own docblock). Real fixture data
 * doubles as the "recent" content here: every fixture image's own
 * date_available (2026-08-01 00:00:00) exactly matches PIWIGO_TEST_NOW
 * (.env.test), so it's always >= any `start-recent-N`-derived threshold
 * regardless of N, and the 2 fixture categories (1 = 'Sample Album', 3
 * direct images; 2 = its child 'Nested Sub Album', 2 direct images) are
 * both public/visible to every real fixture user (level 0, no forbidden
 * categories) -- no custom category/image fixtures needed.
 *
 * $_SESSION is read/written directly throughout (SessionService's own
 * 'pwg_'-prefixed keys), same convention as DeviceHelperTest/
 * NoPhotoYetRendererTest in this same suite.
 */
final class FilterServiceInitializeFromRequestTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

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
        // 'used'=true (real default) + 'add_notes'=true, forced directly
        // rather than depending on PageFilterHelper::scriptBasename()'s own
        // $_SERVER-based page-name resolution in a CLI test process.
        CurrentConfig::setFilterPages(['default' => ['used' => true, 'cancel' => false, 'add_notes' => true]]);

        $this->conn = DbConnection::build();

        CurrentUser::set(User::fromUserArray(['id' => 1, 'status' => 'admin', 'level' => 8, 'forbidden_categories' => '', 'recent_period' => 7]));

        $_SESSION = [];
        PageState::reset();
        FilterState::reset();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $_SESSION = [];
        unset($_GET['filter']);
        CurrentUser::reset();
        PageState::reset();
        FilterState::reset();
        parent::tearDown();
    }

    /**
     * @return list<int>
     */
    private function sortedVisibleIds(string $csv): array
    {
        $ids = array_map(intval(...), explode(',', $csv));
        sort($ids);

        return $ids;
    }

    public function test_initialize_computes_and_persists_the_filter_from_a_get_param_and_adds_the_header_note(): void
    {
        $_GET['filter'] = 'start-recent-30';

        new FilterService($this->conn)->initializeFromRequest();

        self::assertTrue(FilterState::isEnabled());
        $categories = FilterState::categories();
        self::assertEqualsCanonicalizing([1, 2], array_keys($categories));
        self::assertSame(3, $categories[1]['nb_images']);
        self::assertSame(2, $categories[2]['nb_images']);
        // Category 2's images roll up into its parent (category 1).
        self::assertSame(5, $categories[1]['count_images']);

        self::assertSame([1, 2], $this->sortedVisibleIds(FilterState::visibleCategories()));
        self::assertSame([1, 2, 3, 4, 5], $this->sortedVisibleIds(FilterState::visibleImages()));

        self::assertSame(
            ['Photos posted within the last 30 days.'],
            PageState::current()->headerNotes
        );

        // Session persistence -- the exact shape read back by a later call.
        self::assertTrue($_SESSION['pwg_filter_enabled']);
        $checkKey = $_SESSION['pwg_filter_check_key'];
        self::assertIsArray($checkKey);
        self::assertSame(1, $checkKey['user']);
        self::assertSame(30, $checkKey['recent_period']);
        self::assertSame(date('Ymd'), $checkKey['date']);
        self::assertIsString($_SESSION['pwg_filter_categories']);
        $unserializedCategories = unserialize($_SESSION['pwg_filter_categories']);
        self::assertIsArray($unserializedCategories);
        self::assertEqualsCanonicalizing([1, 2], array_keys($unserializedCategories));
    }

    public function test_initialize_reads_cached_categories_from_the_session_without_recomputing_when_not_stale(): void
    {
        $_GET['filter'] = 'start-recent-14';
        new FilterService($this->conn)->initializeFromRequest();
        self::assertSame(2, FilterState::categories()[2]['nb_images']);

        // A brand-new, real "recent" image inserted into category 2 -- a
        // real recompute would bump its nb_images to 3; staying at 2 below
        // proves the 2nd call read the session's own serialized snapshot
        // instead of re-querying the DB.
        $this->conn->executeStatement(
            "INSERT INTO " . \Piwigo\Db\Tables::images() . " (file, path, date_available) VALUES ('cache-probe.jpg', 'upload/cache-probe.jpg', '2026-08-01 00:00:00')"
        );
        $newImageId = (int) $this->conn->lastInsertId();
        $this->conn->executeStatement(
            'INSERT INTO ' . \Piwigo\Db\Tables::imageCategory() . ' (image_id, category_id) VALUES (?, 2)',
            [$newImageId]
        );

        try {
            unset($_GET['filter']);
            FilterState::reset();
            new FilterService($this->conn)->initializeFromRequest();

            self::assertTrue(FilterState::isEnabled());
            self::assertSame(2, FilterState::categories()[2]['nb_images']);
        } finally {
            $this->conn->executeStatement('DELETE FROM ' . \Piwigo\Db\Tables::images() . ' WHERE id = ?', [$newImageId]);
        }
    }

    public function test_initialize_recomputes_when_the_cached_check_key_belongs_to_a_different_user(): void
    {
        $_SESSION['pwg_filter_enabled'] = true;
        $_SESSION['pwg_filter_check_key'] = [
            'user' => 999999,
            'recent_period' => 30,
            'time' => time(),
            'date' => date('Ymd'),
        ];

        new FilterService($this->conn)->initializeFromRequest();

        self::assertTrue(FilterState::isEnabled());
        // Recomputed (not left at the stale/absent cached value) -- the
        // check-key's user id now matches the real current user (1).
        $checkKey = $_SESSION['pwg_filter_check_key'];
        self::assertSame(1, $checkKey['user']);
        self::assertEqualsCanonicalizing([1, 2], array_keys(FilterState::categories()));
    }

    public function test_initialize_recomputes_when_the_cached_check_key_is_older_than_30_seconds(): void
    {
        $_SESSION['pwg_filter_enabled'] = true;
        $_SESSION['pwg_filter_check_key'] = [
            'user' => 1,
            'recent_period' => 30,
            'time' => time() - 31,
            'date' => date('Ymd'),
        ];

        new FilterService($this->conn)->initializeFromRequest();

        self::assertTrue(FilterState::isEnabled());
        $checkKey = $_SESSION['pwg_filter_check_key'];
        self::assertGreaterThan(time() - 5, $checkKey['time']);
    }

    public function test_initialize_disables_and_clears_the_session_when_the_page_filter_is_cancelled(): void
    {
        $_GET['filter'] = 'start-recent-7';
        new FilterService($this->conn)->initializeFromRequest();
        self::assertTrue($_SESSION['pwg_filter_enabled']);

        unset($_GET['filter']);
        CurrentConfig::setFilterPages(['default' => ['used' => true, 'cancel' => true, 'add_notes' => true]]);
        FilterState::reset();

        new FilterService($this->conn)->initializeFromRequest();

        self::assertFalse(FilterState::isEnabled());
        self::assertArrayNotHasKey('pwg_filter_enabled', $_SESSION);
        self::assertArrayNotHasKey('pwg_filter_check_key', $_SESSION);
        self::assertArrayNotHasKey('pwg_filter_categories', $_SESSION);
        self::assertArrayNotHasKey('pwg_filter_visible_categories', $_SESSION);
        self::assertArrayNotHasKey('pwg_filter_visible_images', $_SESSION);
    }

    public function test_initialize_disables_when_no_get_param_and_no_prior_session_state_exists(): void
    {
        // Never touched $_GET['filter'] or $_SESSION at all -- the plain
        // "nothing ever enabled this" default path.
        new FilterService($this->conn)->initializeFromRequest();

        self::assertFalse(FilterState::isEnabled());
    }

    public function test_initialize_treats_a_malformed_filter_value_as_disabled(): void
    {
        $_GET['filter'] = 'not-a-real-filter-token';

        new FilterService($this->conn)->initializeFromRequest();

        self::assertFalse(FilterState::isEnabled());
    }
}
