<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\TypedRepository;
use Piwigo\Notification\NotificationByMailRepository;
use Piwigo\Notification\Projection\NotificationInsertRow;
use Piwigo\Notification\Projection\UserMailNotification;
use Piwigo\Notification\Projection\UserWithoutNotificationRow;
use Piwigo\Notification\UserMailNotificationEntity;

/**
 * Piwigo\Notification\NotificationByMailRepository -- has its own
 * dedicated tests/Integration/NotificationByMailRepositoryTest.php
 * covering 4 of its 6 methods; this ports those down to the Unit suite
 * via the real-DB-no-HTTP ImageRepositoryTest.php pattern and adds
 * nullifyBlankEmails()/findUsersWithoutNotificationRow() coverage of
 * its own.
 *
 * Fixture shape (tests/Fixtures/piwigo-17.0.sql): user_mail_notification
 * has 2 rows -- user 1 (fixture_admin, email set, check_key
 * 'abcdef1234567890', enabled=1), user 3 (regular_user, email NULL,
 * check_key 'ghijkl9876543210', enabled=0). Users 2 (guest)/4
 * (power_user) have no notification row and no real email.
 */
function nbmTestRepo(): NotificationByMailRepository
{
    $conn = DbConnection::build();
    $repo = TypedRepository::narrow(EntityManagerFactory::build($conn)->getRepository(UserMailNotificationEntity::class), NotificationByMailRepository::class);

    return $repo;
}

function nbmTestCountRows(Connection $conn): int
{
    $value = $conn->createQueryBuilder()
        ->select('COUNT(*)')
        ->from('user_mail_notification')
        ->executeQuery()
        ->fetchOne();

    return is_numeric($value) ? (int) $value : 0;
}

test('countByCheckKey() finds an existing key', function (): void {
    expect(nbmTestRepo()->countByCheckKey('abcdef1234567890'))
        ->toBe(1);
});

test('countByCheckKey() returns zero for an unknown key', function (): void {
    expect(nbmTestRepo()->countByCheckKey('no-such-key'))
        ->toBe(0);
});

test('findUserNotifications() subscribe returns every subscriber', function (): void {
    $rows = nbmTestRepo()
        ->findUserNotifications('subscribe', [], '');

    $usernames = array_map(static fn (UserMailNotification $r): string => $r->username, $rows);
    expect($usernames)
        ->toBe(['fixture_admin', 'regular_user']);
});

test('findUserNotifications() send excludes users with no email or disabled', function (): void {
    // regular_user has no email and is also disabled -- excluded either way.
    $rows = nbmTestRepo()
        ->findUserNotifications('send', [], '');

    expect($rows)
        ->toHaveCount(1)
        ->and($rows[0]->username)->toBe('fixture_admin');
});

test('findUserNotifications() filters by check key list', function (): void {
    $rows = nbmTestRepo()
        ->findUserNotifications('subscribe', ['abcdef1234567890'], '');

    expect($rows)
        ->toHaveCount(1)
        ->and($rows[0]->username)->toBe('fixture_admin');
});

test('findUserNotifications() filters by enabled value', function (): void {
    $rows = nbmTestRepo()
        ->findUserNotifications('subscribe', [], '0');

    expect($rows)
        ->toHaveCount(1)
        ->and($rows[0]->username)->toBe('regular_user');
});

test('findUserNotifications() narrows user_id to a real UserId', function (): void {
    $rows = nbmTestRepo()
        ->findUserNotifications('subscribe', [], '');

    expect($rows[0]->userId)->toEqual(UserId::from(1));
});

test('deleteByCheckKeys() is a no-op for an empty list', function (): void {
    $conn = DbConnection::build();
    $before = nbmTestCountRows($conn);

    nbmTestRepo()
        ->deleteByCheckKeys([]);

    // Guards against building "DELETE FROM ... WHERE check_key IN ()" --
    // invalid SQL -- for an empty list; the fixture's 2 real rows must
    // survive untouched, proving the early return (not a real,
    // scoped-to-nothing DELETE) is what actually ran.
    expect(nbmTestCountRows($conn))
        ->toBe($before);
});

test('deleteByCheckKeys() removes a key containing a single quote', function (): void {
    $checkKey = "o'brien-" . bin2hex(random_bytes(4));
    $repo = nbmTestRepo();
    // user 2 (guest) has no notification row in the fixture.
    $repo->insertNotifications([
        new NotificationInsertRow(userId: 2, checkKey: $checkKey, enabled: 0),
    ]);
    expect($repo->countByCheckKey($checkKey))
        ->toBe(1);

    $repo->deleteByCheckKeys([$checkKey]);

    expect($repo->countByCheckKey($checkKey))
        ->toBe(0);
});

