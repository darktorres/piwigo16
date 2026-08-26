<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use LogicException;
use Override;
use Piwigo\Admin\Maintenance\FilesystemIntegrityChecker;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Db\DbConnection;
use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentPathsTestFactory;
use Piwigo\Tests\Support\CurrentTemplateTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;

/**
 * fsQuickCheck()/imagesIntegrity() need a real DB
 * ('images'/imageCategory()/config()) and, for fsQuickCheck(), a
 * real Template (Lang::t()+$template->assign() writes to 'header_msgs')
 * plus a real ConfigService (confUpdateParam() persists
 * 'fs_quick_check_last_check') -- the same DI-bootstrapped shape
 * CheckIntegrityTest uses for this file's sibling maintenance class.
 *
 * `Piwigo\Db\DbConnection::build()` is NOT a singleton (confirmed via
 * direct read: `DriverManager::getConnection(self::params())` mints a
 * fresh connection/session on every call) -- fsQuickCheck()/
 * imagesIntegrity() each open their own connection internally, distinct
 * from this file's own `$this->conn`. That rules out an uncommitted-
 * transaction-then-rollback trick (a different MySQL session can't see
 * another session's uncommitted rows) for the "images table is empty"
 * guard test below: it commits a real DELETE and restores the fixture via
 * loadFixture() in a finally block, the same restore convention
 * UserListCommandTest already uses for its own FK-cascading mutation.
 *
 * Fixture images 1-5 (tests/Fixtures/piwigo-17.0.sql, confirmed via direct
 * read) each have a real backing file already on disk at
 * `CurrentPathsTestFactory::get()->root . path` (confirmed live with file_exists()),
 * so the sampled-up-to-50-ids "missing photos" loop (lines 99-120) never
 * trips for them -- every test below that needs to reach the duplicate-
 * paths check relies on that.
 */
final class FilesystemIntegrityCheckerTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private Connection $conn;

    /**
     * Container-shared instance, resolved once per test in setUp(), after
     * Kernel::boot(), same convention as this file's sibling Integration
     * tests.
     */
    private FilesystemIntegrityChecker $checker;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        // This class runs unwrapped, real-commit tests against the
        // shared fixture DB -- marks the shared trust flag dirty so any
        // DbTransactionTestOverride-wrapped class running after this one
        // knows it can't skip its own reimport. See
        // IntegrationTestCase::$sharedFixtureKnownPristine's own docblock.
        IntegrationTestCase::markSharedFixtureDirty();
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
        Kernel::boot();
        CurrentConfigServiceTestFactory::get()->set(new ConfigService($this->buildConfigRepository(), CurrentConfigTestFactory::get()));

        $checker = Kernel::container()->get(FilesystemIntegrityChecker::class);
        if (! $checker instanceof FilesystemIntegrityChecker) {
            throw new LogicException('Container returned an unexpected type for ' . FilesystemIntegrityChecker::class);
        }
        $this->checker = $checker;

        $this->conn = DbConnection::build();
        // The fixture never seeds this param (confirmed via grep) -- a
        // previous test method's write is the only source of a stale row,
        // and confUpdateParam()'s own cache-clearing doesn't help a test
        // that reads it back with raw SQL.
        $this->conn->executeStatement("DELETE FROM config WHERE param = 'fs_quick_check_last_check'");

        CurrentTemplateTestFactory::get()->set(TemplateTestFactory::build(CurrentPathsTestFactory::get()->root . 'themes/admin', 'default'));
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->conn->executeStatement("DELETE FROM config WHERE param = 'fs_quick_check_last_check'");
        CurrentTemplateTestFactory::get()->reset();
        Kernel::reset();
        parent::tearDown();
    }

    /**
     * @param array<int<0, max>|string, mixed> $params
     */
    private function fetchOneInt(string $sql, array $params = []): int
    {
        $value = $this->conn->fetchOne($sql, $params);
        self::assertIsNumeric($value);

        return (int) $value;
    }

    /**
     * confUpdateParam() JSON-encodes the value (ConfigService::encode()),
     * so a plain SELECT needs one json_decode() to get back the raw
     * date('c') string -- reads via raw SQL rather than
     * ConfigService::confGetParam() so this doesn't depend on the
     * ConfigRepository's own EntityManager identity map staying fresh
     * across a raw SQL write elsewhere in the same test.
     */
    private function fsQuickCheckLastCheckRaw(): ?string
    {
        $raw = $this->conn->fetchOne("SELECT value FROM config WHERE param = 'fs_quick_check_last_check'");
        if ($raw === false) {
            return null;
        }

        self::assertIsString($raw);
        $decoded = json_decode($raw, true);
        self::assertIsString($decoded);

        return $decoded;
    }

    // ------------------------------------------------------- period guard

    public function testFsQuickCheckWritesNothingWhenThePeriodIsDisabled(): void
    {
        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->fsQuickCheckPeriod = 0;

        $this->checker->fsQuickCheck();

        self::assertNull($this->fsQuickCheckLastCheckRaw());
    }

    public function testFsQuickCheckWritesTheLastCheckTimestampAndNoHeaderMessageWhenThePeriodIsEnabled(): void
    {
        // CurrentConfig::reset() in setUp() leaves fsQuickCheckPeriod at
        // its own property-initializer default (86400, nonzero) -- this
        // proves the guard's fall-through path, not just its early return.
        $this->checker->fsQuickCheck();

        self::assertNotNull($this->fsQuickCheckLastCheckRaw());
        // Every fixture image resolves to a real file and none of them
        // share a path, so neither the missing-photos nor the
        // duplicate-paths branch ever assigns 'header_msgs'.
        self::assertNull(CurrentTemplateTestFactory::get()->get()->getTemplateVars('header_msgs'));
    }

    // -------------------------------------------------- run-once guard

    public function testFsQuickCheckIsANoOpOnASecondCallWithinTheSameRequest(): void
    {
        $this->checker->fsQuickCheck();
        self::assertNotNull($this->fsQuickCheckLastCheckRaw());

        // Overwrite the just-written row directly, bypassing
        // confUpdateParam() -- if the self::$fsQuickCheckDone guard didn't
        // block the next call, fsQuickCheck() would overwrite this
        // sentinel with a fresh date('c') value.
        $this->conn->executeStatement(
            "UPDATE config SET value = '\"sentinel-unchanged\"' WHERE param = 'fs_quick_check_last_check'"
        );

        $this->checker->fsQuickCheck();

        self::assertSame('sentinel-unchanged', $this->fsQuickCheckLastCheckRaw());
    }

    public function testResetAllowsFsQuickCheckToRunAgain(): void
    {
        $this->checker->fsQuickCheck();
        $this->conn->executeStatement(
            "UPDATE config SET value = '\"sentinel-before-reset\"' WHERE param = 'fs_quick_check_last_check'"
        );
        // The raw UPDATE above bypasses ConfigRepository's own
        // EntityManager (the private, standalone one buildConfigRepository()
        // constructs in setUp() -- a *different* instance than
        // Kernel::container()'s own, confirmed live), leaving its identity
        // map holding the pre-overwrite ConfigEntry. Without this clear(),
        // upsert()'s find() below would resolve that stale entity, see no
        // property change once it's set back to a fresh date('c'), and
        // flush() would silently skip the UPDATE entirely -- the same
        // identity-map staleness hazard applies to any raw-write call site
        // that bypasses a repository's own EntityManager (e.g.
        // TagRepository).
        $this->configEntityManager?->clear();

        $this->checker->reset();
        $this->checker->fsQuickCheck();

        $after = $this->fsQuickCheckLastCheckRaw();
        self::assertNotNull($after);
        self::assertNotSame('sentinel-before-reset', $after, 'reset() must flip $fsQuickCheckDone back to false, letting the next call actually run');
    }

    // --------------------------------------------------- empty-ids guard

    public function testFsQuickCheckReturnsEarlyWithoutErrorWhenTheImagesTableIsEmpty(): void
    {
        // With zero images, both id-sampling queries (issue1827 + random)
        // return zero rows, so $fs_quick_check_ids stays empty. Without
        // the `count($fs_quick_check_ids) < 1` guard, the next query would
        // build the syntactically invalid `WHERE id IN ()` (implode() of
        // an empty array) and fetchAllAssociative() would throw.
        $this->conn->executeStatement('DELETE FROM images');

        try {
            $this->checker->fsQuickCheck();

            // Reaching this line at all (no DBAL exception) is the guard
            // firing. The last-check write on line 52 running first
            // (before this guard) is independent confirmation execution
            // really did get past the period/Done guards rather than
            // trivially short-circuiting earlier.
            self::assertNotNull($this->fsQuickCheckLastCheckRaw());
        } finally {
            // DELETE cascaded away every child row an ON DELETE CASCADE FK
            // points at images (image_category, favorites, comments,
            // image_tag, ...) -- a full fixture reimport is the only way
            // to restore all of them for this file's other tests, the
            // same restore-via-reload convention UserListCommandTest uses.
            $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
        }
    }

    // ------------------------------------------------- duplicate paths

    public function testFsQuickCheckReportsTheCorrectDuplicatePathCountInAHeaderMessage(): void
    {
        // Pairing one new row against each of fixture images 1 and 2's own
        // (already-confirmed-existing) paths creates exactly 2
        // duplicate-path groups without needing any new files on disk.
        $path1 = $this->conn->fetchOne('SELECT path FROM images WHERE id = 1');
        $path2 = $this->conn->fetchOne('SELECT path FROM images WHERE id = 2');
        self::assertIsString($path1);
        self::assertIsString($path2);

        $this->conn->executeStatement(
            'INSERT INTO images (file, path) VALUES (?, ?), (?, ?)',
            ['fsic-dup-a.jpg', $path1, 'fsic-dup-b.jpg', $path2]
        );
        $newIds = $this->conn->fetchFirstColumn(
            "SELECT id FROM images WHERE file IN ('fsic-dup-a.jpg', 'fsic-dup-b.jpg')"
        );
        self::assertCount(2, $newIds);

        try {
            $this->checker->fsQuickCheck();

            self::assertSame(
                ['We have found 2 duplicate paths. Details provided by plugin Check Uploads'],
                CurrentTemplateTestFactory::get()->get()->getTemplateVars('header_msgs')
            );
        } finally {
            $this->conn->executeStatement(
                'DELETE FROM images WHERE id IN (?, ?)',
                $newIds
            );
        }
    }

    // ---------------------------------------------------- imagesIntegrity()

    public function testImagesIntegrityDeletesImageCategoryRowsWhoseImageNoLongerExists(): void
    {
        // image_category.image_id carries a real ON DELETE CASCADE
        // FK back to images, so a genuine orphan can never arise
        // through normal DB writes (confirmed live: a plain INSERT with a
        // nonexistent image_id is rejected by the FK) -- disabling FK
        // checks just for this insert reproduces the only real way this
        // state has ever existed in practice, the same pattern
        // UserServiceTest's own orphan-user-group test uses.
        $this->disableForeignKeyChecks($this->conn);
        $this->conn->executeStatement(
            'INSERT INTO image_category (image_id, category_id) VALUES (777777, 1)'
        );
        $this->enableForeignKeyChecks($this->conn);

        try {
            self::assertSame(
                1,
                $this->fetchOneInt('SELECT COUNT(*) FROM image_category WHERE image_id = 777777')
            );

            $this->checker->imagesIntegrity();

            self::assertSame(
                0,
                $this->fetchOneInt('SELECT COUNT(*) FROM image_category WHERE image_id = 777777')
            );
        } finally {
            $this->conn->executeStatement('DELETE FROM image_category WHERE image_id = 777777');
        }
    }

    public function testImagesIntegrityLeavesValidImageCategoryRowsUntouchedWhenThereAreNoOrphans(): void
    {
        $before = $this->fetchOneInt('SELECT COUNT(*) FROM image_category');
        self::assertGreaterThan(0, $before, 'the fixture must have at least one real image_category row for this to be a meaningful assertion');

        $this->checker->imagesIntegrity();

        self::assertSame($before, $this->fetchOneInt('SELECT COUNT(*) FROM image_category'));
    }
}
