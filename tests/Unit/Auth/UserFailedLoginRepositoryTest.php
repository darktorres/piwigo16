<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Piwigo\Auth\UserFailedLoginEntity;
use Piwigo\Auth\UserFailedLoginRepository;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;

/**
 * Piwigo\Auth\UserFailedLoginRepository -- has no dedicated Integration
 * test file of its own (spec ported down from tests/Integration/
 * AuthServiceTest.php + MaintenancePurgeFailedLoginsCommandTest.php,
 * which exercise it only indirectly through AuthService::pwgLogin()'s/
 * the maintenance command's own real flows). Real DB, no HTTP -- same
 * ImageRepositoryTest.php precedent as every other Unit-suite Repository
 * test. Table is append-only (surrogate auto-increment PK, no natural
 * unique key) -- every test scopes cleanup to its own reserved
 * TEST-NET-3 (RFC 5737) IP so it can't collide with any other row.
 */
function userFailedLoginTestRepo(): UserFailedLoginRepository
{
    $conn = DbConnection::build();
    $repo = EntityManagerFactory::build($conn)->getRepository(UserFailedLoginEntity::class);

    return $repo;
}

function userFailedLoginTestRepoForConn(Connection $conn): UserFailedLoginRepository
{
    return EntityManagerFactory::build($conn)->getRepository(UserFailedLoginEntity::class);
}

function userFailedLoginTestPurgeIp(Connection $conn, string $ip): void
{
    $conn->createQueryBuilder()
        ->delete('user_failed_logins')
        ->where('ip = :ip')
        ->setParameter('ip', $ip)
        ->executeStatement();
}

test('recordFailure() persists a real user_id and ip', function (): void {
    $conn = DbConnection::build();
    $ip = '203.0.113.11';

    try {
        userFailedLoginTestRepo()->recordFailure(1, $ip, '2026-08-01 12:00:00');

        $row = $conn->createQueryBuilder()
            ->select('user_id', 'ip', 'attempted_at')
            ->from('user_failed_logins')
            ->where('ip = :ip')
            ->setParameter('ip', $ip)
            ->fetchAssociative();

        expect($row)
            ->toBe([
                'user_id' => 1,
                'ip' => $ip,
                'attempted_at' => '2026-08-01 12:00:00',
            ]);
    } finally {
        userFailedLoginTestPurgeIp($conn, $ip);
    }
});

test('recordFailure() accepts a null user_id for an unknown-username attempt', function (): void {
    $conn = DbConnection::build();
    $ip = '203.0.113.12';

    try {
        userFailedLoginTestRepo()->recordFailure(null, $ip, '2026-08-01 12:00:00');

        $userId = $conn->createQueryBuilder()
            ->select('user_id')
            ->from('user_failed_logins')
            ->where('ip = :ip')
            ->setParameter('ip', $ip)
            ->fetchOne();

        expect($userId)
            ->toBeNull();
    } finally {
        userFailedLoginTestPurgeIp($conn, $ip);
    }
});

test('recordFailure() gracefully stores an empty-string ip (REMOTE_ADDR unavailable) instead of throwing', function (): void {
    $conn = DbConnection::build();

    // ip_address_graceful round-trips '' to/from null -- clean up by
    // attempted_at instead of by (empty) ip.
    $marker = '2099-01-02 03:04:05';

    try {
        userFailedLoginTestRepo()->recordFailure(1, '', $marker);

        $ip = $conn->createQueryBuilder()
            ->select('ip')
            ->from('user_failed_logins')
            ->where('attempted_at = :at')
            ->setParameter('at', $marker)
            ->fetchOne();

        expect($ip)
            ->toBe('');
    } finally {
        $conn->createQueryBuilder()
            ->delete('user_failed_logins')
            ->where('attempted_at = :at')
            ->setParameter('at', $marker)
            ->executeStatement();
    }
});

