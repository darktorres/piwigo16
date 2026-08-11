<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Piwigo\Caddie\CaddieEntity;
use Piwigo\Caddie\CaddieRepository;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;

/**
 * Piwigo\Caddie\CaddieRepository -- has its own dedicated
 * tests/Integration/CaddieRepositoryTest.php; this is the same spec
 * ported down to the Unit suite via the real-DB-no-HTTP
 * ImageRepositoryTest.php pattern. caddie is empty in the fixture and
 * only 4 real (FK-valid) user ids exist, so -- same reasoning as the
 * Integration original -- each test cleans up its own rows via
 * try/finally instead of relying on disjoint user ids for
 * INTRA-file isolation.
 *
 * User ids 1/2 only, deliberately, never 3/4 -- composer test's own
 * parallel runner puts this file and CaddieServiceTest.php in
 * different worker processes against the SAME real, shared DB
 * (matching the exact SearchServiceTest.php/SearchRepositoryTest.php
 * collision found and fixed this same session), and both files used
 * to draw from the same 4-user pool with no cross-file coordination.
 * Confirmed live: this produced real, intermittent addElements()/
 * findElementIdsForUser() failures here whenever CaddieServiceTest.php's
 * own concurrently-running fillCurrentUserCaddie() tests happened to
 * write to (or clear) the same user id at the same moment. The 2 files
 * now partition the only 4 real ids -- this file owns 1/2,
 * CaddieServiceTest.php owns 3/4 -- so no shared row can ever be
 * touched by both at once, regardless of run order or worker
 * scheduling.
 *
 * 8 confirmed-equivalent mutations (3 distinct root causes), not
 * individually tested:
 * addElements()'s own per-column ParameterType::INTEGER type hints
 * (element_id/user_id) are redundant on this driver -- both bound
 * values are already real PHP ints from a strictly-typed method
 * parameter, and DBAL's own type inference binds a native int
 * correctly with or without an explicit hint (confirmed live: removing
 * either hint by hand and rerunning this file's own addElements() tests
 * still inserts the right row); findElementIdsForUser()'s
 * `is_numeric($v) ? (int) $v : 0` is unreachable -- getSingleColumnResult()
 * for an image_id-typed column already returns real, native PHP ints on
 * this driver (confirmed live via a raw, uncast query), same root cause
 * as ImageRepositoryTest.php/ApiKeyRepositoryTest.php; replaceForUser()'s
 * `array_keys($inserts[0])` reading a different array index is
 * unobservable -- every element in $inserts has the identical
 * ['user_id', 'element_id'] key shape, confirmed by this file's own
 * multi-element replaceForUser() test.
 */
function caddieTestRepo(): CaddieRepository
{
    $conn = DbConnection::build();

    return EntityManagerFactory::build($conn)->getRepository(CaddieEntity::class);
}

/**
 * getEntityManager() is protected on Doctrine's own EntityRepository
 * base class -- the em->clear() staleness tests below need direct
 * EntityManager access (for find()) alongside the repo, so this builds
 * both from the same connection instead of trying to pull the
 * EntityManager back out of an already-constructed repo.
 *
 * @return array{0: CaddieRepository, 1: Doctrine\ORM\EntityManagerInterface}
 */
function caddieTestRepoWithEm(): array
{
    $em = EntityManagerFactory::build(DbConnection::build());

    return [$em->getRepository(CaddieEntity::class), $em];
}

function caddieTestClear(Connection $conn, int $userId): void
{
    $conn->createQueryBuilder()
        ->delete('caddie')
        ->where('user_id = :userId')
        ->setParameter('userId', $userId)
        ->executeStatement();
}

/**
 * @return list<int>
 */
function caddieTestFetchElementIds(Connection $conn, int $userId): array
{
    $ids = $conn->createQueryBuilder()
        ->select('element_id')
        ->from('caddie')
        ->where('user_id = :userId')
        ->setParameter('userId', $userId)
        ->orderBy('element_id', 'ASC')
        ->executeQuery()
        ->fetchFirstColumn();

    return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $ids);
}

test('addElements() inserts new rows and returns the count', function (): void {
    $conn = DbConnection::build();

    try {
        $added = caddieTestRepo()
            ->addElements(1, [1, 2, 3]);

        expect($added)
            ->toBe(3)
            ->and(caddieTestFetchElementIds($conn, 1))
            ->toBe([1, 2, 3]);
    } finally {
        caddieTestClear($conn, 1);
    }
});

test('addElements() skips elements already in the caddie', function (): void {
    $conn = DbConnection::build();

    try {
        $repo = caddieTestRepo();
        $repo->addElements(2, [1, 2]);

        $added = $repo->addElements(2, [2, 3, 4]);

        expect($added)
            ->toBe(2)
            ->and(caddieTestFetchElementIds($conn, 2))
            ->toBe([1, 2, 3, 4]);
    } finally {
        caddieTestClear($conn, 2);
    }
});

test('addElements() returns zero for an empty list', function (): void {
    expect(caddieTestRepo()->addElements(2, []))->toBe(0);
});

