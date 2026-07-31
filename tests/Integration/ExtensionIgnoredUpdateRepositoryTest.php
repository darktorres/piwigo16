<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Piwigo\Admin\Extensions\ExtensionIgnoredUpdateEntity;
use Piwigo\Admin\Extensions\ExtensionIgnoredUpdateRepository;
use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\CurrentConfig;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\Tables;

/**
 * Direct coverage of ExtensionIgnoredUpdateRepository's own query/mutation
 * methods. ExtensionUpdateCheckerTest (Unit) stubs this repository
 * entirely (see that file's own docblock), so findIgnoredIdsByType() and
 * unignore() were never exercised against a real EntityManager/DB
 * connection by any existing test -- isIgnored()/ignore()/resetType()/
 * resetAll() are already reached elsewhere (real pwg.extensions.
 * ignoreUpdate/checkUpdates round trips), so this file only adds the two
 * methods that weren't.
 *
 * The fixture ships zero `extension_ignored_updates` rows, so every id
 * used here is test-only; each test cleans up its own disposable rows via
 * try/finally.
 */
final class ExtensionIgnoredUpdateRepositoryTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private ExtensionIgnoredUpdateRepository $repo;

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

        $this->conn = DbConnection::build();
        $this->repo = EntityManagerFactory::build($this->conn)->getRepository(ExtensionIgnoredUpdateEntity::class);
    }

    public function test_find_ignored_ids_by_type_returns_only_rows_for_the_requested_type(): void
    {
        $now = '2026-07-30 00:00:00';
        $this->repo->ignore(ExtensionType::Plugin, 'test-plugin-a', $now);
        $this->repo->ignore(ExtensionType::Plugin, 'test-plugin-b', $now);
        $this->repo->ignore(ExtensionType::Theme, 'test-theme-a', $now);

        try {
            $ids = $this->repo->findIgnoredIdsByType(ExtensionType::Plugin);
            sort($ids);

            self::assertSame(['test-plugin-a', 'test-plugin-b'], $ids, 'the Theme row must not leak into the Plugin result');
        } finally {
            $this->conn->executeStatement(
                'DELETE FROM ' . Tables::extensionIgnoredUpdates()
                . " WHERE extension_id IN ('test-plugin-a', 'test-plugin-b', 'test-theme-a')"
            );
        }
    }

    public function test_find_ignored_ids_by_type_returns_an_empty_list_when_none_are_ignored(): void
    {
        // Exercises array_map()'s own empty-input branch: findBy() returns
        // [] for a type with no ignored rows, and array_map(fn, []) must
        // stay [] rather than e.g. null -- the fixture ships zero
        // extension_ignored_updates rows and no other test in this file
        // leaves any behind (each cleans up in its own finally), so
        // Language genuinely has none here.
        self::assertSame([], $this->repo->findIgnoredIdsByType(ExtensionType::Language));
    }

    public function test_unignore_removes_an_existing_ignored_row(): void
    {
        $this->repo->ignore(ExtensionType::Plugin, 'test-plugin-unignore', '2026-07-30 00:00:00');
        self::assertTrue($this->repo->isIgnored(ExtensionType::Plugin, 'test-plugin-unignore'), 'setup: the row must exist before unignore() runs');

        $this->repo->unignore(ExtensionType::Plugin, 'test-plugin-unignore');

        self::assertFalse($this->repo->isIgnored(ExtensionType::Plugin, 'test-plugin-unignore'));

        // Confirms the row is actually gone from the DB (not merely absent
        // from Doctrine's identity map) -- a raw query bypassing the
        // EntityManager entirely.
        $count = $this->conn->fetchOne(
            'SELECT COUNT(*) FROM ' . Tables::extensionIgnoredUpdates() . " WHERE extension_id = 'test-plugin-unignore'"
        );
        self::assertSame(0, is_numeric($count) ? (int) $count : null);
    }

    public function test_unignore_is_a_no_op_when_the_row_was_never_ignored(): void
    {
        // The $entity === null branch: 'no-such-extension' was never
        // ignore()'d by this or any other test in this file, so find()
        // genuinely returns null and unignore() must return before ever
        // calling getEntityManager()/remove()/flush().
        $countBefore = $this->conn->fetchOne('SELECT COUNT(*) FROM ' . Tables::extensionIgnoredUpdates());

        $this->repo->unignore(ExtensionType::Plugin, 'no-such-extension');

        $countAfter = $this->conn->fetchOne('SELECT COUNT(*) FROM ' . Tables::extensionIgnoredUpdates());
        self::assertSame($countBefore, $countAfter);
    }
}
