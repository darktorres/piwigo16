<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Piwigo\Db\DbConnection;
use Piwigo\Db\DbInfo;

/**
 * Piwigo\Db\DbInfo -- had zero dedicated coverage (see /home/torres/.claude/
 * plans/piped-enchanting-spark.md, Wave 1).
 *
 * pgsql-support campaign: `getEnums()` itself was deleted once its last
 * real caller was retyped onto a real PHP enum ({@see
 * \Piwigo\Category\CategoryStatus}/{@see \Piwigo\History\HistoryImageType}/
 * {@see \Piwigo\PluginConfig\PluginState}, alongside the pre-existing
 * {@see \Piwigo\Users\UserStatus}) -- its own 3 dedicated tests went with
 * it.
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

}
