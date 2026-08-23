<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Name\OptionallyQualifiedName;
use Override;
use Piwigo\Admin\Install\InstallSchemaDropper;
use Piwigo\Admin\Install\InstallSchemaMigrator;
use Piwigo\Db\DbConnection;
use Piwigo\Tests\Support\DbCredentialsTestFactory;

/**
 * InstallSchemaDropper::hasExistingInstall()/drop() are InstallWizard's own
 * confirm-overwrite flow's real detection/deletion primitives -- exercised
 * directly here against a real, disposable database (never the shared
 * fixture one every other Integration test assumes is already loaded),
 * separate from InstallWizardTest.php's own end-to-end coverage of the
 * wizard-level flow around these two methods.
 *
 * hasExistingInstall() returning null (a connected-but-can't-introspect
 * privilege gap) is deliberately NOT exercised here: reproducing it would
 * need a genuinely separate, deliberately under-privileged DB user, and
 * PIWIGO_DB_USER in .env.test has no GRANT/CREATE USER privilege of its
 * own to create one (confirmed live via SHOW GRANTS) -- the same
 * "provably unreachable from this environment" reasoning
 * InstallWizardTest.php's own top-of-class docblock already documents for
 * 3 of its own branches.
 */
final class InstallSchemaDropperTest extends IntegrationTestCase
{
    /**
     * @var array<string, string> original PIWIGO_DB_* env values, restored
     *   in tearDown() -- see InstallServiceTest.php's own identical field
     *   for why this matters in a shared-process test run.
     */
    private array $originalDbEnv = [];

    /**
     * @var list<string> extra databases this test created, dropped in tearDown()
     */
    private array $createdDatabases = [];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        foreach (['PIWIGO_DB_HOST', 'PIWIGO_DB_USER', 'PIWIGO_DB_PASSWORD', 'PIWIGO_DB_BASE', 'PIWIGO_DB_DRIVER', 'PIWIGO_DB_PORT'] as $key) {
            $value = getenv($key);
            $this->originalDbEnv[$key] = $value === false ? '' : $value;
        }
    }

    #[Override]
    protected function tearDown(): void
    {
        DbCredentialsTestFactory::get()
            ->seed($this->originalDbEnv);
        foreach ($this->createdDatabases as $dbName) {
            $this->dropDatabase($dbName);
        }
        parent::tearDown();
    }

    private function createFreshDatabase(): string
    {
        // 'piwigo_installwizard_' -- not 'piwigo_schemadropper_' -- is
        // deliberate: PIWIGO_DB_USER's own real grant in .env.test is
        // scoped to that exact prefix (confirmed live via SHOW GRANTS,
        // same one InstallWizardTest.php's own createFreshDatabase()
        // already relies on), not a generic wildcard this new test class
        // could otherwise use freely.
        $name = 'piwigo_installwizard_dropper_' . bin2hex(random_bytes(5));
        $this->dropAndCreateDatabase($name);
        $this->createdDatabases[] = $name;

        return $name;
    }

    private function connectionFor(string $dbName): Connection
    {
        DbCredentialsTestFactory::get()
            ->seed([
                'PIWIGO_DB_HOST' => $this->dbHost,
                'PIWIGO_DB_USER' => $this->dbUser,
                'PIWIGO_DB_PASSWORD' => $this->dbPass,
                'PIWIGO_DB_BASE' => $dbName,
            ]);

        return DbConnection::build();
    }

    /**
     * @return list<string>
     */
    private function realTableNames(Connection $conn): array
    {
        return array_map(
            static fn (OptionallyQualifiedName $name): string => $name->getUnqualifiedName()
                ->getValue(),
            $conn->createSchemaManager()
                ->introspectTableNames(),
        );
    }

    public function testHasExistingInstallIsFalseForAFreshEmptyDatabase(): void
    {
        $conn = $this->connectionFor($this->createFreshDatabase());

        self::assertFalse(new InstallSchemaDropper()->hasExistingInstall($conn));
    }

    public function testHasExistingInstallIsTrueOnceTheRealBaselineHasMigrated(): void
    {
        $conn = $this->connectionFor($this->createFreshDatabase());
        self::assertNull(new InstallSchemaMigrator()->migrate($conn));

        self::assertTrue(new InstallSchemaDropper()->hasExistingInstall($conn));
    }

    public function testDropRemovesEveryRealPiwigoTableIncludingGroupsAndMigrationVersions(): void
    {
        $conn = $this->connectionFor($this->createFreshDatabase());
        self::assertNull(new InstallSchemaMigrator()->migrate($conn));
        self::assertContains('groups', $this->realTableNames($conn), 'sanity check: the baseline must have created groups before drop() runs');

        new InstallSchemaDropper()
            ->drop($conn);

        self::assertSame([], $this->realTableNames($conn), 'drop() must leave zero tables behind in an otherwise-empty database');
    }

    public function testDropLeavesAnUnrelatedTableInTheSameDatabaseUntouched(): void
    {
        $conn = $this->connectionFor($this->createFreshDatabase());
        self::assertNull(new InstallSchemaMigrator()->migrate($conn));
        $conn->executeStatement('CREATE TABLE some_unrelated_app_table (id INT)');

        new InstallSchemaDropper()
            ->drop($conn);

        self::assertSame(['some_unrelated_app_table'], $this->realTableNames($conn));
    }

    public function testMigrateSucceedsCleanlyImmediatelyAfterDropWithNoLeftoverTableCollisions(): void
    {
        $conn = $this->connectionFor($this->createFreshDatabase());
        self::assertNull(new InstallSchemaMigrator()->migrate($conn));

        new InstallSchemaDropper()
            ->drop($conn);

        self::assertNull(new InstallSchemaMigrator()->migrate($conn), 're-migrating right after drop() must succeed with no "table already exists" collisions');
    }
}
