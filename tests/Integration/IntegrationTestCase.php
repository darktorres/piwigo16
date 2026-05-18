<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Piwigo\Core\InstallSentinel;
use Symfony\Component\Process\Process;

/**
 * Shared database infrastructure for integration tests.
 *
 * Requires these environment variables (loaded from .env.test by
 * tests/bootstrap.php):
 *
 *   PIWIGO_DB_HOST      MySQL host reachable by the test runner
 *   PIWIGO_DB_PORT      MySQL port (default: 3306)
 *   PIWIGO_DB_USER      MySQL user
 *   PIWIGO_DB_PASSWORD  MySQL password
 *   PIWIGO_DB_BASE      Test database name — never the production DB
 *   PIWIGO_BASE_URL     Base URL of the running Apache Piwigo instance
 *
 * Apache request routing: every HTTP call from these tests carries the
 * `X-Piwigo-Env: test` header (see TEST_HEADER), which makes the runtime
 * read .env.test and use local/.installed.test instead of the prod
 * counterparts. Tests therefore never swap files on disk — prod config
 * stays untouched even if a test crashes.
 */
abstract class IntegrationTestCase extends TestCase
{
    /** Header line array suitable for CURLOPT_HTTPHEADER. */
    protected const array TEST_HEADER = ['X-Piwigo-Env: test'];

    protected string $dbHost = '';
    protected int $dbPort = 0;
    protected string $dbUser = '';
    protected string $dbPass = '';
    protected string $dbName = '';
    protected string $baseUrl = '';

    /**
     * Per-process cache of fixture-path => true once a template DB has
     * been built from that fixture. The template lives in a parallel
     * database named "<dbName>__tpl" and gets reused across every test
     * in the run.
     *
     * @var array<string, true>
     */
    private static array $templateBuilt = [];

    /**
     * Marks that the test database schema may diverge from the template
     * (because a test ran DDL: ALTER TABLE, OPTIMIZE TABLE on InnoDB,
     * TRUNCATE, etc.). The next {@see resetDatabaseFast()} call falls
     * back to a full DROP DATABASE + fixture reload instead of the
     * fast DELETE + INSERT-SELECT path.
     */
    private static bool $schemaDirty = true;

    protected function setUpConnectionFromEnv(): void
    {
        $this->dbHost  = (string) getenv('PIWIGO_DB_HOST');
        $this->dbPort  = (int)    getenv('PIWIGO_DB_PORT');
        $this->dbUser  = (string) getenv('PIWIGO_DB_USER');
        $this->dbPass  = (string) getenv('PIWIGO_DB_PASSWORD');
        $this->dbName  = (string) getenv('PIWIGO_DB_BASE');
        $this->baseUrl = rtrim((string) getenv('PIWIGO_BASE_URL'), '/');
    }

    /**
     * Tests that drive the HTTP entrypoint (install.php, /ws, etc.) call
     * this in setUp so they fail loudly with a clear message — not with a
     * cryptic curl HTTP 0 — when .env.test omits PIWIGO_BASE_URL.
     * DB-only integration tests do not need this guard.
     */
    protected function requireBaseUrl(): void
    {
        if ($this->baseUrl === '') {
            self::fail('PIWIGO_BASE_URL is not set in .env.test — integration tests need a running web server.');
        }
    }

