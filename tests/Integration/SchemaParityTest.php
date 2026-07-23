<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Name\UnqualifiedName;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaValidator;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigEntry;
use Piwigo\Config\ConfigLoader;
use Piwigo\Db\DbConnection;
use Piwigo\Db\TablePrefixListener;

/**
 * `orm:validate-schema`-equivalent check: does the mapped entity metadata
 * (currently just ConfigEntry -- P17-23 maps the other 33 origin tables)
 * actually match the live database, on whichever provider this test
 * process is connected to. The multi-provider guarantee comes from the
 * CI matrix job (P15) running this exact file against real MySQL,
 * MariaDB, and PostgreSQL service containers -- not from this test
 * looping over 3 hardcoded connections itself, matching every other
 * Integration test's single-connection-from-env design.
 *
 * Deliberately does NOT use SchemaValidator::schemaInSyncWithMetadata()
 * for a whole-database comparison -- verified empirically that it treats
 * "in sync" as "the live database contains ONLY what's mapped," so with
 * just ConfigEntry mapped it proposes dropping all 41 other tables and
 * all 42 FK constraints. That's not a real mismatch, just the wrong tool
 * for this project's deliberately incremental entity-mapping strategy
 * (P14's own "first repositories needed for Config/DB bootstrap"
 * scoping, carried through P15). validateMapping() (metadata-internal
 * correctness) plus a targeted column-level check against just the
 * config table is the real, meaningful parity check at this stage.
 */
final class SchemaParityTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private EntityManagerInterface $em;

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

        $conn = DbConnection::build();
        $ormConfig = ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 2) . '/src/Piwigo'], isDevMode: true);
        $ormConfig->enableNativeLazyObjects(true);
        $em = new EntityManager($conn, $ormConfig);
        $em->getEventManager()->addEventListener(Events::loadClassMetadata, new TablePrefixListener());
        $this->em = $em;
    }

    public function test_mapped_entity_metadata_has_no_validation_errors(): void
    {
        $validator = new SchemaValidator($this->em);

        self::assertSame([], $validator->validateMapping(), 'Entity mapping metadata must have zero validation errors');
    }

    public function test_config_table_columns_match_config_entry_mapping(): void
    {
        $metadata = $this->em->getClassMetadata(ConfigEntry::class);
        $tableName = $metadata->getTableName();
        if ($tableName === '') {
            self::fail('ConfigEntry metadata has no table name');
        }
        $sm = $this->em->getConnection()->createSchemaManager();
        $columns = $sm->introspectTableColumnsByUnquotedName($tableName);
        $columnNames = array_map(static function (Column $column): string {
            $objectName = $column->getObjectName();
            self::assertInstanceOf(UnqualifiedName::class, $objectName);
            return $objectName->getIdentifier()->getValue();
        }, $columns);

        foreach (['param', 'value', 'comment'] as $mappedField) {
            self::assertContains(
                $mappedField,
                $columnNames,
                "ConfigEntry maps '{$mappedField}' but the live {$metadata->getTableName()} table has no such column"
            );
        }
    }
}
