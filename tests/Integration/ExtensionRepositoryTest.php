<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use LogicException;
use Override;
use Piwigo\Admin\Extensions\ExtensionRepository;
use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;

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

        $this->conn = DbConnection::build();
        $this->repo = new ExtensionRepository(EntityManagerFactory::build($this->conn));
    }

    public function testFindAllReturnsTheFixtureLanguage(): void
    {
        $rows = $this->repo->findAll(ExtensionType::Language);

        self::assertArrayHasKey('en_UK', $rows);
        self::assertSame('English (Great Britain)', $rows['en_UK']['name']);
    }

    // findAll()'s `if (! is_string($id)) { continue; }` guard (its own
    // line 63) is left uncovered deliberately, not by oversight: `id` is
    // the literal PRIMARY KEY on all 3 tables (plugins/themes/languages,
    // confirmed via install/piwigo_structure-mysql.sql), and MySQL forces
    // every PRIMARY KEY column NOT NULL regardless of its own nullability
    // declaration -- there is no way to INSERT a NULL id even by dropping
    // down to raw SQL against a real schema-conformant DB, and DBAL never
    // returns a VARCHAR column as anything but a PHP string. A row this
    // branch would `continue` past cannot exist in any real, constraint-
    // enforced database. Pure static-analysis narrowing for `$row['id']`
    // (raw DBAL rows type as `array<string, mixed>`), not a real runtime
    // guard.

    public function testFindReturnsASingleRow(): void
    {
        $row = $this->repo->find(ExtensionType::Language, 'en_UK');

        self::assertIsArray($row);
        self::assertSame('16.3.0', $row['version']);
    }

    public function testFindReturnsNullForAMissingRow(): void
    {
        self::assertNull($this->repo->find(ExtensionType::Plugin, 'no-such-plugin'));
    }

    public function testInsertNamedCreatesAThemeRow(): void
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

    public function testUpdatePluginStateChangesTheColumn(): void
    {
        // insertPlugin() no longer exists (P27.8: zero real production
        // callers, confirmed dead once ExtensionLifecycle's own plugin
        // 'install' case retargeted onto PluginConfig\PluginRegistry) --
        // seeds the row directly via DBAL instead, matching this
        // repository class's own raw-DBAL shape.
        $this->conn->insert('plugins', [
            'id' => 'test-plugin-state',
            'version' => '1.0.0',
            'state' => 'inactive',
        ]);

        try {
            $this->repo->updatePluginState('test-plugin-state', 'active');

            $row = $this->repo->find(ExtensionType::Plugin, 'test-plugin-state');
            self::assertIsArray($row);
            self::assertSame('active', $row['state']);
        } finally {
            $this->repo->delete(ExtensionType::Plugin, 'test-plugin-state');
        }
    }

    public function testUpdateVersionChangesTheColumn(): void
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

    public function testCountMatchesTheNumberOfRows(): void
    {
        self::assertSame(1, $this->repo->count(ExtensionType::Language));

        $this->repo->insertNamed(ExtensionType::Language, 'test-lang-count', '1.0', 'Test');
        try {
            self::assertSame(2, $this->repo->count(ExtensionType::Language));
        } finally {
            $this->repo->delete(ExtensionType::Language, 'test-lang-count');
        }
    }

    public function testFindAnyThemeIdExcludingReturnsAnotherTheme(): void
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

    public function testFindAnyThemeIdExcludingReturnsNullWhenNoneOtherExists(): void
    {
        self::assertNull($this->repo->findAnyThemeIdExcluding('default'));
    }

    public function testFindUserIdsByThemeReturnsUsersOnThatTheme(): void
    {
        // fixture: all 4 users default to theme 'default' (AppInfo::DEFAULT_TEMPLATE)
        $ids = $this->repo->findUserIdsByTheme('default');

        sort($ids);
        self::assertSame(['1', '2', '3', '4'], $ids);
    }

    // findUserIdsByTheme()'s `if (! $row['userId'] instanceof UserId) {
    // throw ... }` guard (its own line 215) is left uncovered
    // deliberately too: `ui.userId` is UserInfoEntity's own #[ORM\Id]
    // column, mapped through the custom 'user_id' Doctrine type
    // (confirmed via direct read of UserInfoEntity's own constructor
    // property attribute) -- every real row Doctrine's DQL hydration
    // produces for a `user_id` PRIMARY KEY column goes through that same
    // type's convertToPHPValue(), which always returns a real UserId.
    // There is no real DB row (user_id is NOT NULL, being the PK) or DQL
    // hydration path that could produce anything else here; this guards
    // against a hypothetical future change to the type mapping itself,
    // not a real runtime state reachable through this method's own
    // inputs.

    public function testFindUserIdsByThemeReturnsEmptyForAnUnusedTheme(): void
    {
        self::assertSame([], $this->repo->findUserIdsByTheme('no-such-theme'));
    }

    public function testSetThemeForUsersUpdatesOnlyTheGivenUsers(): void
    {
        try {
            $this->repo->setThemeForUsers('elegant', ['3']);

            self::assertSame('elegant', $this->fetchUserInfoColumn(3, 'theme'));
            self::assertSame('default', $this->fetchUserInfoColumn(1, 'theme'));
        } finally {
            $this->repo->setThemeForUsers('default', ['3']);
        }
    }

    public function testSetThemeForUsersIsANoOpForEmptyIds(): void
    {
        $this->repo->setThemeForUsers('elegant', []);

        self::assertSame('default', $this->fetchUserInfoColumn(1, 'theme'));
    }

    public function testReassignUsersFromLanguageUpdatesMatchingRows(): void
    {
        try {
            $this->repo->reassignUsersFromLanguage('en_UK', 'fr_FR');

            self::assertSame('fr_FR', $this->fetchUserInfoColumn(1, 'language'));
            self::assertSame('fr_FR', $this->fetchUserInfoColumn(4, 'language'));
        } finally {
            $this->repo->reassignUsersFromLanguage('fr_FR', 'en_UK');
        }
    }

    public function testSetLanguageForUserIdsUpdatesOnlyTheGivenUsers(): void
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
            ->from('user_infos')
            ->where('user_id = :id')
            ->setParameter('id', $userId)
            ->executeQuery()
            ->fetchOne();

        return is_string($value) ? $value : null;
    }
}
