<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Piwigo\Bootstrap\PresentationAccessor;
use Piwigo\Config\ConfigEntry;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Core\Kernel;
use Piwigo\Core\PageState;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\Tables;
use Piwigo\Mail\NotificationByMailSender;
use Piwigo\Notification\Projection\UserMailNotification;

/**
 * Piwigo\Mail\NotificationByMailSender::incMailSentSuccess()/
 * incMailSentFailed()/displayCounterInfo()/assignVarsNbmMailContent()/
 * sendMailNotifications()/doSubscribeUnsubscribeNotificationByMail() --
 * all reachable directly (no need to drive a full subscribe/send admin-page
 * POST), and all had zero or partial coverage (see /home/torres/.claude/
 * plans/piped-enchanting-spark.md, Wave 1). Resolved through the real DI
 * container (PresentationAccessor), same "needs a real DB connection to
 * construct" shape as ExtendedDomainAccessorTest's accessors.
 *
 * A real mail-send's success/failure is normally non-deterministic here
 * (see NotificationByMailSubControllerTest's own docblock: depends on
 * whether the local MTA accepts-then-bounces or outright rejects) -- but
 * pointing `smtp_host` at a real closed local port (127.0.0.1:1, nothing
 * ever listens there) makes Symfony Mailer's EsmtpTransport fail the
 * *connection* itself, deterministically and near-instantly (confirmed via
 * a standalone sanity script: ECONNREFUSED in ~60ms, not a hang), which
 * MailService::mail() surfaces as a deterministic `false` return. This
 * lets the large "delivery failed" branches of sendMailNotifications()/
 * doSubscribeUnsubscribeNotificationByMail() finally be exercised for
 * real, without needing a mock transport (none is injected -- both
 * methods call `new MailService()` directly).
 *
 * That forced failure always goes through MailService::mail()'s own
 * `trigger_error(..., E_USER_WARNING)` (its calling condition,
 * `! (bool) ini_get('display_errors')`, is true in this CLI test process:
 * `display_errors` reads back as `''` here, confirmed via
 * `php -r "var_dump(ini_get('display_errors'));"`) -- phpunit.xml's
 * failOnWarning="true" would otherwise fail these tests on that expected,
 * production-code-deliberate warning, so every call that can reach a real
 * send is `@`-suppressed, matching ArrayHelperTest's established
 * `@unserialize()` pattern for the same failOnWarning interaction.
 */
