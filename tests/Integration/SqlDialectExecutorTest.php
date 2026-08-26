<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Override;
use Piwigo\Db\DbConnection;
use Piwigo\Db\SqlDialectExecutor;
use Piwigo\Tests\Support\DbTransactionTestOverride;

/**
 * SqlDialectExecutor's real DB round-trips -- no fixture dependency,
 * every method here is pure date arithmetic computed server-side.
 */
final class SqlDialectExecutorTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private Connection $conn;

    private SqlDialectExecutor $executor;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        if (! self::$fixtureReady) {
            $this->reimportFixtureIfSharedStateUnknown(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        // PILOT (transaction-wrapping rollout): begin before any container
        // resolution below -- see ApiKeyServiceGetAvailableTest.php's own
        // comment for the full reasoning.
        DbTransactionTestOverride::begin();

        $this->conn = DbConnection::build();
        $this->executor = new SqlDialectExecutor($this->conn);
    }

    #[Override]
    protected function tearDown(): void
    {
        DbTransactionTestOverride::rollback();
        parent::tearDown();
    }

    public function testFetchRecentCutoffDateReturnsARealComputedDateString(): void
    {
        $result = $this->executor->fetchRecentCutoffDate(7);

        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}/', $result);
    }

    public function testFetchTomorrowReturnsNowPlusOneDay(): void
    {
        // Computed against the DB server's own NOW(), not PHP's local
        // clock -- the two processes aren't guaranteed to share a
        // timezone (confirmed live: this box's PHP CLI and MySQL server
        // clocks sit a full calendar day apart), and fetchTomorrow()
        // itself is entirely server-side ADDDATE(NOW(), ...) arithmetic.
        $dbNow = $this->conn->fetchOne('SELECT NOW()');
        self::assertIsString($dbNow);
        $expected = new DateTimeImmutable($dbNow . ' +1 day');

        $result = $this->executor->fetchTomorrow();

        self::assertSame($expected->format('Y-m-d'), substr($result, 0, 10));
    }

    public function testFetchFutureDatesForReturnsNowPlusNDaysKeyedByDayCount(): void
    {
        $dbNow = $this->conn->fetchOne('SELECT NOW()');
        self::assertIsString($dbNow);

        $result = $this->executor->fetchFutureDatesFor([1, 7, 30]);

        self::assertSame([1, 7, 30], array_keys($result));
        foreach ([1, 7, 30] as $days) {
            $expected = new DateTimeImmutable($dbNow . " +{$days} days");
            $value = $result[$days];
            self::assertIsString($value);
            self::assertSame($expected->format('Y-m-d'), substr($value, 0, 10));
        }
    }

    public function testFetchFutureDatesForReturnsEmptyForAnEmptyInputWithoutQuerying(): void
    {
        self::assertSame([], $this->executor->fetchFutureDatesFor([]));
    }

    // fetchFutureDatesFor()'s own `if ($row === false) { return []; }`
    // guard (right after fetchAssociative()) is not chased here: the
    // built query is always a bare `SELECT <computed columns>` with no
    // FROM clause -- confirmed live (and matching MySQL's own documented
    // behavior for a FROM-less SELECT) that this always returns exactly
    // one row, for any non-empty $days, so fetchAssociative() can never
    // genuinely return false through this call shape. A real connection
    // failure mid-query would throw a Doctrine exception instead of
    // returning false, so it's not reachable that way either.
}
