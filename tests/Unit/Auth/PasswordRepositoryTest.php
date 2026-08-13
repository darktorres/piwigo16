<?php

declare(strict_types=1);

use Piwigo\Auth\PasswordRepository;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Users\UserEntity;

/**
 * Piwigo\Auth\PasswordRepository -- no dedicated test file at any layer
 * before this one (checked Unit/Integration/Contract/Browser); only
 * incidentally exercised as a collaborator inside
 * tests/Integration/PasswordServiceTest.php/tests/Unit/Auth/
 * PasswordServiceTest.php. Uses fixture user 4 (power_user), not user 3
 * -- PasswordServiceTest.php's own rehash test already mutates user 3's
 * password column, and these are 2 separate --parallel-eligible Unit
 * files, same "own your row space" reasoning as every other repo test
 * this session.
 */
function passwordRepoTestRepo(): PasswordRepository
{
    return new PasswordRepository(EntityManagerFactory::build(DbConnection::build()));
}

const PASSWORD_REPO_TEST_USER_ID = 4;
const PASSWORD_REPO_TEST_ORIGINAL_HASH = '$2y$04$qo4pdN6PHJzR/qcol0qRl.zXOTP0tu34A1v6YC0tr1gQsvSIYS2Rm';

test('updatePasswordHash() updates the real password column', function (): void {
    $conn = DbConnection::build();

    try {
        passwordRepoTestRepo()->updatePasswordHash(PASSWORD_REPO_TEST_USER_ID, '$2y$04$disposableHashForThisTestOnly.....');

        $stored = $conn->createQueryBuilder()
            ->select('password')
            ->from('users')
            ->where('id = :id')
            ->setParameter('id', PASSWORD_REPO_TEST_USER_ID)
            ->executeQuery()
            ->fetchOne();

        expect($stored)
            ->toBe('$2y$04$disposableHashForThisTestOnly.....');
    } finally {
        $conn->executeStatement(
            'UPDATE users SET password = ? WHERE id = ?',
            [PASSWORD_REPO_TEST_ORIGINAL_HASH, PASSWORD_REPO_TEST_USER_ID]
        );
    }
});

test('updatePasswordHash() clears the identity map, so a later find() sees the real update instead of a stale cached hash', function (): void {
    $em = EntityManagerFactory::build(DbConnection::build());
    $repo = new PasswordRepository($em);
    $userId = UserId::from(PASSWORD_REPO_TEST_USER_ID);

    $before = $em->find(UserEntity::class, $userId);
    expect($before)
        ->not->toBeNull();

    try {
        $repo->updatePasswordHash(PASSWORD_REPO_TEST_USER_ID, '$2y$04$anotherDisposableHashForThisTest...');

        $after = $em->find(UserEntity::class, $userId);
        expect($after)
            ->not->toBeNull()
            ->and($after?->password)
            ->toBe('$2y$04$anotherDisposableHashForThisTest...');
    } finally {
        DbConnection::build()->executeStatement(
            'UPDATE users SET password = ? WHERE id = ?',
            [PASSWORD_REPO_TEST_ORIGINAL_HASH, PASSWORD_REPO_TEST_USER_ID]
        );
    }
});
