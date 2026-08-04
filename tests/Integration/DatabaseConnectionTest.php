<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

/**
 * Restores the P2 verification step that was planned ("IntegrationTestCase-based
 * smoke passes against piwigo_test") but never actually landed as a concrete test —
 * tests/Integration/ only ever held the shared base class, so `pest --testsuite
 * Integration` silently exited non-zero with "No tests found". This is the minimal
 * real smoke test that base class was written for.
 */
final class DatabaseConnectionTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        if (!self::$fixtureReady) {
            $this->resetDatabase();
            $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }
    }

    public function test_it_connects_to_the_test_database(): void
    {
        self::assertNotSame('', $this->dbName, 'PIWIGO_DB_BASE must be set in .env.test');

        if ($this->dbDriver === 'pgsql') {
            // newPgsqlConnection() itself already asserts a successful
            // connect (its own return type has no false/failure state to
            // check here, unlike mysqli's own connect_errno).
            pg_close($this->newPgsqlConnection($this->dbName));

            return;
        }

        $db = $this->newMysqli($this->dbName);
        self::assertSame(0, $db->connect_errno, $db->connect_error ?? '');
        $db->close();
    }

    public function test_it_reads_the_loaded_fixture(): void
    {
        $count = $this->queryScalar(sprintf('SELECT COUNT(*) FROM %simages', $this->dbPrefix));
        self::assertGreaterThan(0, (int) $count, 'Expected the committed fixture to seed at least one image row');
    }

    public function test_it_reports_the_active_test_env_header(): void
    {
        self::assertSame(['X-Piwigo-Env: test'], $this->testHeader());
    }
}
