<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Piwigo\Auth\PasswordResetRequestEntity;
use Piwigo\Auth\PasswordResetRequestRepository;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\TypedRepository;

/**
 * [P44-L] Piwigo\Auth\PasswordResetRequestRepository -- same shape as
 * {@see \Piwigo\Auth\UserFailedLoginRepository}, see
 * UserFailedLoginRepositoryTest.php's own docblock for the shared
 * reasoning (real DB, no HTTP, append-only table, TEST-NET-3 IP scoping
 * per test to avoid collisions).
 */
function passwordResetRequestTestRepo(): PasswordResetRequestRepository
{
    $conn = DbConnection::build();
    $repo = TypedRepository::narrow(EntityManagerFactory::build($conn)->getRepository(PasswordResetRequestEntity::class), PasswordResetRequestRepository::class);

    return $repo;
}

function passwordResetRequestTestRepoForConn(Connection $conn): PasswordResetRequestRepository
{
    return TypedRepository::narrow(EntityManagerFactory::build($conn)->getRepository(PasswordResetRequestEntity::class), PasswordResetRequestRepository::class);
}

function passwordResetRequestTestPurgeIp(Connection $conn, string $ip): void
{
    $conn->createQueryBuilder()
        ->delete('password_reset_requests')
        ->where('ip = :ip')
        ->setParameter('ip', $ip)
        ->executeStatement();
}

test('recordRequest() persists a real user_id and ip', function (): void {
    $conn = DbConnection::build();
    $ip = '203.0.113.21';

    try {
        passwordResetRequestTestRepo()->recordRequest(1, $ip, '2026-08-01 12:00:00');

        $row = $conn->createQueryBuilder()
            ->select('user_id', 'ip', 'requested_at')
            ->from('password_reset_requests')
            ->where('ip = :ip')
            ->setParameter('ip', $ip)
            ->fetchAssociative();

        expect($row)
            ->toBe([
                'user_id' => 1,
                'ip' => $ip,
                'requested_at' => '2026-08-01 12:00:00',
            ]);
    } finally {
        passwordResetRequestTestPurgeIp($conn, $ip);
    }
});

test('recordRequest() accepts a null user_id for an unresolvable username/email', function (): void {
    $conn = DbConnection::build();
    $ip = '203.0.113.22';

    try {
        passwordResetRequestTestRepo()->recordRequest(null, $ip, '2026-08-01 12:00:00');

        $userId = $conn->createQueryBuilder()
            ->select('user_id')
            ->from('password_reset_requests')
            ->where('ip = :ip')
            ->setParameter('ip', $ip)
            ->fetchOne();

        expect($userId)
            ->toBeNull();
    } finally {
        passwordResetRequestTestPurgeIp($conn, $ip);
    }
});

test('recordRequest() gracefully stores an empty-string ip (REMOTE_ADDR unavailable) instead of throwing', function (): void {
    $conn = DbConnection::build();

    // ip_address_graceful round-trips '' to/from null -- clean up by
    // requested_at instead of by (empty) ip.
    $marker = '2099-01-02 03:04:06';

    try {
        passwordResetRequestTestRepo()->recordRequest(1, '', $marker);

        $ip = $conn->createQueryBuilder()
            ->select('ip')
            ->from('password_reset_requests')
            ->where('requested_at = :at')
            ->setParameter('at', $marker)
            ->fetchOne();

        expect($ip)
            ->toBe('');
    } finally {
        $conn->createQueryBuilder()
            ->delete('password_reset_requests')
            ->where('requested_at = :at')
            ->setParameter('at', $marker)
            ->executeStatement();
    }
});

test('countRecentByUserId() counts only requests for that user at or after the threshold', function (): void {
    $conn = DbConnection::build();
    $ip = '203.0.113.23';
    $repo = passwordResetRequestTestRepoForConn($conn);
    $repo->recordRequest(1, $ip, '2026-08-01 10:00:00');
    $repo->recordRequest(1, $ip, '2026-08-01 11:00:00');
    $repo->recordRequest(3, $ip, '2026-08-01 11:00:00');

    try {
        expect($repo->countRecentByUserId(1, '2026-08-01 10:30:00'))
            ->toBe(1)
            ->and($repo->countRecentByUserId(1, '2026-08-01 00:00:00'))
            ->toBe(2)
            ->and($repo->countRecentByUserId(3, '2026-08-01 00:00:00'))
            ->toBe(1);
    } finally {
        passwordResetRequestTestPurgeIp($conn, $ip);
    }
});

test('countRecentByIp() counts only requests from that ip at or after the threshold', function (): void {
    $conn = DbConnection::build();
    $ip = '203.0.113.24';
    $otherIp = '203.0.113.25';

    try {
        $repo = passwordResetRequestTestRepo();
        $repo->recordRequest(1, $ip, '2026-08-01 10:00:00');
        $repo->recordRequest(1, $otherIp, '2026-08-01 10:00:00');

        expect($repo->countRecentByIp($ip, '2026-08-01 00:00:00'))
            ->toBe(1)
            ->and($repo->countRecentByIp($otherIp, '2026-08-01 00:00:00'))
            ->toBe(1)
            ->and($repo->countRecentByIp($ip, '2026-08-01 10:30:00'))
            ->toBe(0);
    } finally {
        passwordResetRequestTestPurgeIp($conn, $ip);
        passwordResetRequestTestPurgeIp($conn, $otherIp);
    }
});

test('countRecentByIp() returns 0 for an unparseable ip without querying (defensive branch)', function (): void {
    $repo = passwordResetRequestTestRepo();

    expect($repo->countRecentByIp('not-an-ip', '2026-08-01 00:00:00'))
        ->toBe(0);
});

test('purgeOlderThan() deletes rows requested before the threshold, keeps rows at or after it, and returns the deleted count', function (): void {
    $conn = DbConnection::build();
    $oldIp = '203.0.113.26';
    $recentIp = '203.0.113.27';

    try {
        $repo = passwordResetRequestTestRepo();
        $repo->recordRequest(1, $oldIp, '2020-01-01 00:00:00');
        $repo->recordRequest(1, $oldIp, '2020-01-02 00:00:00');
        $repo->recordRequest(1, $recentIp, '2026-08-01 00:00:00');

        $deleted = $repo->purgeOlderThan('2025-01-01 00:00:00');

        // Global purge (no ip filter) -- >=2 rather than ===2 so this
        // doesn't depend on no other old-dated row existing anywhere else
        // in the shared test DB at the same time.
        expect($deleted)
            ->toBeGreaterThanOrEqual(2)
            ->and($repo->countRecentByIp($oldIp, '1970-01-01 00:00:00'))
            ->toBe(0)
            ->and($repo->countRecentByIp($recentIp, '1970-01-01 00:00:00'))
            ->toBe(1);
    } finally {
        passwordResetRequestTestPurgeIp($conn, $oldIp);
        passwordResetRequestTestPurgeIp($conn, $recentIp);
    }
});
