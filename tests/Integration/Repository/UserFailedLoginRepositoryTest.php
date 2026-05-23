<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration\Repository;

use Doctrine\DBAL\Connection;
use Piwigo\Tests\Integration\IntegrationTestCase;
use Piwigo\Users\LoginThrottle;
use Piwigo\Users\UserFailedLoginRepository;

final class UserFailedLoginRepositoryTest extends IntegrationTestCase
{
    private const string FIXTURE = __DIR__ . '/../../../dev/fixtures/piwigo-17.0.sql';

    private Connection $conn;
    private UserFailedLoginRepository $repo;

    #[\Override]
    protected function setUp(): void
    {
        $this->setUpConnectionFromEnv();
        $this->resetDatabaseFast(self::FIXTURE);
        $this->conn = $this->newDbalConnection();
        $this->repo = new UserFailedLoginRepository($this->conn, 'piwigo_');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->conn->close();
    }

    public function test_insertFailure_increments_recent_count(): void
    {
        $now = new \DateTimeImmutable();
        $this->repo->insertFailure(1, '10.0.0.1', $now);
        $this->repo->insertFailure(1, '10.0.0.1', $now);

        self::assertSame(2, $this->repo->recentFailureCountForUser(1, 900));
    }

    public function test_recentFailureCountForUser_respects_window(): void
    {
        $this->repo->insertFailure(1, '10.0.0.1', new \DateTimeImmutable('-2000 seconds'));
        $this->repo->insertFailure(1, '10.0.0.1', new \DateTimeImmutable());

        self::assertSame(1, $this->repo->recentFailureCountForUser(1, 900));
        self::assertSame(2, $this->repo->recentFailureCountForUser(1, 3600));
    }

    public function test_recentFailureCountForUser_scopes_to_user(): void
    {
        $now = new \DateTimeImmutable();
        $this->repo->insertFailure(1, '10.0.0.1', $now);
        $this->repo->insertFailure(3, '10.0.0.1', $now);

        self::assertSame(1, $this->repo->recentFailureCountForUser(1, 900));
        self::assertSame(1, $this->repo->recentFailureCountForUser(3, 900));
    }

    public function test_deleteForUser_clears_only_that_user(): void
    {
        $now = new \DateTimeImmutable();
        $this->repo->insertFailure(1, '10.0.0.1', $now);
        $this->repo->insertFailure(3, '10.0.0.1', $now);

        $this->repo->deleteForUser(1);

        self::assertSame(0, $this->repo->recentFailureCountForUser(1, 900));
        self::assertSame(1, $this->repo->recentFailureCountForUser(3, 900));
    }

    public function test_purgeOlderThan_deletes_expired_rows(): void
    {
        $this->repo->insertFailure(1, '10.0.0.1', new \DateTimeImmutable('-2000 seconds'));
        $this->repo->insertFailure(1, '10.0.0.1', new \DateTimeImmutable());

        $deleted = $this->repo->purgeOlderThan(new \DateTimeImmutable('-900 seconds'));

        self::assertSame(1, $deleted);
        self::assertSame(1, $this->repo->recentFailureCountForUser(1, 3600));
    }

    public function test_null_user_id_failure_does_not_contribute_to_per_user_count(): void
    {
        $this->repo->insertFailure(null, '10.0.0.1', new \DateTimeImmutable());
        $this->repo->insertFailure(1, '10.0.0.1', new \DateTimeImmutable());

        self::assertSame(1, $this->repo->recentFailureCountForUser(1, 900));
    }

    public function test_LoginThrottle_isLockedOut_crosses_threshold(): void
    {
        $throttle = new LoginThrottle($this->repo, threshold: 5, windowSeconds: 900);

        for ($i = 0; $i < 4; $i++) {
            $throttle->recordFailure(1, '10.0.0.1');
        }
        self::assertFalse($throttle->isLockedOut(1));
        $throttle->recordFailure(1, '10.0.0.1');
        self::assertTrue($throttle->isLockedOut(1));

        $throttle->clearFailures(1);
        self::assertFalse($throttle->isLockedOut(1));
    }

    public function test_LoginThrottle_purgeExpired_drops_old_rows(): void
    {
        $this->repo->insertFailure(1, '10.0.0.1', new \DateTimeImmutable('-3000 seconds'));
        $this->repo->insertFailure(1, '10.0.0.1', new \DateTimeImmutable());
        $throttle = new LoginThrottle($this->repo, threshold: 5, windowSeconds: 900);

        $throttle->purgeExpired();

        self::assertSame(1, $this->repo->recentFailureCountForUser(1, 3600));
    }
}
