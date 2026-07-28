<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Piwigo\Activity\ActivityEntity;
use Piwigo\Activity\ActivityService;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\ConfigEntry;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\Tables;
use Piwigo\Group\GroupEntity;
use Piwigo\Html\HtmlService;
use Piwigo\Mail\MailService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserInfoEntity;
use Piwigo\Users\UserService;

/**
 * Piwigo\Mail\MailService::mailNotificationAdmins()/mailAdmins()/
 * mailGroup()/mail()/sendMailTest() -- the real DB-backed, network-touching
 * methods this class's own MailServiceTest (Unit suite) deliberately
 * doesn't reach (that file only exercises pure helpers -- formatEmail(),
 * unformatEmail(), getCleanRecipientsList(), etc). See
 * /home/torres/.claude/plans/piped-enchanting-spark.md, Wave 1.
 *
 * Same deterministic-delivery-failure trick as
 * NotificationByMailSenderTest (see that class's own docblock for the full
 * reasoning): `smtp_host` pointed at a real closed local port makes every
 * real send fail near-instantly and deterministically, which
 * MailService::mail()'s own trigger_error(E_USER_WARNING) turns into a
 * PHP warning failOnWarning="true" would otherwise fail the test on -- a
 * plain `@` does NOT stop PHPUnit's ErrorHandler from surfacing it
 * regardless, so every call that can reach a real send goes through this
 * class's own suppressMailerWarning() (a real no-op error handler for the
 * duration of the call).
 *
 * Fixture shape this file depends on: only user 1 (fixture_admin) has a
 * real mail_address (users 2-4 are NULL) and is the fixture's only
 * webmaster/admin (status 'webmaster', users 3/4 are 'normal') -- see
 * piwigo_user_group: user 1 is in group 1 only, user 3 is in groups 1 and
 * 2. This makes user 1 double as both "the only admin" and "the only
 * group-1 member with a real email", and user 3 (status 'normal', so
 * AuthService::createUserAuthKey() actually succeeds for it, unlike
 * user 1's 'webmaster' status) the natural pick for a temporary real
 * mail_address to exercise mailGroup()'s auth-key-link branch without a
 * lasting fixture mutation.
 */
