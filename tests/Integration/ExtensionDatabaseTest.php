<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Name\UnqualifiedName;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use InvalidArgumentException;
use Override;
use Piwigo\Common\ValueObject\PluginId;
use Piwigo\Db\DbConnection;
use Piwigo\Permission\SqlCondition;
use Piwigo\PluginConfig\ExtensionDatabase;

/**
 * Covers `Piwigo\PluginConfig\ExtensionDatabase`, the per-extension-
 * namespaced raw-DB facade handed out by `ExtensionContext::db()` --
 * grounded in `docs/porting-open-items.md`'s "no general raw-DB
 * surface" framework gap and the real `BanIP_13.0.a`/`AdditionalPages_14.a`
 * callers `ExtensionDatabase`'s own class docblock traces this to.
 *
 * Runs unwrapped, real-commit DDL against the real configured test DB
 * (`$this->dbDriver`, set from `PIWIGO_DB_DRIVER` -- mysqli or pgsql in
 * this project's own test matrix) rather than under
 * `DbTransactionTestOverride`, same reasoning as `BatchWriterTest.php`'s
 * own docblock: DDL isn't safely nestable inside that wrapping across
 * both supported drivers. `createTable()`'s own portable-DDL-via-
 * `Doctrine\DBAL\Schema\Table` design is exercised for real here against
 * whichever driver is configured -- proof of genuine cross-engine
 * portability, not just a design claim.
 */
