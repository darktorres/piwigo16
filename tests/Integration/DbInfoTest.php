<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Piwigo\Db\DbConnection;
use Piwigo\Db\DbInfo;
use Piwigo\Db\Tables;

/**
 * Piwigo\Db\DbInfo -- had zero dedicated coverage (see /home/torres/.claude/
 * plans/piped-enchanting-spark.md, Wave 1). `user_infos.status` is a real
 * MySQL ENUM column (see this repo's own schema dump), used here rather
 * than a synthetic table since getEnums() parses a live `DESC` result.
 */
final class DbInfoTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private DbInfo $dbInfo;

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

        $this->dbInfo = new DbInfo(DbConnection::build());
    }

    public function test_version_returns_a_real_non_empty_mysql_version_string(): void
    {
        $version = $this->dbInfo->version();

        self::assertNotSame('', $version);
        // A real MySQL/MariaDB version string always starts with a digit.
        self::assertMatchesRegularExpression('/^\d/', $version);
    }

    public function test_get_enums_parses_a_real_enum_columns_definition(): void
    {
        $options = $this->dbInfo->getEnums(Tables::userInfos(), 'status');

        self::assertSame(['webmaster', 'admin', 'normal', 'generic', 'guest'], $options);
    }

    public function test_get_enums_returns_an_empty_list_for_an_unknown_field(): void
    {
        $options = $this->dbInfo->getEnums(Tables::userInfos(), 'this_field_does_not_exist');

        self::assertSame([], $options);
    }

    public function test_get_enums_produces_a_garbled_result_for_a_non_enum_field(): void
    {
        // Real, pre-existing quirk, confirmed live (not "fixed" here --
        // out of scope for a test-writing pass): getEnums() never checks
        // that the matched column's Type actually starts with "enum(" --
        // it unconditionally applies substr($type, 5, -1) (stripping
        // "enum(" and a trailing ")") to whatever DESC reports. For
        // user_id ("mediumint unsigned", a real non-enum column), that
        // slice produces the nonsense string "mint unsigne", not an
        // empty result -- every real caller only ever passes a genuinely
        // enum column, so this never surfaces in practice.
        $options = $this->dbInfo->getEnums(Tables::userInfos(), 'user_id');

        self::assertSame(['mint unsigne'], $options);
    }
}
