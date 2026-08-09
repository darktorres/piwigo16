<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\Tables;
use Piwigo\Feed\FeedEntity;
use Piwigo\Feed\FeedRepository;

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
    $repo = EntityManagerFactory::build($conn)->getRepository(FeedEntity::class);

    return $repo;
}

function feedTestId(): string
{
    return 'ut_feed_' . bin2hex(random_bytes(8));
}

function feedTestDelete(Connection $conn, string $id): void
{
    $conn->createQueryBuilder()
        ->delete(Tables::userFeed())
        ->where('id = :id')
        ->setParameter('id', $id)
        ->executeStatement();
}

test('existsById() is false before insert() and true after', function (): void {
    $conn = DbConnection::build();
    $id = feedTestId();
    $repo = feedTestRepo();

    try {
        expect($repo->existsById($id))->toBeFalse();

        $repo->insert($id, 1);

        expect($repo->existsById($id))->toBeTrue();
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
        if ($info === null) {
            throw new RuntimeException('Expected a matching feed row.');
        }

        expect($info->userId)->toBe(1);
        expect($info->lastCheck)->toBeNull();
    } finally {
        feedTestDelete($conn, $id);
    }
});

test('findById() returns null for an id that was never inserted', function (): void {
    expect(feedTestRepo()->findById(feedTestId()))->toBeNull();
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
        if ($info === null) {
            throw new RuntimeException('Expected a matching feed row.');
        }

        expect($info->lastCheck)->toEqual($now);
    } finally {
        feedTestDelete($conn, $id);
    }
});

test('updateLastCheck() is a no-op for an id that doesn\'t exist, instead of throwing', function (): void {
    // Not feedTestRepo() -- its own toBeInstanceOf() assertion would make
    // this "risky" (throwsNoExceptions() expects exactly 0).
    $repo = EntityManagerFactory::build(DbConnection::build())->getRepository(FeedEntity::class);

    $repo->updateLastCheck(feedTestId(), new DateTimeImmutable('2026-08-01 12:00:00'));
})->throwsNoExceptions();
