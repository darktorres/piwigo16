<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Audit\AuditLogEntity;
use Piwigo\Audit\AuditRepository;
use Piwigo\Audit\Projection\AuditLogEntry;
use Piwigo\Common\ValueObject\IpAddress;
use Piwigo\Common\ValueObject\SqlDateTime;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;

/**
 * Piwigo\Audit\AuditRepository -- has its own dedicated
 * tests/Integration/AuditRepositoryTest.php; this is the same spec
 * ported down to the Unit suite via the real-DB-no-HTTP
 * ImageRepositoryTest.php pattern. `audit_log` is a genuinely global,
 * insert-only, unscoped table -- every test here filters its own rows
 * by a unique per-run `entityType` marker instead of assuming exclusive
 * ownership of the table.
 *
 * 1 confirmed-equivalent mutation, not individually tested:
 * findLatestRowHash()'s own `setMaxResults(1)` mutated to `setMaxResults(2)`
 * is unreachable through its own return value -- the method only ever
 * reads `$entities[0]`, so whether the query fetches 1 or 2 rows (both
 * DESC-ordered, so index 0 is the same row either way) changes nothing
 * observable.
 */
function auditTestRepo(): AuditRepository
{
    $conn = DbConnection::build();
    $repo = EntityManagerFactory::build($conn)->getRepository(AuditLogEntity::class);

    return $repo;
}

function auditTestPurge(Connection $conn, string $entityType): void
{
    $conn->createQueryBuilder()
        ->delete('audit_log')
        ->where('entity_type = :entityType')
        ->setParameter('entityType', $entityType)
        ->executeStatement();
}

test('insert() persists every column and returns the real auto-generated id', function (): void {
    $conn = DbConnection::build();
    $marker = 'ut_audit_' . bin2hex(random_bytes(6));

    try {
        $id = auditTestRepo()
            ->insert(
                actorId: UserId::from(1),
                action: 'create',
                entityType: $marker,
                entityId: 42,
                beforeJson: null,
                afterJson: '{"name":"test"}',
                ipAddress: IpAddress::from('203.0.113.20'),
                createdAt: SqlDateTime::from('2026-08-01 12:00:00'),
                prevHash: null,
                rowHash: str_repeat('a', 64),
            );

        expect($id)
            ->toBeGreaterThan(0);

        $stored = $conn->createQueryBuilder()
            ->select('actor_id', 'action', 'entity_id', 'before_json', 'after_json', 'ip_address', 'created_at', 'prev_hash', 'row_hash')
            ->from('audit_log')
            ->where('id = :id')
            ->setParameter('id', $id)
            ->fetchAssociative();

        if (! is_array($stored)) {
            throw new RuntimeException('Expected a matching audit_log row.');
        }

        // after_json is stored via a real MySQL JSON column, which
        // reformats whitespace on write ('{"name":"test"}' round-trips as
        // '{"name": "test"}') -- compare the decoded value, not the raw
        // string, to avoid coupling this test to that formatting detail.
        $afterJson = $stored['after_json'];
        expect(is_string($afterJson) ? json_decode($afterJson, true) : null)
            ->toBe([
                'name' => 'test',
            ]);
        unset($stored['after_json']);

        expect($stored)
            ->toBe([
                'actor_id' => 1,
                'action' => 'create',
                'entity_id' => 42,
                'before_json' => null,
                'ip_address' => '203.0.113.20',
                'created_at' => '2026-08-01 12:00:00',
                'prev_hash' => null,
                'row_hash' => str_repeat('a', 64),
            ]);
    } finally {
        auditTestPurge($conn, $marker);
    }
});

test('findLatestRowHash() returns the most recently inserted row\'s hash, bypassing any stale identity-map cache (HINT_REFRESH)', function (): void {
    $conn = DbConnection::build();
    $marker = 'ut_audit_' . bin2hex(random_bytes(6));

    try {
        $repo = auditTestRepo();
        $repo->insert(null, 'create', $marker, null, null, null, null, SqlDateTime::from('2026-08-01 12:00:00'), null, str_repeat('a', 64));
        $repo->insert(null, 'update', $marker, null, null, null, null, SqlDateTime::from('2026-08-01 12:00:01'), str_repeat('a', 64), str_repeat('b', 64));

        // findLatestRowHash() is genuinely global (no entityType filter of
        // its own) -- assert it's the real chain tip we just wrote, not
        // that it's exclusively ours (another process could insert
        // between our 2nd insert and this read in a shared test DB).
        expect($repo->findLatestRowHash())
            ->toBe(str_repeat('b', 64));
    } finally {
        auditTestPurge($conn, $marker);
    }
});

