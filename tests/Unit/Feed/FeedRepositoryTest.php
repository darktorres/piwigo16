<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\TypedRepository;
use Piwigo\Feed\FeedEntity;
use Piwigo\Feed\FeedRepository;
use Piwigo\Feed\Projection\FeedInfo;

/**
 * Piwigo\Feed\FeedRepository -- has its own dedicated
 * tests/Integration/FeedRepositoryTest.php; this is the same spec
 * ported down to the Unit suite via the real-DB-no-HTTP
 * ImageRepositoryTest.php pattern. `user_feed` is empty in the fixture,
 * so every row here is a throwaway insert cleaned up in each test's own
 * finally block.
 */
function feedTestRepo(): FeedRepository
{
    $conn = DbConnection::build();
    $repo = TypedRepository::narrow(EntityManagerFactory::build($conn)->getRepository(FeedEntity::class), FeedRepository::class);

    return $repo;
}

function feedTestId(): string
{
    return 'ut_feed_' . bin2hex(random_bytes(8));
}

function feedTestDelete(Connection $conn, string $id): void
{
    $conn->createQueryBuilder()
        ->delete('user_feed')
        ->where('id = :id')
        ->setParameter('id', $id)
        ->executeStatement();
}

test('existsById() is false before insert() and true after', function (): void {
    $conn = DbConnection::build();
    $id = feedTestId();
    $repo = feedTestRepo();

    try {
        expect($repo->existsById($id))
            ->toBeFalse();

        $repo->insert($id, 1);

        expect($repo->existsById($id))
            ->toBeTrue();
    } finally {
        feedTestDelete($conn, $id);
    }
});

test('findById() returns the owning user id with a null last-check for a freshly-inserted feed', function (): void {
    $conn = DbConnection::build();
    $id = feedTestId();
    $repo = feedTestRepo();

    try {
        $repo->insert($id, 1);

        $info = $repo->findById($id);
        if (! $info instanceof FeedInfo) {
            throw new RuntimeException('Expected a matching feed row.');
        }

        expect($info->userId)
            ->toBe(1);
        expect($info->lastCheck)
            ->toBeNull();
    } finally {
        feedTestDelete($conn, $id);
    }
});

test('findById() returns null for an id that was never inserted', function (): void {
    expect(feedTestRepo()->findById(feedTestId()))
        ->toBeNull();
});

test('updateLastCheck() sets the timestamp on an existing feed', function (): void {
    $conn = DbConnection::build();
    $id = feedTestId();
    $repo = feedTestRepo();

    try {
        $repo->insert($id, 1);
        $now = new DateTimeImmutable('2026-08-01 12:00:00');

        $repo->updateLastCheck($id, $now);

        $info = $repo->findById($id);
        if (! $info instanceof FeedInfo) {
            throw new RuntimeException('Expected a matching feed row.');
        }

        expect($info->lastCheck)
            ->toEqual($now);
    } finally {
        feedTestDelete($conn, $id);
    }
});

test('findDistinctUserIds() returns every distinct user_id in the table', function (): void {
    $conn = DbConnection::build();
    $repo = feedTestRepo();
    $id1 = feedTestId();
    $id2 = feedTestId();

    try {
        $repo->insert($id1, 1);
        $repo->insert($id2, 3);

        $ids = array_map(static fn (UserId $id): int => $id->value, $repo->findDistinctUserIds());
        sort($ids);

        expect($ids)
            ->toBe([1, 3]);
    } finally {
        feedTestDelete($conn, $id1);
        feedTestDelete($conn, $id2);
    }
});

test('findDistinctUserIds() and deleteForUserIds() agree on a real orphaned row', function (): void {
    // fk_user_feed_user_id makes this orphan impossible to create through
    // normal writes (insert() itself would fail against a real
    // nonexistent user_id) -- same "bulk import with checks off"
    // scenario as NotificationByMailRepositoryTest's own sibling test.
    $conn = DbConnection::build();
    $isPostgres = getenv('PIWIGO_DB_DRIVER') === 'pgsql';
    $id = feedTestId();
    $conn->executeStatement($isPostgres ? 'SET session_replication_role = replica' : 'SET FOREIGN_KEY_CHECKS=0');
    $conn->executeStatement('INSERT INTO user_feed (id, user_id) VALUES (:id, 60000)', [
        'id' => $id,
    ]);
    $conn->executeStatement($isPostgres ? 'SET session_replication_role = DEFAULT' : 'SET FOREIGN_KEY_CHECKS=1');

    try {
        $repo = feedTestRepo();
        $ids = array_map(static fn (UserId $id): int => $id->value, $repo->findDistinctUserIds());

        expect($ids)
            ->toContain(60000);

        $repo->deleteForUserIds([UserId::from(60000)]);

        expect($repo->existsById($id))
            ->toBeFalse();
    } finally {
        feedTestDelete($conn, $id);
    }
});

test('deleteForUserIds() is a no-op for an empty list', function (): void {
    $conn = DbConnection::build();
    $id = feedTestId();
    $repo = feedTestRepo();

    try {
        $repo->insert($id, 1);

        $repo->deleteForUserIds([]);

        expect($repo->existsById($id))
            ->toBeTrue();
    } finally {
        feedTestDelete($conn, $id);
    }
});

test('updateLastCheck() is a no-op for an id that doesn\'t exist, instead of throwing', function (): void {
    // Not feedTestRepo() -- its own toBeInstanceOf() assertion would make
    // this "risky" (throwsNoExceptions() expects exactly 0).
    $repo = TypedRepository::narrow(EntityManagerFactory::build(DbConnection::build())->getRepository(FeedEntity::class), FeedRepository::class);

    $repo->updateLastCheck(feedTestId(), new DateTimeImmutable('2026-08-01 12:00:00'));
})->throwsNoExceptions();
