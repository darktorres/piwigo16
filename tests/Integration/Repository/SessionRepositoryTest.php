<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration\Repository;

use Doctrine\DBAL\Connection;
use Piwigo\Session\SessionRepository;
use Piwigo\Tests\Integration\IntegrationTestCase;

/**
 * Real-DB integration tests for {@see SessionRepository}. The fixture
 * seeds no session rows; each test inserts its own via write().
 */
final class SessionRepositoryTest extends IntegrationTestCase
{
    private const string FIXTURE = __DIR__ . '/../../../dev/fixtures/piwigo-17.0.sql';

    private Connection $conn;
    private SessionRepository $repo;

    #[\Override]
    protected function setUp(): void
    {
        $this->setUpConnectionFromEnv();
        $this->resetDatabaseFast(self::FIXTURE);
        $this->conn = $this->newDbalConnection();
        $this->repo = new SessionRepository($this->conn, 'piwigo_');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->conn->close();
    }

    public function test_write_and_read_round_trip(): void
    {
        $this->repo->write('session-alpha', 'pwg_uid|i:3;');

        self::assertSame('pwg_uid|i:3;', $this->repo->read('session-alpha'));
    }

    public function test_read_returns_empty_for_missing_session(): void
    {
        self::assertSame('', $this->repo->read('does-not-exist'));
    }

    public function test_write_is_REPLACE_INTO_idempotent_on_id(): void
    {
        $this->repo->write('session-beta', 'first-payload');
        $this->repo->write('session-beta', 'second-payload');

        self::assertSame('second-payload', $this->repo->read('session-beta'));

        // Exactly one row for the composite id.
        $count = $this->conn->executeQuery(
            'SELECT COUNT(*) FROM piwigo_sessions WHERE id = ?',
            ['session-beta']
        )->fetchOne();
        self::assertSame(1, $count);
    }

    public function test_destroy_removes_the_row(): void
    {
        $this->repo->write('session-gamma', 'payload');
        $this->repo->destroy('session-gamma');

        self::assertSame('', $this->repo->read('session-gamma'));
    }

    public function test_deleteByUserId_matches_pwg_uid_pattern(): void
    {
        $this->repo->write('s-user-3', 'pwg_uid|i:3;extra|s:5:"hello";');
        $this->repo->write('s-user-4', 'pwg_uid|i:4;');
        $this->repo->write('s-unrelated', 'no_uid_here');

        $this->repo->deleteByUserId(3);

        self::assertSame('', $this->repo->read('s-user-3'), 'user 3 session removed');
        self::assertSame('pwg_uid|i:4;', $this->repo->read('s-user-4'), 'user 4 session kept');
        self::assertSame('no_uid_here', $this->repo->read('s-unrelated'), 'unrelated session kept');
    }
}