test('addElements() silently skips a nonexistent image id', function (): void {
    $conn = DbConnection::build();

    $added = caddieTestRepo()
        ->addElements(1, [999999]);

    expect($added)
        ->toBe(0)
        ->and(caddieTestFetchElementIds($conn, 1))
        ->toBe([]);
});

test('addElements() scopes to the given user', function (): void {
    $conn = DbConnection::build();

    try {
        $repo = caddieTestRepo();
        $repo->addElements(1, [1]);
        $repo->addElements(2, [1]);

        expect(caddieTestFetchElementIds($conn, 1))
            ->toBe([1])
            ->and(caddieTestFetchElementIds($conn, 2))
            ->toBe([1]);
    } finally {
        caddieTestClear($conn, 1);
        caddieTestClear($conn, 2);
    }
});

test('removeElementsForUser() deletes only the given elements', function (): void {
    $conn = DbConnection::build();

    try {
        $repo = caddieTestRepo();
        $repo->addElements(1, [1, 2, 3]);

        $repo->removeElementsForUser(1, [2]);

        expect(caddieTestFetchElementIds($conn, 1))
            ->toBe([1, 3]);
    } finally {
        caddieTestClear($conn, 1);
    }
});

test('removeElementsForUser() is a no-op for an empty list', function (): void {
    $conn = DbConnection::build();

    try {
        $repo = caddieTestRepo();
        $repo->addElements(1, [1, 2]);

        $repo->removeElementsForUser(1, []);

        // Guards against building "DELETE ... WHERE element_id IN ()" for
        // an empty list -- the real rows just inserted above must survive
        // untouched.
        expect(caddieTestFetchElementIds($conn, 1))
            ->toBe([1, 2]);
    } finally {
        caddieTestClear($conn, 1);
    }
});

test('findElementIdsForUser() returns only that user\'s own elements', function (): void {
    $conn = DbConnection::build();

    try {
        $repo = caddieTestRepo();
        $repo->addElements(1, [1, 2]);
        $repo->addElements(2, [3]);

        expect($repo->findElementIdsForUser(1))
            ->toBe([1, 2])
            ->and($repo->findElementIdsForUser(2))
            ->toBe([3]);
    } finally {
        caddieTestClear($conn, 1);
        caddieTestClear($conn, 2);
    }
});

test('findElementIdsForUser() returns empty for a user with no caddie', function (): void {
    expect(caddieTestRepo()->findElementIdsForUser(2))
        ->toBe([]);
});

test('replaceForUser() empties the existing caddie then inserts the new elements', function (): void {
    $conn = DbConnection::build();

    try {
        $repo = caddieTestRepo();
        $repo->addElements(1, [1, 2, 3]);

        $repo->replaceForUser(1, [4, 5]);

        expect(caddieTestFetchElementIds($conn, 1))
            ->toBe([4, 5]);
    } finally {
        caddieTestClear($conn, 1);
    }
});

test('replaceForUser() empties the caddie for an empty replacement list', function (): void {
    $conn = DbConnection::build();

    try {
        $repo = caddieTestRepo();
        $repo->addElements(1, [1, 2]);

        $repo->replaceForUser(1, []);

        expect(caddieTestFetchElementIds($conn, 1))
            ->toBe([]);
    } finally {
        caddieTestClear($conn, 1);
    }
});

test('replaceForUser() clears the EntityManager identity map, so a later find() sees the real deletion instead of a stale cached entity', function (): void {
    $conn = DbConnection::build();

    try {
        [$repo, $em] = caddieTestRepoWithEm();
        $repo->addElements(1, [1]);
        // find() on this EntityManager tracks this composite-key entity
        // in its identity map -- replaceForUser()'s own bulk DQL DELETE
        // bypasses that map entirely, so without its em->clear() call, a
        // later find() for the same key would return this already-loaded
        // (now stale) object instead of null.
        $tracked = $em->find(CaddieEntity::class, [
            'userId' => UserId::from(1),
            'elementId' => ImageId::from(1),
        ]);
        expect($tracked)
            ->not->toBeNull();

        $repo->replaceForUser(1, [2]);

        expect($em->find(CaddieEntity::class, [
            'userId' => UserId::from(1),
            'elementId' => ImageId::from(1),
        ]))->toBeNull();
    } finally {
        caddieTestClear($conn, 1);
    }
});

test('removeElementsForUser() clears the EntityManager identity map, so a later find() sees the real deletion instead of a stale cached entity', function (): void {
    $conn = DbConnection::build();

    try {
        [$repo, $em] = caddieTestRepoWithEm();
        $repo->addElements(1, [1]);
        $tracked = $em->find(CaddieEntity::class, [
            'userId' => UserId::from(1),
            'elementId' => ImageId::from(1),
        ]);
        expect($tracked)
            ->not->toBeNull();

        $repo->removeElementsForUser(1, [1]);

        expect($em->find(CaddieEntity::class, [
            'userId' => UserId::from(1),
            'elementId' => ImageId::from(1),
        ]))->toBeNull();
    } finally {
        caddieTestClear($conn, 1);
    }
});
