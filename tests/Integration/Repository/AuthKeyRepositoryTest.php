<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration\Repository;

use Doctrine\DBAL\Connection;
use Piwigo\Auth\AuthKeyRepository;
use Piwigo\Tests\Integration\IntegrationTestCase;

/**
 * Real-DB integration tests for {@see AuthKeyRepository}. The fixture
 * seeds no auth-key rows; each test inserts its own. The user_infos
 * companion rows for the seeded users (1, 2) are required for the JOIN
 * path in findAuthKeyDetails.
 */
final class AuthKeyRepositoryTest extends IntegrationTestCase
{
    private const string FIXTURE = __DIR__ . '/../../../dev/fixtures/piwigo-17.0.sql';

    private Connection $conn;
    private AuthKeyRepository $repo;

    #[\Override]
    protected function setUp(): void
    {
        $this->setUpConnectionFromEnv();
        $this->resetDatabaseFast(self::FIXTURE);
        $this->conn = $this->newDbalConnection();
        $this->repo = new AuthKeyRepository($this->conn, 'piwigo_');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->conn->close();
    }

    private function insertKey(int $userId, string $key, string $expiredOn = '2099-01-01 00:00:00'): int
    {
        return $this->repo->insertKey([
            'auth_key'   => $key,
            'user_id'    => $userId,
            'created_on' => '2026-05-18 12:00:00',
            'expired_on' => $expiredOn,
            'key_type'   => 'session',
        ]);
    }

    public function test_insertKey_then_existsByKey_round_trips(): void
    {
        $this->insertKey(1, 'auth-key-alpha');

        self::assertTrue($this->repo->existsByKey('auth-key-alpha'));
        self::assertFalse($this->repo->existsByKey('nonexistent'));
    }

    public function test_existsByKeyAndUser_scopes_to_owner(): void
    {
        $this->insertKey(1, 'shared-shape');

        self::assertTrue($this->repo->existsByKeyAndUser('shared-shape', 1));
        self::assertFalse($this->repo->existsByKeyAndUser('shared-shape', 3), 'key owned by user 1, not user 3');
    }

    public function test_revokeKey_sets_revoked_on(): void
    {
        $this->insertKey(1, 'will-revoke');

        $this->repo->revokeKey(1, 'will-revoke', '2026-05-18 13:00:00');

        $revokedAt = $this->conn->executeQuery(
            'SELECT revoked_on FROM piwigo_user_auth_keys WHERE auth_key = ?',
            ['will-revoke']
        )->fetchOne();
        self::assertSame('2026-05-18 13:00:00', $revokedAt);
    }

    /**
     * fk_user_auth_keys_user_id ON DELETE CASCADE: deleting the owner user
     * must remove their auth-key rows.
     */
    public function test_user_delete_cascades_to_auth_keys(): void
    {
        $this->insertKey(3, 'cascade-target');
        self::assertTrue($this->repo->existsByKey('cascade-target'), 'precondition');

        $this->conn->executeStatement('DELETE FROM piwigo_users WHERE id = 3');

        self::assertFalse($this->repo->existsByKey('cascade-target'));
    }
}
