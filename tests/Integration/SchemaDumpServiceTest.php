<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use Piwigo\Config\ConfigLoader;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Db\DbConnection;
use Piwigo\Db\SchemaDumpService;

/**
 * Covers whichever platform `.env.test`'s PIWIGO_DB_DRIVER points this
 * Integration suite run at (MySQL or PostgreSQL): `dump()`'s own real
 * behavior detects the connected platform and writes
 * `piwigo_structure-{mysql,pgsql}.sql` accordingly, and this test's own
 * snapshot/restore and `label` assertion follow the same detected
 * platform.
 *
 * SchemaDumpService::dump() writes to the real, tracked
 * install/piwigo_structure-{mysql,pgsql}.sql (there's no test-injectable
 * output path -- schema:dump is meant to run against a genuinely blank,
 * freshly-migrated database and overwrite that file for real). The
 * fixture DB here is a captured snapshot, not a fresh Migrator run, so
 * its schema can carry harmless rendering differences (e.g. a redundant
 * per-column CHARACTER SET clause matching the table's own default) that
 * don't reflect real drift -- snapshot/restore the file around this test
 * the same way other Integration tests here snapshot/restore real
 * env/global state, so running this test never leaves an uncommitted
 * diff in the working tree.
 */
final class SchemaDumpServiceTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private string $schemaFilePath;

    private string $originalSchemaFileContent;

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

        CurrentConfigTestFactory::get()->reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $this->schemaFilePath = dirname(__DIR__, 2) . '/install/piwigo_structure-' . ($this->dbDriver === 'pgsql' ? 'pgsql' : 'mysql') . '.sql';
        $this->originalSchemaFileContent = (string) file_get_contents($this->schemaFilePath);
    }

    #[Override]
    protected function tearDown(): void
    {
        file_put_contents($this->schemaFilePath, $this->originalSchemaFileContent);
        parent::tearDown();
    }

    public function test_dump_detects_mysql_and_writes_a_deterministic_schema_file(): void
    {
        $service = new SchemaDumpService(DbConnection::build());

        $result = $service->dump();

        self::assertSame($this->dbDriver === 'pgsql' ? 'pgsql' : 'mysql', $result['label']);
        self::assertFileExists($result['path']);

        $content = (string) file_get_contents($result['path']);
        self::assertStringContainsString('CREATE TABLE', $content);
        self::assertStringNotContainsString('migration_versions', $content);
        self::assertStringNotContainsString('GTID_PURGED', $content);
        self::assertStringNotContainsString('AUTO_INCREMENT=', $content);

        $second = $service->dump();
        $secondContent = (string) file_get_contents($second['path']);
        self::assertSame($content, $secondContent, 'schema:dump output must be deterministic across runs');
    }
}
