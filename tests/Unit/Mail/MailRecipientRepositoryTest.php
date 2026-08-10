<?php

declare(strict_types=1);

use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Mail\MailRecipientRepository;
use Piwigo\Mail\Projection\MailRecipient;

/**
 * Piwigo\Mail\MailRecipientRepository -- has its own dedicated
 * tests/Integration/MailRecipientRepositoryTest.php; this is the same
 * spec ported down to the Unit suite via the real-DB-no-HTTP
 * ImageRepositoryTest.php pattern, reusing that Integration test's own
 * documented fixture facts and "temporarily give user 3 a real
 * email/language, restore afterward" technique.
 *
 * Fixture shape (tests/Fixtures/piwigo-17.0.sql): 4 users -- 1
 * fixture_admin (status webmaster, mail_address set, member of group 1),
 * 2 guest (status guest, mail_address NULL), 3 regular_user (status
 * normal, mail_address NULL, member of groups 1 and 2), 4 power_user
 * (status normal, mail_address NULL, member of group 3). All 4 share
 * language 'en_UK' in user_infos out of the box. Every method here
 * filters on a non-empty email address, so users 2-4's real NULL
 * mail_address excludes them by default.
 */
function mailRecipientTestRepo(): MailRecipientRepository
{
    return new MailRecipientRepository(EntityManagerFactory::build(DbConnection::build()));
}

afterEach(function (): void {
    $conn = DbConnection::build();
    $conn->executeStatement('UPDATE ' . 'users' . ' SET mail_address = NULL WHERE id IN (3, 4)');
    $conn->executeStatement("UPDATE " . 'user_infos' . " SET status = 'normal', language = 'en_UK' WHERE user_id IN (3, 4)");
});

test('findAdminsAndWebmasters() returns only the real webmaster when no admin status exists', function (): void {
    $recipients = mailRecipientTestRepo()->findAdminsAndWebmasters(['webmaster'], null, null);

    expect($recipients)->toEqual([
        new MailRecipient(userId: 1, name: 'fixture_admin', email: 'fixture_admin@example.test', status: null),
    ]);
});

test('findAdminsAndWebmasters() includes a real admin-status user with a real email', function (): void {
    $conn = DbConnection::build();
    $conn->executeStatement("UPDATE " . 'users' . " SET mail_address = 'power.user@example.test' WHERE id = 4");
    $conn->executeStatement("UPDATE " . 'user_infos' . " SET status = 'admin' WHERE user_id = 4");

    $recipients = mailRecipientTestRepo()->findAdminsAndWebmasters(['webmaster', 'admin'], null, null);
    $byId = [];
    foreach ($recipients as $r) {
        $byId[$r->userId] = $r;
    }

    expect(array_keys($byId))->toBe([1, 4])
        ->and($byId[4]->name)->toBe('power_user')
        ->and($byId[4]->email)->toBe('power.user@example.test');
});

test('findAdminsAndWebmasters() excludes the given user id', function (): void {
    expect(mailRecipientTestRepo()->findAdminsAndWebmasters(['webmaster'], null, 1))->toBe([]);
});

test('findAdminsAndWebmasters() filters by group', function (): void {
    // user 1 (webmaster) is a member of group 1 only.
    $repo = mailRecipientTestRepo();
    $inGroup = $repo->findAdminsAndWebmasters(['webmaster'], 1, null);
    $notInGroup = $repo->findAdminsAndWebmasters(['webmaster'], 3, null);

    expect($inGroup)->toHaveCount(1)
        ->and($inGroup[0]->userId)->toBe(1)
        ->and($notInGroup)->toBe([]);
});

test('findAdminsAndWebmasters() returns empty for a status nobody has', function (): void {
    expect(mailRecipientTestRepo()->findAdminsAndWebmasters(['guest'], null, null))->toBe([]);
});

test('findDistinctLanguagesInGroup() returns only eligible members\' languages', function (): void {
    $conn = DbConnection::build();
    $conn->executeStatement("UPDATE " . 'users' . " SET mail_address = 'regular.user@example.test' WHERE id = 3");
    $conn->executeStatement("UPDATE " . 'user_infos' . " SET language = 'fr_FR' WHERE user_id = 3");

    // group 1 has users 1 (en_UK, real email) and 3 (fr_FR, now a real email).
    $languages = mailRecipientTestRepo()->findDistinctLanguagesInGroup(1, null);
    sort($languages);

    expect($languages)->toBe(['en_UK', 'fr_FR']);
});

test('findDistinctLanguagesInGroup() honors the language filter', function (): void {
    $conn = DbConnection::build();
    $conn->executeStatement("UPDATE " . 'users' . " SET mail_address = 'regular.user@example.test' WHERE id = 3");
    $conn->executeStatement("UPDATE " . 'user_infos' . " SET language = 'fr_FR' WHERE user_id = 3");

    expect(mailRecipientTestRepo()->findDistinctLanguagesInGroup(1, 'fr_FR'))->toBe(['fr_FR']);
});

test('findDistinctLanguagesInGroup() returns empty when the only member has no email', function (): void {
    // group 3's only member is user 4, whose mail_address is still NULL.
    expect(mailRecipientTestRepo()->findDistinctLanguagesInGroup(3, null))->toBe([]);
});

test('findByGroupAndLanguage() returns the matching recipient with its status', function (): void {
    $recipients = mailRecipientTestRepo()->findByGroupAndLanguage(1, 'en_UK');

    expect($recipients)->toEqual([
        new MailRecipient(userId: 1, name: 'fixture_admin', email: 'fixture_admin@example.test', status: 'webmaster'),
    ]);
});

test('findByGroupAndLanguage() returns empty for a language nobody in the group has', function (): void {
    expect(mailRecipientTestRepo()->findByGroupAndLanguage(1, 'de_DE'))->toBe([]);
});

test('findByGroupAndLanguage() scopes correctly across two languages in the same group', function (): void {
    $conn = DbConnection::build();
    $conn->executeStatement("UPDATE " . 'users' . " SET mail_address = 'regular.user@example.test' WHERE id = 3");
    $conn->executeStatement("UPDATE " . 'user_infos' . " SET language = 'fr_FR' WHERE user_id = 3");

    $repo = mailRecipientTestRepo();
    $frenchRecipients = $repo->findByGroupAndLanguage(1, 'fr_FR');
    $englishRecipients = $repo->findByGroupAndLanguage(1, 'en_UK');

    expect($frenchRecipients)->toEqual([
        new MailRecipient(userId: 3, name: 'regular_user', email: 'regular.user@example.test', status: 'normal'),
    ])
        ->and($englishRecipients)->toHaveCount(1)
        ->and($englishRecipients[0]->userId)->toBe(1);
});
