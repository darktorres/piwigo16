<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Name\UnqualifiedName;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaValidator;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigEntry;
use Piwigo\Config\ConfigLoader;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;

/**
 * `orm:validate-schema`-equivalent check: does the mapped entity metadata
 * actually match the live database, on whichever provider this test
 * process is connected to. Scans all of src/Piwigo (not just ConfigEntry)
 * for attribute-mapped entities; ConfigEntry is simply the one this
 * file's 2nd test targets directly. CI's own `integration` job
 * (.github/workflows/ci.yml) currently exercises only a single MySQL 9.7
 * service container -- there is no matrix job and no MariaDB/PostgreSQL
 * coverage yet, matching every other Integration test's
 * single-connection-from-env design.
 *
 * Real bug found and fixed here, not just a retarget: this used to
 * hand-build its own `EntityManager` via a bare `ORMSetup::
 * createAttributeMetadataConfig()` + `new EntityManager(...)`, bypassing
 * {@see EntityManagerFactory::build()}'s own custom-DBAL-type
 * registration (`group_id`/`user_id`/`category_id`/`comment_id`/
 * `tag_id`/`ip_address`) entirely -- since `Doctrine\DBAL\Types\Type`'s
 * own registry is process-global, not per-`EntityManager`, this test only
 * ever passed by accident, when some other test file already called
 * `EntityManagerFactory::build()` earlier in the same PHP process and
 * registered those types as a side effect; run in isolation (or first in
 * a fresh process), `validateMapping()` failed with a wall of "uses a
 * non-existent type 'user_id'" errors across every VO-typed entity. Now
 * builds through the real factory instead of a parallel, drifted-from
 * copy of its own config.
 *
 * Deliberately does NOT use SchemaValidator::schemaInSyncWithMetadata()
 * for a whole-database comparison -- verified empirically that it treats
 * "in sync" as "the live database contains ONLY what's mapped," so it
 * flags every still-unmapped table and FK constraint as a phantom drop.
 * That's not a real mismatch, just the wrong tool for this project's
 * deliberately incremental entity-mapping strategy (P14's own "first
 * repositories needed for Config/DB bootstrap" scoping, carried through
 * P15). validateMapping() (metadata-internal correctness) plus a
 * targeted column-level check against just the config table is the
 * real, meaningful parity check at this stage.
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

        $currentConfig = \Piwigo\Core\Kernel::container()->get(\Piwigo\Config\CurrentConfig::class);
        if (! $currentConfig instanceof \Piwigo\Config\CurrentConfig) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Config\CurrentConfig::class);
        }
        $currentConfig->reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $this->em = EntityManagerFactory::build(DbConnection::build());
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