final class ExtensionDatabaseTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private const string SUFFIX = 'scratch';

    private Connection $conn;

    private ExtensionDatabase $db;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        IntegrationTestCase::markSharedFixtureDirty();
        $this->setUpConnectionFromEnv();

        if (! self::$fixtureReady) {
            $this->resetDatabase();
            $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        $this->conn = DbConnection::build();
        $this->db = new ExtensionDatabase($this->conn, PluginId::from('extdbtest'));
        $this->dropScratchTableIfExists();
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->dropScratchTableIfExists();
        parent::tearDown();
    }

    private function dropScratchTableIfExists(): void
    {
        if ($this->db->hasTable(self::SUFFIX)) {
            $this->db->dropTable(self::SUFFIX);
        }
    }

    private function createScratchTable(): void
    {
        $this->db->createTable(self::SUFFIX, static function (Table $table): void {
            $table->addColumn('id', 'integer', [
                'autoincrement' => true,
            ]);
            $table->addColumn('ip', 'string', [
                'length' => 45,
            ]);
            $table->addPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()
                    ->setColumnNames(UnqualifiedName::unquoted('id'))
                    ->create()
            );
        });
    }

    public function testTableNameNamespacesBySuffixAndExtensionId(): void
    {
        self::assertSame('ext_extdbtest_scratch', $this->db->tableName(self::SUFFIX));
    }

    public function testTableNameRejectsAnInvalidSuffix(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // A real injection shape one level up from BanIP's own bug --
        // this is exactly the argument a raw string-interpolated
        // "$_POST['inserip']-as-table-name" mistake would pass.
        $this->db->tableName("ip_ban'; DROP TABLE users; --");
    }

    public function testCreateTableThenHasTableReflectsRealSchemaState(): void
    {
        self::assertFalse($this->db->hasTable(self::SUFFIX));

        $this->createScratchTable();

        self::assertTrue($this->db->hasTable(self::SUFFIX));
    }

    public function testDropTableThenHasTableReturnsFalse(): void
    {
        $this->createScratchTable();
        self::assertTrue($this->db->hasTable(self::SUFFIX));

        $this->db->dropTable(self::SUFFIX);

        self::assertFalse($this->db->hasTable(self::SUFFIX));
    }

    /**
     * BanIP's own real shape: individually inserted/edited/deleted rows,
     * plus a LIKE-based range-prefix match -- SqlCondition, not flat
     * equality, is what makes this expressible while keeping the table
     * itself unnameable by the caller.
     */
    public function testInsertSelectUpdateDeleteRoundTripAgainstARealTable(): void
    {
        $this->createScratchTable();

        $firstId = $this->db->insert(self::SUFFIX, [
            'ip' => '192.168.1.1',
        ]);
        $secondId = $this->db->insert(self::SUFFIX, [
            'ip' => '192.168.1.2',
        ]);
        $this->db->insert(self::SUFFIX, [
            'ip' => '10.0.0.1',
        ]);

        self::assertGreaterThan(0, $firstId);
        self::assertGreaterThan($firstId, $secondId);

        $rangeMatches = $this->db->select(self::SUFFIX, SqlCondition::fromRawSql('ip LIKE :pattern', [
            'pattern' => '192.168.1.%',
        ]), orderByColumn: 'id');
        self::assertSame(['192.168.1.1', '192.168.1.2'], array_column($rangeMatches, 'ip'));

        $this->db->update(self::SUFFIX, [
            'ip' => '192.168.1.99',
        ], SqlCondition::fromRawSql('id = :id', [
            'id' => $firstId,
        ]));
        $updated = $this->db->select(self::SUFFIX, SqlCondition::fromRawSql('id = :id', [
            'id' => $firstId,
        ]));
        self::assertSame('192.168.1.99', $updated[0]['ip']);

        $deleted = $this->db->delete(self::SUFFIX, SqlCondition::fromRawSql('ip LIKE :pattern', [
            'pattern' => '192.168.%',
        ]));
        self::assertSame(2, $deleted);

        $remaining = $this->db->select(self::SUFFIX);
        self::assertSame(['10.0.0.1'], array_column($remaining, 'ip'));
    }

    public function testCountReflectsRealRowCount(): void
    {
        $this->createScratchTable();
        self::assertSame(0, $this->db->count(self::SUFFIX));

        $this->db->insert(self::SUFFIX, [
            'ip' => '1.2.3.4',
        ]);
        $this->db->insert(self::SUFFIX, [
            'ip' => '5.6.7.8',
        ]);

        self::assertSame(2, $this->db->count(self::SUFFIX));
        self::assertSame(1, $this->db->count(self::SUFFIX, SqlCondition::fromRawSql('ip = :ip', [
            'ip' => '1.2.3.4',
        ])));
    }

    /**
     * `PluginId`/`ThemeId` both allow hyphens (`PluginId::PATTERN`), but
     * neither `Connection::insert()` nor a hand-built `QueryBuilder` quotes
     * the table name they splice into SQL text -- an unquoted hyphenated
     * identifier is a real SQL syntax error on every supported platform.
     * `tableName()` itself doesn't validate `$extensionId` (only its own
     * `$suffix` argument), so a plugin author with a real, valid,
     * hyphenated id (e.g. many real catalog entries use one) would hit
     * this on the very first `insert()`/`select()` call without the
     * quoting `ExtensionDatabase` does internally.
     */
    public function testWorksForAnExtensionIdContainingAHyphen(): void
    {
        $db = new ExtensionDatabase($this->conn, PluginId::from('ext-with-hyphen'));
        self::assertSame('ext_ext-with-hyphen_scratch', $db->tableName(self::SUFFIX));

        if ($db->hasTable(self::SUFFIX)) {
            $db->dropTable(self::SUFFIX);
        }

        try {
            $db->createTable(self::SUFFIX, static function (Table $table): void {
                $table->addColumn('id', 'integer', [
                    'autoincrement' => true,
                ]);
                $table->addColumn('ip', 'string', [
                    'length' => 45,
                ]);
                $table->addPrimaryKeyConstraint(
                    PrimaryKeyConstraint::editor()
                        ->setColumnNames(UnqualifiedName::unquoted('id'))
                        ->create()
                );
            });

            $db->insert(self::SUFFIX, [
                'ip' => '203.0.113.1',
            ]);

            self::assertSame(['203.0.113.1'], array_column($db->select(self::SUFFIX), 'ip'));
        } finally {
            $db->dropTable(self::SUFFIX);
        }
    }
}
