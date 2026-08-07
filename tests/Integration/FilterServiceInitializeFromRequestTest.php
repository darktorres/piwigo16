<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use Piwigo\Core\Kernel;
use LogicException;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Db\Tables;
use Doctrine\DBAL\Connection;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\CurrentConfig;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Core\FilterState;
use Piwigo\Tests\Support\PageStateTestFactory;
use Piwigo\Db\DbConnection;
use Piwigo\Filter\FilterService;
use Piwigo\Lang\Translator;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionService;
use Piwigo\Tests\Support\CurrentUserTestFactory;
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

    private FilterState $filterState;

    private SessionService $sessionService;

    private Translator $translator;

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
        // 'used'=true (real default) + 'add_notes'=true, forced directly
        // rather than depending on PageFilterHelper::scriptBasename()'s own
        // $_SERVER-based page-name resolution in a CLI test process.
        $currentConfig->setFilterPages(['default' => ['used' => true, 'cancel' => false, 'add_notes' => true]]);

        $this->conn = DbConnection::build();

        CurrentUserTestFactory::get()->set(User::fromUserArray(['id' => 1, 'status' => 'admin', 'level' => 8, 'forbidden_categories' => '', 'recent_period' => 7]));

        $_SESSION = [];
        PageStateTestFactory::get()->reset();
        $this->filterState = new FilterState();
        $this->sessionService = new SessionService(EntityManagerFactory::build($this->conn)->getRepository(SessionEntity::class),CurrentConfigTestFactory::get());
        $this->translator = new Translator(CurrentConfigTestFactory::get());
    }

    #[Override]
    protected function tearDown(): void
    {
        $_SESSION = [];
        unset($_GET['filter']);
        CurrentUserTestFactory::get()->reset();
        PageStateTestFactory::get()->reset();
        parent::tearDown();
    }

    /**
     * Reads a $_SESSION value through an honestly-mixed-typed boundary.
     * PHPStan otherwise keeps tracking a $_SESSION key's last-assigned
     * literal type straight through an intervening real method call (it
     * has no visibility into that call's own writes to the superglobal),
     * so a plain `$_SESSION[$key]` read a few lines after assigning a
     * scalar literal to that same key gets reported as still holding that
     * exact literal -- even once the method under test has genuinely
     * overwritten it. Confirmed via a standalone \PHPStan\dumpType()
     * reproduction.
     */
    private function sessionValue(string $key): mixed
    {
        return $_SESSION[$key] ?? null;
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

        new FilterService($this->filterState, $this->sessionService, $this->translator, LangTestFactory::get(), CurrentConfigTestFactory::get(), new EventDispatcher(), $this->conn)->initializeFromRequest(PageStateTestFactory::get(), CurrentUserTestFactory::get());

        self::assertTrue($this->filterState->isEnabled());
        $categories = $this->filterState->categories();
        self::assertEqualsCanonicalizing([1, 2], array_keys($categories));
        self::assertSame(3, $categories[1]['nb_images']);
        self::assertSame(2, $categories[2]['nb_images']);
        // Category 2's images roll up into its parent (category 1).
        self::assertSame(5, $categories[1]['count_images']);

        self::assertSame([1, 2], $this->sortedVisibleIds($this->filterState->visibleCategories()));
        self::assertSame([1, 2, 3, 4, 5], $this->sortedVisibleIds($this->filterState->visibleImages()));

        self::assertSame(
            ['Photos posted within the last 30 days.'],
            PageStateTestFactory::get()->headerNotes
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
        new FilterService($this->filterState, $this->sessionService, $this->translator, LangTestFactory::get(), CurrentConfigTestFactory::get(), new EventDispatcher(), $this->conn)->initializeFromRequest(PageStateTestFactory::get(), CurrentUserTestFactory::get());
        self::assertSame(2, $this->filterState->categories()[2]['nb_images']);

        // A brand-new, real "recent" image inserted into category 2 -- a
        // real recompute would bump its nb_images to 3; staying at 2 below
        // proves the 2nd call read the session's own serialized snapshot
        // instead of re-querying the DB.
        $this->conn->executeStatement(
            "INSERT INTO " . Tables::images() . " (file, path, date_available) VALUES ('cache-probe.jpg', 'upload/cache-probe.jpg', '2026-08-01 00:00:00')"
        );
        $newImageId = (int) $this->conn->lastInsertId();
        $this->conn->executeStatement(
            'INSERT INTO ' . Tables::imageCategory() . ' (image_id, category_id) VALUES (?, 2)',
            [$newImageId]
        );

        try {
            unset($_GET['filter']);
            $this->filterState->reset();
            new FilterService($this->filterState, $this->sessionService, $this->translator, LangTestFactory::get(), CurrentConfigTestFactory::get(), new EventDispatcher(), $this->conn)->initializeFromRequest(PageStateTestFactory::get(), CurrentUserTestFactory::get());

            self::assertTrue($this->filterState->isEnabled());
            self::assertSame(2, $this->filterState->categories()[2]['nb_images']);
        } finally {
            $this->conn->executeStatement('DELETE FROM ' . Tables::images() . ' WHERE id = ?', [$newImageId]);
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

        new FilterService($this->filterState, $this->sessionService, $this->translator, LangTestFactory::get(), CurrentConfigTestFactory::get(), new EventDispatcher(), $this->conn)->initializeFromRequest(PageStateTestFactory::get(), CurrentUserTestFactory::get());

        self::assertTrue($this->filterState->isEnabled());
        // Recomputed (not left at the stale/absent cached value) -- the
        // check-key's user id now matches the real current user (1).
        // The read (after initializeFromRequest()'s own impure call) is
        // exactly the shape written above -- PHPStan can't narrow a
        // superglobal offset across an intervening impure call, so assert
        // the shape it's known to recompute into instead.
        /** @var array{user: int, recent_period: int, time: int, date: string} $checkKey */
        $checkKey = $_SESSION['pwg_filter_check_key'];
        self::assertSame(1, $checkKey['user']);
        self::assertEqualsCanonicalizing([1, 2], array_keys($this->filterState->categories()));
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

        new FilterService($this->filterState, $this->sessionService, $this->translator, LangTestFactory::get(), CurrentConfigTestFactory::get(), new EventDispatcher(), $this->conn)->initializeFromRequest(PageStateTestFactory::get(), CurrentUserTestFactory::get());

        self::assertTrue($this->filterState->isEnabled());
        // Same reason as the sibling test above: assert the recomputed
        // shape at the read site, since PHPStan can't narrow $_SESSION
        // across initializeFromRequest()'s own impure call.
        /** @var array{user: int, recent_period: int, time: int, date: string} $checkKey */
        $checkKey = $_SESSION['pwg_filter_check_key'];
        self::assertGreaterThan(time() - 5, $checkKey['time']);
    }

    public function test_initialize_disables_and_clears_the_session_when_the_page_filter_is_cancelled(): void
    {
        $_GET['filter'] = 'start-recent-7';
        new FilterService($this->filterState, $this->sessionService, $this->translator, LangTestFactory::get(), CurrentConfigTestFactory::get(), new EventDispatcher(), $this->conn)->initializeFromRequest(PageStateTestFactory::get(), CurrentUserTestFactory::get());
        self::assertTrue($_SESSION['pwg_filter_enabled']);

        unset($_GET['filter']);
        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->setFilterPages(['default' => ['used' => true, 'cancel' => true, 'add_notes' => true]]);
        $this->filterState->reset();

        new FilterService($this->filterState, $this->sessionService, $this->translator, LangTestFactory::get(), CurrentConfigTestFactory::get(), new EventDispatcher(), $this->conn)->initializeFromRequest(PageStateTestFactory::get(), CurrentUserTestFactory::get());

        self::assertFalse($this->filterState->isEnabled());
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
        new FilterService($this->filterState, $this->sessionService, $this->translator, LangTestFactory::get(), CurrentConfigTestFactory::get(), new EventDispatcher(), $this->conn)->initializeFromRequest(PageStateTestFactory::get(), CurrentUserTestFactory::get());

        self::assertFalse($this->filterState->isEnabled());
    }

    public function test_initialize_treats_a_malformed_filter_value_as_disabled(): void
    {
        $_GET['filter'] = 'not-a-real-filter-token';

        new FilterService($this->filterState, $this->sessionService, $this->translator, LangTestFactory::get(), CurrentConfigTestFactory::get(), new EventDispatcher(), $this->conn)->initializeFromRequest(PageStateTestFactory::get(), CurrentUserTestFactory::get());

        self::assertFalse($this->filterState->isEnabled());
    }

    public function test_initialize_falls_back_to_the_default_check_key_when_the_cached_session_value_is_malformed(): void
    {
        $_SESSION['pwg_filter_enabled'] = true;
        // Genuinely malformed -- not even an array, unlike the "different
        // user"/"stale timestamp" tests above which both start from a
        // well-shaped array. initializeFromRequest()'s own
        // `! is_array($filter_key) || ! isset(...)` guard exists precisely
        // for this shape of corruption (its own inline comment: real
        // session data is only ever written in the well-shaped form by
        // this same method) -- simulates a corrupted/foreign session value
        // rather than one this method itself could ever have written.
        $_SESSION['pwg_filter_check_key'] = 'not-an-array';

        new FilterService($this->filterState, $this->sessionService, $this->translator, LangTestFactory::get(), CurrentConfigTestFactory::get(), new EventDispatcher(), $this->conn)->initializeFromRequest(PageStateTestFactory::get(), CurrentUserTestFactory::get());

        self::assertTrue($this->filterState->isEnabled());
        // Recomputed from the fallback default (time=0 unconditionally
        // forces the "stale" recompute branch) -- the check-key now
        // reflects the real current user/request, not the malformed
        // session value.
        $checkKey = $this->sessionValue('pwg_filter_check_key');
        self::assertIsArray($checkKey);
        self::assertSame(1, $checkKey['user']);
        self::assertEqualsCanonicalizing([1, 2], array_keys($this->filterState->categories()));
    }

    public function test_initialize_falls_back_to_a_sentinel_when_every_category_is_forbidden(): void
    {
        // forbidden_categories excludes both fixture categories (1, 2) --
        // findComputedCategoriesRollup()'s own `c.id NOT IN (...)` filter
        // then matches zero rows, so getComputedCategories() returns an
        // empty categories array. implode(',', array_keys([])) is '',
        // which initializeFromRequest()'s own "must be not empty" guards
        // replace with the -1 sentinel -- for visible_categories directly
        // (the empty implode()), and for visible_images too since
        // `category_id IN (-1)` then matches no real image_category row
        // either.
        CurrentUserTestFactory::get()->set(User::fromUserArray(['id' => 1, 'status' => 'admin', 'level' => 8, 'forbidden_categories' => '1,2', 'recent_period' => 7]));
        $_GET['filter'] = 'start-recent-30';

        new FilterService($this->filterState, $this->sessionService, $this->translator, LangTestFactory::get(), CurrentConfigTestFactory::get(), new EventDispatcher(), $this->conn)->initializeFromRequest(PageStateTestFactory::get(), CurrentUserTestFactory::get());

        self::assertTrue($this->filterState->isEnabled());
        self::assertSame([], $this->filterState->categories());
        self::assertSame('-1', $this->filterState->visibleCategories());
        self::assertSame('-1', $this->filterState->visibleImages());
    }
}
