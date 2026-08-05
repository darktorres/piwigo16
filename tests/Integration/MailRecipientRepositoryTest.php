<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Mail\MailRecipientRepository;
use Piwigo\Mail\Projection\MailRecipient;

/**
 * Fixture shape (see tests/Fixtures/piwigo-17.0.sql): 4 users --
 * 1 fixture_admin (status webmaster, mail_address set, member of group 1),
 * 2 guest (status guest, mail_address NULL), 3 regular_user (status normal,
 * mail_address NULL, member of groups 1 and 2), 4 power_user (status
 * normal, mail_address NULL, member of group 3). All 4 share language
 * 'en_UK' in user_infos out of the box.
 *
 * findAdminsAndWebmasters()/findDistinctLanguagesInGroup()/
 * findByGroupAndLanguage() all filter on a non-empty email address, so
 * users 2-4's real NULL mail_address excludes them by default -- each test
 * that needs a 2nd eligible recipient temporarily gives user 3 a real email
 * (and, for the language tests, a distinct language), restored in
 * tearDown() the same way CategoryRepositoryTest restores its own
 * temporarily-mutated fixture columns.
 */
final class MailRecipientRepositoryTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private MailRecipientRepository $repo;

    private Connection $conn;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        if (! self::$fixtureReady) {
            $this->resetDatabase();
            $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        $currentConfig = \Piwigo\Core\Kernel::container()->get(\Piwigo\Config\CurrentConfig::class);
        if (! $currentConfig instanceof \Piwigo\Config\CurrentConfig) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Config\CurrentConfig::class);
        }
        $currentConfig->reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $this->conn = DbConnection::build();
        $this->repo = new MailRecipientRepository($this->conn);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->conn->executeStatement("UPDATE " . Tables::users() . " SET mail_address = NULL WHERE id IN (3, 4)");
        $this->conn->executeStatement("UPDATE " . Tables::userInfos() . " SET status = 'normal', language = 'en_UK' WHERE user_id IN (3, 4)");
        parent::tearDown();
    }

    public function test_find_admins_and_webmasters_returns_only_the_webmaster_when_no_admin_status_exists(): void
    {
        $recipients = $this->repo->findAdminsAndWebmasters('id', 'username', 'mail_address', ['webmaster'], null, null);

        self::assertCount(1, $recipients);
        self::assertEquals(
            new MailRecipient(userId: 1, name: 'fixture_admin', email: 'fixture_admin@example.test', status: null),
            $recipients[0]
        );
    }

    public function test_find_admins_and_webmasters_includes_a_real_admin_status_user_with_a_real_email(): void
    {
        $this->conn->executeStatement("UPDATE " . Tables::users() . " SET mail_address = 'power.user@example.test' WHERE id = 4");
        $this->conn->executeStatement("UPDATE " . Tables::userInfos() . " SET status = 'admin' WHERE user_id = 4");

        $recipients = $this->repo->findAdminsAndWebmasters('id', 'username', 'mail_address', ['webmaster', 'admin'], null, null);

        $byId = $this->indexByUserId($recipients);
        self::assertSame([1, 4], array_keys($byId));
        self::assertSame('power_user', $byId[4]->name);
        self::assertSame('power.user@example.test', $byId[4]->email);
    }

    public function test_find_admins_and_webmasters_excludes_the_given_user_id(): void
    {
        $recipients = $this->repo->findAdminsAndWebmasters('id', 'username', 'mail_address', ['webmaster'], null, 1);

        self::assertSame([], $recipients);
    }

    public function test_find_admins_and_webmasters_filters_by_group(): void
    {
        // user 1 (webmaster) is a member of group 1 only.
        $inGroup = $this->repo->findAdminsAndWebmasters('id', 'username', 'mail_address', ['webmaster'], 1, null);
        $notInGroup = $this->repo->findAdminsAndWebmasters('id', 'username', 'mail_address', ['webmaster'], 3, null);

        self::assertCount(1, $inGroup);
        self::assertSame(1, $inGroup[0]->userId);
        self::assertSame([], $notInGroup);
    }

    public function test_find_admins_and_webmasters_returns_empty_for_a_status_nobody_has(): void
    {
        self::assertSame([], $this->repo->findAdminsAndWebmasters('id', 'username', 'mail_address', ['guest'], null, null));
    }

    public function test_find_distinct_languages_in_group_returns_only_eligible_members_languages(): void
    {
        $this->conn->executeStatement("UPDATE " . Tables::users() . " SET mail_address = 'regular.user@example.test' WHERE id = 3");
        $this->conn->executeStatement("UPDATE " . Tables::userInfos() . " SET language = 'fr_FR' WHERE user_id = 3");

        // group 1 has users 1 (en_UK, real email) and 3 (fr_FR, now a real email).
        $languages = $this->repo->findDistinctLanguagesInGroup('id', 'mail_address', 1, null);
        sort($languages);

        self::assertSame(['en_UK', 'fr_FR'], $languages);
    }

    public function test_find_distinct_languages_in_group_honors_the_language_filter(): void
    {
        $this->conn->executeStatement("UPDATE " . Tables::users() . " SET mail_address = 'regular.user@example.test' WHERE id = 3");
        $this->conn->executeStatement("UPDATE " . Tables::userInfos() . " SET language = 'fr_FR' WHERE user_id = 3");

        $languages = $this->repo->findDistinctLanguagesInGroup('id', 'mail_address', 1, 'fr_FR');

        self::assertSame(['fr_FR'], $languages);
    }

    public function test_find_distinct_languages_in_group_returns_empty_when_the_only_member_has_no_email(): void
    {
        // group 3's only member is user 4, whose mail_address is still NULL.
        self::assertSame([], $this->repo->findDistinctLanguagesInGroup('id', 'mail_address', 3, null));
    }

    public function test_find_by_group_and_language_returns_the_matching_recipient_with_its_status(): void
    {
        $recipients = $this->repo->findByGroupAndLanguage('id', 'username', 'mail_address', 1, 'en_UK');

        self::assertCount(1, $recipients);
        self::assertEquals(
            new MailRecipient(userId: 1, name: 'fixture_admin', email: 'fixture_admin@example.test', status: 'webmaster'),
            $recipients[0]
        );
    }

    public function test_find_by_group_and_language_returns_empty_for_a_language_nobody_in_the_group_has(): void
    {
        self::assertSame([], $this->repo->findByGroupAndLanguage('id', 'username', 'mail_address', 1, 'de_DE'));
    }

    public function test_find_by_group_and_language_scopes_correctly_across_two_languages_in_the_same_group(): void
    {
        $this->conn->executeStatement("UPDATE " . Tables::users() . " SET mail_address = 'regular.user@example.test' WHERE id = 3");
        $this->conn->executeStatement("UPDATE " . Tables::userInfos() . " SET language = 'fr_FR' WHERE user_id = 3");

        $frenchRecipients = $this->repo->findByGroupAndLanguage('id', 'username', 'mail_address', 1, 'fr_FR');
        $englishRecipients = $this->repo->findByGroupAndLanguage('id', 'username', 'mail_address', 1, 'en_UK');

        self::assertCount(1, $frenchRecipients);
        self::assertEquals(
            new MailRecipient(userId: 3, name: 'regular_user', email: 'regular.user@example.test', status: 'normal'),
            $frenchRecipients[0]
        );
        self::assertCount(1, $englishRecipients);
        self::assertSame(1, $englishRecipients[0]->userId);
    }

    /**
     * @param list<MailRecipient> $recipients
     * @return array<int, MailRecipient>
     */
    private function indexByUserId(array $recipients): array
    {
        $byId = [];
        foreach ($recipients as $recipient) {
            $byId[$recipient->userId] = $recipient;
        }

        return $byId;
    }
}