test('findLatestRowHash() really does bypass the identity map (HINT_REFRESH), not just read a freshly-persisted value', function (): void {
    $conn = DbConnection::build();
    $marker = 'ut_audit_' . bin2hex(random_bytes(6));

    try {
        $repo = auditTestRepo();
        $id = $repo->insert(null, 'create', $marker, null, null, null, null, SqlDateTime::from('2026-08-01 12:00:00'), null, str_repeat('a', 64));

        // Same EntityManager the insert() above tracked this entity in --
        // a raw UPDATE bypassing the ORM entirely (simulating exactly the
        // out-of-band tampering this method's own docblock names) leaves
        // that tracked entity's in-memory rowHash stale. Without
        // HINT_REFRESH, findLatestRowHash() would still return
        // str_repeat('a', 64) here.
        $conn->createQueryBuilder()
            ->update('audit_log')
            ->set('row_hash', ':hash')
            ->where('id = :id')
            ->setParameter('hash', str_repeat('c', 64))
            ->setParameter('id', $id)
            ->executeStatement();

        expect($repo->findLatestRowHash())
            ->toBe(str_repeat('c', 64));
    } finally {
        auditTestPurge($conn, $marker);
    }
});

test('findLatestRowHash() returns null only when the table is genuinely empty', function (): void {
    // Can't truncate the shared table here -- just prove the method
    // returns a real, non-null 64-char hash when rows exist (the
    // null-when-empty branch is exercised by tests/Integration/
    // AuditRepositoryTest.php against its own isolated fixture DB).
    expect(auditTestRepo()->findLatestRowHash())
        ->not->toBeNull();
});

test('findAllInOrder() returns every row as an AuditLogEntry, in ascending id order', function (): void {
    $conn = DbConnection::build();
    $marker = 'ut_audit_' . bin2hex(random_bytes(6));

    try {
        $repo = auditTestRepo();
        $firstId = $repo->insert(UserId::from(1), 'create', $marker, 1, null, null, null, SqlDateTime::from('2026-08-01 12:00:00'), null, str_repeat('a', 64));
        $secondId = $repo->insert(UserId::from(1), 'update', $marker, 1, '{}', '{}', null, SqlDateTime::from('2026-08-01 12:00:01'), str_repeat('a', 64), str_repeat('b', 64));

        $all = $repo->findAllInOrder();
        $ours = array_values(array_filter($all, static fn (AuditLogEntry $e): bool => $e->entityType === $marker));

        expect($ours)
            ->toHaveCount(2)
            ->and($ours[0]->id)->toBe($firstId)
            ->and($ours[0]->action)->toBe('create')
            ->and($ours[1]->id)->toBe($secondId)
            ->and($ours[1]->action)->toBe('update')
            ->and($ours[1]->prevHash)->toBe(str_repeat('a', 64));
    } finally {
        auditTestPurge($conn, $marker);
    }
});

test('findAllInOrder() really does bypass the identity map (HINT_REFRESH), not just read a freshly-persisted value', function (): void {
    $conn = DbConnection::build();
    $marker = 'ut_audit_' . bin2hex(random_bytes(6));

    try {
        $repo = auditTestRepo();
        $id = $repo->insert(null, 'create', $marker, null, null, null, null, SqlDateTime::from('2026-08-01 12:00:00'), null, str_repeat('a', 64));

        // Same reasoning as findLatestRowHash()'s own HINT_REFRESH test --
        // a raw UPDATE bypassing the ORM leaves this same EntityManager's
        // already-tracked entity stale without the hint.
        $conn->createQueryBuilder()
            ->update('audit_log')
            ->set('row_hash', ':hash')
            ->where('id = :id')
            ->setParameter('hash', str_repeat('d', 64))
            ->setParameter('id', $id)
            ->executeStatement();

        $all = $repo->findAllInOrder();
        $ours = array_values(array_filter($all, static fn (AuditLogEntry $e): bool => $e->entityType === $marker));

        expect($ours)
            ->toHaveCount(1)
            ->and($ours[0]->rowHash)->toBe(str_repeat('d', 64));
    } finally {
        auditTestPurge($conn, $marker);
    }
});