final class MailServiceTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private Connection $conn;

    private MailService $mailer;

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

        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();
        Kernel::boot();

        $this->conn = DbConnection::build();
        $repo = EntityManagerFactory::build($this->conn)->getRepository(ConfigEntry::class);
        self::assertInstanceOf(\Piwigo\Config\ConfigRepository::class, $repo);
        $configService = new ConfigService($repo);
        CurrentConfigService::set($configService);
        $configService->loadConfFromDb();

        MailService::reset();
        $this->mailer = new MailService();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->conn->executeStatement('UPDATE ' . Tables::users() . ' SET mail_address = NULL WHERE id = 3');
        CurrentConfig::setSmtpHost('');
        CurrentConfig::setDebugMail(false);
        // CurrentUser::attachGlobals() is a lazy-init `??=` -- once
        // setCurrentUserToFixtureAdmin() has called CurrentUser::set(),
        // attachGlobals() alone is a no-op (self::$instance is already
        // non-null) and the webmaster identity would leak into every
        // later test in this file/process. reset() first is required.
        CurrentUser::reset();
        CurrentUser::attachGlobals();
        MailService::reset();
        Kernel::reset();
        parent::tearDown();
    }

    private function buildUserService(): UserService
    {
        return new UserService(
            EntityManagerFactory::build($this->conn)->getRepository(UserInfoEntity::class),
            EntityManagerFactory::build($this->conn)->getRepository(GroupEntity::class),
            new MailService(),
            new ActivityService(EntityManagerFactory::build($this->conn)->getRepository(ActivityEntity::class)),
            new HtmlService(),
            $this->conn,
        );
    }

    private function setCurrentUserToFixtureAdmin(): void
    {
        $data = $this->buildUserService()->buildUser(UserId::from(1));
        CurrentUser::set(User::fromUserArray($data));
    }

    /**
     * A plain @ does NOT stop PHPUnit's ErrorHandler from surfacing the
     * expected "Mailer Error" trigger_error() below regardless (confirmed:
     * @ only affects error_reporting(), not whether the handler chain
     * runs) -- a real no-op error handler for the duration of the one
     * expected-to-warn call is the only reliable way to swallow it,
     * matching ImageGdTest's own established pattern.
     */
    private function suppressMailerWarning(callable $fn): mixed
    {
        set_error_handler(static fn (): bool => true);
        try {
            return $fn();
        } finally {
            restore_error_handler();
        }
    }

    public function test_mailNotificationAdmins_returns_false_for_an_empty_subject(): void
    {
        self::assertFalse($this->mailer->mailNotificationAdmins('', 'content'));
    }

    public function test_mailNotificationAdmins_returns_false_for_empty_content(): void
    {
        self::assertFalse($this->mailer->mailNotificationAdmins('subject', ''));
    }

    public function test_mailNotificationAdmins_sends_to_the_only_admin_and_fails_delivery_deterministically(): void
    {
        CurrentConfig::setSmtpHost('127.0.0.1:1');

        $result = $this->suppressMailerWarning(fn () => $this->mailer->mailNotificationAdmins('Test subject', 'Test content'));

        self::assertFalse($result);
    }

    public function test_mailNotificationAdmins_builds_subject_and_content_from_lang_args_arrays(): void
    {
        CurrentConfig::setSmtpHost('127.0.0.1:1');
        $subject = Lang::buildArgs('Registration of %s', 'someuser');
        $content = [
            Lang::buildArgs('User: %s', 'someuser'),
            Lang::buildArgs('Email: %s', 'someuser@example.test'),
        ];

        $result = $this->suppressMailerWarning(fn () => $this->mailer->mailNotificationAdmins($subject, $content));

        self::assertFalse($result);
    }

    public function test_mailAdmins_returns_false_when_content_and_tpl_are_both_empty(): void
    {
        self::assertFalse($this->mailer->mailAdmins([], []));
    }

    public function test_mailAdmins_returns_true_when_excluding_the_current_user_leaves_no_admin(): void
    {
        // user 1 (fixture_admin) is the fixture's only webmaster/admin --
        // excluding it as the current user (the default) empties the
        // recipient list entirely, so mailAdmins() short-circuits to
        // `true` (see its own "$admins === []" branch) without ever
        // reaching mail().
        $this->setCurrentUserToFixtureAdmin();

        self::assertTrue($this->mailer->mailAdmins(['content' => 'hi'], []));
    }

    public function test_mailAdmins_sends_to_the_admin_and_fails_delivery_deterministically(): void
    {
        CurrentConfig::setSmtpHost('127.0.0.1:1');

        $result = $this->suppressMailerWarning(fn () => $this->mailer->mailAdmins(['content' => 'hi'], []));

        self::assertFalse($result);
    }

    public function test_mailAdmins_only_webmasters_still_finds_the_webmaster(): void
    {
        CurrentConfig::setSmtpHost('127.0.0.1:1');

        $result = $this->suppressMailerWarning(fn () => $this->mailer->mailAdmins(['content' => 'hi'], [], true, true));

        self::assertFalse($result);
    }

    public function test_mailGroup_returns_false_for_group_zero(): void
    {
        self::assertFalse($this->mailer->mailGroup(0, ['content' => 'hi'], []));
    }

    public function test_mailGroup_returns_false_when_content_and_tpl_are_both_empty(): void
    {
        self::assertFalse($this->mailer->mailGroup(1, [], []));
    }

    public function test_mailGroup_returns_true_for_a_group_with_no_language_matches(): void
    {
        self::assertTrue($this->mailer->mailGroup(99999, ['content' => 'hi'], []));
    }

    public function test_mailGroup_sends_to_the_single_real_recipient_and_fails_delivery_deterministically(): void
    {
        // Group 1 has user 1 (real email) and user 3 (NULL email, filtered
        // out at the query level) -- exactly one real send attempt.
        CurrentConfig::setSmtpHost('127.0.0.1:1');

        $result = $this->suppressMailerWarning(fn () => $this->mailer->mailGroup(1, ['content' => 'hi']));

        self::assertFalse($result);
    }

    public function test_mailGroup_builds_an_auth_key_link_for_a_normal_status_recipient_with_a_real_email(): void
    {
        // Temporary, restored in tearDown: user 3 (status 'normal', so
        // AuthService::createUserAuthKey() actually succeeds, unlike user
        // 1's 'webmaster' status) is already in group 2 with no other
        // real-email member, giving a single deterministic recipient that
        // exercises the authkey!==false LINK-building branch.
        $this->conn->executeStatement(
            "UPDATE " . Tables::users() . " SET mail_address = 'temp3@example.test' WHERE id = 3"
        );
        CurrentConfig::setSmtpHost('127.0.0.1:1');

        $result = $this->suppressMailerWarning(fn () => $this->mailer->mailGroup(2, ['content' => 'hi'], ['assign' => ['LINK' => 'http://example.test/link']]));

        self::assertFalse($result);
    }

    public function test_mail_returns_true_immediately_when_to_and_cc_and_bcc_are_all_empty(): void
    {
        self::assertTrue($this->mailer->mail('', []));
    }

    public function test_mail_attempts_delivery_when_only_cc_is_set_and_to_is_empty(): void
    {
        CurrentConfig::setSmtpHost('127.0.0.1:1');

        $result = $this->suppressMailerWarning(fn () => $this->mailer->mail('', ['Cc' => 'cc@example.test', 'content' => 'hi']));

        self::assertFalse($result);
    }

    public function test_sendMailTest_dumps_a_labelled_error_file_when_debug_mail_is_enabled(): void
    {
        CurrentConfig::setSmtpHost('127.0.0.1:1');
        CurrentConfig::setDebugMail(true);
        $dir = dirname(__DIR__, 2) . '/_data/tmp';
        $before = glob($dir . '/mail.*');
        self::assertIsArray($before);

        $result = $this->suppressMailerWarning(fn () => $this->mailer->mail(
            ['name' => 'Someone', 'email' => 'someone@example.test'],
            ['subject' => 'hi', 'content' => 'body', 'content_format' => 'text/plain']
        ));
        self::assertFalse($result);

        $after = glob($dir . '/mail.*');
        self::assertIsArray($after);
        $created = array_values(array_diff($after, $before));

        self::assertCount(1, $created);
        self::assertStringEndsWith('.ERROR.txt', $created[0]);
        $contents = file_get_contents($created[0]);
        self::assertIsString($contents);
        self::assertStringStartsWith('ERROR: ', $contents);

        unlink($created[0]);
    }
}