test('insertNotifications() is a no-op for an empty list', function (): void {
    $conn = DbConnection::build();
    $before = nbmTestCountRows($conn);

    nbmTestRepo()
        ->insertNotifications([]);

    expect(nbmTestCountRows($conn))
        ->toBe($before);
});

test('nullifyBlankEmails() sets a whitespace-only email to NULL', function (): void {
    $conn = DbConnection::build();

    try {
        $conn->createQueryBuilder()
            ->update('users')
            ->set('mail_address', ':email')
            ->where('id = 4')
            ->setParameter('email', '   ')
            ->executeStatement();

        nbmTestRepo()
            ->nullifyBlankEmails();

        $email = $conn->createQueryBuilder()
            ->select('mail_address')
            ->from('users')
            ->where('id = 4')
            ->fetchOne();

        expect($email)
            ->toBeNull();
    } finally {
        $conn->createQueryBuilder()
            ->update('users')
            ->set('mail_address', 'NULL')
            ->where('id = 4')
            ->executeStatement();
    }
});

test('nullifyBlankEmails() leaves a real email untouched', function (): void {
    $conn = DbConnection::build();

    nbmTestRepo()
        ->nullifyBlankEmails();

    $email = $conn->createQueryBuilder()
        ->select('mail_address')
        ->from('users')
        ->where('id = 1')
        ->fetchOne();

    expect($email)
        ->toBe('fixture_admin@example.test');
});

test('findDistinctUserIds() returns every distinct user_id in the table', function (): void {
    $ids = array_map(static fn (UserId $id): int => $id->value, nbmTestRepo()->findDistinctUserIds());
    sort($ids);

    expect($ids)
        ->toBe([1, 3]);
});

test('findDistinctUserIds() and deleteForUserIds() agree on a real orphaned row', function (): void {
    // fk_user_mail_notification_user_id makes this orphan impossible to
    // create through normal writes -- same "bulk import with checks off"
    // scenario as CategoryServiceTest's own checkCategoriesIntegrity()
    // orphan tests.
    $conn = DbConnection::build();
    $isPostgres = getenv('PIWIGO_DB_DRIVER') === 'pgsql';
    $conn->executeStatement($isPostgres ? 'SET session_replication_role = replica' : 'SET FOREIGN_KEY_CHECKS=0');
    $conn->executeStatement("INSERT INTO user_mail_notification (user_id, check_key, enabled) VALUES (60000, 'orphan-nbm-test', 0)");
    $conn->executeStatement($isPostgres ? 'SET session_replication_role = DEFAULT' : 'SET FOREIGN_KEY_CHECKS=1');

    try {
        $repo = nbmTestRepo();
        $ids = array_map(static fn (UserId $id): int => $id->value, $repo->findDistinctUserIds());

        expect($ids)
            ->toContain(60000);

        $repo->deleteForUserIds([UserId::from(60000)]);

        expect(nbmTestCountRows($conn))
            ->toBe(2);
    } finally {
        $conn->executeStatement('DELETE FROM user_mail_notification WHERE user_id = 60000');
    }
});

test('deleteForUserIds() is a no-op for an empty list', function (): void {
    $conn = DbConnection::build();
    $before = nbmTestCountRows($conn);

    nbmTestRepo()
        ->deleteForUserIds([]);

    expect(nbmTestCountRows($conn))
        ->toBe($before);
});

test('findUsersWithoutNotificationRow() returns only users with a real email and no notification row yet', function (): void {
    $conn = DbConnection::build();

    try {
        // user 4 (power_user) has no notification row and no real email
        // in the fixture -- give it a real email so it becomes eligible.
        $conn->createQueryBuilder()
            ->update('users')
            ->set('mail_address', ':email')
            ->where('id = 4')
            ->setParameter('email', 'power.user@example.test')
            ->executeStatement();

        $result = nbmTestRepo()
            ->findUsersWithoutNotificationRow();
        $byId = [];
        foreach ($result as $row) {
            $byId[$row->userId] = $row;
        }

        // user 1 already has a notification row -- excluded.
        // user 2/3 have no real email -- excluded.
        expect(array_keys($byId))
            ->toBe([4])
            ->and($byId[4])
            ->toEqual(new UserWithoutNotificationRow(userId: 4, username: 'power_user', mailAddress: 'power.user@example.test'));
    } finally {
        $conn->createQueryBuilder()
            ->update('users')
            ->set('mail_address', 'NULL')
            ->where('id = 4')
            ->executeStatement();
    }
});
