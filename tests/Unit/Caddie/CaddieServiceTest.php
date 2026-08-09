<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Piwigo\Caddie\CaddieService;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Users\User;

/**
 * Piwigo\Caddie\CaddieService -- has its own dedicated
 * tests/Integration/CaddieServiceTest.php; this is the same spec ported
 * down to the Unit suite. fillCurrentUserCaddie() is a thin wrapper
 * resolving the current user id and delegating straight to
 * CaddieRepository::addElements() -- exercised through the real
 * Piwigo\Users\CurrentUser singleton (CurrentUserTestFactory has a
 * pre-boot fallback, no Kernel::boot() needed) to prove it reads the
 * *current* user's id, not a hardcoded/mixed-up one.
 */
function caddieServiceTestClear(Connection $conn, int $userId): void
{
    $conn->createQueryBuilder()
        ->delete(Tables::caddie())
        ->where('user_id = :userId')
        ->setParameter('userId', $userId)
        ->executeStatement();
}

/**
 * @return list<int>
 */
function caddieServiceTestFetchElementIds(Connection $conn, int $userId): array
{
    $ids = $conn->createQueryBuilder()
        ->select('element_id')
        ->from(Tables::caddie())
        ->where('user_id = :userId')
        ->setParameter('userId', $userId)
        ->orderBy('element_id', 'ASC')
        ->executeQuery()
        ->fetchFirstColumn();

    return array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $ids);
}

test('fillCurrentUserCaddie() inserts rows for the current user id', function (): void {
    $conn = DbConnection::build();

    try {
        CurrentUserTestFactory::get()->set(User::fromUserArray(['id' => 3]));

        CaddieService::fillCurrentUserCaddie([2, 4, 1], CurrentUserTestFactory::get());

        expect(caddieServiceTestFetchElementIds($conn, 3))->toBe([1, 2, 4]);
    } finally {
        caddieServiceTestClear($conn, 3);
    }
});

test('fillCurrentUserCaddie() scopes to whichever user is current', function (): void {
    $conn = DbConnection::build();

    try {
        CurrentUserTestFactory::get()->set(User::fromUserArray(['id' => 1]));
        CaddieService::fillCurrentUserCaddie([5], CurrentUserTestFactory::get());

        CurrentUserTestFactory::get()->set(User::fromUserArray(['id' => 4]));
        CaddieService::fillCurrentUserCaddie([2, 3], CurrentUserTestFactory::get());

        expect(caddieServiceTestFetchElementIds($conn, 1))->toBe([5])
            ->and(caddieServiceTestFetchElementIds($conn, 4))->toBe([2, 3]);
    } finally {
        caddieServiceTestClear($conn, 1);
        caddieServiceTestClear($conn, 4);
    }
});

test('fillCurrentUserCaddie() does nothing for an empty element list', function (): void {
    $conn = DbConnection::build();
    CurrentUserTestFactory::get()->set(User::fromUserArray(['id' => 3]));

    CaddieService::fillCurrentUserCaddie([], CurrentUserTestFactory::get());

    expect(caddieServiceTestFetchElementIds($conn, 3))->toBe([]);
});
