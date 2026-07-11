<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Piwigo\Config\Config;
use Piwigo\Config\ConfigLoader;
use Piwigo\Db\DbConnection;
use Piwigo\Db\SchemaDumpService;

/**
 * Covers the MySQL path against the real fixture DB (the CI multi-provider
 * matrix job covers MariaDB/PostgreSQL directly against service
 * containers -- this Integration suite only ever runs against MySQL, per
 * every other test file here).
 */
final class SchemaDumpServiceTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

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
    }

    public function test_dump_detects_mysql_and_writes_a_deterministic_schema_file(): void
    {
        $service = new SchemaDumpService(DbConnection::build());

        $result = $service->dump();

        self::assertSame('mysql', $result['label']);
        self::assertFileExists($result['path']);

        $content = (string) file_get_contents($result['path']);
        self::assertStringContainsString('CREATE TABLE', $content);
        self::assertStringNotContainsString('doctrine_migration_versions', $content);
        self::assertStringNotContainsString('GTID_PURGED', $content);
        self::assertStringNotContainsString('AUTO_INCREMENT=', $content);

        $second = $service->dump();
        $secondContent = (string) file_get_contents($second['path']);
        self::assertSame($content, $secondContent, 'schema:dump output must be deterministic across runs');
    }
}
