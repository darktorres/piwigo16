<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\Tables;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionRepository;

/**
 * Piwigo\Session\SessionRepository -- has its own dedicated
 * tests/Integration/SessionRepositoryTest.php; this is the same spec
 * ported down to the Unit suite via the real-DB-no-HTTP
 * ImageRepositoryTest.php pattern.
 */
function sessionTestRepo(): SessionRepository
{
    $conn = DbConnection::build();
    $repo = EntityManagerFactory::build($conn)->getRepository(SessionEntity::class);
    expect($repo)->toBeInstanceOf(SessionRepository::class);

    return $repo;
}

function sessionTestId(string $suffix = ''): string
{
    return 'ut-session-' . $suffix . bin2hex(random_bytes(8));
}

function sessionTestDelete(Connection $conn, string $id): void
{
    $conn->createQueryBuilder()
        ->delete(Tables::sessions())
        ->where('id = :id')
        ->setParameter('id', $id)
        ->executeStatement();
}

test('write() then read() round-trips real data', function (): void {
    $conn = DbConnection::build();
    $id = sessionTestId();

    try {
        $repo = sessionTestRepo();
        $repo->write($id, 'pwg_uid|i:1;');

        expect($repo->read($id))->toBe('pwg_uid|i:1;');
    } finally {
        sessionTestDelete($conn, $id);
    }
});

test('write() replaces an existing row for the same id', function (): void {
    $conn = DbConnection::build();
    $id = sessionTestId();

    try {
        $repo = sessionTestRepo();
        $repo->write($id, 'first');
        $repo->write($id, 'second');

        expect($repo->read($id))->toBe('second');
    } finally {
        sessionTestDelete($conn, $id);
    }
});

test('read() returns an empty string for a missing id', function (): void {
    expect(sessionTestRepo()->read(sessionTestId()))->toBe('');
});

test('destroy() removes the row', function (): void {
    $conn = DbConnection::build();
    $id = sessionTestId();

    try {
        $repo = sessionTestRepo();
        $repo->write($id, 'data');

        $repo->destroy($id);

        expect($repo->read($id))->toBe('');
    } finally {
        sessionTestDelete($conn, $id);
    }
});

test('gc() deletes only sessions older than the cutoff and returns the count', function (): void {
    $conn = DbConnection::build();
    $oldId = sessionTestId('old-');
    $freshId = sessionTestId('fresh-');

    try {
        $repo = sessionTestRepo();
        // write() tracks $oldId in this same EntityManager's identity
        // map -- then backdating its expiration via a raw UPDATE
        // (bypassing the ORM) is what actually proves gc()'s own
        // em->clear() call: without it, read($oldId) below would return
        // the stale in-memory row instead of seeing it as deleted.
        $repo->write($oldId, 'stale');
        $conn->executeStatement(
            'UPDATE ' . Tables::sessions() . ' SET expiration = ? WHERE id = ?',
            [(new DateTimeImmutable('-1 year'))->format('Y-m-d H:i:s'), $oldId],
        );
        $repo->write($freshId, 'fresh');

        $deleted = $repo->gc(3600);

        expect($deleted)->toBeGreaterThanOrEqual(1)
            ->and($repo->read($oldId))->toBe('')
            ->and($repo->read($freshId))->toBe('fresh');
    } finally {
        sessionTestDelete($conn, $oldId);
        sessionTestDelete($conn, $freshId);
    }
});

test('deleteByUserId() removes matching serialized sessions, bypassing any stale identity-map cache', function (): void {
    $conn = DbConnection::build();
    $id = sessionTestId();

    try {
        $repo = sessionTestRepo();
        // PHP's native session-serialize format ("name|value;", the "php"
        // session.serialize_handler this app actually uses) is NOT
        // serialize()'s array format -- the LIKE pattern this method
        // matches against expects the former.
        $repo->write($id, 'pwg_uid|i:987654;');

        $repo->deleteByUserId(987654);

        expect($repo->read($id))->toBe('');
    } finally {
        sessionTestDelete($conn, $id);
    }
});

test('deleteByUserId() does not remove a session belonging to a different user id', function (): void {
    $conn = DbConnection::build();
    $id = sessionTestId();

    try {
        $repo = sessionTestRepo();
        $repo->write($id, 'pwg_uid|i:111111;');

        $repo->deleteByUserId(222222);

        expect($repo->read($id))->toBe('pwg_uid|i:111111;');
    } finally {
        sessionTestDelete($conn, $id);
    }
});
