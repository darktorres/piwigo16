<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;

/**
 * Restores a verification step that was planned ("IntegrationTestCase-based
 * smoke passes against piwigo_test") but never actually landed as a concrete test —
 * tests/Integration/ only ever held the shared base class, so `pest --testsuite
 * Integration` silently exited non-zero with "No tests found". This is the minimal
 * real smoke test that base class was written for.
 */
final class DatabaseConnectionTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        // This class runs unwrapped, real-commit tests against the
        // shared fixture DB -- marks the shared trust flag dirty so any
        // DbTransactionTestOverride-wrapped class running after this one
        // knows it can't skip its own reimport. See
        // IntegrationTestCase::$sharedFixtureKnownPristine's own docblock.
        IntegrationTestCase::markSharedFixtureDirty();
        $this->setUpConnectionFromEnv();

        if (! self::$fixtureReady) {
            $this->resetDatabase();
            $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }
    }

    public function testItConnectsToTheTestDatabase(): void
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

    public function testItReadsTheLoadedFixture(): void
    {
        $count = $this->queryScalar('SELECT COUNT(*) FROM images');
        self::assertGreaterThan(0, (int) $count, 'Expected the committed fixture to seed at least one image row');
    }

    public function testItReportsTheActiveTestEnvHeader(): void
    {
        self::assertSame(['X-Piwigo-Env: test'], $this->testHeader());
    }
}