test('countRecentByUserId() counts only attempts for that user at or after the threshold', function (): void {
    // Both countRecentByUserId()'s and countRecentByIp()'s own (int)
    // casts are confirmed-equivalent mutations on this driver --
    // getSingleScalarResult() on a COUNT() DQL query already returns a
    // real, native PHP int (confirmed live via a raw, uncast
    // createQueryBuilder() COUNT() call), same root cause documented in
    // ImageRepositoryTest.php/ApiKeyRepositoryTest.php. The strict
    // toBe(int) assertions throughout this file would already fail if
    // this driver ever started returning a numeric string instead.
    //
    // Transaction-wrapped -- countRecentByUserId() has no ip scoping at
    // all (by design: it's the user-scoped lockout check), and its own
    // '2026-08-01 00:00:00' threshold here is exactly
    // PIWIGO_TEST_NOW's frozen value, i.e. "every user_id=1 row that
    // has ever existed under the frozen clock" -- AuthServiceTest.php's
    // own pwgLogin()-triggered rows for user_id=1 (ip='', a different
    // ip, so unaffected by this file's own ip-scoped cleanup) share
    // that exact same timestamp and were observed inflating this count
    // under --parallel; confirmed live via a 5-run composer test loop.
    $conn = DbConnection::build();
    $conn->beginTransaction();
    $ip = '203.0.113.13';

    try {
        $repo = userFailedLoginTestRepoForConn($conn);
        $repo->recordFailure(1, $ip, '2026-08-01 10:00:00');
        $repo->recordFailure(1, $ip, '2026-08-01 11:00:00');
        $repo->recordFailure(3, $ip, '2026-08-01 11:00:00');

        expect($repo->countRecentByUserId(1, '2026-08-01 10:30:00'))
            ->toBe(1)
            ->and($repo->countRecentByUserId(1, '2026-08-01 00:00:00'))
            ->toBe(2)
            ->and($repo->countRecentByUserId(3, '2026-08-01 00:00:00'))
            ->toBe(1);
    } finally {
        $conn->rollBack();
    }
});

test('countRecentByIp() counts only attempts from that ip at or after the threshold', function (): void {
    $conn = DbConnection::build();
    $ip = '203.0.113.14';
    $otherIp = '203.0.113.15';

    try {
        $repo = userFailedLoginTestRepo();
        $repo->recordFailure(1, $ip, '2026-08-01 10:00:00');
        $repo->recordFailure(1, $otherIp, '2026-08-01 10:00:00');

        expect($repo->countRecentByIp($ip, '2026-08-01 00:00:00'))
            ->toBe(1)
            ->and($repo->countRecentByIp($otherIp, '2026-08-01 00:00:00'))
            ->toBe(1)
            ->and($repo->countRecentByIp($ip, '2026-08-01 10:30:00'))
            ->toBe(0);
    } finally {
        userFailedLoginTestPurgeIp($conn, $ip);
        userFailedLoginTestPurgeIp($conn, $otherIp);
    }
});

test('countRecentByIp() returns 0 for an unparseable ip without querying (defensive branch)', function (): void {
    // Confirmed live (hand-mutated source, reran this exact test): removing
    // the early return and letting a null $ipVo reach setParameter('ip', ...)
    // is observably equivalent -- Doctrine binds an unbound-type null
    // parameter as SQL NULL rather than routing it through
    // ip_address_graceful's own convertToDatabaseValue(), so "f.ip = NULL"
    // (always false) still yields 0, same as the early return. The early
    // return only saves a wasted round-trip, it doesn't change this
    // method's observable result for any input.
    $repo = userFailedLoginTestRepo();

    expect($repo->countRecentByIp('not-an-ip', '2026-08-01 00:00:00'))
        ->toBe(0);
});

test('purgeOlderThan() deletes rows attempted before the threshold, keeps rows at or after it, and returns the deleted count', function (): void {
    $conn = DbConnection::build();
    $oldIp = '203.0.113.16';
    $recentIp = '203.0.113.17';

    try {
        $repo = userFailedLoginTestRepo();
        $repo->recordFailure(1, $oldIp, '2020-01-01 00:00:00');
        $repo->recordFailure(1, $oldIp, '2020-01-02 00:00:00');
        $repo->recordFailure(1, $recentIp, '2026-08-01 00:00:00');

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
        userFailedLoginTestPurgeIp($conn, $oldIp);
        userFailedLoginTestPurgeIp($conn, $recentIp);
    }
});