    protected function resetDatabase(): void
    {
        $db = $this->newMysqli('');
        $db->query("DROP DATABASE IF EXISTS `{$this->dbName}`");
        $db->query("CREATE DATABASE `{$this->dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $db->close();
    }

    /**
     * Fast per-test reset that bypasses the 41×CREATE TABLE cost of
     * {@see resetDatabase()} + {@see loadFixture()}. The first call per
     * fixture builds a "<dbName>__tpl" template database from $fixturePath
     * and reloads the test DB from it; subsequent calls do
     * `DELETE FROM` + `INSERT … SELECT … FROM <template>.<table>` for every
     * table, then realign AUTO_INCREMENT counters. Cost: ~150–250 ms per
     * test versus ~2.3 s for the full reload, with the schema preserved
     * across resets.
     *
     * Tests that mutate schema (ALTER TABLE column changes, etc.) must
     * call {@see markSchemaDirty()} so the next setUp falls back to the
     * slow full-reload path. OPTIMIZE TABLE on InnoDB rebuilds storage
     * but preserves the logical schema, so it does not need this.
     */
    protected function resetDatabaseFast(string $fixturePath): void
    {
        $templateDb = $this->dbName . '__tpl';

        if (!isset(self::$templateBuilt[$fixturePath])) {
            $this->buildTemplateDatabase($templateDb, $fixturePath);
            self::$templateBuilt[$fixturePath] = true;
            self::$schemaDirty = true;
        }

        if (self::$schemaDirty) {
            // Replay the fixture against the test DB so foreign-key
            // constraints (which CREATE TABLE LIKE does not copy) are
            // installed. Slow path: ~2.4 s. Subsequent tests in the
            // class take the fast resetDataFromTemplate path below.
            $this->resetDatabase();
            $this->loadFixture($fixturePath);
            self::$schemaDirty = false;
            return;
        }

        $this->resetDataFromTemplate($templateDb);
    }

    /**
     * Tests that mutate schema call this in tearDown so the next test
     * does a full DROP DATABASE + fixture reload, rebuilding the schema
     * from scratch. See {@see resetDatabaseFast()}.
     */
    protected function markSchemaDirty(): void
    {
        self::$schemaDirty = true;
    }

    private function buildTemplateDatabase(string $templateDb, string $fixturePath): void
    {
        $db = $this->newMysqli('');
        $db->query("DROP DATABASE IF EXISTS `{$templateDb}`");
        $db->query("CREATE DATABASE `{$templateDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $db->close();

        $originalDbName = $this->dbName;
        $this->dbName = $templateDb;
        try {
            $this->loadFixture($fixturePath);
        } finally {
            $this->dbName = $originalDbName;
        }
    }

    private function resetDataFromTemplate(string $templateDb): void
    {
        $db = $this->newMysqli($this->dbName);

        $res = $db->query(
            'SELECT TABLE_NAME, AUTO_INCREMENT FROM information_schema.TABLES '
            . "WHERE TABLE_SCHEMA = '{$templateDb}' AND TABLE_TYPE = 'BASE TABLE'"
        );
        self::assertInstanceOf(\mysqli_result::class, $res);
        /** @var list<array{0: string, 1: ?string}> $rows */
        $rows = [];
        while (($row = $res->fetch_row()) !== null) {
            self::assertIsString($row[0]);
            $ai = $row[1];
            // newMysqli() does not set MYSQLI_OPT_INT_AND_FLOAT_NATIVE,
            // so AUTO_INCREMENT comes back as string|null.
            self::assertTrue($ai === null || is_string($ai));
            $rows[] = [$row[0], $ai];
        }
        $res->close();

        $parts = ['SET FOREIGN_KEY_CHECKS = 0'];
        foreach ($rows as [$t, ]) {
            $parts[] = "DELETE FROM `{$this->dbName}`.`{$t}`";
        }
        foreach ($rows as [$t, ]) {
            $parts[] = "INSERT INTO `{$this->dbName}`.`{$t}` SELECT * FROM `{$templateDb}`.`{$t}`";
        }
        foreach ($rows as [$t, $ai]) {
            if ($ai !== null) {
                $parts[] = "ALTER TABLE `{$this->dbName}`.`{$t}` AUTO_INCREMENT = {$ai}";
            }
        }
        $parts[] = 'SET FOREIGN_KEY_CHECKS = 1';
        $this->multiQuery($db, implode('; ', $parts));

        $db->close();
    }

    private function multiQuery(\mysqli $db, string $sql): void
    {
        if (!$db->multi_query($sql)) {
            self::fail('multi_query failed: ' . $db->error);
        }
        do {
            if ($result = $db->store_result()) {
                $result->free();
            }
            if (!$db->more_results()) {
                break;
            }
        } while ($db->next_result());

        if ($db->errno !== 0) {
            self::fail('multi_query error: ' . $db->error);
        }
    }

    protected function loadFixture(string $path): void
    {
        self::assertFileExists($path, 'Fixture file must exist');

        $isSocket = str_starts_with($this->dbHost, '/');
        $args = ['mysql', "-u{$this->dbUser}"];
        if ($this->dbPass !== '') {
            $args[] = "-p{$this->dbPass}";
        }
        if ($isSocket) {
            $args[] = "--socket={$this->dbHost}";
        } else {
            $args[] = "-h{$this->dbHost}";
            $args[] = "-P{$this->dbPort}";
        }
        $args[] = $this->dbName;

        $proc = new Process($args);
        $proc->setInput((string) file_get_contents($path));
        $proc->mustRun();
    }

    /**
     * Marks the test runtime as installed so subsequent HTTP requests
     * skip the install redirect. InstallSentinel writes
     * `local/.installed.test` (TestMode is active in CLI bootstrap).
     * Integration tests bypass the install.php form by loading SQL
     * fixtures directly, so they own the sentinel lifecycle.
     */
    protected function markTestInstalled(): void
    {
        InstallSentinel::markInstalled(\Piwigo\Core\Paths::fromRoot(dirname(__DIR__, 2)));
    }

    private function newMysqli(string $dbName): \mysqli
    {
        if (str_starts_with($this->dbHost, '/')) {
            return new \mysqli('localhost', $this->dbUser, $this->dbPass, $dbName, 0, $this->dbHost);
        }
        return new \mysqli($this->dbHost, $this->dbUser, $this->dbPass, $dbName, $this->dbPort);
    }

    /**
     * Build a DBAL connection to the test database from the env-sourced
     * credentials populated by {@see self::setUpConnectionFromEnv()}.
     * Used by the per-Repository integration tests so they can construct
     * Repository instances without booting the DI container.
     */
    protected function newDbalConnection(): Connection
    {
        $params = [
            'driver'   => 'mysqli',
            'user'     => $this->dbUser,
            'password' => $this->dbPass,
            'dbname'   => $this->dbName,
            'charset'  => 'utf8mb4',
            'driverOptions' => [
                MYSQLI_OPT_INT_AND_FLOAT_NATIVE => true,
            ],
        ];
        if (str_starts_with($this->dbHost, '/')) {
            $params['unix_socket'] = $this->dbHost;
        } else {
            $params['host'] = $this->dbHost;
            $params['port'] = $this->dbPort;
        }
        return DriverManager::getConnection($params);
    }

    protected function queryScalar(string $sql): string
    {
        $db = $this->newMysqli($this->dbName);
        $result = $db->query($sql);
        self::assertInstanceOf(\mysqli_result::class, $result);
        $row = $result->fetch_row();
        $db->close();
        self::assertIsArray($row);
        self::assertIsString($row[0]);
        return $row[0];
    }
}