final class NotificationByMailSenderTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private NotificationByMailSender $sender;

    private Connection $conn;

    /** @var array{check_key: string, enabled: int, last_send: ?string} */
    private array $user1OriginalRow;

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

        $conn = DbConnection::build();
        $repo = EntityManagerFactory::build($conn)->getRepository(ConfigEntry::class);
        self::assertInstanceOf(\Piwigo\Config\ConfigRepository::class, $repo);
        $configService = new ConfigService($repo);
        CurrentConfigService::set($configService);
        // sendMailNotifications()'s recent-post-dates block builds real
        // thumbnail URLs (NotificationService::getHtmlDescriptionRecentPostDate()
        // -> DerivativeImage::thumb_url()) -- confirmed via a standalone
        // sanity script that without this trio, that call chain throws
        // ("no URL service set (RequestBootstrap not run yet?)" / a null
        // DerivativeParams from an empty ImageStdParams type map), since
        // IntegrationTestCase never runs a full RequestBootstrap. Same
        // real-config-row wiring as CategoryDefaultRendererTest/
        // CalendarMonthlyTest's own identical setUp.
        $configService->loadConfFromDb();
        \Piwigo\Image\ImageStdParams::load_from_db();
        \Piwigo\Image\DerivativeImage::setUrlService(new \Piwigo\Url\UrlService(new \Piwigo\Html\HtmlService()));

        $this->conn = $conn;
        $row = $this->conn->fetchAssociative(
            'SELECT check_key, enabled, last_send FROM ' . Tables::userMailNotification() . ' WHERE user_id = 1'
        );
        self::assertIsArray($row);
        self::assertIsString($row['check_key']);
        self::assertIsNumeric($row['enabled']);
        $lastSend = $row['last_send'];
        if ($lastSend !== null) {
            self::assertIsString($lastSend);
        }
        $this->user1OriginalRow = [
            'check_key' => $row['check_key'],
            'enabled' => (int) $row['enabled'],
            'last_send' => $lastSend,
        ];

        $this->sender = PresentationAccessor::notificationByMailSender();
        PageState::reset();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->restoreUser1Row();
        CurrentConfig::setSmtpHost('');
        CurrentConfig::setNbmListAllEnabledUsersToSend(false);
        CurrentConfig::setNbmSendDetailedContent(true);
        PageState::reset();
        Kernel::reset();
        parent::tearDown();
    }

    private function fakeUser(): UserMailNotification
    {
        return new UserMailNotification(1, 'ck12345', 'fixture_admin', 'admin@example.test', '1', null, 'normal');
    }

    private function setUser1LastSend(?string $lastSend): void
    {
        $this->conn->executeStatement(
            'UPDATE ' . Tables::userMailNotification() . ' SET last_send = ? WHERE user_id = 1',
            [$lastSend]
        );
    }

    private function restoreUser1Row(): void
    {
        $this->conn->executeStatement(
            'UPDATE ' . Tables::userMailNotification() . ' SET enabled = ?, last_send = ? WHERE user_id = 1',
            [$this->user1OriginalRow['enabled'], $this->user1OriginalRow['last_send']]
        );
    }

    private function user1Enabled(): int
    {
        $value = $this->conn->fetchOne(
            'SELECT enabled FROM ' . Tables::userMailNotification() . ' WHERE user_id = 1'
        );
        self::assertIsNumeric($value);

        return (int) $value;
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

    public function test_incMailSentSuccess_records_an_info_message(): void
    {
        $this->sender->incMailSentSuccess($this->fakeUser());

        self::assertSame(
            ['Mail sent to fixture_admin [admin@example.test].'],
            PageState::current()->infos
        );
    }

    public function test_incMailSentFailed_records_an_error_message(): void
    {
        $this->sender->incMailSentFailed($this->fakeUser());

        self::assertSame(
            ['Error when sending email to fixture_admin [admin@example.test].'],
            PageState::current()->errors
        );
    }

    public function test_displayCounterInfo_reports_no_mail_to_send_when_nothing_was_attempted(): void
    {
        $this->sender->displayCounterInfo();

        self::assertSame(['No mail to be sent.'], PageState::current()->infos);
        self::assertSame([], PageState::current()->errors);
    }

    public function test_displayCounterInfo_reports_only_a_success_count_when_nothing_failed(): void
    {
        $this->sender->incMailSentSuccess($this->fakeUser());
        PageState::reset();

        $this->sender->displayCounterInfo();

        self::assertSame(['1 mail has been sent.'], PageState::current()->infos);
        self::assertSame([], PageState::current()->errors);
    }

    public function test_displayCounterInfo_reports_both_failure_and_success_counts_when_some_succeeded(): void
    {
        $this->sender->incMailSentSuccess($this->fakeUser());
        $this->sender->incMailSentFailed($this->fakeUser());
        PageState::reset();

        $this->sender->displayCounterInfo();

        self::assertSame(['1 mail has not been sent.'], PageState::current()->errors);
        self::assertSame(['1 mail has been sent.'], PageState::current()->infos);
    }

    public function test_displayCounterInfo_reports_only_a_failure_count_when_nothing_succeeded(): void
    {
        $this->sender->incMailSentFailed($this->fakeUser());
        PageState::reset();

        $this->sender->displayCounterInfo();

        self::assertSame(['1 mail has not been sent.'], PageState::current()->errors);
        self::assertSame([], PageState::current()->infos);
    }

    public function test_assignVarsNbmMailContent_builds_a_real_mail_template_without_error(): void
    {
        // No fatal/exception is the real assertion here: this exercises the
        // full UrlService full-url toggling + MailService::getMailTemplate()
        // chain end to end. The built Smarty template is a throwaway local
        // inside the method (never written back to $this->mailTemplate for
        // a single isolated call), so there's no further state to inspect
        // from outside without changing production code.
        $this->expectNotToPerformAssertions();
        $this->sender->assignVarsNbmMailContent($this->fakeUser());
    }

    public function test_sendMailNotifications_returns_an_empty_list_for_an_unrecognised_action(): void
    {
        self::assertSame([], $this->sender->sendMailNotifications('not_a_real_action'));
    }

    public function test_sendMailNotifications_send_action_reports_no_user_when_the_check_key_list_matches_nothing(): void
    {
        $result = $this->sender->sendMailNotifications('send', ['this-check-key-does-not-exist']);

        self::assertSame([], $result);
        self::assertSame(['No user to be notified by mail.'], PageState::current()->errors);
    }

    public function test_sendMailNotifications_list_to_send_returns_only_users_with_pending_news(): void
    {
        // Far enough in the past that every fixture photo (date_available
        // 2026-08-01, see NotificationServiceTest's own confirmed
        // newsExists() boundary) counts as "news" since that last_send.
        $this->setUser1LastSend('2000-01-01 00:00:00');

        $result = $this->sender->sendMailNotifications('list_to_send', [$this->user1OriginalRow['check_key']]);

        self::assertCount(1, $result);
        self::assertInstanceOf(UserMailNotification::class, $result[0]);
        self::assertSame($this->user1OriginalRow['check_key'], $result[0]->checkKey);
        self::assertSame([], PageState::current()->errors);
    }

    public function test_sendMailNotifications_list_to_send_excludes_a_user_with_no_pending_news(): void
    {
        // A last_send at (not before) the fixed test clock leaves no room
        // for any fixture photo to count as "new" -- the inverse fixture
        // shape from the "pending news" test above.
        $this->setUser1LastSend('2026-08-01 00:00:00');

        $result = $this->sender->sendMailNotifications('list_to_send', [$this->user1OriginalRow['check_key']]);

        self::assertSame([], $result);
    }

    public function test_sendMailNotifications_list_to_send_returns_the_raw_list_unfiltered_when_quick_list_is_enabled(): void
    {
        // Quick-list mode (nbm_list_all_enabled_users_to_send) skips the
        // per-user newsExists() check entirely -- confirmed by pairing it
        // with a last_send that would otherwise exclude the user (see the
        // "excludes a user with no pending news" test above).
        $this->setUser1LastSend('2026-08-01 00:00:00');
        CurrentConfig::setNbmListAllEnabledUsersToSend(true);

        $result = $this->sender->sendMailNotifications('list_to_send', [$this->user1OriginalRow['check_key']]);

        self::assertCount(1, $result);
        self::assertInstanceOf(UserMailNotification::class, $result[0]);
        self::assertSame($this->user1OriginalRow['check_key'], $result[0]->checkKey);
    }

    public function test_sendMailNotifications_send_action_records_a_failed_mail_and_treats_the_check_key_on_delivery_failure(): void
    {
        $this->setUser1LastSend('2000-01-01 00:00:00');
        CurrentConfig::setSmtpHost('127.0.0.1:1');

        // A forced delivery failure always raises MailService::mail()'s
        // own deliberate E_USER_WARNING in this CLI process, which
        // failOnWarning="true" would otherwise turn into a test failure.
        $result = $this->suppressMailerWarning(fn () => $this->sender->sendMailNotifications('send', [$this->user1OriginalRow['check_key']]));

        self::assertSame([$this->user1OriginalRow['check_key']], $result);
        // incMailSentFailed() (inside the per-user loop) then
        // displayCounterInfo() (after the loop) both write to `errors` --
        // displayCounterInfo() only ever adds to `infos` when at least one
        // mail also succeeded (see the already-covered
        // test_displayCounterInfo_reports_only_a_failure_count_when_nothing_succeeded).
        self::assertSame(
            [
                'Error when sending email to fixture_admin [fixture_admin@example.test].',
                '1 mail has not been sent.',
            ],
            PageState::current()->errors
        );
        self::assertSame([], PageState::current()->infos);
    }

    public function test_sendMailNotifications_send_action_uses_the_newsExists_only_branch_when_detailed_content_is_disabled(): void
    {
        $this->setUser1LastSend('2000-01-01 00:00:00');
        CurrentConfig::setSmtpHost('127.0.0.1:1');
        CurrentConfig::setNbmSendDetailedContent(false);

        $result = $this->suppressMailerWarning(fn () => $this->sender->sendMailNotifications('send', [$this->user1OriginalRow['check_key']]));

        self::assertSame([$this->user1OriginalRow['check_key']], $result);
        self::assertSame(
            [
                'Error when sending email to fixture_admin [fixture_admin@example.test].',
                '1 mail has not been sent.',
            ],
            PageState::current()->errors
        );
    }

    public function test_findAvailableCheckKey_returns_a_key_not_already_taken(): void
    {
        $key = $this->sender->findAvailableCheckKey();

        self::assertNotSame($this->user1OriginalRow['check_key'], $key);
        $taken = $this->conn->fetchOne(
            'SELECT COUNT(*) FROM ' . Tables::userMailNotification() . ' WHERE check_key = ?',
            [$key]
        );
        self::assertIsNumeric($taken);
        self::assertSame(0, (int) $taken);
    }

    public function test_getUserNotifications_delegates_to_the_notification_by_mail_service(): void
    {
        $result = $this->sender->getUserNotifications('subscribe', [$this->user1OriginalRow['check_key']], '');

        self::assertCount(1, $result);
        self::assertSame($this->user1OriginalRow['check_key'], $result[0]->checkKey);
        self::assertSame('fixture_admin', $result[0]->username);
    }

    public function test_checkSendmailTimeout_and_startTime_reflect_a_freshly_constructed_sender(): void
    {
        // A freshly-constructed sender's own $startTime is effectively
        // "now" (Piwigo\Core\TimingHelper::getMoment(), a real wall-clock
        // read, not the frozen PIWIGO_TEST_NOW), so an immediate
        // checkSendmailTimeout() call is always well within the timeout
        // window -- CurrentConfig::nbmTreatmentTimeoutDefault()'s default
        // is measured in whole seconds.
        self::assertGreaterThan(0.0, $this->sender->startTime());
        self::assertFalse($this->sender->isSendmailTimeout());

        $isTimeout = $this->sender->checkSendmailTimeout();

        self::assertFalse($isTimeout);
        self::assertFalse($this->sender->isSendmailTimeout());
    }

    public function test_doSubscribeUnsubscribeNotificationByMail_ignores_an_empty_check_key_list(): void
    {
        $result = $this->sender->doSubscribeUnsubscribeNotificationByMail(true, false, []);

        self::assertSame([], $result);
        self::assertSame(['0 users updated.'], PageState::current()->infos);
        self::assertSame([], PageState::current()->errors);
    }

    public function test_doSubscribeUnsubscribeNotificationByMail_records_a_failed_mail_and_leaves_enabled_unchanged_on_delivery_failure(): void
    {
        CurrentConfig::setSmtpHost('127.0.0.1:1');
        self::assertSame(1, $this->user1Enabled());

        $result = $this->suppressMailerWarning(fn () => $this->sender->doSubscribeUnsubscribeNotificationByMail(
            true,
            false,
            [$this->user1OriginalRow['check_key']]
        ));

        self::assertSame([$this->user1OriginalRow['check_key']], $result);
        // Four independent addError() call sites all fire for this one
        // failed attempt, in call order: incMailSentFailed() inside the
        // loop, the loop's own $doUpdate===false branch, displayCounterInfo()
        // after the loop (mirrors sendMailNotifications' identical
        // "errors only, no infos, on an all-failure batch" shape -- see
        // test_displayCounterInfo_reports_only_a_failure_count_when_nothing_succeeded),
        // and the final $errorOnUpdatedDataCount!==0 summary.
        self::assertSame(
            [
                'Error when sending email to fixture_admin [fixture_admin@example.test].',
                'User fixture_admin [fixture_admin@example.test] not removed from the subscription list.',
                '1 mail has not been sent.',
                '1 user not updated.',
            ],
            PageState::current()->errors
        );
        self::assertSame(['0 users updated.'], PageState::current()->infos);
        // $doUpdate stayed false on delivery failure, so the batched
        // massUpdate() never included this row -- enabled is untouched.
        self::assertSame(1, $this->user1Enabled());
    }
}
