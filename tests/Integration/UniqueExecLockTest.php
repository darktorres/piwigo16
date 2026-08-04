<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Piwigo\Core\UniqueExecLock;
use Piwigo\Db\DbConnection;

/**
 * Phase 4 Item 18: rewritten for the GET_LOCK()/RELEASE_LOCK()/
 * IS_USED_LOCK()-based redesign -- the old config-table-row-backed
 * mechanism (INSERT IGNORE + re-SELECT race resolution, a manual
 * time()-based staleness timeout) no longer exists, so its own dedicated
 * "stale lock recovery" test no longer applies (a GET_LOCK() lock has no
 * staleness concept: it clears automatically the instant its owning
 * connection closes). "another exec already holds the lock" now needs a
 * genuinely separate physical connection to simulate real cross-process
 * contention -- a single connection can hold any number of distinct named
 * locks simultaneously without contending with itself.
 */
final class UniqueExecLockTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

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

        $this->token = 'test_lock_' . bin2hex(random_bytes(4));
    }

    #[\Override]
    protected function tearDown(): void
    {
        UniqueExecLock::ends($this->token);
        UniqueExecLock::reset();
        parent::tearDown();
    }

    public function test_is_running_is_false_when_no_lock_is_held(): void
    {
        self::assertFalse(UniqueExecLock::isRunning($this->token));
    }

    public function test_begins_acquires_the_lock(): void
    {
        self::assertTrue(UniqueExecLock::begins($this->token));
        self::assertTrue(UniqueExecLock::isRunning($this->token));
    }

    public function test_begins_on_the_same_connection_succeeds_again_for_the_same_token(): void
    {
        // MySQL's GET_LOCK() re-acquiring an already-held name on the SAME
        // connection succeeds (it's reentrant per-connection, not a
        // separate contended acquisition) -- real behavior, not a gap in
        // this class's own design.
        self::assertTrue(UniqueExecLock::begins($this->token));
        self::assertTrue(UniqueExecLock::begins($this->token));
    }

    public function test_begins_fails_immediately_when_a_different_connection_already_holds_the_lock(): void
    {
        // Default $timeout = 0 -- must return false right away, not block
        // waiting for the other connection to release it.
        $otherConn = DbConnection::build();

        try {
            $acquiredByOther = $otherConn->fetchOne(
                'SELECT GET_LOCK(?, ?)',
                [$this->lockNameFor($this->token), 1]
            );
            self::assertSame(1, $acquiredByOther);

            self::assertFalse(UniqueExecLock::begins($this->token));
        } finally {
            $otherConn->fetchOne('SELECT RELEASE_LOCK(?)', [$this->lockNameFor($this->token)]);
        }
    }

    public function test_ends_releases_the_lock(): void
    {
        UniqueExecLock::begins($this->token);
        self::assertTrue(UniqueExecLock::isRunning($this->token));

        UniqueExecLock::ends($this->token);

        self::assertFalse(UniqueExecLock::isRunning($this->token));
    }

    public function test_ends_is_a_noop_when_the_lock_was_never_held(): void
    {
        UniqueExecLock::ends($this->token);

        self::assertFalse(UniqueExecLock::isRunning($this->token));
    }

    /**
     * Phase 4 Item 18's own stated central claim, per the plan's own
     * Verification section: a GET_LOCK() lock releases automatically the
     * instant its owning connection closes -- not just after an explicit
     * ends() call -- including an abnormal process death, unlike the old
     * INSERT-IGNORE-row design this replaced (which needed a manual
     * staleness timeout to ever notice). Never directly proven until now;
     * every other test here only exercises the explicit-ends() path.
     */
    public function test_lock_is_released_automatically_when_its_owning_connection_closes_without_calling_ends(): void
    {
        $otherConn = DbConnection::build();

        $acquiredByOther = $otherConn->fetchOne('SELECT GET_LOCK(?, ?)', [$this->lockNameFor($this->token), 1]);
        self::assertSame(1, $acquiredByOther, 'the other connection must genuinely hold the lock first');
        self::assertFalse(UniqueExecLock::begins($this->token), 'contended while the other connection is still open');

        // No RELEASE_LOCK() call -- close the connection outright and drop
        // every reference to it, simulating an abrupt process death rather
        // than a clean ends() call.
        $otherConn->close();
        unset($otherConn);

        self::assertTrue(UniqueExecLock::begins($this->token), 'must be acquirable now that the other connection is gone, with no explicit release');
    }

    /**
     * Mirrors UniqueExecLock's own private lockName() exactly, so this
     * test's separate connection contends for the real same lock name.
     */
    private function lockNameFor(string $tokenName): string
    {
        $prefix = \Piwigo\Db\DbCredentials::current()->prefix;

        return 'piwigo_exec_' . sha1($prefix . ':unique_exec:' . $tokenName);
    }
}
