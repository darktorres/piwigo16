<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Piwigo\Admin\Extensions\ExtensionRepository;
use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigLoader;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;

/**
 * Direct coverage of ExtensionRepository's own CRUD/query methods --
 * ExtensionLifecycleTest already exercises most of these indirectly through
 * ExtensionLifecycle's action state machine, but findAnyThemeIdExcluding()/
 * findUserIdsByTheme()/setThemeForUsers()/reassignUsersFromLanguage()/
 * setLanguageForUserIds() were never reached by any existing test (no
 * "language delete succeeds" or "language set_default" scenario exists in
 * ExtensionLifecycleTest, since those also touch the filesystem via
 * deltree()) -- tested here directly against the repository instead, since
 * these five are pure DB operations with no filesystem dependency.
 *
 * Every mutating test inserts and cleans up its own disposable rows; the
 * fixture ships zero `plugins`/`themes` rows and a single `languages` row
 * (en_UK), so `id`s here are always test-only, never colliding with real
 * fixture data.
 */
final class ExtensionRepositoryTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private ExtensionRepository $repo;

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

        Config::reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $this->conn = DbConnection::build();
        $this->repo = new ExtensionRepository($this->conn);
    }

    public function test_find_all_returns_the_fixture_language(): void
    {
        $rows = $this->repo->findAll(ExtensionType::Language);

        self::assertArrayHasKey('en_UK', $rows);
        self::assertSame('English [UK]', $rows['en_UK']['name']);
    }

    public function test_find_returns_a_single_row(): void
    {
        $row = $this->repo->find(ExtensionType::Language, 'en_UK');

        self::assertIsArray($row);
        self::assertSame('auto', $row['version']);
    }

    public function test_find_returns_null_for_a_missing_row(): void
    {
        self::assertNull($this->repo->find(ExtensionType::Plugin, 'no-such-plugin'));
    }

    public function test_insert_plugin_then_find_and_delete_round_trips(): void
    {
        $this->repo->insertPlugin('test-plugin', '1.0.0');

        try {
            $row = $this->repo->find(ExtensionType::Plugin, 'test-plugin');
            self::assertIsArray($row);
            self::assertSame('1.0.0', $row['version']);
            self::assertSame('inactive', $row['state']);
        } finally {
            $this->repo->delete(ExtensionType::Plugin, 'test-plugin');
        }

        self::assertNull($this->repo->find(ExtensionType::Plugin, 'test-plugin'));
    }

    public function test_insert_named_creates_a_theme_row(): void
    {
        $this->repo->insertNamed(ExtensionType::Theme, 'test-theme', '2.0.0', 'Test Theme');

        try {
            $row = $this->repo->find(ExtensionType::Theme, 'test-theme');
            self::assertIsArray($row);
            self::assertSame('2.0.0', $row['version']);
            self::assertSame('Test Theme', $row['name']);
        } finally {
            $this->repo->delete(ExtensionType::Theme, 'test-theme');
        }
    }

    public function test_update_plugin_state_changes_the_column(): void
    {
        $this->repo->insertPlugin('test-plugin-state', '1.0.0');

        try {
            $this->repo->updatePluginState('test-plugin-state', 'active');

            $row = $this->repo->find(ExtensionType::Plugin, 'test-plugin-state');
            self::assertIsArray($row);
            self::assertSame('active', $row['state']);
        } finally {
            $this->repo->delete(ExtensionType::Plugin, 'test-plugin-state');
        }
    }

    public function test_update_version_changes_the_column(): void
    {
        $this->repo->insertNamed(ExtensionType::Theme, 'test-theme-version', '1.0.0', 'T');

        try {
            $this->repo->updateVersion(ExtensionType::Theme, 'test-theme-version', '1.5.0');

            $row = $this->repo->find(ExtensionType::Theme, 'test-theme-version');
            self::assertIsArray($row);
            self::assertSame('1.5.0', $row['version']);
        } finally {
            $this->repo->delete(ExtensionType::Theme, 'test-theme-version');
        }
    }

    public function test_count_matches_the_number_of_rows(): void
    {
        self::assertSame(1, $this->repo->count(ExtensionType::Language));

        $this->repo->insertNamed(ExtensionType::Language, 'test-lang-count', '1.0', 'Test');
        try {
            self::assertSame(2, $this->repo->count(ExtensionType::Language));
        } finally {
            $this->repo->delete(ExtensionType::Language, 'test-lang-count');
        }
    }

    public function test_find_any_theme_id_excluding_returns_another_theme(): void
    {
        $this->repo->insertNamed(ExtensionType::Theme, 'test-theme-a', '1.0', 'A');
        $this->repo->insertNamed(ExtensionType::Theme, 'test-theme-b', '1.0', 'B');

        try {
            $found = $this->repo->findAnyThemeIdExcluding('test-theme-a');
            self::assertSame('test-theme-b', $found);
        } finally {
            $this->repo->delete(ExtensionType::Theme, 'test-theme-a');
            $this->repo->delete(ExtensionType::Theme, 'test-theme-b');
        }
    }

    public function test_find_any_theme_id_excluding_returns_null_when_none_other_exists(): void
    {
        self::assertNull($this->repo->findAnyThemeIdExcluding('modus'));
    }

    public function test_find_user_ids_by_theme_returns_users_on_that_theme(): void
    {
        // fixture: all 4 users default to theme 'modus'
        $ids = $this->repo->findUserIdsByTheme('modus');

        sort($ids);
        self::assertSame(['1', '2', '3', '4'], $ids);
    }

    public function test_find_user_ids_by_theme_returns_empty_for_an_unused_theme(): void
    {
        self::assertSame([], $this->repo->findUserIdsByTheme('no-such-theme'));
    }

    public function test_set_theme_for_users_updates_only_the_given_users(): void
    {
        try {
            $this->repo->setThemeForUsers('elegant', ['3']);

            self::assertSame('elegant', $this->fetchUserInfoColumn(3, 'theme'));
            self::assertSame('modus', $this->fetchUserInfoColumn(1, 'theme'));
        } finally {
            $this->repo->setThemeForUsers('modus', ['3']);
        }
    }

    public function test_set_theme_for_users_is_a_no_op_for_empty_ids(): void
    {
        $this->repo->setThemeForUsers('elegant', []);

        self::assertSame('modus', $this->fetchUserInfoColumn(1, 'theme'));
    }

    public function test_reassign_users_from_language_updates_matching_rows(): void
    {
        try {
            $this->repo->reassignUsersFromLanguage('en_UK', 'fr_FR');

            self::assertSame('fr_FR', $this->fetchUserInfoColumn(1, 'language'));
            self::assertSame('fr_FR', $this->fetchUserInfoColumn(4, 'language'));
        } finally {
            $this->repo->reassignUsersFromLanguage('fr_FR', 'en_UK');
        }
    }

    public function test_set_language_for_user_ids_updates_only_the_given_users(): void
    {
        try {
            $this->repo->setLanguageForUserIds('fr_FR', 1, 2);

            self::assertSame('fr_FR', $this->fetchUserInfoColumn(1, 'language'));
            self::assertSame('fr_FR', $this->fetchUserInfoColumn(2, 'language'));
            self::assertSame('en_UK', $this->fetchUserInfoColumn(3, 'language'));
        } finally {
            $this->repo->setLanguageForUserIds('en_UK', 1, 2);
        }
    }

    private function fetchUserInfoColumn(int $userId, string $column): ?string
    {
        $value = $this->conn->createQueryBuilder()
            ->select($column)
            ->from(Tables::userInfos())
            ->where('user_id = :id')
            ->setParameter('id', $userId)
            ->executeQuery()
            ->fetchOne();

        return is_string($value) ? $value : null;
    }
}
