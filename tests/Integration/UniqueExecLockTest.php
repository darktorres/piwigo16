<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Core\Kernel;
use Piwigo\Core\UniqueExecLock;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;

/**
 * Piwigo\Core\UniqueExecLock -- isRunning() had zero coverage and begins()
 * only partial (see /home/torres/.claude/plans/piped-enchanting-spark.md,
 * Wave 1): the race-losing branch (another exec already holds the token)
 * and the stale-lock timeout-recovery branch were both untested.
 */
final class UniqueExecLockTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private Connection $conn;

    private string $token;

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

        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();
        Kernel::boot();
        CurrentConfigService::set(new ConfigService($this->buildConfigRepository()));

        $this->conn = DbConnection::build();
        $this->token = 'test_lock_' . bin2hex(random_bytes(4));
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->conn->executeStatement(
            'DELETE FROM ' . Tables::config() . ' WHERE param = ?',
            [$this->token . '_running']
        );
        Kernel::reset();
        parent::tearDown();
    }

    public function test_isRunning_is_false_when_no_lock_row_exists(): void
    {
        self::assertFalse(UniqueExecLock::isRunning($this->token));
    }

    public function test_begins_wins_the_race_and_creates_a_lock_row(): void
    {
        $execId = UniqueExecLock::begins($this->token);

        self::assertIsString($execId);
        self::assertSame(8, strlen($execId));
        self::assertTrue(UniqueExecLock::isRunning($this->token));
    }

    public function test_begins_loses_the_race_when_another_exec_already_holds_a_fresh_lock(): void
    {
        $firstExecId = UniqueExecLock::begins($this->token);
        self::assertIsString($firstExecId);

        $secondExecId = UniqueExecLock::begins($this->token, 60);

        self::assertFalse($secondExecId);
        // the first exec's row is still the one present -- the second
        // call's own INSERT IGNORE was a no-op against the existing row.
        self::assertTrue(UniqueExecLock::isRunning($this->token));
    }

    public function test_begins_recovers_a_stale_lock_past_the_timeout_and_wins(): void
    {
        $this->conn->executeStatement(
            "INSERT INTO " . Tables::config() . " (param, value) VALUES (?, ?)",
            [$this->token . '_running', 'stale-exec-' . (time() - 120)]
        );
        self::assertTrue(UniqueExecLock::isRunning($this->token));

        $execId = UniqueExecLock::begins($this->token, 60);

        self::assertIsString($execId);
        $row = $this->conn->fetchOne(
            'SELECT value FROM ' . Tables::config() . ' WHERE param = ?',
            [$this->token . '_running']
        );
        self::assertIsString($row);
        self::assertStringStartsWith($execId . '-', $row);
    }

    public function test_ends_removes_the_lock_row(): void
    {
        UniqueExecLock::begins($this->token);
        self::assertTrue(UniqueExecLock::isRunning($this->token));

        UniqueExecLock::ends($this->token);

        self::assertFalse(UniqueExecLock::isRunning($this->token));
    }
}
